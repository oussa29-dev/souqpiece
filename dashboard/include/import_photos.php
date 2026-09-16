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

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nomEntree = $zip->getNameIndex($i);
        if ($nomEntree === false) {
            continue;
        }
        // Dossiers (y compris __MACOSX/...) et fichiers systeme - jamais
        // une vraie photo, ignores silencieusement.
        if (substr($nomEntree, -1) === '/' || str_starts_with($nomEntree, '__MACOSX/')) {
            continue;
        }
        $base = basename($nomEntree);
        if (in_array(strtolower($base), $ignores, true)) {
            continue;
        }

        $cheminCible = $dossierDestination . DIRECTORY_SEPARATOR . $base;

        if (!$zip->extractTo($dossierDestination, [$nomEntree])) {
            continue;
        }

        $cheminReel = realpath($cheminCible);
        if ($cheminReel === false || strncmp($cheminReel, $racineReelle, strlen($racineReelle)) !== 0) {
            // Extrait hors du dossier attendu (zip slip) - supprime et ignore.
            if ($cheminReel !== false) {
                @unlink($cheminReel);
            }
            continue;
        }

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
