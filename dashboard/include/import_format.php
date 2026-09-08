<?php
// Détection du format d'un fichier Excel d'import (stock/ventes/achats) par
// NOM de colonne, jamais par lettre fixe. Motivation (voir
// PLAN_IMPORT_STOCK_3_FICHIERS.md) : le boss a déjà envoyé deux mises en
// page différentes du même rapport "stock complet" (colonnes A→H puis
// A→K, `N°` ajouté, `PV Detail` ajouté) et prévient explicitement qu'il
// faut se baser sur le nom des colonnes, pas leur position - vérifié :
// un fichier à l'ancien format lu avec les lettres de l'autre créerait
// ~17 500 produits fantômes.

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ImportFormatException extends Exception
{
}

// Normalise un libellé d'en-tête pour la comparaison : majuscules, sans
// accents, sans espaces/ponctuation. "P.Achat Moyen" et "Quantite Vendue"
// et "Qté Vendue" doivent pouvoir être reconnus malgré leurs graphies
// différentes.
function import_format_normaliser_libelle(string $texte): string
{
    $texte = trim($texte);
    // Le signe degre ("N°") translitere de facon imprevisible selon la
    // plateforme (parfois "deg", parfois disparait, parfois casse
    // iconv) - autant le retirer explicitement avant toute autre etape.
    $texte = str_replace(["\xC2\xB0", '°'], '', $texte);
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
    if ($translit !== false) {
        $texte = $translit;
    }
    $texte = strtoupper($texte);
    $texte = preg_replace('/[^A-Z0-9]/', '', $texte);
    return $texte ?? '';
}

// Table des libellés connus -> champ canonique. Une valeur de libellé
// peut apparaître dans plusieurs fichiers avec la même orthographe (ex.
// "Marque") ou une orthographe différente selon le fichier (ex. "Qté
// Vendue" côté ventes vs "Quantite Vendue" côté achats) - les deux sont
// listés et pointent vers le même champ canonique.
function import_format_table_libelles(): array
{
    return [
        'REFERENCE' => 'REFERENCE',
        'DESIGNATION' => 'DESIGNATION',
        'DESIGNATIONS' => 'DESIGNATION',
        'MARQUE' => 'MARQUE',
        'QUANT' => 'QUANT',
        'QUANTITE' => 'QUANT',
        'PRIXACHAT' => 'PRIX_ACHAT',
        'PACHATMOYEN' => 'PRIX_ACHAT',
        'PVGROS' => 'PV_GROS',
        'PVENTEMOYEN' => 'PV_GROS',
        'PVDETAIL' => 'PV_DETAIL',
        'STOCKACTUEL' => 'STOCK_ACTUEL',
        'QTEVENDUE' => 'QTE_VENDUE',
        'QUANTITEVENDUE' => 'QTE_VENDUE',
        'QUANTITEACHETE' => 'QTE_ACHETEE',
        'QUANTITEACHETEE' => 'QTE_ACHETEE',
        'CLIENT' => 'CLIENT',
        'NFACTURE' => 'FACTURE',
        'DATEVENTE' => 'DATE_VENTE',
        'BENEFICE' => 'BENEFICE',
        'PRIXVENTE' => 'PRIX_VENTE',
        'PRIXMOYEN' => 'PRIX_MOYEN',
        'N' => 'NUMERO_LIGNE',
    ];
}

// Champs numériques : sujets au décalage "en-tête fusionné" (la valeur
// vit une colonne à gauche du libellé). Jamais les champs texte
// (référence/désignation/marque), qui n'ont jamais montré ce décalage
// dans les fichiers réels observés.
function import_format_champs_numeriques(): array
{
    return [
        'QUANT', 'PRIX_ACHAT', 'PV_GROS', 'PV_DETAIL', 'STOCK_ACTUEL',
        'QTE_VENDUE', 'QTE_ACHETEE', 'BENEFICE', 'PRIX_VENTE', 'PRIX_MOYEN',
        'NUMERO_LIGNE',
    ];
}

function import_format_est_vide($valeur): bool
{
    return $valeur === null || trim((string)$valeur) === '';
}

/**
 * Détecte la ligne d'en-tête, le type de fichier (stock/ventes/achats) et
 * la colonne réelle de chaque champ reconnu, en se basant uniquement sur
 * les libellés - jamais sur une lettre de colonne fixe.
 *
 * @return array{type:string, ligne_entete:int, colonnes:array<string,string>, libelles_trouves:array<string,string>}
 * @throws ImportFormatException si aucune ligne d'en-tête exploitable n'est trouvée,
 *                                ou si le type de fichier ne peut pas être determiné.
 */
function import_format_detecter(Worksheet $sheet): array
{
    $table = import_format_table_libelles();
    $derniereColonne = $sheet->getHighestColumn();
    $derniereColonneIndex = min(Coordinate::columnIndexFromString($derniereColonne), 20);

    $meilleureLigne = null;
    $meilleuresColonnes = [];
    $meilleursLibelles = [];
    $meilleurScore = 0;

    for ($ligne = 1; $ligne <= 12; $ligne++) {
        $colonnesTrouvees = [];
        $libellesTrouves = [];
        for ($col = 1; $col <= $derniereColonneIndex; $col++) {
            $lettre = Coordinate::stringFromColumnIndex($col);
            $brut = trim((string)$sheet->getCell($lettre . $ligne)->getValue());
            if ($brut === '') {
                continue;
            }
            $norme = import_format_normaliser_libelle($brut);
            if ($norme !== '' && isset($table[$norme])) {
                $champ = $table[$norme];
                // Le premier trouvé (de gauche à droite) gagne - en cas de
                // doublon improbable sur une ligne, mieux vaut un
                // comportement déterministe qu'un écrasement silencieux.
                if (!isset($colonnesTrouvees[$champ])) {
                    $colonnesTrouvees[$champ] = $lettre;
                    $libellesTrouves[$champ] = $brut;
                }
            }
        }

        // REFERENCE/DESIGNATION/MARQUE comptent double : ce sont les
        // champs les plus fiables pour reconnaître une vraie ligne
        // d'en-tête plutôt qu'une ligne de titre ou une coïncidence.
        $score = count($colonnesTrouvees);
        foreach (['REFERENCE', 'DESIGNATION', 'MARQUE'] as $cle) {
            if (isset($colonnesTrouvees[$cle])) {
                $score++;
            }
        }

        if ($score > $meilleurScore) {
            $meilleurScore = $score;
            $meilleureLigne = $ligne;
            $meilleuresColonnes = $colonnesTrouvees;
            $meilleursLibelles = $libellesTrouves;
        }
    }

    if ($meilleureLigne === null || $meilleurScore < 5) {
        throw new ImportFormatException(
            "Impossible de reconnaître la mise en page de ce fichier : aucune ligne "
            . "d'en-tête avec des colonnes connues (Reference, Designation, Marque...) "
            . "n'a été trouvée dans les 12 premières lignes."
        );
    }

    $premiereLigneDonnees = $meilleureLigne + 1;

    // Correction du décalage "en-tête fusionné" : sur les fichiers réels,
    // le libellé "Quant" (et lui seul, jusqu'ici) est positionné une
    // colonne à droite de sa vraie colonne de valeurs (vérifié sur les
    // deux versions du fichier stock complet). Détecté génériquement :
    // si la colonne du libellé est vide sur un échantillon de lignes de
    // données alors que la colonne immédiatement à gauche est remplie,
    // la vraie colonne de valeurs est celle de gauche.
    $champsNumeriques = import_format_champs_numeriques();
    $derniereLigneDonnees = $sheet->getHighestDataRow();
    $echantillon = range($premiereLigneDonnees, (int)min($derniereLigneDonnees, $premiereLigneDonnees + 9));

    $colonnesDejaUtilisees = array_flip($meilleuresColonnes);
    foreach ($meilleuresColonnes as $champ => $lettre) {
        if (!in_array($champ, $champsNumeriques, true)) {
            continue;
        }
        $index = Coordinate::columnIndexFromString($lettre);
        if ($index <= 1) {
            continue;
        }
        $lettreGauche = Coordinate::stringFromColumnIndex($index - 1);

        // Ne jamais decaler vers une colonne deja utilisee par un autre
        // champ reconnu - sinon un champ reel mais rarement rempli (ex.
        // "Quantite Vendue" du fichier achats, vide la plupart des jours
        // par pure realite metier, pas par decalage d'en-tete) se ferait
        // a tort ecraser sur la colonne de son voisin de gauche deja
        // affecte a un autre champ (mesure : cassait QTE_ACHETEE/
        // QTE_VENDUE sur le fichier achats reel).
        if (isset($colonnesDejaUtilisees[$lettreGauche])) {
            continue;
        }

        $videIci = 0;
        $rempliGauche = 0;
        $total = 0;
        foreach ($echantillon as $ligneEch) {
            $total++;
            $valIci = $sheet->getCell($lettre . $ligneEch)->getValue();
            $valGauche = $sheet->getCell($lettreGauche . $ligneEch)->getValue();
            if (import_format_est_vide($valIci)) {
                $videIci++;
            }
            if (!import_format_est_vide($valGauche)) {
                $rempliGauche++;
            }
        }

        // Seuil strict a droite (100% vide) : une vraie colonne-espaceur
        // issue d'un en-tete fusionne n'a jamais de valeur, contrairement
        // a un champ reel simplement peu rempli ce jour-la. A gauche, une
        // seule valeur reelle suffit a confirmer que ce n'est pas non
        // plus une colonne morte - un seuil de ratio ici serait fragile :
        // un echantillon qui tombe sur plusieurs lignes vides/de
        // continuation en tete de fichier (mesure sur un fichier de test)
        // ferait rater un vrai decalage a tort.
        if ($total > 0 && $videIci === $total && $rempliGauche > 0) {
            $meilleuresColonnes[$champ] = $lettreGauche;
        }
    }

    // Signature du type de fichier - voir PLAN_IMPORT_STOCK_3_FICHIERS.md
    // section 1 pour la justification de chaque combinaison.
    $a = function (string $champ) use ($meilleuresColonnes): bool {
        return isset($meilleuresColonnes[$champ]);
    };

    if ($a('QTE_ACHETEE') && $a('BENEFICE')) {
        $type = 'achats';
    } elseif ($a('CLIENT') && $a('FACTURE')) {
        $type = 'ventes';
    } elseif ($a('QUANT') && $a('PRIX_ACHAT') && !$a('QTE_ACHETEE') && !$a('CLIENT')) {
        $type = 'stock';
    } else {
        $paires = [];
        foreach ($meilleursLibelles as $champ => $libelle) {
            $paires[] = "$libelle→$champ";
        }
        $trouves = implode(', ', $paires);
        throw new ImportFormatException(
            "Colonnes reconnues mais le type de fichier (stock complet / ventes du "
            . "jour / achats du jour) ne peut pas être déterminé avec certitude. "
            . "Colonnes trouvées : $trouves"
        );
    }

    return [
        'type' => $type,
        'ligne_entete' => $meilleureLigne,
        'premiere_ligne_donnees' => $premiereLigneDonnees,
        'colonnes' => $meilleuresColonnes,
        'libelles_trouves' => $meilleursLibelles,
    ];
}

// Vérifie que le fichier détecté correspond bien au bouton cliqué par
// l'utilisateur - un garde-fou explicite en plus de la détection, pas un
// remplacement : voir Phase 0 du plan. Lance une exception avec un
// message actionnable si les deux ne concordent pas.
function import_format_verifier_type(array $descripteur, string $typeAttendu): void
{
    if ($descripteur['type'] !== $typeAttendu) {
        $noms = ['stock' => 'Stock complet', 'ventes' => 'Ventes du jour', 'achats' => 'Achats du jour'];
        $attenduNom = $noms[$typeAttendu] ?? $typeAttendu;
        $trouveNom = $noms[$descripteur['type']] ?? $descripteur['type'];
        throw new ImportFormatException(
            "Vous avez choisi « $attenduNom » mais ce fichier ressemble à un fichier "
            . "« $trouveNom » (d'après ses colonnes). Import annulé, rien n'a été "
            . "écrit - vérifiez le fichier ou choisissez le bon bouton."
        );
    }
}

// Lit la valeur d'un champ canonique pour une ligne donnée. Retourne null
// si le champ n'a pas été détecté dans ce fichier (permet aux imports de
// fonctionner même si un champ optionnel comme PV_DETAIL est absent).
function import_format_lire(Worksheet $sheet, array $descripteur, int $ligne, string $champ)
{
    if (!isset($descripteur['colonnes'][$champ])) {
        return null;
    }
    $lettre = $descripteur['colonnes'][$champ];
    return $sheet->getCell($lettre . $ligne)->getCalculatedValue();
}

// Les fichiers "achats"/"ventes" du boss donnent parfois les nombres en
// texte avec l'espace comme séparateur de milliers ("3 300.00") - un
// simple (float) PHP échouerait silencieusement sur ces chaînes-là selon
// le connecteur. Convertit proprement dans tous les cas (nombre déjà
// numérique, ou texte avec espaces/virgules).
function import_format_nombre($valeur): float
{
    if ($valeur === null || $valeur === '') {
        return 0.0;
    }
    if (is_int($valeur) || is_float($valeur)) {
        return (float)$valeur;
    }
    $texte = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], (string)$valeur);
    return (float)$texte;
}
