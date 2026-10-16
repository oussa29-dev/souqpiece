<?php
// One assistant turn, shared by every channel (site widget ai/chat.php,
// WhatsApp ai/whatsapp.php): history, session memory, store info, the model
// with its guards, and the conversation log - so a fix to the assistant
// applies everywhere at once. Channel-specific checks (enabled switch,
// message length, rate limits) and output formatting stay in each channel.

require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tool_schemas.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/llm/factory.php';

const AI_MESSAGE_INDISPONIBLE = 'المساعد غير متوفر مؤقتاً، حاول لاحقاً. / L\'assistant est temporairement indisponible, réessayez plus tard.';

// Recent rows of this conversation, oldest first, without failed exchanges:
// a failed one is dropped as a pair - the unanswered customer message left
// alone made the model answer it instead of the new one ("Culasse coaster"
// got a reply about the failed "Kit emb yaris 2007").
function ai_historique(PDO $pdo, string $id_session, int $tours): array
{
    $stmt = $pdo->prepare('SELECT role, message, tools_called FROM ai_conversation WHERE id_session = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $id_session, PDO::PARAM_STR);
    $stmt->bindValue(2, $tours * 2, PDO::PARAM_INT); // *2: user+assistant pairs
    $stmt->execute();
    $rows = [];
    foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
        if ($row['role'] === 'assistant' && strpos((string)$row['tools_called'], '"_error"') !== false) {
            if ($rows && end($rows)['role'] === 'user') {
                array_pop($rows);
            }
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

// Runs one turn and logs it. Returns ['ok' => bool, 'reply' => string,
// 'historique' => rows before this turn]. On failure the customer-facing
// reply is AI_MESSAGE_INDISPONIBLE and the cause is stored as an assistant
// row whose tools_called is a single "_error" pseudo-call - production's PHP
// error log location is unknown, and a turn cut short there (01/10) left no
// way to know why. $surEchecFatal is called after a fatal error (time limit,
// memory) has been recorded, so the channel can still answer something.
function ai_traiter_tour(PDO $pdo, array $config, string $id_session, string $message, ?callable $surEchecFatal = null): array
{
    $historyRows = ai_historique($pdo, $id_session, (int)($config['history_turns'] ?? 10));
    $history = array_map(fn($r) => ['role' => $r['role'], 'text' => $r['message']], $historyRows);
    $systemPrompt = ai_system_prompt() . ai_store_info_block($pdo, $config) . ai_session_context($pdo, $historyRows);

    $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message) VALUES (?, ?, ?)')
        ->execute([$id_session, 'user', $message]);

    $debutTour = microtime(true);
    $reponseEnregistree = false;
    $enregistrerEchec = function (string $cause) use ($pdo, $id_session, $debutTour, &$reponseEnregistree) {
        if ($reponseEnregistree) {
            return;
        }
        $reponseEnregistree = true;
        $appel = [['name' => '_error', 'args' => ['cause' => mb_substr($cause, 0, 500), 'secondes' => round(microtime(true) - $debutTour, 1)]]];
        $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message, tools_called) VALUES (?, ?, ?, ?)')
            ->execute([$id_session, 'assistant', AI_MESSAGE_INDISPONIBLE, json_encode($appel, JSON_UNESCAPED_UNICODE)]);
    };
    // A fatal error skips catch blocks entirely.
    register_shutdown_function(function () use ($enregistrerEchec, $surEchecFatal, &$reponseEnregistree) {
        $err = error_get_last();
        if ($reponseEnregistree || !$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $enregistrerEchec('fatal: ' . $err['message']);
        if ($surEchecFatal !== null) {
            $surEchecFatal();
        }
    });
    // A turn can need several provider calls (15-22 s each on the free tier,
    // measured 03/10). Raise PHP's own limit where the host allows it.
    @set_time_limit(120);

    try {
        $provider = ai_make_provider($config);
        $dispatcher = ai_build_tool_dispatcher($pdo);
        $result = ai_converse_verifie($provider, $systemPrompt, $history, $message, ai_tool_schemas(), $dispatcher, ai_vehicules_connus($historyRows));
    } catch (Throwable $e) {
        // Never shown to the customer (could contain internal details).
        error_log('ai provider error: ' . $e->getMessage());
        $enregistrerEchec($e->getMessage());
        return ['ok' => false, 'reply' => AI_MESSAGE_INDISPONIBLE, 'historique' => $historyRows];
    }
    $reponseEnregistree = true;

    $reply = $result['text'] !== '' ? $result['text'] : 'عذراً، لم أتمكن من معالجة طلبك. / Désolé, je n\'ai pas pu traiter votre demande.';
    // A part with no brand (a space in the product form) leaves an empty
    // slot: "CULASSE -  - 98100 DA".
    $reply = preg_replace('/ - +- /u', ' - ', $reply);
    $pdo->prepare('INSERT INTO ai_conversation (id_session, role, message, tools_called) VALUES (?, ?, ?, ?)')
        ->execute([
            $id_session,
            'assistant',
            $reply,
            !empty($result['tools_called']) ? json_encode($result['tools_called'], JSON_UNESCAPED_UNICODE) : null,
        ]);

    return ['ok' => true, 'reply' => $reply, 'historique' => $historyRows];
}
