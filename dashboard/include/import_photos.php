<?php
// Import en masse des photos produit depuis un fichier .zip (voir
// PLAN_IMPORT_PHOTOS.md). Nom de fichier attendu :
// REFERENCE_MARQUEPIECE_N.ext - les deux premiers segments identifient
// le produit exact (meme logique reference+marque que le reste du
// site), N indique dans quel emplacement (img1..img10) la ranger en
// priorite.

class ImportPhotosException extends Exception
{
}

// Extensions acceptees - decision explicite (voir plan) : uniquement ce
// que produit un export/telephone courant, jamais .heic (illisible par
// les navigateurs sans conversion) ni aucun autre format.
function photo_extensions_acceptees(): array
{
    return ['jpg', 'jpeg', 'png'];
}

// "/" et "*" sont interdits dans un nom de fichier Windows, mais 7013
// references (~27% du catalogue) et 88 marques contiennent un "/" reel
// (ex. reference "84306-02190/", marque "ZEXEL/BOSCH" pour une piece
// compatible double-marque) - "*" est plus rare (157 references). Le
// boss doit pouvoir les taper quand meme dans le nom du fichier : ces
// deux caracteres sont remplacables par un marqueur reversible, jamais
// vu dans aucune reference ou marque actuelle (verifie), reconverti a
// l'identique avant la recherche en base - jamais de correspondance
// approximative. Volontairement PAS de simple suppression du caractere
// interdit : mesure sur le catalogue reel, ça creerait de vraies
// collisions entre produits distincts (ex. ".11115-58070" avec et sans
// le slash correspondent a 2 produits differents).
function photo_marqueurs_caracteres_interdits(): array
{
    return ['~' => '/', '^' => '*'];
}

function photo_restaurer_caracteres_interdits(string $texte): string
{
    return strtr($texte, photo_marqueurs_caracteres_interdits());
}

/**
 * Analyse un nom de fichier - ancre depuis la FIN plutot que le debut :
 * une reference peut elle-meme contenir un underscore (deja vu dans le
 * catalogue avec d'autres separateurs comme "/"), decouper naivement sur
 * le premier "_" casserait ce cas. Les noms de marque observes dans le
 * catalogue ne contiennent eux jamais d'underscore.
 *
 * @return array{reference:string, marquepiece:string, imgnbr:int}|null null si le nom ne correspond pas au format attendu.
 */
function photo_parser_nom(string $nomFichier): ?array
{
    $extensions = implode('|', array_map('preg_quote', photo_extensions_acceptees()));
    // 1-2 chiffres seulement - exclut volontairement un nombre qui
    // ressemblerait a autre chose (une annee, un code produit) plutot
    // que de le confondre avec un numero d'image.
    if (!preg_match('/^(.*)_(\d{1,2})\.(' . $extensions . ')$/i', $nomFichier, $m)) {
        return null;
    }
    $avantNumero = $m[1];
    $imgnbr = (int)$m[2];

    if ($imgnbr < 1 || $imgnbr > 10) {
        return null;
    }

    $dernierUnderscore = strrpos($avantNumero, '_');
    if ($dernierUnderscore === false) {
        return null;
    }

    $reference = trim(substr($avantNumero, 0, $dernierUnderscore));
    $marquepiece = trim(substr($avantNumero, $dernierUnderscore + 1));

    if ($reference === '' || $marquepiece === '') {
        return null;
    }

    $reference = photo_restaurer_caracteres_interdits($reference);
    $marquepiece = photo_restaurer_caracteres_interdits($marquepiece);

    return ['reference' => $reference, 'marquepiece' => $marquepiece, 'imgnbr' => $imgnbr];
}

/**
 * Cherche le premier emplacement libre (chaine vide) a partir de
 * $depart : d'abord vers l'avant ($depart, $depart+1, ..., 10), puis, si
 * rien de libre, boucle vers le debut (1, 2, ..., $depart-1). $depart
 * est un point de depart, pas une position exacte des qu'elle est deja
 * prise. Le bouclage est necessaire : un produit avec par exemple img8
 * plein, img9 libre, img10 plein, et une photo demandant img10, doit
 * quand meme trouver img9 plutot que d'echouer alors qu'une place existe
 * juste avant.
 *
 * @param array<int,string> $emplacements cle 1..10 => nom de fichier stocke (vide si libre)
 */
function photo_trouver_emplacement_libre(array $emplacements, int $depart): ?int
{
    for ($n = $depart; $n <= 10; $n++) {
        if (trim((string)($emplacements[$n] ?? '')) === '') {
            return $n;
        }
    }
    for ($n = 1; $n < $depart; $n++) {
        if (trim((string)($emplacements[$n] ?? '')) === '') {
            return $n;
        }
    }
    return null;
}

// Extraction securisee : chaque entree du zip est verifiee pour rester
// bien a l'interieur du dossier de destination avant extraction ("zip
// slip" - une entree malveillante du type "../../..." ne doit jamais
// pouvoir ecrire hors de ce dossier). Ignore silencieusement les
// dossiers et les fichiers systeme connus (jamais une vraie photo).
function photo_extraire_zip_securise(string $cheminZip, string $dossierDestination): array
{
    $zip = new ZipArchive();
    if ($zip->open($cheminZip) !== true) {
        throw new ImportPhotosException("Impossible d'ouvrir le fichier zip (corrompu ou format invalide).");
    }

    if (!is_dir($dossierDestination) && !mkdir($dossierDestination, 0755, true) && !is_dir($dossierDestination)) {
        $zip->close();
        throw new ImportPhotosException('Impossible de créer le dossier temporaire d\'extraction.');
    }

    $racineReelle = realpath($dossierDestination);
    if ($racineReelle === false) {
        $zip->close();
        throw new ImportPhotosException('Dossier temporaire introuvable après création.');
    }

    $ignores = ['.ds_store', 'thumbs.db'];
    $fichiers = [];
    $dejaEcrits = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nomEntree = $zip->getNameIndex($i);
        if ($nomEntree === false) {
            continue;
        }
        // Dossiers (y compris __MACOSX/...) et fichiers systeme - jamais
        // une vraie photo, ignores silencieusement.
        if (substr($nomEntree, -1) === '/' || substr($nomEntree, 0, 9) === '__MACOSX/') {
            continue;
        }

        // Extrait le contenu nous-memes (getFromIndex + file_put_contents)
        // plutot que via extractTo() : la plupart des photos sont zippees
        // a partir d'un DOSSIER (comportement par defaut de Windows/
        // WinRAR quand on fait clic droit > Compresser), donc les entrees
        // portent un chemin du type "MonDossier/REF_MARQUE_1.jpg" -
        // extractTo() recree cette arborescence sur le disque alors que
        // le code ne verifiait qu'un chemin plat (basename seul), ratant
        // systematiquement le vrai fichier extrait et l'ignorant en
        // silence, sans meme le signaler comme erreur (mesure : un vrai
        // lot de 3 photos dans un sous-dossier, aucune appliquee, aucune
        // anomalie affichee). En choisissant nous-memes ou ecrire (juste
        // le nom de fichier final, sans le sous-dossier d'origine), on
        // elimine aussi tout risque de zip-slip a la racine : basename()
        // ne peut jamais produire de chemin qui sorte de $dossierDestination.
        $nomNormalise = str_replace('\\', '/', $nomEntree);
        $base = basename($nomNormalise);
        if ($base === '' || in_array(strtolower($base), $ignores, true)) {
            continue;
        }
        if (isset($dejaEcrits[strtolower($base)])) {
            // Meme nom de fichier rencontre deux fois dans le zip (deux
            // sous-dossiers differents) - la premiere occurrence est
            // gardee, la suivante ignoree plutot que silencieusement
            // ecrasee sur le disque.
            continue;
        }

        $contenu = $zip->getFromIndex($i);
        if ($contenu === false) {
            continue;
        }

        $cheminCible = $dossierDestination . DIRECTORY_SEPARATOR . $base;
        if (file_put_contents($cheminCible, $contenu) === false) {
            continue;
        }

        $cheminReel = realpath($cheminCible);
        if ($cheminReel === false || strncmp($cheminReel, $racineReelle, strlen($racineReelle)) !== 0) {
            // Garde-fou supplementaire, ne devrait plus jamais se
            // declencher maintenant que le chemin est construit par nous.
            if ($cheminReel !== false) {
                @unlink($cheminReel);
            }
            continue;
        }

        $dejaEcrits[strtolower($base)] = true;
        $fichiers[] = $cheminReel;
    }

    $zip->close();
    return $fichiers;
}

function photo_supprimer_dossier(string $dossier): void
{
    if (!is_dir($dossier)) {
        return;
    }
    $items = scandir($dossier);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $chemin = $dossier . DIRECTORY_SEPARATOR . $item;
        if (is_dir($chemin)) {
            photo_supprimer_dossier($chemin);
        } else {
            @unlink($chemin);
        }
    }
    @rmdir($dossier);
}
