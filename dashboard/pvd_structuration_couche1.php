<?php
// CLI-only, PLAN_PVD_DESCRIPTION.md couche 1: adds 5 structured columns to
// pvd and backfills them from existing description text. Purely additive -
// pvd.description is never modified or read differently by any existing
// page after this runs.
//
// Defaults to a dry run that prints what it would do without touching the
// database or the schema. Pass --apply to actually add the columns and
// write the extracted values.
//
// Run: php dashboard/pvd_structuration_couche1.php          (dry run)
//      php dashboard/pvd_structuration_couche1.php --apply   (writes)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/include/pvd_extraction.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

if ($apply) {
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pvd' AND COLUMN_NAME = 'annee_debut'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE pvd ADD COLUMN annee_debut SMALLINT NULL");
        $pdo->exec("ALTER TABLE pvd ADD COLUMN annee_fin SMALLINT NULL");
        $pdo->exec("ALTER TABLE pvd ADD COLUMN marque_texte VARCHAR(100) NULL");
        $pdo->exec("ALTER TABLE pvd ADD COLUMN pays_origine VARCHAR(50) NULL");
        $pdo->exec("ALTER TABLE pvd ADD COLUMN notes_libres TEXT NULL");
        echo "Colonnes ajoutees.\n\n";
    } else {
        echo "Colonnes deja presentes, pas de re-creation.\n\n";
    }
}

$dictionnairePays = pvd_dictionnaire_pays();
$rows = $pdo->query('SELECT id_pvd, description FROM pvd')->fetchAll(PDO::FETCH_ASSOC);

$sqlUpdate = $apply
    ? $pdo->prepare('UPDATE pvd SET annee_debut = ?, annee_fin = ?, marque_texte = ?, pays_origine = ?, notes_libres = ? WHERE id_pvd = ?')
    : null;

$compte = [
    'total' => count($rows),
    'annee_ok' => 0,
    'marque_ok' => 0,
    'pays_ok' => 0,
    'pays_non_reconnu' => 0,
    'notes_libres_remplie' => 0,
];
$paysNonReconnus = [];

foreach ($rows as $r) {
    $desc = $r['description'];

    [$anneeDebut, $anneeFin] = pvd_extraire_annee($desc);
    $marque = pvd_extraire_marque_texte($desc);
    $pays = pvd_extraire_pays($desc, $dictionnairePays);
    $notes = pvd_a_un_motif_connu($desc) ? null : $desc;

    if ($anneeDebut !== null) {
        $compte['annee_ok']++;
    }
    if ($marque !== null) {
        $compte['marque_ok']++;
    }
    if ($pays !== null) {
        $compte['pays_ok']++;
    } elseif (preg_match('/MADE IN\s+([A-Z ]+?)\s*(?:\/\/|$)/iu', $desc, $m)) {
        $compte['pays_non_reconnu']++;
        $brut = strtoupper(trim($m[1]));
        $paysNonReconnus[$brut] = ($paysNonReconnus[$brut] ?? 0) + 1;
    }
    if ($notes !== null) {
        $compte['notes_libres_remplie']++;
    }

    if ($apply) {
        $sqlUpdate->execute([$anneeDebut, $anneeFin, $marque, $pays, $notes, $r['id_pvd']]);
    }
}

echo "Total lignes pvd: {$compte['total']}\n";
printf("annee_debut rempli: %d (%.1f%%)\n", $compte['annee_ok'], 100 * $compte['annee_ok'] / $compte['total']);
printf("marque_texte rempli: %d (%.1f%%)\n", $compte['marque_ok'], 100 * $compte['marque_ok'] / $compte['total']);
printf("pays_origine rempli: %d (%.1f%%)\n", $compte['pays_ok'], 100 * $compte['pays_ok'] / $compte['total']);
printf("notes_libres remplie: %d (%.1f%%)\n", $compte['notes_libres_remplie'], 100 * $compte['notes_libres_remplie'] / $compte['total']);

if (!empty($paysNonReconnus)) {
    echo "\nValeurs MADE IN non reconnues par le dictionnaire (a ajouter si besoin):\n";
    arsort($paysNonReconnus);
    foreach ($paysNonReconnus as $p => $n) {
        echo "  $n x \"$p\"\n";
    }
}
