<?php
// Phase 3 gate check: real conversational turns through the actual model +
// tool loop, against real local data. CLI only, never web-exposed.
// Run: php ai/chat_cli_test.php
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/llm/factory.php';

$config = require __DIR__ . '/config.php';
if ($config['gemini']['api_key'] === 'PUT_YOUR_GEMINI_API_KEY_HERE') {
    die("Set a real key in ai/config.php first (see ai/config.example.php).\n");
}

$provider = ai_make_provider($config);
$dispatcher = ai_build_tool_dispatcher($pdo);
$schemas = ai_tool_schemas();
$systemPrompt = ai_system_prompt();

// Realistic bilingual customer messages, built from real catalog vocabulary
// (recherche log itself is unusable for Arabic - see the ?????? rows, a
// direct casualty of the latin1 encoding issue found in the DB analysis).
$cases = [
    'FR - part name only' => 'je cherche un CROISILLON CARDON pour ma NAVARA',
    'FR - reference number' => "vous avez la piece 11311-54052 ?",
    'AR - part + vehicle, colloquial' => 'عندكم كولان كاردون لقاشقاي؟',
    'AR - reference number' => 'واش كاين المرجع 11311-54052',
    'AR - compatibility trap (must refuse to confirm)' => 'هل هذه القطعة تتوافق مع محرك 2.5 ديزل موديل 2015؟',
    'FR - stock quantity trap (must not give a number)' => 'vous en avez combien en stock exactement ?',
    'FR - delivery price' => 'le prix de livraison a domicile pour Oran ?',
    'AR - vague request (should route via categories)' => 'نحتاج قطع فرامل',
];

foreach ($cases as $label => $message) {
    echo str_repeat('=', 70) . "\n";
    echo "[$label]\n> $message\n\n";

    try {
        $result = $provider->converse($systemPrompt, [], $message, $schemas, $dispatcher);
        echo ($result['text'] ?: '(empty response)') . "\n";
        if (!empty($result['tools_called'])) {
            echo "\n-- tools called --\n";
            foreach ($result['tools_called'] as $tc) {
                echo "  {$tc['name']}(" . json_encode($tc['args'], JSON_UNESCAPED_UNICODE) . ")\n";
            }
        }
        if (!empty($result['error'])) {
            echo "\n[!] error: {$result['error']}\n";
        }
    } catch (Throwable $e) {
        echo "[!] EXCEPTION: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
