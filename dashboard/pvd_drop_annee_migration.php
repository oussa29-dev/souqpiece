<?php
// CLI-only - supprime pvd.annee_debut/annee_fin (voir db/pvd_drop_annee.sql
// pour le contexte). Idempotent : ne fait rien si les colonnes sont deja
// absentes, donc sans risque a relancer.
//
// Run: php dashboard/pvd_drop_annee_migration.php          (dry run)
//      php dashboard/pvd_drop_annee_migration.php --apply   (supprime)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$colonnes = ['annee_debut', 'annee_fin'];
$aSupprimer = [];
foreach ($colonnes as $colonne) {
    $existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pvd' AND COLUMN_NAME = '$colonne'")->fetchColumn();
    echo "pvd.$colonne : " . ($existe ? "presente" : "deja absente") . "\n";
    if ($existe) {
        $aSupprimer[] = $colonne;
    }
}

if (empty($aSupprimer)) {
    echo "\nRien a faire, les deux colonnes sont deja absentes.\n";
    exit;
}

if (!$apply) {
    exit;
}

echo "\n";
foreach ($aSupprimer as $colonne) {
    $pdo->exec("ALTER TABLE pvd DROP COLUMN $colonne");
    echo "Supprimee : pvd.$colonne\n";
}
echo "\nTerminee.\n";
