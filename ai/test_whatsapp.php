<?php
// CLI tests of the WhatsApp channel (ai/whatsapp_lib.php) with fake Meta
// payloads and a fake sender - nothing is sent to WhatsApp. Without
// arguments no model call is made (free); --live adds one real assistant
// turn (a few tenths of a cent). Test rows are removed at the end.
//
// Run: php ai/test_whatsapp.php [--live]
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}
require_once __DIR__ . '/../dashboard/database.php';
require_once __DIR__ . '/whatsapp_lib.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$config = require __DIR__ . '/config.php';
$live = in_array('--live', $argv, true);

$ok = 0;
$echecs = [];
$verifier = function (bool $passe, string $label, $detail = '') use (&$ok, &$echecs) {
    if ($passe) {
        $ok++;
        echo "  OK    $label\n";
    } else {
        $echecs[] = $label;
        echo "  FAIL  $label -- " . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    }
};

$numeroTest = '213000000001';
$payload = function (string $wamid, string $type, string $texte = '') use ($numeroTest) {
    $m = ['from' => $numeroTest, 'id' => $wamid, 'timestamp' => (string)time(), 'type' => $type];
    if ($type === 'text') {
        $m['text'] = ['body' => $texte];
    }
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [[
        'field' => 'messages',
        'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'X'], 'messages' => [$m]],
    ]]]]];
};
$nettoyer = function () use ($pdo, $numeroTest) {
    $pdo->prepare('DELETE FROM ai_conversation WHERE id_session = ?')->execute(['wa:' . $numeroTest]);
    $pdo->prepare('DELETE FROM ai_whatsapp_recu WHERE telephone = ?')->execute([$numeroTest]);
};
$nettoyer();

echo "== Payload, signature, format\n";
$statut = ['entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => 'x', 'status' => 'delivered']]]]]]]];
$verifier(wa_messages_recus($statut) === [], 'statuts de livraison ignores');
$recus = wa_messages_recus($payload('wamid.T1', 'text', 'Culasse coaster'));
$verifier(count($recus) === 1 && $recus[0]['texte'] === 'Culasse coaster' && $recus[0]['de'] === $numeroTest, 'message texte extrait', $recus);
$corps = '{"a":1}';
$verifier(wa_signature_valide($corps, 'sha256=' . hash_hmac('sha256', $corps, 's3cret'), 's3cret'), 'signature Meta valide acceptee');
$verifier(!wa_signature_valide($corps, 'sha256=' . hash_hmac('sha256', $corps, 'autre'), 's3cret'), 'signature invalide refusee');
$verifier(!wa_signature_valide($corps, '', 's3cret'), 'signature absente refusee');
$f = wa_formater_reponse("CULASSE - 98100 DA - disponible - [voir le produit](produit.php?id=7518&id_voiture=76)", 'https://souqpieceba.com');
$verifier($f === 'CULASSE - 98100 DA - disponible - https://souqpieceba.com/produit.php?id=7518&id_voiture=76', 'lien converti en adresse complete', $f);
$long = implode("\n", array_fill(0, 300, str_repeat('x', 30)));
$morceaux = wa_decouper($long);
$verifier(count($morceaux) >= 3 && max(array_map('mb_strlen', $morceaux)) <= 4000, 'message long decoupe sous 4096 caracteres', array_map('mb_strlen', $morceaux));

echo "== Traitement (expediteur factice)\n";
$envoyes = [];
$lus = [];
$envoyer = function ($a, $texte) use (&$envoyes) { $envoyes[] = [$a, $texte]; return true; };
$marquer = function ($wamid) use (&$lus) { $lus[] = $wamid; };

$j = wa_traiter_payload($pdo, $config, $payload('wamid.IMG', 'image'), $envoyer, $marquer);
$verifier(($j[0]['action'] ?? '') === 'non_texte' && ($envoyes[0][1] ?? '') === WA_TEXTE_SEULEMENT && $lus === ['wamid.IMG'], 'image -> demande d ecrire en texte, message marque lu', $j);
$j = wa_traiter_payload($pdo, $config, $payload('wamid.IMG', 'image'), $envoyer, $marquer);
$verifier(($j[0]['action'] ?? '') === 'doublon' && count($envoyes) === 1, 'meme message relivre par Meta -> ignore', $j);

if ($live) {
    echo "== Tour reel (appel au modele)\n";
    $envoyes = [];
    $j = wa_traiter_payload($pdo, $config, $payload('wamid.T1', 'text', 'Culasse coaster'), $envoyer, $marquer);
    $r = $envoyes[0][1] ?? '';
    echo "  reponse: " . str_replace("\n", ' / ', mb_substr($r, 0, 300)) . "\n";
    $verifier(($j[0]['action'] ?? '') === 'repondu', 'message texte -> reponse de l assistant', $j);
    $verifier(strpos($r, WA_NOTICE) === 0, 'premier message: mention "assistant automatique"');
    $verifier(strpos($r, 'https://souqpieceba.com/produit.php?id=') !== false && strpos($r, '](') === false, 'liens en adresses completes');
    $verifier((int)$pdo->query("SELECT COUNT(*) FROM ai_conversation WHERE id_session = 'wa:$numeroTest'")->fetchColumn() === 2, 'echange enregistre dans ai_conversation (wa:<numero>)');
    $envoyes = [];
    wa_traiter_payload($pdo, $config, $payload('wamid.T2', 'text', 'Vilbroqeun'), $envoyer, $marquer);
    $r2 = $envoyes[0][1] ?? '';
    echo "  reponse 2: " . str_replace("\n", ' / ', mb_substr($r2, 0, 200)) . "\n";
    $verifier($r2 !== '' && strpos($r2, WA_NOTICE) !== 0, 'message suivant: pas de mention repetee');
}

$nettoyer();
echo "---\n$ok passed, " . count($echecs) . " failed\n";
exit($echecs ? 1 : 0);
