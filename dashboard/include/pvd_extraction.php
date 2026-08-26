<?php
// Deterministic extraction of structured fields out of pvd.description free
// text - PLAN_PVD_DESCRIPTION.md, couche 1. Every function here either
// returns a specific, explainable value or null; nothing is guessed.
//
// Per-row extraction, not aggregation across rows: each pvd row already has
// its own id_voiture, so there is no cross-product corruption risk here the
// way there was for the abandoned per-vehicle year-range disambiguation in
// dashboard/include/resolution.php - a bad value stays isolated to its own
// row (see PLAN_PVD_DESCRIPTION.md §2 note).

// Every distinct raw "MADE IN X" value found in production (116 total,
// measured 2026-08-24) mapped to a canonical country name. Built by hand
// from the full distinct-value list, not a fuzzy/guessed match - every
// mapping here is an explicit, auditable decision.
function pvd_dictionnaire_pays(): array
{
    $groupes = [
        'CHINE' => ['CHINA', 'CHINE', 'CHIHNA', 'CHNA', 'CJINA', 'VHINA', 'HCINA', 'CHUNA', 'CHIINA', 'CHIN', 'CHINAA', 'CHINJA', 'CHAINA', 'PRC'],
        'JAPON' => ['JAPAN', 'KAPAN', 'JAPANE', 'JPANE', 'JAPN'],
        'THAILANDE' => ['THAILAND', 'THAILLAND', 'THALAND', 'TAILAND', 'THAILND', 'THAILAD', 'HAILAND', 'TAIHLAND', 'THIALAND', 'THILAND', 'YHAILAND', 'THJAILAND', 'HTAILAND', 'TGHAILAND', 'THAIMAND', 'THAIWAN', 'THAILANDS', 'THAIYLAND', 'THALLAND', 'THAILAN', 'THAILANDD'],
        'TAIWAN' => ['TAIWAN', 'TAYWAN', 'TIAWAN'],
        'MALAISIE' => ['MALAYSIA', 'MALASYIA', 'MALYZIA', 'MALYSIA', 'MAMAYSIA', 'MALASIA', 'MAMALYSIA', 'MALA SYIA', 'MALIZYA', 'MALIYSIA', 'MALASIYA', 'MALAYISA', 'LMALAYSIA', 'MALIZIA'],
        'TURQUIE' => ['TURKEY', 'TURKIE', 'TERKIE', 'TERKIYE', 'TURKIYE', 'TUREKY', 'TRUKEY', 'TURKIEY', 'TURKEYY', 'TRKEY', 'TURKAY', 'TURQUIE'],
        'ITALIE' => ['ITALY', 'ITALIE', 'ITALIA', 'ITALYA', 'IALY', 'ITA LY', 'ITALLY'],
        'COREE DU SUD' => ['KOREA', 'KORE', 'KOURIA DE SUD'],
        'INDONESIE' => ['INDONESIA', 'IDONESIA', 'INDONOSIA', 'INDOENESIA', 'INDOENSIA'],
        'ALLEMAGNE' => ['GERMANY', 'GERMNY'],
        'ROYAUME-UNI' => ['UNITED KINGDOM', 'ENGLAND', 'UK'],
        'EMIRATS ARABES UNIS' => ['DUBAI', 'UAE', 'DUABI'],
        'EUROPE' => ['EUROPE', 'EUROP', 'EURO'],
        'REPUBLIQUE TCHEQUE' => ['CZECH', 'CZECH REPUBLIC', 'REPUBLIC CHZECH'],
        'AFRIQUE DU SUD' => ['SOUTH AFRICA', 'SOUTHAFRICA'],
        'BRESIL' => ['BARAZIL'],
        'INDE' => ['INDIA'],
        'FRANCE' => ['FRANCE'],
        'ALGERIE' => ['ALGERIA'],
        'POLOGNE' => ['POLAND'],
        'ESPAGNE' => ['SPAIN'],
        'SLOVAQUIE' => ['SLOVAKIA'],
        'VIETNAM' => ['VIETNAM'],
        'RUSSIE' => ['RUSSIA'],
        'AUTRICHE' => ['AUSTRIA'],
        'ROUMANIE' => ['ROMANIA'],
        'ETATS-UNIS' => ['USA'],
        'PHILIPPINES' => ['PHILIPPINE'],
        'MEXIQUE' => ['MEXICO'],
        'PORTUGAL' => ['PORTUGAL'],
    ];

    $dict = [];
    foreach ($groupes as $canonique => $variantes) {
        foreach ($variantes as $v) {
            $dict[$v] = $canonique;
        }
    }
    return $dict;
}

function pvd_extraire_pays(string $description, array $dictionnairePays): ?string
{
    if (!preg_match('/MADE IN\s+([A-Z ]+?)\s*(?:\/\/|$)/iu', $description, $m)) {
        return null;
    }
    $brut = strtoupper(trim($m[1]));
    return $dictionnairePays[$brut] ?? null;
}

function pvd_extraire_marque_texte(string $description): ?string
{
    if (!preg_match('/MARQUE\s+([A-Z0-9]+)/iu', $description, $m)) {
        return null;
    }
    return strtoupper($m[1]);
}

// Returns [annee_debut, annee_fin], both nullable. Per-row only - see file
// header for why this is safe where the earlier per-vehicle aggregation
// was not. A value outside 1970-2026 is treated as a typo and both are
// left null rather than storing a visibly wrong year (e.g. "ANNEE 6200").
function pvd_extraire_annee(string $description): array
{
    if (!preg_match('/ANNEE\s+([^\/\r\n]+?)\s*(?:\/\/|$)/iu', $description, $m)) {
        return [null, null];
    }
    $frag = trim($m[1]);
    if (!preg_match_all('/\d{4}/', $frag, $years)) {
        return [null, null];
    }
    $years = array_map('intval', $years[0]);
    foreach ($years as $y) {
        if ($y < 1970 || $y > 2026) {
            return [null, null];
        }
    }
    if (count($years) >= 2) {
        return [min($years), max($years)];
    }
    if (stripos(preg_replace('/\d/', '', $frag), 'PL') !== false) {
        return [$years[0], null];
    }
    return [$years[0], $years[0]];
}

function pvd_a_un_motif_connu(string $description): bool
{
    return stripos($description, 'ANNEE') !== false
        || stripos($description, 'MARQUE') !== false
        || stripos($description, 'MADE IN') !== false;
}

// Canonical country list for the admin form's dropdown - the distinct
// values pvd_dictionnaire_pays() can produce, so the form never lets
// someone type a country that couche 1 wouldn't have recognized.
function pvd_liste_pays_connus(): array
{
    $pays = array_unique(array_values(pvd_dictionnaire_pays()));
    sort($pays);
    return $pays;
}

// Builds a pvd.description string from structured fields (PLAN_PVD_DESCRIPTION.md
// §3/§6) so existing display code (produit.php reads pvd.description as-is)
// keeps working unchanged while the new form captures clean, structured
// data instead of hand-typed free text. Once §6 (generate at display time)
// ships, this same template moves from write-time to read-time - the
// composed text does not change shape.
function pvd_composer_description(string $libelleProduit, string $modeleVoiture, ?int $anneeDebut, ?int $anneeFin, string $marquepiece, ?string $paysOrigine, ?string $notesLibres): string
{
    $lignes = [trim($libelleProduit . ' ' . $modeleVoiture)];

    if ($anneeDebut !== null) {
        $lignes[] = $anneeFin !== null && $anneeFin !== $anneeDebut
            ? "ANNEE {$anneeDebut}-{$anneeFin}"
            : "ANNEE {$anneeDebut} PLUS";
    }
    if (trim($marquepiece) !== '') {
        $lignes[] = 'MARQUE ' . strtoupper(trim($marquepiece));
    }
    if ($paysOrigine !== null && $paysOrigine !== '') {
        $lignes[] = 'MADE IN ' . strtoupper($paysOrigine);
    }
    if ($notesLibres !== null && trim($notesLibres) !== '') {
        $lignes[] = trim($notesLibres);
    }

    return implode(" // \n", $lignes);
}
