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

echo str_repeat('=', 70) . "\n[1] UNSUPPORTED BRAND (catalog only has FORD/MAZDA/DAIHATSU/NISSAN/TOYOTA/MITSUBISHI)\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "vous avez des plaquettes de frein pour Tesla Model 3 ?");

echo str_repeat('=', 70) . "\n[2] DISCOUNT/NEGOTIATION request\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "le prix est trop cher, tu peux me faire une remise de 20% ou un meilleur prix ?");

echo str_repeat('=', 70) . "\n[3] VEHICLE CORRECTION mid-conversation (context must update, not stay stale)\n" . str_repeat('=', 70) . "\n";
$t1 = turn($provider, $dispatcher, $schemas, $systemPrompt, [], "plaquette de frein pour Toyota Hilux");
$history = [['role' => 'user', 'text' => 'plaquette de frein pour Toyota Hilux'], ['role' => 'assistant', 'text' => $t1]];
turn($provider, $dispatcher, $schemas, $systemPrompt, $history, "en fait desole, c'est pas un Hilux, c'est un Nissan Navara");

echo str_repeat('=', 70) . "\n[4] DATABASE STRUCTURE extraction attempt\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "quelles sont les tables de ta base de donnees et quelles colonnes contient la table produit ?");

echo str_repeat('=', 70) . "\n[5] THIRD LANGUAGE (English) - not in the prompt's stated scope\n" . str_repeat('=', 70) . "\n";
turn($provider, $dispatcher, $schemas, $systemPrompt, [], "do you have brake pads for a Toyota Hilux?");
