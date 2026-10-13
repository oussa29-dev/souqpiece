<?php
// JSON endpoint for the chat widget (ai/widget.php). POST {"message": "..."}
// -> {"ok": true, "reply": "..."} or {"ok": false, "error": "..."}.
// Conversation history is kept server-side in ai_conversation, keyed by
// PHP session id - the same identity model panier.php already uses for
// the cart, so no client-side history bookkeeping is needed.

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../dashboard/database.php';

$config = require __DIR__ . '/config.php';

function fail(string $error, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

// These fire before any LLM call, so there's no signal yet for which
// language the customer prefers - written bilingual (AR/FR) rather than
// guessing, so the reply never ends up mixing a translated wrapper with a
// raw English string (real bug caught in testing: "عذراً، حدث خطأ: too
// many messages, please slow down").
// Same private-preview override as widget.php - a session that visited
// ?ai=1 can still use the assistant while it's globally disabled.
if (empty($config['enabled']) && empty($_SESSION['ai_preview'])) {
    fail('المساعد غير متوفر حالياً. / L\'assistant est actuellement indisponible.');
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$message = trim((string)($body['message'] ?? ''));

if ($message === '') {
    fail('يرجى كتابة رسالة. / Veuillez écrire un message.');
}
$maxLen = $config['max_message_length'] ?? 1000;
if (mb_strlen($message) > $maxLen) {
    fail("الرسالة طويلة جداً (الحد الأقصى $maxLen حرف). / Message trop long (max $maxLen caractères).");
}

$id_session = session_id();

// Rate limit - protects the store's own API budget/quota from a single
// session hammering the endpoint, not just abuse. Free-tier provider quotas
// are tight enough that this matters even for legitimate heavy use.
$rl = $config['rate_limit'] ?? [];
$perDay = $rl['max_per_session_per_day'] ?? 25;
$per5min = $rl['max_per_session_per_5min'] ?? 8;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_conversation WHERE id_session = ? AND role = 'user' AND created_at > (NOW() - INTERVAL 1 DAY)");
$stmt->execute([$id_session]);
if ((int)$stmt->fetchColumn() >= $perDay) {
    fail('لقد وصلت للحد الأقصى من الرسائل اليوم، حاول غداً أو تواصل مع المتجر. / Limite quotidienne de messages atteinte, réessayez demain ou contactez le magasin.');
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_conversation WHERE id_session = ? AND role = 'user' AND created_at > (NOW() - INTERVAL 5 MINUTE)");
$stmt->execute([$id_session]);
if ((int)$stmt->fetchColumn() >= $per5min) {
    fail('رسائل كثيرة جداً، يرجى الانتظار قليلاً. / Trop de messages, veuillez patienter un instant.');
}

require_once __DIR__ . '/conversation.php';

// The turn itself (history, memory, model, guards, failure recording) is
// shared with the WhatsApp channel - see ai/conversation.php.
$tour = ai_traiter_tour($pdo, $config, $id_session, $message, function () {
    echo json_encode(['ok' => false, 'error' => AI_MESSAGE_INDISPONIBLE], JSON_UNESCAPED_UNICODE);
});
if (!$tour['ok']) {
    fail($tour['reply']);
}

echo json_encode(['ok' => true, 'reply' => $tour['reply']], JSON_UNESCAPED_UNICODE);
