<?php
// CLI-only - fait tourner la classification LLM (dashboard/include/import_classification.php)
// sur les designations distinctes d'un fichier Excel, AVANT de la brancher
// sur stock.php, pour mesurer le taux de resolution et le nombre reel
// d'appels LLM necessaires. Ecrit dans import_designation (cache reel,
// reutilisable par un futur import) mais ne touche jamais produit/pvd.
//
// Run: php dashboard/import_classification_dry_run.php <fichier.xlsx> [limite]
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/include/import_classification.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$fichier = $argv[1] ?? null;
$limite = isset($argv[2]) ? (int)$argv[2] : null;
if (!$fichier || !file_exists($fichier)) {
    die("Usage: php dashboard/import_classification_dry_run.php <fichier.xlsx> [limite]\n");
}

$spreadsheet = IOFactory::load($fichier);
$designations = [];
foreach ($spreadsheet->getAllSheets() as $sheet) {
    $highestRow = $sheet->getHighestRow();
    for ($r = 2; $r <= $highestRow; $r++) {
        $c = trim((string)$sheet->getCell('C' . $r)->getCalculatedValue());
        if ($c !== '') {
            $designations[$c] = true;
        }
    }
}
$designations = array_keys($designations);
if ($limite !== null) {
    $designations = array_slice($designations, 0, $limite);
}

echo "Designations distinctes a traiter : " . count($designations) . "\n";
$avant = (int)$pdo->query('SELECT COUNT(*) FROM import_designation')->fetchColumn();

$debut = microtime(true);
$resultats = import_classification_resoudre($pdo, $designations);
$duree = microtime(true) - $debut;

$apres = (int)$pdo->query('SELECT COUNT(*) FROM import_designation')->fetchColumn();

$compte = ['resolu' => 0, 'a_verifier' => 0, 'sans_categorie' => 0, 'sans_vehicule' => 0];
foreach ($resultats as $r) {
    $compte[$r['statut']]++;
    if ($r['id_categorie'] === null) {
        $compte['sans_categorie']++;
    }
    if (empty($r['id_voitures'])) {
        $compte['sans_vehicule']++;
    }
}

printf("Nouvelles lignes ajoutees au cache : %d (appels LLM reels, pas juste lus depuis le cache)\n", $apres - $avant);
printf("Duree totale : %.1fs\n\n", $duree);
printf("resolu (applique automatiquement) : %d (%.1f%%)\n", $compte['resolu'], 100 * $compte['resolu'] / count($resultats));
printf("a_verifier (suggestion humaine)    : %d (%.1f%%)\n", $compte['a_verifier'], 100 * $compte['a_verifier'] / count($resultats));
printf("sans categorie du tout             : %d (%.1f%%)\n", $compte['sans_categorie'], 100 * $compte['sans_categorie'] / count($resultats));
printf("sans vehicule du tout              : %d (%.1f%%)\n", $compte['sans_vehicule'], 100 * $compte['sans_vehicule'] / count($resultats));

echo "\n--- 25 exemples ---\n";
$pdoVehicules = [];
foreach ($pdo->query('SELECT id_voiture, modele FROM voiture')->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $pdoVehicules[$v['id_voiture']] = $v['modele'];
}
$pdoCategories = [];
foreach ($pdo->query('SELECT id_categorie, libelle FROM categorie')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $pdoCategories[$c['id_categorie']] = $c['libelle'];
}

$i = 0;
foreach ($resultats as $designation => $r) {
    if ($i++ >= 25) {
        break;
    }
    $cat = $r['id_categorie'] !== null ? $pdoCategories[$r['id_categorie']] : '(aucune)';
    $veh = implode(', ', array_map(fn($id) => $pdoVehicules[$id] ?? "id$id", $r['id_voitures']));
    echo "[{$r['statut']}] $designation => $cat | " . ($veh ?: '(aucun vehicule)') . "\n";
}
