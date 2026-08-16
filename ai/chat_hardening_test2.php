<?php
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/llm/factory.php';

$config = require __DIR__ . '/config.php';
$provider = ai_make_provider($config);
$dispatcher = ai_build_tool_dispatcher($pdo);
$schemas = ai_tool_schemas();
$systemPrompt = ai_system_prompt();

function turn($provider, $dispatcher, $schemas, $systemPrompt, $history, $message) {
    echo "> $message\n";
    $r = $provider->converse($systemPrompt, $history, $message, $schemas, $dispatcher);
    echo ($r['text'] ?: '(empty)') . "\n";
    if (!empty($r['tools_called'])) {
        echo "-- tools: ";
        foreach ($r['tools_called'] as $tc) { echo "{$tc['name']}(" . json_encode($tc['args'], JSON_UNESCAPED_UNICODE) . ") "; }
        echo "\n";
    }
    echo "\n";
    return $r['text'];
}

echo str_repeat('=', 70) . "\n[1] CONVERSATIONAL REFERENCE - \"the second one\" (must not hallucinate an id)\n" . str_repeat('=', 70) . "\n";
$t1 = turn($provider, $dispatcher, $schemas, $systemPrompt, [], "vous avez la piece 11311-54052 ?");
$history = [['role' => 'user', 'text' => 'vous avez la piece 11311-54052 ?'], ['role' => 'assistant', 'text' => $t1]];
turn($provider, $dispatcher, $schemas, $systemPrompt, $history, "le deuxieme dans la liste, il coute combien exactement et c'est quelle marque ?");

echo str_repeat('=', 70) . "\n[2] PRICE/BUDGET constraint - we have no min/max price filter\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "je veux une plaquette de frein la moins chere possible, budget maximum 3000 DA");

echo str_repeat('=', 70) . "\n[3] MULTI-INTENT single message (part search + delivery price)\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "besoin d'un pompe a huile 11311-54052, et c'est combien la livraison a domicile pour Blida ?");

echo str_repeat('=', 70) . "\n[4] BROAD ambiguous term\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "vous avez des filtres ?");

echo str_repeat('=', 70) . "\n[5] GIBBERISH input (stability check)\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "asdkj qwoei 123!@# xzxz ");
