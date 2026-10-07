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
    ['prod 01/10 "كاردن ياريس" NSP130 - 4 CARDON dispo', 'كاردن', 42, '/CARDON/i', [42], 1],
    ['prod 01/10 variante كاردان', 'كاردان', 42, '/CARDON/i', [42], 1],
    ['prod 01/10 "ديسك فرن ريفو"', 'ديسك فرن', 57, '/DISQUE/i', [57], 1],
    ['prod 01/10 "كرمايور ياريس"', 'كرمايور', 42, '/CREMA/i', [42], 1],
    ['prod 01/10 "Plaquet revo"', 'Plaquet', 57, '/PLAQUET/i', [57], 1],
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
    // "Not found" means no EXACT match: close suggestions (reference_proche)
    // are allowed, as long as they are flagged.
    $exactes = array_filter($lignes, fn($l) => empty($l['reference_proche']));
    $passe = (count($exactes) > 0) === $trouve;
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

// Store owner 07/10: reversed halves and one character off.
foreach ([['0C381-16400', '/0C381/', false], ['0C380-16400', '/0C381/', true], ['16400-0C380', '/0C381/', true]] as [$ref, $motif, $proche]) {
    $lignes = array_merge([], ...array_values(ai_lookup_by_reference($pdo, $ref)));
    $trouve = array_filter($lignes, fn($l) => preg_match($motif, $l['reference']));
    $flagsOk = $trouve && array_reduce($trouve, fn($c, $l) => $c && (!empty($l['reference_proche']) === $proche), true);
    $affiche((bool)$flagsOk, "reference \"$ref\" -> 16400-0C381" . ($proche ? ' (proche)' : ' (exacte)'), count($lignes) . ' ligne(s): ' . implode(' ; ', array_map(fn($l) => $l['reference'] . (!empty($l['reference_proche']) ? '*' : ''), $lignes)));
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

// Phonetic matching (ai/phonetic.php) - Darija part names are mostly the
// French word spelled by ear in Arabic letters (store owner, 01/10).
// "!" = must NOT match anything (everyday words, and words whose French
// equivalent is a different word - those belong in the dictionary).
$phonetiques = [
    'روتيل' => '/^rot/', 'كاردن' => '/^card/', 'ديمارور' => '/^demarreur$/', 'الترناتور' => '/^alternateur$/',
    'بلاكات' => '/^plaquette$/', 'رولمان' => '/^roulement$/', 'بيستون' => '/^piston$/', 'توندور' => '/^tendeur$/',
    'كابتور' => '/^capteur$/', 'ترونغل' => '/^triangle$/', 'بومبا' => '/^pompe$/', 'فيلتر' => '/^filt/',
    'بغيت' => '!', 'السعر' => '!', 'الثمن' => '!', 'محرك' => '!', 'مزال' => '!', 'ماستر' => '!', 'ريترو' => '!',
    'فيزيبل' => '!',
    // Production 04/10: "اكس تري" / "اكس اتري" (axe etrier) found nothing -
    // 2-consonant words were never matched, and AXE's X was read as "ch".
    'اكس' => '/^axe$/', 'تري' => '/^etri/', 'اتري' => '/^etri/', 'ايتري' => '/^etri/',
    'تندار' => '/^tendeur$/', 'امرتيسار' => '/^amortisseur$/',
    'بلي' => '!', 'بيك' => '!', 'خلاص' => '!', 'قبل' => '!', 'مكانش' => '!', 'راني' => '!', 'شوي' => '!',
];
foreach ($phonetiques as $mot => $attendu) {
    $r = ai_correspondances_phonetiques($pdo, $mot);
    $passe = $attendu === '!' ? $r === [] : ($r !== [] && preg_match($attendu, $r[0]) === 1);
    $affiche($passe, "phonetique \"$mot\"", 'obtenu: ' . ($r ? implode(', ', $r) : 'rien'));
}
foreach ([['اكس تري', 60, '/AXE ETRI/i'], ['اكس اتري', 60, '/AXE ETRI/i'], ['تندار امرتيسار', 60, '/TENDEUR AMORT/i'], ['بلاكات', 57, '/PLAQUET/i'], ['رولمان', 51, '/ROUL/i'], ['ترونغل', 42, '/TRIANG/i'], ['بومبا', null, '/POMPE/i']] as [$q, $v, $motif]) {
    $rows = ai_search_products($pdo, $q, $v, null, 8);
    $ok2 = count(array_filter($rows, fn($r) => preg_match($motif, $r['libelle']))) > 0 && preg_match($motif, $rows[0]['libelle'] ?? '');
    $affiche((bool)$ok2, "search phonetique \"$q\"" . ($v ? " v$v" : ''), count($rows) . ' resultat(s), premier: ' . ($rows[0]['libelle'] ?? 'aucun'));
}

// Guard (ai/guard.php): a reply may only link products a tool returned in
// that turn. Scripted fake model reproducing production 01/10 ("Plaquet
// revo": a product list with no tool call), then a retry that searches but
// still slips in one invented line.
require_once __DIR__ . '/llm/LlmProvider.php';
require_once __DIR__ . '/guard.php';
class FauxModele implements LlmProvider
{
    public int $appels = 0;
    private array $script;
    public function __construct(array $script) { $this->script = $script; }
    public function converse(string $systemPrompt, array $history, string $userMessage, array $toolSchemas, callable $toolDispatcher, int $maxToolRounds = 5): array
    {
        $etape = $this->script[$this->appels++];
        return $etape($toolDispatcher);
    }
}
$vraiId = (int)(ai_search_products($pdo, 'plaquette', 57, null, 1)[0]['id_produit'] ?? 0);
$faux = new FauxModele([
    fn($d) => ['text' => "PLAQUETTE INVENTEE - 7020 DA - [voir le produit](produit.php?id=999999)", 'tools_called' => []],
    function ($d) {
        $rows = $d('search_products', ['query' => 'plaquette', 'id_voiture' => 57, 'limit' => 1]);
        return ['text' => "PLAQUETTE - " . $rows[0]['prix'] . " DA - [voir le produit](produit.php?id=" . $rows[0]['id_produit'] . "&id_voiture=57)\nAUTRE INVENTEE - [voir le produit](produit.php?id=999998)", 'tools_called' => [['name' => 'search_products', 'args' => []]]];
    },
]);
$r = ai_converse_verifie($faux, '', [], 'Plaquet revo', [], ai_build_tool_dispatcher($pdo));
$affiche($faux->appels === 2 && strpos($r['text'], "id=$vraiId") !== false && strpos($r['text'], '99999') === false,
    'guard: produits non retournes par un outil -> nouvel essai, lignes inventees retirees', "appels={$faux->appels} texte=" . substr($r['text'], 0, 150));

$honnete = new FauxModele([
    function ($d) {
        $rows = $d('search_products', ['query' => 'plaquette', 'id_voiture' => 57, 'limit' => 2]);
        return ['text' => implode("\n", array_map(fn($p) => "X - [voir le produit](produit.php?id={$p['id_produit']})", $rows)), 'tools_called' => []];
    },
]);
$r = ai_converse_verifie($honnete, '', [], 'Plaquet revo', [], ai_build_tool_dispatcher($pdo));
$affiche($honnete->appels === 1 && substr_count($r['text'], 'produit.php?id=') === 2, 'guard: reponse honnete inchangee, un seul appel', "appels={$honnete->appels}");

// A close-reference suggestion shows its reference even if the model drops
// it (store owner's 0C380-16400, 07/10).
$refsProches = [];
ai_refs_proches_resultat(ai_lookup_by_reference($pdo, '0C380-16400'), $refsProches);
$idRevo = array_search('16400-0C381-AT', $refsProches, true);
$texte = ai_ajouter_refs_proches("RADIATEUR REVO ESS AUTO - GECER - 21360 DA - [voir le produit](produit.php?id=$idRevo)", $refsProches);
$affiche($idRevo !== false && strpos($texte, '(réf. 16400-0C381-AT)') !== false, 'guard: reference ajoutee aux suggestions proches', $texte);

$total = $ok + count($echecs);
foreach ($echecs as $e) {
    echo "FAIL  $e\n";
}
echo "---\n$ok/$total cas reussis\n";
exit($echecs ? 1 : 0);
