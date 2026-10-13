<?php
// CLI-only - cree la table ai_whatsapp_recu (voir db/ai_whatsapp.sql pour le
// contexte). Idempotent : ne fait rien si la table existe deja.
//
// Run: php dashboard/ai_whatsapp_migration.php          (dry run)
//      php dashboard/ai_whatsapp_migration.php --apply   (cree la table)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_whatsapp_recu'")->fetchColumn();
echo "ai_whatsapp_recu : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la table existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$sql = file_get_contents(__DIR__ . '/../db/ai_whatsapp.sql');
if ($sql === false) {
    die("Impossible de lire db/ai_whatsapp.sql\n");
}
$pdo->exec($sql);
echo "\nCreee : ai_whatsapp_recu\n";
