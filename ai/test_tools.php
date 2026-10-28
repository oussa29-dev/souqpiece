<?php
// CLI-only sanity check for ai/tools.php against real local data.
// Run: php ai/test_tools.php
// Not part of the web app - never exposed via HTTP.
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== null ? " -- " . json_encode($detail, JSON_UNESCAPED_UNICODE) : "") . "\n";
    }
}

echo "== ai_escape_like ==\n";
check('escapes underscore', ai_escape_like('550458_VW000') === '550458\\_VW000');
check('escapes percent', ai_escape_like('50%off') === '50\\%off');
check('escapes backslash itself (must run first, or it double-escapes the others)', ai_escape_like('a\\b') === 'a\\\\b');
check('real ambiguous reference still resolves exactly via partial-match path', (function () use ($pdo) {
    $r = ai_lookup_by_reference($pdo, '550458_VW000');
    $all = array_merge(...array_values($r));
    return count($all) >= 1 && $all[0]['reference'] === '550458_VW000';
})());

echo "\n== search_products ==\n";
$r = ai_search_products($pdo, 'CROISILLON CARDON');
check('libelle search returns results', count($r) > 0, $r);
check('no zero-price rows leak through', !array_filter($r, fn($x) => (int)$x['prix'] === 0));

$r = ai_search_products($pdo, '48258-42020'); // real logged customer search (a reference number)
check('real logged search term resolves to something or empty cleanly', is_array($r));

function ai_test_ligne_du_vehicule(PDO $pdo, array $row, int $idVoiture): bool
{
    if ((int)$row['voiture_id'] === $idVoiture) {
        return true;
    }
    if (($row['lien_vehicule'] ?? '') !== 'nom_seulement') {
        return false;
    }
    foreach (ai_mots_distinctifs_vehicule($pdo, $idVoiture) as $mot) {
        if (stripos($row['libelle'], $mot) !== false) {
            return true;
        }
    }
    return false;
}

$r = ai_search_products($pdo, 'CROISILLON CARDON', 59); // filtered to a specific vehicle
check('id_voiture filter narrows results', count($r) <= 20);
foreach ($r as $row) {
    // Linked to the vehicle, or unlinked but naming it (lien_vehicule).
    check('  every row matches requested vehicle (linked, or named in its libelle)', ai_test_ligne_du_vehicule($pdo, $row, 59), $row);
}

// Regression: a broad query with more matches than the limit must return
// the SAME subset every time, not an arbitrary one (real bug found via a
// production conversation audit - MySQL's tie order among equal-stock rows
// is unspecified without a deterministic secondary sort key).
$r1 = ai_search_products($pdo, 'frein', null, null, 8);
$r2 = ai_search_products($pdo, 'frein', null, null, 8);
check('broad search is deterministic across repeated identical calls',
    array_column($r1, 'id_produit') === array_column($r2, 'id_produit'),
    ['first' => array_column($r1, 'id_produit'), 'second' => array_column($r2, 'id_produit')]);

$r = ai_search_products($pdo, 'ZZZZZZ_NO_MATCH_XYZ');
check('nonsense query returns empty array, not error', $r === []);

// Regression lock for the removed candidateCap=150. Real, concrete case
// found while testing it: products #5561/#9957/#73319 are genuine,
// in-stock "frein" parts correctly linked to PATROL Y61 (id_voiture=30),
// but their id_produit falls past position 150 in id-ascending order, so
// a fixed candidate LIMIT silently excluded them (3 results instead of
// the real 8+). If a candidate cap is ever reintroduced, this must fail.
$r = ai_search_products($pdo, 'frein', 30, null, 20);
$ids = array_column($r, 'id_produit');
check('broad search + vehicle filter does not silently drop valid results past a fixed candidate position',
    count(array_intersect([5561, 9957, 73319], $ids)) === 3, $ids);
foreach ($r as $row) {
    check('  every row in a large result set is still a real DB record matching the vehicle filter',
        ai_test_ligne_du_vehicule($pdo, $row, 30) && isset($row['prix'], $row['marquepiece']) && (int)$row['prix'] > 0, $row);
}

$r = ai_search_products($pdo, 'PLAQUETTE DE FREIN', null, null, 20, null, 3000);
check('max_price filters out anything above the cap', !array_filter($r, fn($x) => (int)$x['prix'] > 3000), $r);
check('max_price results sorted cheapest first', $r === [] || $r[0]['prix'] <= end($r)['prix'], $r);

$r = ai_search_products($pdo, 'PLAQUETTE DE FREIN', null, null, 20, 2000, 3000);
check('min_price+max_price both apply as a range', !array_filter($r, fn($x) => (int)$x['prix'] < 2000 || (int)$x['prix'] > 3000), $r);

// Real-world query variation cases (mandated by task). Ground truth:
// verified via production conversation audit that the catalog spells this
// word "DEMAREUR" (one R) across 279 products and the subcategory name
// itself - a literal search for the correct French spelling never matched.
echo "\n== search_products: real-world query variations ==\n";
$expectedDemarreurIds = [1651, 2105, 2985]; // top-3 by id_produit ASC, verified present

$variations = [
    'accented correct French spelling'   => 'démarreur',
    'unaccented correct French spelling' => 'demarreur',
    'catalog\'s actual (misspelled) spelling' => 'demareur',
    'Arabic/Darija transliteration'      => 'ديمارور',
    'English automotive synonym'         => 'starter',
];
foreach ($variations as $label => $term) {
    $t0 = microtime(true);
    $r = ai_search_products($pdo, $term);
    $ms = round((microtime(true) - $t0) * 1000, 1);
    $ids = array_column($r, 'id_produit');
    check("\"$term\" ($label) finds real démarreur products in {$ms}ms",
        count(array_intersect($expectedDemarreurIds, $ids)) > 0, $r);
    check("  every returned row is a real database record (has id/prix/marquepiece keys)",
        !array_filter($r, fn($x) => !isset($x['id_produit'], $x['prix'], $x['marquepiece'])), $r);
    check("  no zero-price rows leak through", !array_filter($r, fn($x) => (int)$x['prix'] === 0));
    check("  stays within a reasonable latency budget (<800ms)", $ms < 800, $ms);
}

$r = ai_search_products($pdo, 'CROISILLON CARDON');
check('exact catalog term (no expansion needed) still works', count($r) > 0, $r);

$t0 = microtime(true);
$r = ai_search_products($pdo, 'amortiseur'); // one-letter typo of "amortisseur", not in the alias dict
$ms = round((microtime(true) - $t0) * 1000, 1);
check("common typo not in the dictionary is caught by the fuzzy fallback in {$ms}ms",
    count($r) > 0 && !array_filter($r, fn($x) => mb_stripos($x['libelle'], 'AMORTIS') === false), $r);

// Part + vehicle combined query, via the intended two-step pattern
// (resolve_vehicle for the id_voiture, then search_products filtered by
// it) - matches how the LLM actually calls these tools per prompt.php.
$veh = ai_resolve_vehicle($pdo, 'Yaris 4 NCP151');
check('vehicle resolves for the combined-query test', $veh['unique'] === true, $veh);
$r = ai_search_products($pdo, 'demarreur', $veh['matches'][0]['id_voiture']);
check('part+vehicle combined query returns only that vehicle\'s rows (or empty, never another vehicle\'s)',
    !array_filter($r, fn($x) => (int)$x['voiture_id'] !== (int)$veh['matches'][0]['id_voiture']), $r);

echo "\n== lookup_by_reference ==\n";
$r = ai_lookup_by_reference($pdo, '11311-54052'); // known ambiguous ref (16 rows in DB)
check('ambiguous reference returns grouped-by-brand structure', is_array($r) && count($r) >= 1, array_keys($r));
$total = array_sum(array_map('count', $r));
check('ambiguous reference surfaces multiple products, does not silently pick one', $total > 1, $total);

$r = ai_lookup_by_reference($pdo, 'DOES-NOT-EXIST-999');
check('unknown reference returns empty, not error', $r === []);

echo "\n== resolve_vehicle ==\n";
$r = ai_resolve_vehicle($pdo, 'QASHQAI');
check('exact model token resolves', count($r['matches']) > 0 && $r['matches'][0]['modele'] === 'QASHQAI JD10', $r);
check('single distinct model is unique=true', $r['unique'] === true, $r);

$r = ai_resolve_vehicle($pdo, 'TOYOTA HILUX');
check('brand + partial model resolves to Toyota Hilux variants', count($r['matches']) > 0 && $r['matches'][0]['marque'] === 'TOYOTA', $r);

$r = ai_resolve_vehicle($pdo, 'قاشقاي'); // Arabic free text - not literally in the (French) data
check('arabic input does not crash, returns array (empty is acceptable/expected)', is_array($r['matches']));

// Determinism cases for prompt rule 9 (vehicle-variant clarification).
// Real catalog data, not synthetic: Hilux has exactly 2 variants
// (94 HILUX 4WD LN166, 92 HILUX 2WD LN145), both score identically on
// "Hilux" alone -> must be a tie -> unique=false -> assistant must ask.
$r = ai_resolve_vehicle($pdo, 'Hilux');
check('ambiguous vehicle name (2+ variants, tied score) is unique=false', $r['unique'] === false, $r);
check('  and actually returns multiple distinct candidates', count($r['matches']) >= 2, $r);

// Yaris has 6 variants in this catalog, all tied on "Yaris" alone.
$r = ai_resolve_vehicle($pdo, 'Yaris');
check('Yaris alone (6 tied variants) is unique=false', $r['unique'] === false, $r);

// Real single-variant case: DAIHATSU TERIOS J210 exists, but so does a
// second TERIOS (J102, id_voiture 103) - "Terios J210" must score higher
// on the J210 row than the J102 row (only one shares the model code),
// so this is a genuine tie-BREAK test, not just a count()==1 test.
$r = ai_resolve_vehicle($pdo, 'Terios J210');
check('enough detail to break a tie (model code) is unique=true', $r['unique'] === true, $r);
check('  and the correct specific variant wins', $r['matches'][0]['id_voiture'] == 102, $r);

echo "\n== get_product ==\n";
$stmt = $pdo->query("SELECT p.id_produit, pv.id_voiture FROM produit p JOIN pvd pv ON pv.id_produit = p.id_produit WHERE p.prix > 0 LIMIT 1");
$sample = $stmt->fetch(PDO::FETCH_ASSOC);
$r = ai_get_product($pdo, (int)$sample['id_produit'], (int)$sample['id_voiture']);
check('known product+vehicle returns full detail with url', $r !== null && isset($r['url']), $r);

$r = ai_get_product($pdo, 999999999);
check('nonexistent product id returns null, not error', $r === null);

$stmt = $pdo->query("SELECT id_produit FROM produit WHERE prix = 0 LIMIT 1");
$zeroPriced = $stmt->fetchColumn();
if ($zeroPriced) {
    $r = ai_get_product($pdo, (int)$zeroPriced);
    check('zero-priced product is excluded (V1 rule)', $r === null, $zeroPriced);
}

echo "\n== list_categories ==\n";
$r = ai_list_categories($pdo);
check('unfiltered category list is non-empty', count($r) > 0, count($r));

$r = ai_list_categories($pdo, 59);
check('vehicle-filtered category list works (id_voiture=59)', is_array($r));

echo "\n== get_delivery_price ==\n";
$r = ai_get_delivery_price($pdo, 'Adrar', 'domicile');
check('known wilaya + domicile resolves', $r !== null && $r['prix'] > 0, $r);

$r = ai_get_delivery_price($pdo, 'Adrar', 'bureau');
check('known wilaya + bureau resolves', $r !== null && $r['prix'] > 0, $r);

$r = ai_get_delivery_price($pdo, 'WilayaDoesNotExist', 'domicile');
check('unknown wilaya returns null, not error', $r === null);

echo "\n== Reference variants (store owner 28/10: 11044-vk505 gave 1 product of 10)\n";
$lignes = array_merge(...array_values(ai_lookup_by_reference($pdo, '11044-vk505')));
$ids = array_column($lignes, 'id_produit');
check('every stored variant of the reference comes back, not just the exact one', count($ids) >= 5, array_column($lignes, 'reference'));
check('each product once', count($ids) === count(array_unique($ids)), $ids);
check('available products first', ($lignes[0]['disponible'] ?? false) === true, $lignes[0] ?? null);

echo "\n== Wholesale price (client_gros)\n";
require_once __DIR__ . '/../dashboard/include/prix_gros.php';
check('estimate: 21000 DA retail -> 17500 PV Gros (x1.2)', prix_gros_estimer(21000) === 17500, prix_gros_estimer(21000));
check('estimate: 3000 DA tie (2000 x1.5 / 2143 x1.4) -> the multiple of 5', prix_gros_estimer(3000) === 2000, prix_gros_estimer(3000));
check('estimate: every PV Gros 1..70000 step 5 comes back unchanged or null, never wrong',
    !array_filter(range(5, 70000, 5), fn($pv) => ($e = prix_gros_estimer((int)round(stock_appliquer_marge($pv)))) !== null && $e !== $pv));
$avecGros = (int)$pdo->query('SELECT id_produit FROM produit WHERE prix_gros > 0 AND prix > prix_gros ORDER BY id_produit LIMIT 1')->fetchColumn();
$sansGros = (int)$pdo->query('SELECT id_produit FROM produit WHERE prix > 0 AND prix_gros IS NULL ORDER BY id_produit LIMIT 1')->fetchColumn();
$prixGros = (int)$pdo->query("SELECT prix_gros FROM produit WHERE id_produit = $avecGros")->fetchColumn();
$r = ai_appliquer_tarif_gros($pdo, ['vendeur' => [['id_produit' => $avecGros, 'prix' => 99999], ['id_produit' => $sansGros, 'prix' => 12345], ['reference' => 'X', 'prix' => null]]]);
check('wholesale customer: product with a PV Gros gets it, tagged "gros"', $r['vendeur'][0]['prix'] === $prixGros && $r['vendeur'][0]['tarif'] === 'gros', $r);
check('wholesale customer: product without one keeps its retail price, tagged "detail"', $r['vendeur'][1]['prix'] === 12345 && $r['vendeur'][1]['tarif'] === 'detail', $r);
check('row without id or price left alone', !isset($r['vendeur'][2]['tarif']), $r);
$normal = ai_build_tool_dispatcher($pdo)('get_product', ['id_produit' => $avecGros]);
$gros = ai_build_tool_dispatcher($pdo, true)('get_product', ['id_produit' => $avecGros]);
check('retail customer: never the wholesale price, no tarif field', isset($normal['prix']) && (int)$normal['prix'] !== $prixGros && !isset($normal['tarif']) && !array_key_exists('prix_gros', $normal), $normal);
check('wholesale customer through the dispatcher: wholesale price', (int)($gros['prix'] ?? 0) === $prixGros && ($gros['tarif'] ?? '') === 'gros', $gros);

echo "\n== Availability (production 18/10: DEMARREUR DJ stock 0 shown \"disponible\")\n";
require_once __DIR__ . '/guard.php';
$r = ai_search_products($pdo, 'demarreur', 54);
check('search rows give disponible true/false, never the stock quantity',
    $r && !array_filter($r, fn($x) => array_key_exists('stock', $x) || !is_bool($x['disponible'] ?? null)), $r);
$p = ai_get_product($pdo, 6967);
check('get_product gives disponible, not stock', $p && is_bool($p['disponible']) && !array_key_exists('stock', $p), $p);
$dispo = [6967 => false, 7518 => true];
$t = ai_corriger_disponibilite("DEMARREUR - DJ - 29400 DA - disponible - [voir le produit](produit.php?id=6967&id_voiture=54)", $dispo);
check('unavailable product shown "disponible" -> "non disponible"', strpos($t, '29400 DA - non disponible - [') !== false, $t);
$t = ai_corriger_disponibilite("ديمارور - DJ - 29400 DA - متوفر - [voir le produit](produit.php?id=6967)", $dispo);
check('unavailable product shown "متوفر" -> "غير متوفر"', strpos($t, 'غير متوفر') !== false, $t);
$ok = "CULASSE - 98100 DA - disponible - [voir le produit](produit.php?id=7518)\nDEMARREUR - DJ - 29400 DA - non disponible - [voir le produit](produit.php?id=6967)\nVeuillez confirmer la disponibilité.";
check('correct lines and text without links left unchanged', ai_corriger_disponibilite($ok, $dispo) === $ok, ai_corriger_disponibilite($ok, $dispo));
$t = ai_corriger_disponibilite("CULASSE - 98100 DA - غير متوفر - [voir le produit](produit.php?id=7518)", $dispo);
check('available product shown "غير متوفر" -> "متوفر"', strpos($t, 'غير') === false && strpos($t, 'متوفر') !== false, $t);

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
