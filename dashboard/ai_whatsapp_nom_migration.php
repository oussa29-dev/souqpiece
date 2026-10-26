<?php
// CLI-only - ajoute ai_whatsapp_recu.nom_profil (voir db/ai_whatsapp_nom.sql
// pour le contexte). Idempotent : ne fait rien si la colonne existe deja.
//
// Run: php dashboard/ai_whatsapp_nom_migration.php          (dry run)
//      php dashboard/ai_whatsapp_nom_migration.php --apply   (ajoute la colonne)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_whatsapp_recu' AND COLUMN_NAME = 'nom_profil'")->fetchColumn();
echo "ai_whatsapp_recu.nom_profil : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la colonne existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$sql = file_get_contents(__DIR__ . '/../db/ai_whatsapp_nom.sql');
if ($sql === false) {
    die("Impossible de lire db/ai_whatsapp_nom.sql\n");
}
$pdo->exec($sql);
echo "\nAjoutee : ai_whatsapp_recu.nom_profil\n";
