<?php
// CLI-only - replays real multi-turn sessions from ai_conversation through
// the FULL assistant (system prompt + store info + session memory + LLM +
// tools), built exactly the way chat.php builds a turn. Unlike
// eval_search.php this CALLS THE LLM PROVIDER (costs quota/money) and its
// output must be read by a human - each session lists what a good answer
// does. Nothing is written to ai_conversation.
//
// Run: php ai/eval_chat.php                 (provider from ai/config.php)
//      php ai/eval_chat.php anthropic       (compare another provider)
//      php ai/eval_chat.php groq B D        (only sessions B and D)
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/llm/factory.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$config = require __DIR__ . '/config.php';
if (isset($argv[1]) && isset($config[$argv[1]])) {
    $config['provider'] = $argv[1];
}
$seulement = array_slice($argv, 2);

$sessions = [
    'A' => ['#60-63 filtre Yaris 4', ['Filter yaris', '4'], 'asks which Yaris (by name+years, no ids), then lists filters for the Yaris 4 NCP151; reply in French'],
    'B' => ['#46-53 plateau NSP130', ['Plateau', 'Yaris', 'Nsp130'], 'after "Nsp130": searches plateau for id 42 without asking the part again; shows PLATAUX D\'EMBRAYAGE'],
    'C' => ['#66-69 vilebrequin Coaster', ['Vilb coaster', 'Vilbroqeun'], 'second turn keeps the Coaster (id 76), shows VILBROKEN/VILBROQUIN for it'],
    'D' => ['#40-45 disque Corolla 2008', ['Disque corolla', 'Corolla 2008'], '"Corolla 2008" resolves to CE140 directly and lists disques, no id shown'],
    'E' => ['#24-29 culasse en darija', ['كيلاس كواستر'], 'Arabic reply listing CULASSE for the Coaster'],
    'F' => ['#30 reference sans prix', ['13508-30011'], 'says the part is listed (PIGNON INTERMEDIAIRE) and to contact the store for the price - not "not found"'],
    'G' => ['#18 vente en gros', ['سلعة جملة'], 'Arabic; no invented wholesale policy; gives the store phone'],
    'H' => ['#34-39 demarreur Yaris 2', ['Dem yaris', 'Ncp90'], 'ends with DEMARREUR products for the Yaris 2 NCP90, each line starting with the product name'],
];

$provider = ai_make_provider($config);
$dispatcher = ai_build_tool_dispatcher($pdo);
$schemas = ai_tool_schemas();
echo "Provider: {$config['provider']}\n";

foreach ($sessions as $cle => [$label, $messages, $attendu]) {
    if ($seulement && !in_array($cle, $seulement, true)) {
        continue;
    }
    echo "\n" . str_repeat('=', 70) . "\n[$cle] $label\nExpected: $attendu\n";
    $rows = [];
    foreach ($messages as $message) {
        $history = array_map(fn($r) => ['role' => $r['role'], 'text' => $r['message']], $rows);
        $systemPrompt = ai_system_prompt() . ai_store_info_block($pdo, $config) . ai_session_context($pdo, $rows);
        $t0 = microtime(true);
        try {
            $result = $provider->converse($systemPrompt, $history, $message, $schemas, $dispatcher);
        } catch (Throwable $e) {
            echo "\n> $message\n!! ERREUR provider: " . $e->getMessage() . "\n";
            break;
        }
        $duree = round(microtime(true) - $t0, 1);
        $outils = array_map(fn($c) => $c['name'] . json_encode($c['args'] ?? [], JSON_UNESCAPED_UNICODE), $result['tools_called'] ?? []);
        echo "\n> $message   ({$duree}s)\n  tools: " . ($outils ? implode(' ; ', $outils) : '-') . "\n" . preg_replace('/^/m', '  ', $result['text']) . "\n";
        $rows[] = ['role' => 'user', 'message' => $message, 'tools_called' => null];
        $rows[] = ['role' => 'assistant', 'message' => $result['text'], 'tools_called' => $result['tools_called'] ? json_encode($result['tools_called'], JSON_UNESCAPED_UNICODE) : null];
        sleep(2);
    }
}
