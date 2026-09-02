<?php
// CLI-only - cree les 3 tables du cache de classification d'import (voir
// db/import_designation.sql pour le contexte complet). Idempotent : ne
// touche rien si les tables existent deja, donc sans risque a relancer.
//
// Run: php dashboard/import_designation_migration.php          (dry run)
//      php dashboard/import_designation_migration.php --apply   (cree)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$tables = ['import_designation', 'import_designation_voiture', 'import_designation_produit'];
$manquantes = [];
foreach ($tables as $table) {
    $existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'")->fetchColumn();
    echo "$table : " . ($existe ? "deja presente" : "a creer") . "\n";
    if (!$existe) {
        $manquantes[] = $table;
    }
}

if (empty($manquantes)) {
    echo "\nRien a faire, les 3 tables existent deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

echo "\n";
$sql = file_get_contents(__DIR__ . '/../db/import_designation.sql');
// Retire les commentaires SQL (-- ...) et la ligne d'instruction finale non
// executable, puis separe les instructions par ';'.
$sql = preg_replace('/--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
    echo "OK : " . strtok($statement, "\n") . "...\n";
}
echo "\nTerminee.\n";
