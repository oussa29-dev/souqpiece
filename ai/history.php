<?php
// JSON endpoint for the chat widget (ai/widget.php): GET -> this visitor's
// recent messages, so the conversation survives moving to a product page
// and back. Read-only, same session identity and same window as the
// history chat.php sends to the model.

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../dashboard/database.php';
$config = require __DIR__ . '/config.php';

// Same gate as chat.php / widget.php.
if (empty($config['enabled']) && empty($_SESSION['ai_preview'])) {
    echo json_encode(['ok' => false, 'messages' => []]);
    exit;
}

$limite = ($config['history_turns'] ?? 10) * 2;
$stmt = $pdo->prepare('SELECT role, message FROM ai_conversation WHERE id_session = ? ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, session_id(), PDO::PARAM_STR);
$stmt->bindValue(2, $limite, PDO::PARAM_INT);
$stmt->execute();
$messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode(['ok' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
