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

require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/llm/factory.php';

// Recent history for this session, oldest first.
$historyLimit = $config['history_turns'] ?? 10;
$stmt = $pdo->prepare('SELECT role, message, tools_called FROM ai_conversation WHERE id_session = ? ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, $id_session, PDO::PARAM_STR);
$stmt->bindValue(2, $historyLimit * 2, PDO::PARAM_INT); // *2: user+assistant pairs
$stmt->execute();
// A failed exchange (see "_error" below) is dropped as a pair: the
// unanswered customer message left alone made the model answer it instead
// of the new one ("Culasse coaster" got a reply about the failed "Kit emb
// yaris 2007").
$historyRows = [];
foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
    if ($row['role'] === 'assistant' && strpos((string)$row['tools_called'], '"_error"') !== false) {
        if ($historyRows && end($historyRows)['role'] === 'user') {
            array_pop($historyRows);
        }
        continue;
    }
    $historyRows[] = $row;
}
$history = array_map(fn($r) => ['role' => $r['role'], 'text' => $r['message']], $historyRows);

$systemPrompt = ai_system_prompt() . ai_store_info_block($pdo, $config) . ai_session_context($pdo, $historyRows);

$logUser = $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message) VALUES (?, ?, ?)');
$logUser->execute([$id_session, 'user', $message]);

// Failures are recorded in ai_conversation itself, as an assistant row whose
// tools_called is a single "_error" pseudo-call - production's PHP error log
// location is unknown/unreadable, and a turn cut short there (01/10, "Kit
// emb yaris 2007": user row saved, nothing else, nothing in the logs) left
// no way to know why. Excluded from the history sent to the model.
$debutTour = microtime(true);
$reponseEnregistree = false;
$messageIndisponible = 'المساعد غير متوفر مؤقتاً، حاول لاحقاً. / L\'assistant est temporairement indisponible, réessayez plus tard.';
$enregistrerEchec = function (string $cause) use ($pdo, $id_session, $debutTour, $messageIndisponible, &$reponseEnregistree) {
    if ($reponseEnregistree) {
        return;
    }
    $reponseEnregistree = true;
    $appel = [['name' => '_error', 'args' => ['cause' => mb_substr($cause, 0, 500), 'secondes' => round(microtime(true) - $debutTour, 1)]]];
    $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message, tools_called) VALUES (?, ?, ?, ?)')
        ->execute([$id_session, 'assistant', $messageIndisponible, json_encode($appel, JSON_UNESCAPED_UNICODE)]);
};
// A fatal error (time limit, memory...) skips catch blocks entirely.
register_shutdown_function(function () use ($enregistrerEchec, $messageIndisponible, &$reponseEnregistree) {
    $err = error_get_last();
    if ($reponseEnregistree || !$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $enregistrerEchec('fatal: ' . $err['message']);
    echo json_encode(['ok' => false, 'error' => $messageIndisponible], JSON_UNESCAPED_UNICODE);
});
// A turn can need several provider calls; on the free tier each one took
// 15-22 s (measured 03/10). Raise PHP's own limit where the host allows it.
@set_time_limit(120);

try {
    $provider = ai_make_provider($config);
    $dispatcher = ai_build_tool_dispatcher($pdo);
    $result = ai_converse_verifie($provider, $systemPrompt, $history, $message, ai_tool_schemas(), $dispatcher, ai_vehicules_connus($historyRows));
} catch (Throwable $e) {
    // Never leak raw provider/API exception details (could contain internal
    // routing/config info) to the client - stored server-side only.
    error_log('ai/chat.php provider error: ' . $e->getMessage());
    $enregistrerEchec($e->getMessage());
    fail($messageIndisponible);
}
$reponseEnregistree = true;

$reply = $result['text'] !== '' ? $result['text'] : 'عذراً، لم أتمكن من معالجة طلبك. / Désolé, je n\'ai pas pu traiter votre demande.';

$logAssistant = $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message, tools_called) VALUES (?, ?, ?, ?)');
$logAssistant->execute([
    $id_session,
    'assistant',
    $reply,
    !empty($result['tools_called']) ? json_encode($result['tools_called'], JSON_UNESCAPED_UNICODE) : null,
]);

echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
