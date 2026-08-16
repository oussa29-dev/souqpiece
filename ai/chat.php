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
$perDay = $rl['max_per_session_per_day'] ?? 40;
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

require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/llm/factory.php';

// Recent history for this session, oldest first.
$historyLimit = $config['history_turns'] ?? 10;
$stmt = $pdo->prepare('SELECT role, message FROM ai_conversation WHERE id_session = ? ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, $id_session, PDO::PARAM_STR);
$stmt->bindValue(2, $historyLimit * 2, PDO::PARAM_INT); // *2: user+assistant pairs
$stmt->execute();
$history = array_reverse(array_map(fn($r) => ['role' => $r['role'], 'text' => $r['message']], $stmt->fetchAll(PDO::FETCH_ASSOC)));

$logUser = $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message) VALUES (?, ?, ?)');
$logUser->execute([$id_session, 'user', $message]);

try {
    $provider = ai_make_provider($config);
    $dispatcher = ai_build_tool_dispatcher($pdo);
    $result = $provider->converse(ai_system_prompt(), $history, $message, ai_tool_schemas(), $dispatcher);
} catch (Throwable $e) {
    // Never leak raw provider/API exception details (could contain internal
    // routing/config info) to the client - log server-side only.
    error_log('ai/chat.php provider error: ' . $e->getMessage());
    fail('المساعد غير متوفر مؤقتاً، حاول لاحقاً. / L\'assistant est temporairement indisponible, réessayez plus tard.');
}

$reply = $result['text'] !== '' ? $result['text'] : 'عذراً، لم أتمكن من معالجة طلبك. / Désolé, je n\'ai pas pu traiter votre demande.';

$logAssistant = $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message, tools_called) VALUES (?, ?, ?, ?)');
$logAssistant->execute([
    $id_session,
    'assistant',
    $reply,
    !empty($result['tools_called']) ? json_encode($result['tools_called'], JSON_UNESCAPED_UNICODE) : null,
]);

echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
