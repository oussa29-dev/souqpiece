<?php
// CLI-only - cree la table import_journal (voir db/import_journal.sql pour
// le contexte). Idempotent : ne fait rien si la table existe deja.
//
// Run: php dashboard/import_journal_migration.php          (dry run)
//      php dashboard/import_journal_migration.php --apply   (cree la table)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_journal'")->fetchColumn();
echo "import_journal : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la table existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$sql = file_get_contents(__DIR__ . '/../db/import_journal.sql');
if ($sql === false) {
    die("Impossible de lire db/import_journal.sql\n");
}
$pdo->exec($sql);
echo "\nCreee : import_journal\n";
