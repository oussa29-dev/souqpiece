<?php
// CLI-only, PLAN_PVD_DESCRIPTION.md - suite couche 2 : deplace pays_origine
// de pvd (par vehicule) vers produit (par produit). Justification mesuree
// (voir db/produit_pays_origine.sql) : 99,1% des produits multi-vehicules
// avaient deja la meme valeur sur toutes leurs lignes pvd.
//
// Pour chaque produit, prend la valeur la plus frequente parmi ses lignes
// pvd non nulles (le mode). Signale les produits ou les vehicules ne sont
// pas unanimes, sans jamais deviner silencieusement.
//
// pvd.pays_origine n'est pas supprimee ni modifiee par ce script - gelee,
// meme convention que pvd.description.
//
// Run: php dashboard/produit_pays_origine_migration.php          (dry run)
//      php dashboard/produit_pays_origine_migration.php --apply   (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

if ($apply) {
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit' AND COLUMN_NAME = 'pays_origine'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE produit ADD COLUMN pays_origine VARCHAR(50) NULL");
        echo "Colonne ajoutee.\n\n";
    } else {
        echo "Colonne deja presente, pas de re-creation.\n\n";
    }
}

$rows = $pdo->query("
    SELECT id_produit, pays_origine
    FROM pvd
    WHERE pays_origine IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$parProduit = [];
foreach ($rows as $r) {
    $parProduit[$r['id_produit']][$r['pays_origine']] = ($parProduit[$r['id_produit']][$r['pays_origine']] ?? 0) + 1;
}

$sqlUpdate = $apply ? $pdo->prepare('UPDATE produit SET pays_origine = ? WHERE id_produit = ?') : null;

$compte = ['total_produits' => count($parProduit), 'rempli' => 0, 'non_unanime' => 0];
$exemplesNonUnanimes = [];

foreach ($parProduit as $idProduit => $valeurs) {
    arsort($valeurs);
    $valeurDominante = array_key_first($valeurs);
    $compte['rempli']++;

    if (count($valeurs) > 1) {
        $compte['non_unanime']++;
        if (count($exemplesNonUnanimes) < 15) {
            $exemplesNonUnanimes[] = "  id_produit={$idProduit} : " . json_encode($valeurs, JSON_UNESCAPED_UNICODE) . " -> retenu \"{$valeurDominante}\"";
        }
    }

    if ($apply) {
        $sqlUpdate->execute([$valeurDominante, $idProduit]);
    }
}

$totalProduits = (int)$pdo->query('SELECT COUNT(*) FROM produit')->fetchColumn();

echo "Produits au total: {$totalProduits}\n";
echo "Produits avec au moins une ligne pvd.pays_origine renseignee: {$compte['total_produits']}\n";
printf("  -> valeur unanime sur toutes les lignes: %d\n", $compte['rempli'] - $compte['non_unanime']);
printf("  -> valeurs divergentes entre vehicules (valeur la plus frequente retenue): %d\n", $compte['non_unanime']);

if (!empty($exemplesNonUnanimes)) {
    echo "\nExemples de produits non unanimes:\n" . implode("\n", $exemplesNonUnanimes) . "\n";
}
