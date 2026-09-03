<?php
// CLI-only - remplace annee_debut/annee_fin de `voiture` par les plages
// fournies dans un fichier Excel (colonne B: "Marque Modele", colonne D:
// "YYYY-YYYY"), source plus fiable que l'estimation par recherche web
// utilisee dans voiture_annee_migration.php (qui reste en base par defaut
// pour tout vehicule absent de ce fichier).
//
// Matching : marque+modele normalise (espaces reduits, casse ignoree),
// jamais par position - un vehicule du fichier qui ne matche aucune ligne
// de `voiture` est signale, jamais applique au hasard.
//
// Run: php dashboard/voiture_annee_import.php <fichier.xlsx>            (dry run)
//      php dashboard/voiture_annee_import.php <fichier.xlsx> --apply     (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$fichier = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);

if (!$fichier || !file_exists($fichier)) {
    die("Usage: php dashboard/voiture_annee_import.php <fichier.xlsx> [--apply]\n");
}

function voiture_annee_normaliser(string $s): string
{
    return preg_replace('/\s+/', ' ', trim(mb_strtoupper($s)));
}

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

$spreadsheet = IOFactory::load($fichier);
$sheet = $spreadsheet->getActiveSheet();
$parCle = [];
for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
    $b = trim((string)$sheet->getCell('B' . $r)->getCalculatedValue());
    $d = trim((string)$sheet->getCell('D' . $r)->getCalculatedValue());
    if ($b === '' || $d === '') {
        continue;
    }
    if (!preg_match('/^(\d{4})-(\d{4})$/', $d, $m)) {
        echo "Ligne $r ignoree : plage inattendue \"$d\" pour \"$b\"\n";
        continue;
    }
    $parCle[voiture_annee_normaliser($b)] = [(int)$m[1], (int)$m[2], $b];
}

$voitures = $pdo->query('SELECT v.id_voiture, m.libelle marque, v.modele, v.annee_debut, v.annee_fin FROM voiture v JOIN marque m ON m.id_marque = v.id_marque')->fetchAll(PDO::FETCH_ASSOC);

$update = $apply ? $pdo->prepare('UPDATE voiture SET annee_debut = ?, annee_fin = ? WHERE id_voiture = ?') : null;
$changements = 0;
$inchanges = 0;
$sansMatch = [];

foreach ($voitures as $v) {
    $cle = voiture_annee_normaliser($v['marque'] . ' ' . $v['modele']);
    if (!isset($parCle[$cle])) {
        $sansMatch[] = "{$v['marque']} {$v['modele']} (id {$v['id_voiture']})";
        continue;
    }
    [$debut, $fin, $texteOriginal] = $parCle[$cle];
    unset($parCle[$cle]);

    if ((int)($v['annee_debut'] ?? 0) === $debut && (int)($v['annee_fin'] ?? 0) === $fin) {
        $inchanges++;
        continue;
    }

    $changements++;
    if ($apply) {
        $update->execute([$debut, $fin, $v['id_voiture']]);
    } else {
        printf(
            "id=%d %s %s : %s-%s -> %d-%d\n",
            $v['id_voiture'],
            $v['marque'],
            $v['modele'],
            $v['annee_debut'] ?? 'NULL',
            $v['annee_fin'] ?? 'NULL',
            $debut,
            $fin
        );
    }
}

echo "\nVehicules en base : " . count($voitures) . "\n";
echo "Deja identiques (rien a faire) : $inchanges\n";
echo ($apply ? "Mis a jour" : "A mettre a jour") . " : $changements\n";

if (!empty($sansMatch)) {
    echo "\nEn base mais absents du fichier (" . count($sansMatch) . ", non touches) :\n";
    foreach ($sansMatch as $s) {
        echo "  - $s\n";
    }
}
if (!empty($parCle)) {
    echo "\nDans le fichier mais absents de la base (" . count($parCle) . ", ignores) :\n";
    foreach ($parCle as $v) {
        echo "  - {$v[2]} ({$v[0]}-{$v[1]})\n";
    }
}
