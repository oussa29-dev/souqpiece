<?php
// CLI-only - ajoute produit.quantite (voir db/produit_quantite.sql pour
// le contexte). Idempotent : ne fait rien si la colonne existe deja.
//
// Run: php dashboard/produit_quantite_migration.php          (dry run)
//      php dashboard/produit_quantite_migration.php --apply   (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit' AND COLUMN_NAME = 'quantite'")->fetchColumn();
echo "produit.quantite : " . ($existe ? "presente" : "absente") . "\n";

if ($existe) {
    echo "\nRien a faire, la colonne existe deja.\n";
    exit;
}

if (!$apply) {
    exit;
}

$pdo->exec("ALTER TABLE produit ADD COLUMN quantite INT NULL DEFAULT NULL AFTER stock");
echo "\nAjoutee : produit.quantite\n";
