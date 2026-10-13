<?php
// CLI-only - cree la table client_gros (voir db/client_gros.sql pour le
// contexte). Idempotent : ne fait rien si la table existe deja.
//
// Run: php dashboard/client_gros_migration.php          (dry run)
//      php dashboard/client_gros_migration.php --apply   (cree la table)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_gros'")->fetchColumn();
echo "client_gros : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la table existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$sql = file_get_contents(__DIR__ . '/../db/client_gros.sql');
if ($sql === false) {
    die("Impossible de lire db/client_gros.sql\n");
}
$pdo->exec($sql);
echo "\nCreee : client_gros\n";
