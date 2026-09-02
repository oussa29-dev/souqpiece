<?php
// CLI-only - ajoute annee_debut/annee_fin a voiture (plage de production
// reelle du vehicule - voir db/voiture_annee.sql pour le contexte complet
// et pourquoi ces valeurs viennent d'une recherche web par code chassis
// plutot que d'une estimation a partir de pvd).
//
// Seules les lignes a confiance haute/moyenne sont ecrites ici. Les autres
// (confiance basse, code chassis introuvable, ou anomalie a verifier comme
// id_voiture=124) restent NULL, a completer plus tard via un fichier
// vehicule*plage-annee fourni separement - jamais devinees ici.
//
// Run: php dashboard/voiture_annee_migration.php          (dry run)
//      php dashboard/voiture_annee_migration.php --apply   (ecrit)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv, true);

echo $apply ? "Mode : APPLICATION REELLE\n\n" : "Mode : DRY RUN (aucune ecriture) - relancer avec --apply pour ecrire\n\n";

if ($apply) {
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voiture' AND COLUMN_NAME = 'annee_debut'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE voiture ADD COLUMN annee_debut SMALLINT NULL");
        $pdo->exec("ALTER TABLE voiture ADD COLUMN annee_fin SMALLINT NULL");
        echo "Colonnes ajoutees.\n\n";
    } else {
        echo "Colonnes deja presentes, pas de re-creation.\n\n";
    }
}

// [id_voiture => [annee_debut, annee_fin]] - confiance haute/moyenne
// uniquement, recherche web par code chassis (2026-08-30).
$plages = [
    107 => [2002, 2011], // DAIHATSU CHARAD L251
    98  => [2007, 2026], // DAIHATSU GRAN MAX S401
    177 => [2006, 2011], // DAIHATSU MATERIA
    105 => [2004, 2015], // DAIHATSU SIRION M301
    103 => [2000, 2005], // DAIHATSU TERIOS J102
    102 => [2006, 2017], // DAIHATSU TERIOS J210

    112 => [2011, 2022], // FORD RANGER T6

    179 => [1971, 1977], // MAZDA B1600
    152 => [1999, 2006], // MAZDA B2500
    151 => [1999, 2007], // MAZDA B2900
    110 => [2011, 2020], // MAZDA BT50 T6 4WD

    119 => [1996, 2007], // MITSUBISHI L200 4w2 K64T
    121 => [2007, 2015], // MITSUBISHI L200 4w2 KA4T
    116 => [2001, 2007], // MITSUBISHI L200 4w4 k74t
    164 => [2015, 2019], // MITSUBISHI L200 KL1T
    47  => [2007, 2015], // MITSUBISHI L200 SPORTERO KB4T
    49  => [2000, 2003], // MITSUBISHI Lancer CS1

    139 => [2007, 2020], // NISSAN CABSTAR BLANC F24F NT400
    138 => [2007, 2013], // NISSAN CABSTAR BLUE F24
    137 => [1999, 2021], // NISSAN CAR CIVILIAN W41
    122 => [2010, 2017], // NISSAN MICRA K13
    123 => [2002, 2010], // NISSAN MICRA K12
    127 => [2005, 2015], // NISSAN NAVARA D40
    169 => [2005, 2012], // NISSAN PATHFINDER R51
    171 => [2010, 2026], // NISSAN PATROL Y62
    160 => [1987, 1997], // NISSAN PATROL Y60
    30  => [1997, 2024], // NISSAN PATROL Y61
    158 => [1986, 1997], // NISSAN PICK UP QD32 4WD
    156 => [1986, 1997], // NISSAN PICK UP TD27 2WD
    162 => [2013, 2021], // NISSAN QACHQAI J11
    125 => [2000, 2012], // NISSAN SUNNY 1 N16
    // 124 SUNNY 2 B10 : anomalie (1966-1970 incoherent avec N16/N17 voisins),
    // probable coquille dans voiture.modele - NON ecrit, a verifier d'abord.
    126 => [2010, 2019], // NISSAN SUNNY 3 N17
    146 => [2004, 2012], // NISSAN TIDDA C11
    134 => [2001, 2012], // NISSAN URVAN E25
    136 => [2012, 2026], // NISSAN URVAN E26
    150 => [2000, 2007], // NISSAN X-TRAIL 2007 T30
    149 => [2007, 2014], // NISSAN X-TRAIL 2011 T31
    148 => [2014, 2026], // NISSAN X-TRAIL 2017 T32

    161 => [2012, 2018], // TOYOTA AVANSA F601
    76  => [1993, 2016], // TOYOTA COASTER HZB50
    54  => [2013, 2019], // TOYOTA COROLLA NDE180 NRE180 ZRE180
    97  => [2000, 2008], // TOYOTA COROLLA CE120 ZZE122
    92  => [1997, 2005], // TOYOTA HILUX 2WD LN145
    94  => [1997, 2005], // TOYOTA HILUX 4WD LN166
    78  => [2007, 2021], // TOYOTA LAND CRUISER VDJ200 V8
    173 => [1984, 1999], // TOYOTA LAND CRUISER FJ75 HZJ75
    82  => [1998, 2005], // TOYOTA LAND CRUISER HZJ105 FZJ105
    89  => [1990, 1997], // TOYOTA LAND CRUISER HZJ80 FZJ80
    86  => [1996, 2002], // TOYOTA LAND CRUISER PRADO 1 KZJ95
    83  => [2002, 2009], // TOYOTA LAND CRUISER PRADO 2 KZJ120
    176 => [1992, 1996], // TOYOTA LITEACE CR27
    155 => [2005, 2016], // TOYOTA RAV4 ALA30
    175 => [2012, 2019], // TOYOTA RAV4 ALA49
    153 => [2000, 2005], // TOYOTA RAV4 CLA21
    58  => [2015, 2020], // TOYOTA REVO 2WD GUN122
    57  => [2015, 2020], // TOYOTA REVO 4WD GUN126
    145 => [1996, 1999], // TOYOTA STARLET EP90
    75  => [1994, 1999], // TOYOTA TERCEL EL50
    60  => [2004, 2015], // TOYOTA VIGO 2WD KUN15 D4D
    95  => [2004, 2015], // TOYOTA VIGO 2WD LAN15
    59  => [2004, 2015], // TOYOTA VIGO 4WD LAN25 KUN25
    63  => [1999, 2005], // TOYOTA YARIS 1 NCP10
    51  => [2005, 2011], // TOYOTA YARIS 2 NCP90
    53  => [2005, 2011], // TOYOTA YARIS 2 SEDON NCP92
    42  => [2011, 2019], // TOYOTA YARIS 3 NSP130
    52  => [2013, 2016], // TOYOTA YARIS 4 NCP151
    62  => [1999, 2005], // TOYOTA YARIS ECHO NCP10
];

$totalVoitures = (int)$pdo->query('SELECT COUNT(*) FROM voiture')->fetchColumn();
echo "Vehicules au total : $totalVoitures\n";
printf("Plages a ecrire (confiance haute/moyenne) : %d\n", count($plages));
printf("Laisses NULL (basse confiance, introuvables, ou anomalie a verifier) : %d\n\n", $totalVoitures - count($plages));

if ($apply) {
    $update = $pdo->prepare('UPDATE voiture SET annee_debut = ?, annee_fin = ? WHERE id_voiture = ?');
    $n = 0;
    foreach ($plages as $idVoiture => [$debut, $fin]) {
        $update->execute([$debut, $fin, $idVoiture]);
        $n += $update->rowCount();
    }
    echo "$n lignes mises a jour.\n";
} else {
    echo "Exemples (5 premieres) :\n";
    $i = 0;
    foreach ($plages as $idVoiture => [$debut, $fin]) {
        if ($i++ >= 5) {
            break;
        }
        echo "  id_voiture=$idVoiture : $debut-$fin\n";
    }
}
