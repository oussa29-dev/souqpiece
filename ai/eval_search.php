<?php
// CLI-only - replays real assistant requests (ai_conversation, 16/08-05/09,
// "#N" = message id) against the read-only tools in ai/tools.php and checks
// each answer against what the catalog actually contains. Deterministic: no
// LLM call, no cost. Run before and after any change to the search layer.
//
// Run: php ai/eval_search.php            (summary + failures)
//      php ai/eval_search.php -v         (every case)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$verbose = in_array('-v', $argv, true);

const YARIS = [42, 51, 52, 53, 62, 63];
const COROLLA = [54, 56, 66, 97, 182];

// search: [query, id_voiture|null, libelle regex, allowed vehicle ids|null, min results]
$recherches = [
    ['#63 "Filter yaris 4" - 5 filtres lies a la NCP151', 'filtre', 52, '/FILT/i', [52], 1],
    ['#61 orthographe du catalogue', 'filter', 52, '/FILT/i', [52], 1],
    ['#11 vehicule dans le texte', 'demarreur yaris 2', null, '/DEMAR/i', [51, 53], 1],
    ['#11 vehicule dans le texte', 'demarreur corolla', null, '/DEMAR/i', COROLLA, 1],
    ['#34 abreviation "Dem"', 'dem', 51, '/DEMAR/i', [51], 1],
    ['#25 darija', 'كيلاس', null, '/CULASSE/i', null, 1],
    ['#25 darija + vehicule en arabe', 'كيلاس كواستر', null, '/CULASSE/i', [76], 1],
    ['#28 culasse coaster', 'culasse coaster', null, '/CULASSE/i', [76], 1],
    ['#66 abreviation + vehicule', 'vilb coaster', null, '/VIL/i', [76], 1],
    ['#68 faute de frappe', 'Vilbroqeun', null, '/VIL/i', null, 1],
    ['#68 faute de frappe + vehicule', 'Vilbroqeun', 76, '/VIL/i', [76], 1],
    ['vilebrequin, orthographe correcte', 'vilebrequin', null, '/VIL/i', null, 1],
    ['#53 plateau NSP130 - 2 lies a la 42', 'plateau', 42, '/PLAT/i', [42], 1],
    ['#54 abreviation "emb"', 'kit emb', 42, '/KIT.*EMB/i', [42], 1],
    ['#56 abreviation "Plaquet"', 'plaquet', 42, '/PLAQUET/i', [42], 1],
    ['#3 arabe "فرامل"', 'فرامل', null, '/FREIN|FRIEN/i', null, 1],
    ['#1 darija "روتروفيزور"', 'روتروفيزور', null, '/RETRO/i', null, 1],
    ['#22 frein Patrol Y61', 'frein', 30, '/FREIN|FRIEN/i', [30], 5],
    ['radiateur (catalogue: RADAITEUR)', 'radiateur yaris', null, '/RAD/i', YARIS, 1],
    ['rotule (catalogue: ROTTUL)', 'rotule', null, '/ROT/i', null, 1],
    ['moyeu (catalogue: MAYEAU)', 'moyeu', null, '/MAYEAU|MOYEU|MAYEU/i', null, 1],
    ['cremaillere (catalogue: CREMAYEUR)', 'cremaillere', null, '/CREMA/i', null, 1],
    ['demarreur (deja couvert)', 'démarreur', null, '/DEMAR/i', null, 1],
];

// ranking: [label, query, id_voiture|null, regex the FIRST result's libelle must match]
$classements = [
    ['#29 la culasse avant le joint de culasse', 'culasse', 76, '/^CULASSE/i'],
    ['#45 disque Corolla CE140', 'disque', 56, '/^DISQUE/i'],
    ['#63 filtre Yaris 4', 'filtre', 52, '/^FILT/i'],
    ['#59 plaquette NSP130', 'plaquette', 42, '/^PLAQUET/i'],
    ['#39 demarreur NCP90', 'demarreur', 51, '/^DEMAR/i'],
    ['#17 suspension', 'amortisseur', 51, '/^AMORT/i'],
];

// reference: [label, reference, expect found, expect price available]
$references = [
    ['#20 reference connue', '43512-52020', true, true],
    ['#30 existe mais prix 0 - ne doit pas repondre "introuvable"', '13508-30011', true, false],
    ['reference sans tirets', '4351252020', true, true],
    ['#12 reference absente du catalogue', '42431-52030', false, null],
];

// vehicle: [label, free text, expect unique, expected ids (unique: top id; else: exact candidate set)]
$vehicules = [
    ['#42 "Corolla 2008" -> CE140 (2008-2013)', 'Corolla 2008', true, [56]],
    ['#7 "yaris 2008" -> NCP90 + NCP92', 'yaris 2008', false, [51, 53]],
    ['#56 "yaris 2012" -> NSP130 + NCP92', 'yaris 2012', false, [42, 53]],
    ['#36 code chassis', 'Yaris NCP90', true, [51]],
    ['#62 "Yaris 4"', 'Yaris 4', true, [52]],
    ['#28 coaster', 'coaster', true, [76]],
    ['#22 Patrol Y61', 'Nissan Patrol Y61', true, [30]],
    ['#25 vehicule en arabe', 'كواستر', true, [76]],
    ['#41 "corolla" seul doit rester ambigu', 'corolla', false, null],
];

$ok = 0;
$echecs = [];
$affiche = function (bool $passe, string $label, string $detail) use (&$ok, &$echecs, $verbose) {
    if ($passe) {
        $ok++;
        if ($verbose) {
            echo "  OK    $label\n";
        }
    } else {
        $echecs[] = "$label -- $detail";
    }
};

foreach ($recherches as [$label, $q, $idVoiture, $motif, $voituresOk, $min]) {
    $t0 = microtime(true);
    $rows = ai_search_products($pdo, $q, $idVoiture, null, 8);
    $ms = round((microtime(true) - $t0) * 1000);
    $pertinents = array_filter($rows, function ($r) use ($motif, $voituresOk) {
        return preg_match($motif, $r['libelle'])
            && ($voituresOk === null || in_array((int)$r['voiture_id'], $voituresOk, true));
    });
    $resume = implode(' ; ', array_map(fn($r) => trim($r['libelle']) . ' [v' . $r['voiture_id'] . ']', array_slice($rows, 0, 3)));
    $affiche(count($pertinents) >= $min, "search \"$q\"" . ($idVoiture ? " v$idVoiture" : '') . " ($label)", count($pertinents) . "/" . count($rows) . " pertinents en {$ms}ms: " . ($resume ?: 'aucun resultat'));
}

foreach ($classements as [$label, $q, $idVoiture, $motif]) {
    $rows = ai_search_products($pdo, $q, $idVoiture, null, 8);
    $premier = $rows[0]['libelle'] ?? '';
    $affiche($premier !== '' && preg_match($motif, trim($premier)) === 1, "top \"$q\"" . ($idVoiture ? " v$idVoiture" : '') . " ($label)", 'premier: ' . ($premier !== '' ? trim($premier) . ' (stock ' . $rows[0]['stock'] . ')' : 'aucun'));
}

foreach ($references as [$label, $ref, $trouve, $prixDispo]) {
    $groupes = ai_lookup_by_reference($pdo, $ref);
    $lignes = array_merge([], ...array_values($groupes));
    $passe = (count($lignes) > 0) === $trouve;
    if ($passe && $trouve && $prixDispo !== null) {
        $avecPrix = array_filter($lignes, fn($l) => empty($l['prix_non_disponible']));
        $passe = $prixDispo ? count($avecPrix) > 0 : count($avecPrix) === 0;
        // A price-0 row must give the model nothing to build a link from.
        foreach ($lignes as $l) {
            if (!empty($l['prix_non_disponible']) && (isset($l['id_produit']) || isset($l['url']))) {
                $passe = false;
            }
        }
    }
    $affiche($passe, "reference \"$ref\" ($label)", count($lignes) . ' ligne(s): ' . json_encode(array_map(fn($l) => [$l['reference'], $l['prix'] ?? null], array_slice($lignes, 0, 3)), JSON_UNESCAPED_UNICODE));
}

foreach ($vehicules as [$label, $texte, $unique, $attendus]) {
    $r = ai_resolve_vehicle($pdo, $texte);
    $ids = array_map(fn($m) => (int)$m['id_voiture'], $r['matches']);
    if ($unique) {
        $passe = $r['unique'] === true && ($ids[0] ?? null) === $attendus[0];
    } else {
        $trie = $ids;
        sort($trie);
        $passe = $r['unique'] === false && ($attendus === null || $trie === $attendus);
    }
    $affiche($passe, "vehicle \"$texte\" ($label)", 'unique=' . json_encode($r['unique']) . ' ids=' . json_encode($ids));
}

// Dispatcher: GPT-5.x models send every optional parameter, zero for "not
// set" - real arguments logged by eval_chat via OpenRouter.
require_once __DIR__ . '/tool_schemas.php';
$dispatch = ai_build_tool_dispatcher($pdo);
$rows = $dispatch('search_products', ['query' => 'Dem', 'id_voiture' => 51, 'id_sous_categorie' => 0, 'limit' => 5, 'min_price' => 0, 'max_price' => 0]);
$affiche(count(array_filter($rows, fn($r) => preg_match('/DEMAR/i', $r['libelle']))) > 0, 'dispatcher: parametres optionnels a 0 = non renseignes', count($rows) . ' resultat(s)');

// Session memory (ai/context.php): what a follow-up turn must inherit,
// rebuilt from the logged tools_called of the real sessions.
require_once __DIR__ . '/context.php';
$contextes = [
    ['#66-#68 "Vilbroqeun" garde le Coaster', [
        ['role' => 'user', 'message' => 'Vilb coaster', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"coaster"}},{"name":"search_products","args":{"id_voiture":76,"limit":8,"query":"vilb"}}]'],
    ], ['/id_voiture=76/', '/COASTER/', '/"vilb"/']],
    ['#46-#50 "Nsp130" garde la piece "Plateau"', [
        ['role' => 'user', 'message' => 'Plateau', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"search_products","args":{"limit":8,"query":"Plateau"}}]'],
        ['role' => 'user', 'message' => 'Yaris', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"Yaris"}}]'],
    ], ['/"Plateau"/']],
    ['#60-62 "4" complete "yaris" non resolu', [
        ['role' => 'user', 'message' => 'Filter yaris', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"yaris"}}]'],
    ], ['/not yet narrowed.*"yaris"/']],
    // Production 01/10: the previous request's Corolla (id 54) and part
    // leaked into the new Yaris request ("!" = must NOT appear).
    ['prod 01/10 nouvelle demande Yaris apres Corolla', [
        ['role' => 'user', 'message' => 'Compresseur corolla', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"corolla"}}]'],
        ['role' => 'user', 'message' => 'Nde180', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"corolla nde180"}},{"name":"search_products","args":{"id_voiture":54,"query":"Compresseur"}}]'],
        ['role' => 'user', 'message' => 'Dem yaris', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => null],
        ['role' => 'user', 'message' => 'Yaris 2', 'tools_called' => null],
        ['role' => 'assistant', 'message' => '...', 'tools_called' => '[{"name":"resolve_vehicle","args":{"free_text":"yaris 2"}}]'],
    ], ['/not yet narrowed.*"yaris 2"/', '!/id_voiture=54/', '!/Compresseur/']],
    ['nouvelle session: aucun contexte', [], ['/^$/']],
];
foreach ($contextes as [$label, $rows, $motifs]) {
    $texte = trim(ai_session_context($pdo, $rows));
    $manquants = array_filter($motifs, function ($m) use ($texte) {
        return $m[0] === '!' ? preg_match(substr($m, 1), $texte) === 1 : !preg_match($m, $texte);
    });
    $affiche($manquants === [], "context ($label)", 'manque ' . implode(' ', $manquants) . ' dans: ' . substr($texte, 0, 160));
}

$total = $ok + count($echecs);
foreach ($echecs as $e) {
    echo "FAIL  $e\n";
}
echo "---\n$ok/$total cas reussis\n";
exit($echecs ? 1 : 0);
