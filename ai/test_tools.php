<?php
// CLI-only sanity check for ai/tools.php against real local data.
// Run: php ai/test_tools.php
// Not part of the web app - never exposed via HTTP.
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';

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

$r = ai_search_products($pdo, 'CROISILLON CARDON', 59); // filtered to a specific vehicle
check('id_voiture filter narrows results', count($r) <= 20);
foreach ($r as $row) {
    check('  every row matches requested vehicle', (int)$row['voiture_id'] === 59, $row);
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
        (int)$row['voiture_id'] === 30 && isset($row['prix'], $row['marquepiece']) && (int)$row['prix'] > 0, $row);
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

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
