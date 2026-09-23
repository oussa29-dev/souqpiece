<?php
// CLI-only - ajoute produit.derniere_verification_stock (voir
// db/produit_derniere_verification_stock.sql pour le contexte).
// Idempotent : ne fait rien si la colonne existe deja.
//
// Run: php dashboard/produit_derniere_verification_stock_migration.php          (dry run)
//      php dashboard/produit_derniere_verification_stock_migration.php --apply   (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit' AND COLUMN_NAME = 'derniere_verification_stock'")->fetchColumn();
echo "produit.derniere_verification_stock : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la colonne existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$pdo->exec("ALTER TABLE produit ADD COLUMN derniere_verification_stock DATETIME NULL DEFAULT NULL AFTER quantite");
echo "\nAjoutee : produit.derniere_verification_stock\n";
