<?php
// Conversations of the customer assistant (ai_conversation): customers list
// on the left, the selected conversation as a chat on the right.
//
// Two levels on one page:
// - dashboard login (the store): the WhatsApp customers only - name, number,
//   last contact, newest first - and what they exchanged with the assistant
//   (store owner's request, 26/10).
// - developer password (?dev=1, hash in the gitignored ai/config.php,
//   'dev_password_hash'): also the website sessions, the tools each reply
//   used, response times and recorded failures. The dashboard has a single
//   shared admin account, hence the separate password.
session_start();
if (!isset($_SESSION['utilisateur'])) {
    header('location:connexion.php');
    exit;
}
$aiConfig = require __DIR__ . '/../ai/config.php';
$hashDev = (string)($aiConfig['dev_password_hash'] ?? '');

if (isset($_GET['sortir'])) {
    unset($_SESSION['dev_acces']);
    header('location:ai-conversations.php');
    exit;
}
$erreurAcces = '';
if (empty($_SESSION['dev_acces']) && $hashDev !== '' && isset($_POST['dev_password'])) {
    if (password_verify((string)$_POST['dev_password'], $hashDev)) {
        session_regenerate_id(true);
        $_SESSION['dev_acces'] = true;
        header('location:ai-conversations.php');
        exit;
    }
    sleep(2);
    $erreurAcces = 'Mot de passe incorrect.';
}
$dev = !empty($_SESSION['dev_acces']);
if (!$dev && (isset($_GET['dev']) || $erreurAcces !== '')) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Accès développeur</title>
<style>body{font-family:sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
form{background:#fff;padding:28px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.08);display:flex;flex-direction:column;gap:12px;min-width:280px}
input,button{padding:10px;border-radius:8px;border:1px solid #ccc;font-size:15px}button{background:#126CFB;color:#fff;border:none;cursor:pointer}
.err{color:#a00707;font-size:14px}</style></head><body>
<?php if ($hashDev === ''): ?>
    <form><b>Accès développeur désactivé</b><span>Aucun mot de passe développeur configuré.</span></form>
<?php else: ?>
    <form method="post" action="ai-conversations.php"><b>Accès développeur</b>
        <input type="password" name="dev_password" placeholder="Mot de passe développeur" autofocus required>
        <?php if ($erreurAcces): ?><span class="err"><?= htmlspecialchars($erreurAcces) ?></span><?php endif; ?>
        <button type="submit">Entrer</button>
    </form>
<?php endif; ?>
</body></html><?php
    exit;
}

require_once('database.php');
require_once('include/telephone.php');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Wholesale prices on WhatsApp (ai/whatsapp_lib.php wa_tarif_gros_actif):
// switched from here, developer view only - the store does not see it.
$tarifGros = null; // null = table ai_reglage missing (migration not run)
if ($dev) {
    if (empty($_SESSION['jeton_dev'])) {
        $_SESSION['jeton_dev'] = bin2hex(random_bytes(16));
    }
    try {
        if (isset($_POST['tarif_gros']) && hash_equals($_SESSION['jeton_dev'], (string)($_POST['jeton'] ?? ''))) {
            $pdo->prepare("REPLACE INTO ai_reglage (cle, valeur) VALUES ('tarif_gros', ?)")->execute([$_POST['tarif_gros'] === '1' ? '1' : '0']);
            header('location:ai-conversations.php?' . http_build_query(array_diff_key($_GET, ['dev' => 1])));
            exit;
        }
        $tarifGros = $pdo->query("SELECT valeur FROM ai_reglage WHERE cle = 'tarif_gros'")->fetchColumn() === '1';
    } catch (PDOException $e) {
        $tarifGros = null;
    }
}

// WhatsApp sessions are "wa:<number>" (ai/whatsapp_lib.php): one per
// customer, for good. The website's are random ids, one per visit.
$canal = $dev && ($_GET['canal'] ?? '') === 'site' ? 'site' : 'wa';
$periodes = ['1' => "Aujourd'hui", '7' => '7 jours', '30' => '30 jours', 'tout' => 'Tout'];
$periode = isset($_GET['periode'], $periodes[$_GET['periode']]) ? $_GET['periode'] : '30';
$recherche = trim((string)($_GET['q'] ?? ''));
$parPage = 40;
$page = max(1, (int)($_GET['page'] ?? 1));
$sessionChoisie = (string)($_GET['s'] ?? '');
if ($sessionChoisie !== '' && (strpos($sessionChoisie, 'wa:') === 0) !== ($canal === 'wa')) {
    $sessionChoisie = '';
}

$conditions = [$canal === 'wa' ? "c.id_session LIKE 'wa:%'" : "c.id_session NOT LIKE 'wa:%'"];
$params = [];
// Numbers the store does not see (the developer's own test number):
// 'whatsapp' => ['masquer_conversations' => ['213...']] in ai/config.php.
$masques = $dev ? [] : array_map(fn($t) => 'wa:' . preg_replace('/\D/', '', (string)$t), (array)($aiConfig['whatsapp']['masquer_conversations'] ?? []));
if ($masques) {
    $conditions[] = 'c.id_session NOT IN (' . implode(',', array_fill(0, count($masques), '?')) . ')';
    $params = array_merge($params, $masques);
    if (in_array($sessionChoisie, $masques, true)) {
        $sessionChoisie = '';
    }
}
if ($recherche !== '') {
    // A word of the conversation, a number (any format) or a customer name.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $recherche) . '%';
    $ou = ['c.id_session IN (SELECT id_session FROM ai_conversation WHERE message LIKE ?)'];
    $params[] = $like;
    $chiffres = telephone_normaliser($recherche);
    if ($chiffres !== null) {
        $ou[] = 'c.id_session = ?';
        $params[] = 'wa:' . $chiffres;
    }
    if ($canal === 'wa') {
        $ou[] = 'SUBSTRING(c.id_session, 4) IN (SELECT telephone FROM client_gros WHERE nom LIKE ?)';
        $ou[] = 'SUBSTRING(c.id_session, 4) IN (SELECT telephone FROM ai_whatsapp_recu WHERE nom_profil LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    $conditions[] = '(' . implode(' OR ', $ou) . ')';
}
// The period filters on the last contact, the list's order: a customer who
// wrote today shows up with the whole conversation, even if it began earlier.
$having = $periode !== 'tout' ? 'HAVING fin >= NOW() - INTERVAL ' . (int)$periode . ' DAY' : '';
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT c.id_session, MAX(c.created_at) AS fin FROM ai_conversation c $where GROUP BY c.id_session $having) t");
$stmt->execute($params);
$totalSessions = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalSessions / $parPage));

$stmt = $pdo->prepare("
    SELECT c.id_session, MAX(c.created_at) AS fin,
           SUM(c.role = 'user') AS nb_messages,
           SUM(c.tools_called LIKE '%\"_error\"%') AS nb_erreurs,
           (SELECT x.message FROM ai_conversation x WHERE x.id_session = c.id_session AND x.role = 'user' ORDER BY x.id DESC LIMIT 1) AS dernier
    FROM ai_conversation c
    $where
    GROUP BY c.id_session
    $having
    ORDER BY fin DESC
    LIMIT $parPage OFFSET " . (($page - 1) * $parPage));
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($sessionChoisie === '' && $sessions) {
    $sessionChoisie = $sessions[0]['id_session'];
}

// Who is behind each WhatsApp number: the name the store gave a wholesale
// customer (Clients gros), else the customer's own WhatsApp profile name.
$clients = [];
$telephones = [];
foreach ($sessions as $s) {
    if (strpos($s['id_session'], 'wa:') === 0) {
        $telephones[substr($s['id_session'], 3)] = true;
    }
}
if (strpos($sessionChoisie, 'wa:') === 0) {
    $telephones[substr($sessionChoisie, 3)] = true;
}
if ($telephones) {
    $place = implode(',', array_fill(0, count($telephones), '?'));
    $stmt = $pdo->prepare("SELECT telephone, nom_profil FROM ai_whatsapp_recu WHERE telephone IN ($place) AND nom_profil IS NOT NULL ORDER BY recu_le");
    $stmt->execute(array_keys($telephones));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $clients[$r['telephone']]['nom'] = $r['nom_profil'];
    }
    $stmt = $pdo->prepare("SELECT telephone, nom, code_client, actif FROM client_gros WHERE telephone IN ($place)");
    $stmt->execute(array_keys($telephones));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $clients[$r['telephone']]['nom'] = $r['nom'];
        $clients[$r['telephone']]['gros'] = (bool)$r['actif'];
        $clients[$r['telephone']]['code'] = (string)$r['code_client'];
    }
}

$messages = [];
$voitures = [];
if ($sessionChoisie !== '') {
    $stmt = $pdo->prepare('SELECT id, role, message, tools_called, created_at FROM ai_conversation WHERE id_session = ? ORDER BY id');
    $stmt->execute([$sessionChoisie]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vehicle labels for every id_voiture the tools used, in one query.
    $ids = [];
    foreach ($dev ? $messages : [] as $m) {
        foreach ((array)json_decode((string)$m['tools_called'], true) as $appel) {
            if (!empty($appel['args']['id_voiture'])) {
                $ids[(int)$appel['args']['id_voiture']] = true;
            }
        }
    }
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT v.id_voiture, CONCAT(m.libelle, ' ', v.modele) FROM voiture v JOIN marque m ON m.id_marque = v.id_marque WHERE v.id_voiture IN ($place)");
        $stmt->execute(array_keys($ids));
        $voitures = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// "Aujourd'hui 15:44", "Hier 21:05", "24/10/2026 15:44".
function date_contact(string $date): string
{
    $t = strtotime($date);
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return "Aujourd'hui " . date('H:i', $t);
    }
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Hier ' . date('H:i', $t);
    }
    return date('d/m/Y H:i', $t);
}

function jour_long(string $date): string
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $t = strtotime($date);
    return ucfirst($jours[(int)date('w', $t)]) . ' ' . date('d/m/Y', $t);
}

// Assistant text: escaped, line breaks kept, and the "[label](produit.php?...)"
// links the widget turns into real links rendered the same way (opening the
// public product page).
function rendre_message(string $texte): string
{
    $morceaux = preg_split('/\[([^\]]+)\]\((produit\.php\?[^)\s]+)\)/u', $texte, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    for ($i = 0; $i < count($morceaux); $i++) {
        if ($i % 3 === 0) {
            $html .= nl2br(h($morceaux[$i]));
        } elseif ($i % 3 === 1) {
            $html .= '<a href="../' . h($morceaux[$i + 1]) . '" target="_blank" rel="noopener">' . h($morceaux[$i]) . '</a>';
            $i++;
        }
    }
    return $html;
}

function rendre_outils(?string $json, array $voitures): string
{
    $appels = json_decode((string)$json, true);
    if (!is_array($appels) || !$appels) {
        return '';
    }
    $html = '';
    foreach ($appels as $appel) {
        $nom = (string)($appel['name'] ?? '?');
        $args = is_array($appel['args'] ?? null) ? $appel['args'] : [];
        if ($nom === '_error') {
            $html .= '<span class="aic-outil aic-outil-erreur">Échec : ' . h($args['cause'] ?? '?') . (isset($args['secondes']) ? ' (' . h($args['secondes']) . ' s)' : '') . '</span>';
            continue;
        }
        $details = [];
        foreach ($args as $cle => $valeur) {
            if ($valeur === null || $valeur === '' || $valeur === 0 || $valeur === '0') {
                continue;
            }
            if ($cle === 'id_voiture' && isset($voitures[(int)$valeur])) {
                $valeur = $voitures[(int)$valeur] . ' #' . (int)$valeur;
            }
            $details[] = h($cle) . ' = <b>' . h(is_scalar($valeur) ? $valeur : json_encode($valeur, JSON_UNESCAPED_UNICODE)) . '</b>';
        }
        $html .= '<span class="aic-outil"><i class="fa-solid fa-wrench"></i> ' . h($nom) . ($details ? ' (' . implode(', ', $details) . ')' : '') . '</span>';
    }
    return $html;
}

$lienFiltres = function (array $changes) use ($canal, $periode, $recherche, $page) {
    $p = array_merge(['canal' => $canal === 'site' ? 'site' : null, 'periode' => $periode, 'q' => $recherche, 'page' => $page], $changes);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};

$telChoisi = strpos($sessionChoisie, 'wa:') === 0 ? substr($sessionChoisie, 3) : '';
$clientChoisi = $clients[$telChoisi] ?? [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Conversations clients</title>
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <style>
        .aic{display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:18px;margin:24px;align-items:start}
        .aic-filtres{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 24px 0}
        .aic-filtres a{text-decoration:none;color:#333;background:#eee;padding:7px 12px;border-radius:8px;font-size:14px}
        .aic-filtres a.actif{background:#126CFB;color:#fff}
        .aic-filtres a.canal{background:#dfe7f5}
        .aic-filtres a.canal.actif{background:#0b3d91;color:#fff}
        .aic-filtres form{display:flex;gap:6px;margin-left:auto}
        .aic-filtres form.aic-tarif,.aic-tarif{margin-left:0;display:flex;gap:8px;align-items:center;font-size:13px;padding:4px 6px 4px 10px;border-radius:8px}
        .aic-tarif.on{background:#e3f6e3;color:#17661a}
        .aic-tarif.off{background:#f1f2f4;color:#555}
        .aic-filtres .aic-tarif button{background:#0b3d91;padding:5px 10px}
        .aic-filtres input[type=text]{padding:7px 10px;border:1px solid #bbb;border-radius:8px;min-width:220px}
        .aic-filtres button{padding:7px 12px;border:none;border-radius:8px;background:rgb(24,185,24);color:#fff;cursor:pointer}
        .aic-liste{border:1px solid #d9dde3;border-radius:10px;overflow:hidden;background:#fff}
        .aic-liste a{display:block;padding:10px 12px;border-bottom:1px solid #eef0f3;text-decoration:none;color:#222}
        .aic-liste a:hover{background:#f4f7fb}
        .aic-liste a.actif{background:#e8f0ff;border-left:4px solid #126CFB}
        .aic-ligne{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
        .aic-nom{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .aic-date{font-size:12px;color:#6b7684;white-space:nowrap}
        .aic-tel{font-size:12px;color:#6b7684;font-variant-numeric:tabular-nums}
        .aic-apercu{margin-top:4px;font-size:13px;color:#444;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .aic-badge{display:inline-block;font-size:11px;border-radius:999px;padding:1px 7px;background:#eef0f3;color:#444}
        .aic-badge.err{background:#fde2e2;color:#a00707}
        .aic-badge.gros{background:#e3f6e3;color:#17661a;font-weight:700}
        .aic-pages{display:flex;justify-content:space-between;padding:8px 12px;font-size:13px;background:#f7f8fa}
        .aic-chat{border:1px solid #d9dde3;border-radius:10px;background:#faf9f9;padding:14px;display:flex;flex-direction:column;gap:10px;min-height:300px}
        .aic-entete{font-size:14px;color:#333;border-bottom:1px solid #e3e6ea;padding-bottom:10px;margin-bottom:4px;display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center}
        .aic-entete a{color:#126CFB;text-decoration:none}
        .aic-retour{display:none}
        .aic-jour{align-self:center;font-size:12px;color:#555;background:#e9edf2;border-radius:999px;padding:3px 12px;margin:6px 0}
        .aic-msg{max-width:78%;padding:10px 13px;border-radius:12px;font-size:14px;line-height:1.6;word-break:break-word}
        .aic-user{align-self:flex-end;background:#e12929;color:#fff;border-bottom-right-radius:3px}
        .aic-assistant{align-self:flex-start;background:#fff;border:1px solid #e3e6ea;border-bottom-left-radius:3px}
        .aic-assistant.aic-erreur{background:#fdf0f0;border-color:#f3b4b4}
        .aic-assistant a{color:#e12929;font-weight:600}
        .aic-meta{font-size:11px;opacity:.75;margin-top:6px;display:flex;gap:10px;flex-wrap:wrap}
        .aic-lent{color:#b45309;font-weight:700;opacity:1}
        .aic-outils{display:flex;flex-direction:column;gap:4px;margin-top:8px}
        .aic-outil{font-size:12px;background:#f1f4f8;border-radius:6px;padding:3px 8px;color:#334;direction:ltr;text-align:left}
        .aic-outil-erreur{background:#fde2e2;color:#a00707}
        .aic-vide{color:#777;padding:30px;text-align:center}
        @media (max-width:900px){
            .aic{grid-template-columns:1fr;margin:14px}
            .aic.choisi .aic-liste{display:none}
            .aic.choisi .aic-retour{display:inline}
            .aic-filtres form{margin-left:0;width:100%}
            .aic-filtres input[type=text]{min-width:0;flex:1}
            .aic-msg{max-width:92%}
        }
    </style>
</head>
<body>
    <?php include('include/menu.php'); ?>
    <div class="site">
        <div class="barre"><?= $canal === 'wa' ? 'Conversations WhatsApp des clients' : 'Conversations du site (assistant IA)' ?></div>

        <div class="aic-filtres">
            <?php if ($dev): ?>
                <a class="canal <?= $canal === 'wa' ? 'actif' : '' ?>" href="<?= h($lienFiltres(['canal' => null, 'page' => 1, 's' => null])) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                <a class="canal <?= $canal === 'site' ? 'actif' : '' ?>" href="<?= h($lienFiltres(['canal' => 'site', 'page' => 1, 's' => null])) ?>"><i class="fa-solid fa-globe"></i> Site</a>
            <?php endif; ?>
            <?php foreach ($periodes as $cle => $libelle): ?>
                <a class="<?= $cle === $periode ? 'actif' : '' ?>" href="<?= h($lienFiltres(['periode' => $cle, 'page' => 1, 's' => null])) ?>"><?= h($libelle) ?></a>
            <?php endforeach; ?>
            <span style="font-size:14px;color:#555"><?= $totalSessions ?> <?= $canal === 'wa' ? 'client(s)' : 'conversation(s)' ?></span>
            <?php if ($dev): ?>
                <a href="?sortir=1" title="Quitter l'accès développeur">Verrouiller</a>
                <?php if ($tarifGros === null): ?>
                    <span class="aic-tarif off">Prix gros WhatsApp : table ai_reglage absente (php dashboard/ai_reglage_migration.php --apply)</span>
                <?php else: ?>
                    <form method="post" class="aic-tarif <?= $tarifGros ? 'on' : 'off' ?>" onsubmit="return confirm(<?= h(json_encode($tarifGros ? 'Désactiver les prix gros sur WhatsApp ? Tout le monde aura le prix du site.' : 'Activer les prix gros sur WhatsApp pour les numéros de Clients gros ?')) ?>)">
                        <input type="hidden" name="jeton" value="<?= h($_SESSION['jeton_dev']) ?>">
                        <input type="hidden" name="tarif_gros" value="<?= $tarifGros ? '0' : '1' ?>">
                        <span>Prix gros WhatsApp : <b><?= $tarifGros ? 'ACTIVÉ' : 'désactivé' ?></b></span>
                        <button type="submit"><?= $tarifGros ? 'Désactiver' : 'Activer' ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <form method="get">
                <?php if ($canal === 'site'): ?><input type="hidden" name="canal" value="site"><?php endif; ?>
                <input type="hidden" name="periode" value="<?= h($periode) ?>">
                <input type="text" name="q" value="<?= h($recherche) ?>" placeholder="<?= $canal === 'wa' ? 'Nom, numéro ou mot (ex. culasse)' : 'Chercher un mot (ex. culasse, كيلاس)' ?>" dir="auto">
                <button type="submit">Chercher</button>
            </form>
        </div>

        <div class="aic <?= isset($_GET['s']) && $messages ? 'choisi' : '' ?>">
            <div class="aic-liste">
                <?php if (!$sessions): ?>
                    <div class="aic-vide">Aucune conversation pour ce filtre.</div>
                <?php endif; ?>
                <?php foreach ($sessions as $s):
                    $tel = strpos($s['id_session'], 'wa:') === 0 ? substr($s['id_session'], 3) : '';
                    $client = $clients[$tel] ?? [];
                ?>
                    <a class="<?= $s['id_session'] === $sessionChoisie ? 'actif' : '' ?>" href="<?= h($lienFiltres(['s' => $s['id_session']])) ?>">
                        <div class="aic-ligne">
                            <span class="aic-nom" dir="auto"><?= h($tel !== '' ? ($client['nom'] ?? 'Client WhatsApp') : 'Visiteur du site') ?></span>
                            <span class="aic-date"><?= h(date_contact($s['fin'])) ?></span>
                        </div>
                        <div class="aic-ligne">
                            <span class="aic-tel"><?= h($tel !== '' ? telephone_afficher($tel) : substr($s['id_session'], 0, 12)) ?></span>
                            <span>
                                <?php if (!empty($client['gros'])): ?><span class="aic-badge gros">Client gros</span><?php endif; ?>
                                <span class="aic-badge"><?= (int)$s['nb_messages'] ?> msg</span>
                                <?php if ($dev && (int)$s['nb_erreurs'] > 0): ?><span class="aic-badge err"><?= (int)$s['nb_erreurs'] ?> échec</span><?php endif; ?>
                            </span>
                        </div>
                        <div class="aic-apercu" dir="auto"><?= h($s['dernier'] ?? '') ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if ($totalPages > 1): ?>
                    <div class="aic-pages">
                        <span><?php if ($page > 1): ?><a href="<?= h($lienFiltres(['page' => $page - 1, 's' => null])) ?>">‹ Précédent</a><?php endif; ?></span>
                        <span><?= $page ?> / <?= $totalPages ?></span>
                        <span><?php if ($page < $totalPages): ?><a href="<?= h($lienFiltres(['page' => $page + 1, 's' => null])) ?>">Suivant ›</a><?php endif; ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="aic-chat">
                <?php if (!$messages): ?>
                    <div class="aic-vide">Choisissez une conversation.</div>
                <?php else: ?>
                    <div class="aic-entete">
                        <a class="aic-retour" href="<?= h($lienFiltres(['s' => null])) ?>">‹ Liste</a>
                        <?php if ($telChoisi !== ''): ?>
                            <b dir="auto"><?= h($clientChoisi['nom'] ?? 'Client WhatsApp') ?></b>
                            <a href="tel:+<?= h($telChoisi) ?>"><i class="fa-solid fa-phone"></i> <?= h(telephone_afficher($telChoisi)) ?></a>
                            <?php if (!empty($clientChoisi['gros'])): ?><span class="aic-badge gros">Client gros<?= $clientChoisi['code'] !== '' ? ' - code ' . h($clientChoisi['code']) : '' ?></span><?php endif; ?>
                        <?php else: ?>
                            <span>Session <b><?= h($sessionChoisie) ?></b></span>
                        <?php endif; ?>
                        <span style="color:#6b7684;font-size:13px">du <?= h(date('d/m/Y', strtotime($messages[0]['created_at']))) ?> au <?= h(date('d/m/Y', strtotime(end($messages)['created_at']))) ?></span>
                    </div>
                    <?php
                        $dernierUser = null;
                        $jourCourant = '';
                        foreach ($messages as $m):
                            $estErreur = $m['role'] === 'assistant' && strpos((string)$m['tools_called'], '"_error"') !== false;
                            $delai = null;
                            if ($m['role'] === 'user') {
                                $dernierUser = strtotime($m['created_at']);
                            } elseif ($dernierUser !== null) {
                                $delai = strtotime($m['created_at']) - $dernierUser;
                                $dernierUser = null;
                            }
                            $jour = date('Y-m-d', strtotime($m['created_at']));
                    ?>
                        <?php if ($jour !== $jourCourant): $jourCourant = $jour; ?>
                            <div class="aic-jour"><?= h(jour_long($m['created_at'])) ?></div>
                        <?php endif; ?>
                        <div class="aic-msg <?= $m['role'] === 'user' ? 'aic-user' : 'aic-assistant' ?> <?= $dev && $estErreur ? 'aic-erreur' : '' ?>" dir="auto">
                            <?= $m['role'] === 'user' ? nl2br(h($m['message'])) : rendre_message((string)$m['message']) ?>
                            <?php if ($dev && $m['role'] === 'assistant'): ?>
                                <?php $outils = rendre_outils($m['tools_called'], $voitures); ?>
                                <?php if ($outils !== ''): ?><div class="aic-outils"><?= $outils ?></div><?php endif; ?>
                            <?php endif; ?>
                            <div class="aic-meta">
                                <span><?= h(date('H:i', strtotime($m['created_at']))) ?></span>
                                <?php if ($dev && $delai !== null): ?>
                                    <span class="<?= $delai > 15 ? 'aic-lent' : '' ?>">réponse en <?= (int)$delai ?> s</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
