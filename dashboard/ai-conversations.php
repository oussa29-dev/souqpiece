<?php
// Read-only viewer of the customer assistant's conversations (ai_conversation):
// sessions list on the left, the selected one as a chat on the right, with
// the tools each reply used, its response time and any recorded failure.
// Admin session required, same as every dashboard page; checked before any
// output so the redirect actually happens.
session_start();
if (!isset($_SESSION['utilisateur'])) {
    header('location:connexion.php');
    exit;
}
require_once('database.php');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$periodes = ['1' => "Aujourd'hui", '7' => '7 jours', '30' => '30 jours', 'tout' => 'Tout'];
$periode = isset($_GET['periode'], $periodes[$_GET['periode']]) ? $_GET['periode'] : '7';
$recherche = trim((string)($_GET['q'] ?? ''));
$parPage = 40;
$page = max(1, (int)($_GET['page'] ?? 1));
$sessionChoisie = (string)($_GET['s'] ?? '');

$conditions = [];
$params = [];
if ($periode !== 'tout') {
    $conditions[] = 'c.created_at >= NOW() - INTERVAL ' . (int)$periode . ' DAY';
}
if ($recherche !== '') {
    $conditions[] = 'c.id_session IN (SELECT id_session FROM ai_conversation WHERE message LIKE ?)';
    $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $recherche) . '%';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id_session) FROM ai_conversation c $where");
$stmt->execute($params);
$totalSessions = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalSessions / $parPage));

$stmt = $pdo->prepare("
    SELECT c.id_session, MIN(c.created_at) AS debut, MAX(c.created_at) AS fin,
           SUM(c.role = 'user') AS nb_messages,
           SUM(c.tools_called LIKE '%\"_error\"%') AS nb_erreurs,
           (SELECT x.message FROM ai_conversation x WHERE x.id_session = c.id_session AND x.role = 'user' ORDER BY x.id LIMIT 1) AS premier
    FROM ai_conversation c
    $where
    GROUP BY c.id_session
    ORDER BY fin DESC
    LIMIT $parPage OFFSET " . (($page - 1) * $parPage));
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($sessionChoisie === '' && $sessions) {
    $sessionChoisie = $sessions[0]['id_session'];
}

$messages = [];
$voitures = [];
if ($sessionChoisie !== '') {
    $stmt = $pdo->prepare('SELECT id, role, message, tools_called, created_at FROM ai_conversation WHERE id_session = ? ORDER BY id');
    $stmt->execute([$sessionChoisie]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vehicle labels for every id_voiture the tools used, in one query.
    $ids = [];
    foreach ($messages as $m) {
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

$lienFiltres = function (array $changes) use ($periode, $recherche, $page) {
    $p = array_merge(['periode' => $periode, 'q' => $recherche, 'page' => $page], $changes);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Conversations assistant IA</title>
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <style>
        .aic{display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:18px;margin:24px;align-items:start}
        .aic-filtres{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 24px 0}
        .aic-filtres a{text-decoration:none;color:#333;background:#eee;padding:7px 12px;border-radius:8px;font-size:14px}
        .aic-filtres a.actif{background:#126CFB;color:#fff}
        .aic-filtres form{display:flex;gap:6px;margin-left:auto}
        .aic-filtres input[type=text]{padding:7px 10px;border:1px solid #bbb;border-radius:8px;min-width:220px}
        .aic-filtres button{padding:7px 12px;border:none;border-radius:8px;background:rgb(24,185,24);color:#fff;cursor:pointer}
        .aic-liste{border:1px solid #d9dde3;border-radius:10px;overflow:hidden;background:#fff}
        .aic-liste a{display:block;padding:10px 12px;border-bottom:1px solid #eef0f3;text-decoration:none;color:#222}
        .aic-liste a:hover{background:#f4f7fb}
        .aic-liste a.actif{background:#e8f0ff;border-left:4px solid #126CFB}
        .aic-liste .aic-date{font-size:12px;color:#6b7684;display:flex;justify-content:space-between;gap:6px}
        .aic-liste .aic-apercu{margin-top:4px;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .aic-badge{display:inline-block;font-size:11px;border-radius:999px;padding:1px 7px;background:#eef0f3;color:#444}
        .aic-badge.err{background:#fde2e2;color:#a00707}
        .aic-pages{display:flex;justify-content:space-between;padding:8px 12px;font-size:13px;background:#f7f8fa}
        .aic-chat{border:1px solid #d9dde3;border-radius:10px;background:#faf9f9;padding:14px;display:flex;flex-direction:column;gap:10px;min-height:300px}
        .aic-entete{font-size:13px;color:#555;border-bottom:1px solid #e3e6ea;padding-bottom:8px;margin-bottom:4px}
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
        @media (max-width:900px){.aic{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <?php include('include/menu.php'); ?>
    <div class="site">
        <div class="barre">Conversations de l'assistant IA</div>

        <div class="aic-filtres">
            <?php foreach ($periodes as $cle => $libelle): ?>
                <a class="<?= $cle === $periode ? 'actif' : '' ?>" href="<?= h($lienFiltres(['periode' => $cle, 'page' => 1, 's' => null])) ?>"><?= h($libelle) ?></a>
            <?php endforeach; ?>
            <span style="font-size:14px;color:#555"><?= $totalSessions ?> conversation(s)</span>
            <form method="get">
                <input type="hidden" name="periode" value="<?= h($periode) ?>">
                <input type="text" name="q" value="<?= h($recherche) ?>" placeholder="Chercher un mot (ex. culasse, كيلاس)" dir="auto">
                <button type="submit">Chercher</button>
            </form>
        </div>

        <div class="aic">
            <div class="aic-liste">
                <?php if (!$sessions): ?>
                    <div class="aic-vide">Aucune conversation pour ce filtre.</div>
                <?php endif; ?>
                <?php foreach ($sessions as $s): ?>
                    <a class="<?= $s['id_session'] === $sessionChoisie ? 'actif' : '' ?>" href="<?= h($lienFiltres(['s' => $s['id_session']])) ?>">
                        <div class="aic-date">
                            <span><?= h(date('d/m H:i', strtotime($s['debut']))) ?></span>
                            <span>
                                <span class="aic-badge"><?= (int)$s['nb_messages'] ?> msg</span>
                                <?php if ((int)$s['nb_erreurs'] > 0): ?><span class="aic-badge err"><?= (int)$s['nb_erreurs'] ?> échec</span><?php endif; ?>
                            </span>
                        </div>
                        <div class="aic-apercu" dir="auto"><?= h($s['premier'] ?? '') ?></div>
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
                        Session <b><?= h(substr($sessionChoisie, 0, 8)) ?></b> —
                        du <?= h(date('d/m/Y H:i', strtotime($messages[0]['created_at']))) ?>
                        au <?= h(date('d/m/Y H:i', strtotime(end($messages)['created_at']))) ?>
                    </div>
                    <?php
                        $dernierUser = null;
                        foreach ($messages as $m):
                            $estErreur = $m['role'] === 'assistant' && strpos((string)$m['tools_called'], '"_error"') !== false;
                            $delai = null;
                            if ($m['role'] === 'user') {
                                $dernierUser = strtotime($m['created_at']);
                            } elseif ($dernierUser !== null) {
                                $delai = strtotime($m['created_at']) - $dernierUser;
                                $dernierUser = null;
                            }
                    ?>
                        <div class="aic-msg <?= $m['role'] === 'user' ? 'aic-user' : 'aic-assistant' ?> <?= $estErreur ? 'aic-erreur' : '' ?>" dir="auto">
                            <?= $m['role'] === 'user' ? nl2br(h($m['message'])) : rendre_message((string)$m['message']) ?>
                            <?php if ($m['role'] === 'assistant'): ?>
                                <?php $outils = rendre_outils($m['tools_called'], $voitures); ?>
                                <?php if ($outils !== ''): ?><div class="aic-outils"><?= $outils ?></div><?php endif; ?>
                            <?php endif; ?>
                            <div class="aic-meta">
                                <span><?= h(date('H:i:s', strtotime($m['created_at']))) ?></span>
                                <?php if ($delai !== null): ?>
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
