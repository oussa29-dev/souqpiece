<?php
// WhatsApp channel of the assistant (Meta WhatsApp Cloud API). Phase 1:
// anyone writing to the shop's number gets the same assistant as the site -
// availability, retail price, product link. Endpoint: ai/whatsapp.php.
// Kept separate from the endpoint so the logic can be tested without Meta
// (the message sender is a parameter).

require_once __DIR__ . '/conversation.php';

// Starts with Latin text: WhatsApp lays out a message starting with Arabic
// right-to-left, which scrambles the product lines and links under it.
const WA_NOTICE = "🤖 SOUQPIECE BA - Assistant automatique / مساعد آلي\n\n";
const WA_TEXTE_SEULEMENT = 'يرجى كتابة طلبك نصاً (اسم القطعة والسيارة). / Merci d\'écrire votre demande en texte (pièce et véhicule).';
const WA_LIMITE = 'لقد وصلت للحد الأقصى من الرسائل اليوم، تواصل مع المحل. / Limite quotidienne de messages atteinte, contactez le magasin.';

// Meta signs every webhook with the app secret (header
// X-Hub-Signature-256: sha256=<hmac>). Without a configured secret the
// check is skipped (local tests only).
function wa_signature_valide(string $corps, string $entete, string $secret): bool
{
    if ($secret === '') {
        return true;
    }
    $attendu = 'sha256=' . hash_hmac('sha256', $corps, $secret);
    return hash_equals($attendu, $entete);
}

// Incoming customer messages of a webhook payload: [['wamid', 'de', 'nom',
// 'type', 'texte']], 'nom' being the sender's WhatsApp profile name (or '').
// Delivery/read statuses (the shop's own messages) are ignored.
function wa_messages_recus(array $payload): array
{
    $messages = [];
    foreach ($payload['entry'] ?? [] as $entree) {
        foreach ($entree['changes'] ?? [] as $changement) {
            if (($changement['field'] ?? '') !== 'messages') {
                continue;
            }
            $noms = [];
            foreach ($changement['value']['contacts'] ?? [] as $contact) {
                $noms[(string)($contact['wa_id'] ?? '')] = trim((string)($contact['profile']['name'] ?? ''));
            }
            foreach ($changement['value']['messages'] ?? [] as $m) {
                $messages[] = [
                    'wamid' => (string)($m['id'] ?? ''),
                    'de' => preg_replace('/\D/', '', (string)($m['from'] ?? '')),
                    'nom' => mb_substr($noms[(string)($m['from'] ?? '')] ?? '', 0, 100),
                    'type' => (string)($m['type'] ?? ''),
                    'texte' => trim((string)($m['text']['body'] ?? '')),
                ];
            }
        }
    }
    return array_values(array_filter($messages, fn($m) => $m['wamid'] !== '' && $m['de'] !== ''));
}

// WhatsApp shows plain text only: the widget's "[label](produit.php?...)"
// links become full addresses on the shop's domain, each on its own line
// with a blank line after it - a long address inside a line wraps badly,
// worst in right-to-left (Arabic) messages.
function wa_formater_reponse(string $texte, string $site): string
{
    $site = rtrim($site, '/');
    $texte = preg_replace_callback('/[ \t]*[-:]?[ \t]*\[([^\]]+)\]\((produit\.php\?[^)\s]+)\)[ \t]*/u', fn($m) => "\n" . $site . '/' . $m[2] . "\n\n", $texte);
    $texte = preg_replace('/\n{3,}/', "\n\n", $texte);
    return trim($texte);
}

// WhatsApp caps a text message at 4096 characters: split on line breaks.
function wa_decouper(string $texte, int $max = 4000): array
{
    if (mb_strlen($texte) <= $max) {
        return [$texte];
    }
    $morceaux = [];
    $courant = '';
    foreach (preg_split('/\R/u', $texte) as $ligne) {
        if ($courant !== '' && mb_strlen($courant) + mb_strlen($ligne) + 1 > $max) {
            $morceaux[] = $courant;
            $courant = '';
        }
        $courant .= ($courant === '' ? '' : "\n") . $ligne;
    }
    if ($courant !== '') {
        $morceaux[] = $courant;
    }
    return $morceaux;
}

// Graph API call; returns true on success. Failures are logged, never
// thrown - the webhook has already answered Meta.
function wa_api(array $wa, array $donnees): bool
{
    if (empty($wa['access_token']) || empty($wa['phone_number_id'])) {
        error_log('whatsapp: access_token or phone_number_id missing in ai/config.php');
        return false;
    }
    $url = 'https://graph.facebook.com/' . ($wa['graph_version'] ?? 'v25.0') . '/' . rawurlencode((string)$wa['phone_number_id']) . '/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $wa['access_token']],
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp'] + $donnees, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $reponse = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);
    if ($reponse === false || $code >= 400) {
        error_log("whatsapp: API error HTTP $code $erreur " . substr((string)$reponse, 0, 300));
        return false;
    }
    return true;
}

function wa_envoyer_texte(array $wa, string $a, string $texte): bool
{
    $ok = true;
    foreach (wa_decouper($texte) as $morceau) {
        $ok = wa_api($wa, ['to' => $a, 'type' => 'text', 'text' => ['body' => $morceau, 'preview_url' => false]]) && $ok;
    }
    return $ok;
}

function wa_marquer_lu(array $wa, string $wamid): void
{
    wa_api($wa, ['status' => 'read', 'message_id' => $wamid]);
}

// Active wholesale customer with this WhatsApp number (Dashboard > Clients
// gros stores numbers as 213XXXXXXXXX, the sender format), or null.
function wa_client_gros(PDO $pdo, string $telephone): ?array
{
    $stmt = $pdo->prepare('SELECT id_client_gros, nom, code_client FROM client_gros WHERE telephone = ? AND actif = 1');
    $stmt->execute([$telephone]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    return $client ?: null;
}

// Handles every customer message of one webhook payload. $envoyer(to, text)
// sends a reply; $marquerLu(wamid) marks the message as read (both injected
// so tests run without Meta). Returns what was done, for tests and logs.
function wa_traiter_payload(PDO $pdo, array $config, array $payload, callable $envoyer, ?callable $marquerLu = null): array
{
    $wa = $config['whatsapp'] ?? [];
    $site = (string)($wa['site_url'] ?? 'https://souqpieceba.com');
    $limiteJour = (int)($wa['max_per_number_per_day'] ?? 100);
    $journal = [];

    foreach (wa_messages_recus($payload) as $m) {
        // Meta redelivers webhooks: handle each message id once.
        $stmt = $pdo->prepare('INSERT IGNORE INTO ai_whatsapp_recu (wamid, telephone, nom_profil) VALUES (?, ?, ?)');
        $stmt->execute([$m['wamid'], $m['de'], $m['nom'] !== '' ? $m['nom'] : null]);
        if ($stmt->rowCount() === 0) {
            $journal[] = ['wamid' => $m['wamid'], 'action' => 'doublon'];
            continue;
        }
        if ($marquerLu !== null) {
            $marquerLu($m['wamid']);
        }

        if ($m['type'] !== 'text' || $m['texte'] === '') {
            $envoyer($m['de'], WA_TEXTE_SEULEMENT);
            $journal[] = ['wamid' => $m['wamid'], 'action' => 'non_texte', 'type' => $m['type']];
            continue;
        }

        $idSession = 'wa:' . $m['de'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_conversation WHERE id_session = ? AND role = 'user' AND created_at > (NOW() - INTERVAL 1 DAY)");
        $stmt->execute([$idSession]);
        if ((int)$stmt->fetchColumn() >= $limiteJour) {
            $envoyer($m['de'], WA_LIMITE);
            $journal[] = ['wamid' => $m['wamid'], 'action' => 'limite'];
            continue;
        }

        $texte = mb_substr($m['texte'], 0, (int)($config['max_message_length'] ?? 1000));
        // Wholesale prices only once the store has switched them on
        // ('tarif_gros' in ai/config.php) - until then everyone gets the
        // retail price, wholesale customers included.
        $clientGros = !empty($wa['tarif_gros']) ? wa_client_gros($pdo, $m['de']) : null;
        $tour = ai_traiter_tour($pdo, $config, $idSession, $texte, function () use ($envoyer, $m) {
            $envoyer($m['de'], AI_MESSAGE_INDISPONIBLE);
        }, $clientGros);

        $reponse = wa_formater_reponse($tour['reply'], $site);
        // Meta's policy: say it is an automated assistant at the start of a
        // conversation.
        if (!$tour['historique']) {
            $reponse = WA_NOTICE . $reponse;
        }
        $envoyer($m['de'], $reponse);
        $journal[] = ['wamid' => $m['wamid'], 'action' => $tour['ok'] ? 'repondu' : 'echec', 'reponse' => $reponse, 'client_gros' => $clientGros !== null];
    }
    return $journal;
}
