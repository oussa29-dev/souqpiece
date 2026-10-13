<?php
// WhatsApp webhook (Meta WhatsApp Cloud API) - the address registered in
// the Meta app as "Callback URL". Settings in the gitignored ai/config.php,
// block 'whatsapp' (see ai/config.example.php). Logic in whatsapp_lib.php.
//
// GET  : Meta's one-time verification (hub.challenge echoed back when
//        hub.verify_token matches).
// POST : messages. Meta wants a fast 200, and an assistant turn takes
//        several seconds, so the 200 is sent and the connection closed
//        first, then the reply is computed and sent through the API.

require_once __DIR__ . '/../dashboard/database.php';
$config = require __DIR__ . '/config.php';
$wa = $config['whatsapp'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $jeton = (string)($wa['verify_token'] ?? '');
    if ($jeton !== '' && ($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals($jeton, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string)($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/whatsapp_lib.php';

$corps = file_get_contents('php://input');
if (!wa_signature_valide($corps, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), (string)($wa['app_secret'] ?? ''))) {
    http_response_code(403);
    exit;
}
$payload = json_decode($corps, true);

// Answer Meta now, keep working after the connection is closed.
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain');
header('Connection: close');
header('Content-Length: 2');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    @ob_end_flush();
    flush();
}

if (empty($wa['enabled']) || !is_array($payload)) {
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
wa_traiter_payload(
    $pdo,
    $config,
    $payload,
    fn($a, $texte) => wa_envoyer_texte($wa, $a, $texte),
    fn($wamid) => wa_marquer_lu($wa, $wamid)
);
