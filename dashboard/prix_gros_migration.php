<?php
// CLI-only - ajoute produit.prix_gros (voir db/prix_gros.sql pour le
// contexte), puis estime le prix gros des produits deja en base a partir de
// leur prix de vente (dashboard/include/prix_gros.php). Idempotent : la
// colonne n'est ajoutee qu'une fois, et seuls les produits sans prix gros
// sont estimes - un prix gros lu dans un fichier de stock n'est jamais
// remplace.
//
// Run: php dashboard/prix_gros_migration.php          (dry run)
//      php dashboard/prix_gros_migration.php --apply   (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/include/prix_gros.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$existe = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit' AND COLUMN_NAME = 'prix_gros'")->fetchColumn();
echo "produit.prix_gros : " . ($existe ? "presente" : "absente") . "\n";

if (!$existe && $apply) {
    $sql = file_get_contents(__DIR__ . '/../db/prix_gros.sql');
    if ($sql === false) {
        die("Impossible de lire db/prix_gros.sql\n");
    }
    $pdo->exec($sql);
    $existe = true;
    echo "Ajoutee : produit.prix_gros, produit.prix_gros_estime\n";
}

$sql = $existe
    ? 'SELECT id_produit, prix FROM produit WHERE prix > 0 AND prix_gros IS NULL'
    : 'SELECT id_produit, prix FROM produit WHERE prix > 0';
$estimes = [];
$sansEstimation = 0;
foreach ($pdo->query($sql) as $row) {
    $pvGros = prix_gros_estimer((int)$row['prix']);
    if ($pvGros === null) {
        $sansEstimation++;
    } else {
        $estimes[(int)$row['id_produit']] = $pvGros;
    }
}
echo "\nProduits a estimer : " . (count($estimes) + $sansEstimation) . "\n";
echo "  prix gros estime : " . count($estimes) . "\n";
echo "  sans estimation fiable (rempli au prochain import du stock) : $sansEstimation\n";

if (!$apply) {
    exit;
}

$stmt = $pdo->prepare('UPDATE produit SET prix_gros = ?, prix_gros_estime = 1 WHERE id_produit = ? AND prix_gros IS NULL');
$pdo->beginTransaction();
foreach ($estimes as $idProduit => $pvGros) {
    $stmt->execute([$pvGros, $idProduit]);
}
$pdo->commit();
echo "\nEcrits : " . count($estimes) . " prix gros estimes\n";
