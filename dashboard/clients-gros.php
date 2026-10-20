<?php
// Wholesale customers (client_gros): list, search, add, edit, enable or
// disable, delete. The WhatsApp assistant will give the wholesale price to
// the active numbers listed here. Login checked before any output so the
// redirect actually happens; every change goes through POST with a
// per-session token.
session_start();
if (!isset($_SESSION['utilisateur'])) {
    header('location:connexion.php');
    exit;
}
require_once('database.php');
require_once('include/telephone.php');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['jeton_clients_gros'])) {
    $_SESSION['jeton_clients_gros'] = bin2hex(random_bytes(16));
}
$jeton = $_SESSION['jeton_clients_gros'];

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$erreurs = [];
$saisie = ['nom' => '', 'telephone' => '', 'code_client' => ''];
$idModifie = isset($_GET['modifier']) ? (int)$_GET['modifier'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($jeton, (string)($_POST['jeton'] ?? ''))) {
        $erreurs[] = 'Session expirée, rechargez la page et recommencez.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'supprimer' && $id > 0) {
            $pdo->prepare('DELETE FROM client_gros WHERE id_client_gros = ?')->execute([$id]);
            header('location:clients-gros.php?msg=supprime');
            exit;
        }
        if ($action === 'basculer' && $id > 0) {
            $pdo->prepare('UPDATE client_gros SET actif = 1 - actif WHERE id_client_gros = ?')->execute([$id]);
            header('location:clients-gros.php?msg=statut');
            exit;
        }
        if ($action === 'ajouter' || ($action === 'modifier' && $id > 0)) {
            $saisie = [
                'nom' => trim((string)($_POST['nom'] ?? '')),
                'telephone' => trim((string)($_POST['telephone'] ?? '')),
                'code_client' => trim((string)($_POST['code_client'] ?? '')),
            ];
            $telephone = telephone_normaliser($saisie['telephone']);
            if ($saisie['nom'] === '' || mb_strlen($saisie['nom']) > 150) {
                $erreurs[] = 'Le nom est obligatoire (150 caractères maximum).';
            }
            if ($telephone === null) {
                $erreurs[] = 'Numéro WhatsApp invalide. Exemples acceptés : 0550 12 34 56, +213 550 12 34 56.';
            }
            if (mb_strlen($saisie['code_client']) > 50) {
                $erreurs[] = 'Le code client ne doit pas dépasser 50 caractères.';
            }
            if ($telephone !== null) {
                $stmt = $pdo->prepare('SELECT nom FROM client_gros WHERE telephone = ? AND id_client_gros <> ?');
                $stmt->execute([$telephone, $id]);
                if ($autre = $stmt->fetchColumn()) {
                    $erreurs[] = 'Ce numéro est déjà enregistré pour « ' . $autre . ' ».';
                }
            }
            if (!$erreurs) {
                $code = $saisie['code_client'] !== '' ? $saisie['code_client'] : null;
                if ($action === 'ajouter') {
                    $pdo->prepare('INSERT INTO client_gros (nom, telephone, code_client) VALUES (?, ?, ?)')
                        ->execute([$saisie['nom'], $telephone, $code]);
                    header('location:clients-gros.php?msg=ajoute');
                } else {
                    $pdo->prepare('UPDATE client_gros SET nom = ?, telephone = ?, code_client = ? WHERE id_client_gros = ?')
                        ->execute([$saisie['nom'], $telephone, $code, $id]);
                    header('location:clients-gros.php?msg=modifie');
                }
                exit;
            }
            if ($action === 'modifier') {
                $idModifie = $id;
            }
        }
    }
}

// Edit form prefilled from the database (unless a failed POST is redisplayed).
if ($idModifie > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM client_gros WHERE id_client_gros = ?');
    $stmt->execute([$idModifie]);
    if ($client = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $saisie = [
            'nom' => $client['nom'],
            'telephone' => telephone_afficher($client['telephone']),
            'code_client' => (string)$client['code_client'],
        ];
    } else {
        $idModifie = 0;
    }
}

$recherche = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT * FROM client_gros';
$params = [];
if ($recherche !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $recherche) . '%';
    $chiffres = preg_replace('/\D/', '', $recherche);
    $sql .= ' WHERE nom LIKE ? OR code_client LIKE ?' . ($chiffres !== '' ? ' OR telephone LIKE ?' : '');
    $params = [$like, $like];
    if ($chiffres !== '') {
        // "0550..." is stored as "213550...": match on the digits after the 0.
        $params[] = '%' . ltrim($chiffres, '0') . '%';
    }
}
$sql .= ' ORDER BY actif DESC, nom ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = (int)$pdo->query('SELECT COUNT(*) FROM client_gros')->fetchColumn();

$messages = [
    'ajoute' => 'Client ajouté.',
    'modifie' => 'Client modifié.',
    'supprime' => 'Client supprimé.',
    'statut' => 'Statut modifié.',
];
$message = $messages[$_GET['msg'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Clients gros</title>
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <style>
        .cg-aide{color:#555;font-size:14px;max-width:80ch;margin:0 0 16px}
        .cg-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:10px}
        .cg-form label{display:flex;flex-direction:column;gap:4px;font-size:14px}
        .page-voiture .cg-form input[type=text]{margin:0;width:220px}
        .page-voiture .cg-form input[type=submit]{margin:0;width:auto;padding:0 18px}
        .cg-annuler{font-size:14px;align-self:center}
        .cg-msg{padding:10px 14px;border-radius:8px;margin:10px 0;font-size:14px}
        .cg-ok{background:#e7f6e7;color:#1d6b1d}
        .cg-err{background:#fde2e2;color:#a00707}
        .cg-err ul{margin:0;padding-left:18px}
        .cg-recherche{display:flex;gap:8px;align-items:center;margin:22px 0 4px}
        .page-voiture .cg-recherche input[type=text]{margin:0;width:260px}
        .page-voiture .cg-recherche input[type=submit]{margin:0;width:auto;padding:0 14px}
        .cg-table{width:100%;margin-top:12px}
        .cg-table td{height:auto;width:auto}
        .cg-inactif td{opacity:.55}
        .cg-statut{border:none;border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer;color:#fff}
        .cg-statut.on{background:rgb(24,185,24)}
        .cg-statut.off{background:#888}
        .cg-actions{display:flex;gap:6px;justify-content:center;align-items:center}
        .cg-actions form{margin:0}
        .page-voiture .cg-actions input[type=submit]{margin:0;width:auto;height:auto;padding:4px 8px;font-size:14px;background:rgb(160,7,7);border-radius:4px}
    </style>
</head>
<body>
    <?php include('include/menu.php'); ?>
    <div class="site">
        <div class="barre">Clients gros</div>
        <div class="page-voiture">
            <p class="cg-aide">Les clients de cette liste, s'ils sont <b>actifs</b>, verront le <b>prix de gros</b> quand ils écrivent à l'assistant sur WhatsApp, depuis le numéro enregistré ici. Le code client est celui de votre logiciel (il servira pour le bon de commande).</p>

            <?php if ($message): ?><div class="cg-msg cg-ok"><?= h($message) ?></div><?php endif; ?>
            <?php if ($erreurs): ?>
                <div class="cg-msg cg-err"><ul><?php foreach ($erreurs as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <h2><?= $idModifie > 0 ? 'Modifier un client' : 'Ajouter un client' ?></h2>
            <form method="POST" class="cg-form" action="clients-gros.php<?= $idModifie > 0 ? '?modifier=' . $idModifie : '' ?>">
                <input type="hidden" name="jeton" value="<?= h($jeton) ?>">
                <input type="hidden" name="action" value="<?= $idModifie > 0 ? 'modifier' : 'ajouter' ?>">
                <?php if ($idModifie > 0): ?><input type="hidden" name="id" value="<?= $idModifie ?>"><?php endif; ?>
                <label>Nom
                    <input type="text" name="nom" value="<?= h($saisie['nom']) ?>" maxlength="150" required dir="auto">
                </label>
                <label>Numéro WhatsApp
                    <input type="text" name="telephone" value="<?= h($saisie['telephone']) ?>" placeholder="0550 12 34 56" required>
                </label>
                <label>Code client (logiciel)
                    <input type="text" name="code_client" value="<?= h($saisie['code_client']) ?>" maxlength="50">
                </label>
                <input type="submit" value="<?= $idModifie > 0 ? 'Enregistrer' : 'Ajouter' ?>">
                <?php if ($idModifie > 0): ?><a class="cg-annuler" href="clients-gros.php">Annuler</a><?php endif; ?>
            </form>

            <form method="GET" class="cg-recherche">
                <input type="text" name="q" value="<?= h($recherche) ?>" placeholder="Nom, numéro ou code" dir="auto">
                <input type="submit" value="Chercher">
                <?php if ($recherche !== ''): ?><a href="clients-gros.php">Tout afficher</a><?php endif; ?>
                <span style="font-size:14px;color:#555"><?= count($clients) ?> affiché(s) sur <?= $total ?></span>
            </form>

            <table class="cg-table">
                <tr><th>Nom</th><th>WhatsApp</th><th>Code client</th><th>Statut</th><th>Actions</th></tr>
                <?php if (!$clients): ?>
                    <tr><td colspan="5"><?= $recherche !== '' ? 'Aucun client ne correspond.' : 'Aucun client gros enregistré.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($clients as $c): ?>
                    <tr class="<?= $c['actif'] ? '' : 'cg-inactif' ?>">
                        <td dir="auto"><?= h($c['nom']) ?></td>
                        <td><?= h(telephone_afficher($c['telephone'])) ?></td>
                        <td><?= h($c['code_client'] ?? '') ?></td>
                        <td>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="jeton" value="<?= h($jeton) ?>">
                                <input type="hidden" name="action" value="basculer">
                                <input type="hidden" name="id" value="<?= (int)$c['id_client_gros'] ?>">
                                <button type="submit" class="cg-statut <?= $c['actif'] ? 'on' : 'off' ?>" title="Cliquer pour <?= $c['actif'] ? 'désactiver' : 'activer' ?>"><?= $c['actif'] ? 'Actif' : 'Inactif' ?></button>
                            </form>
                        </td>
                        <td>
                            <div class="cg-actions">
                                <a class="btn-mod" href="clients-gros.php?modifier=<?= (int)$c['id_client_gros'] ?>">Mod</a>
                                <form method="POST" onsubmit="return confirm('Supprimer le client « <?= h(addslashes($c['nom'])) ?> » ?');">
                                    <input type="hidden" name="jeton" value="<?= h($jeton) ?>">
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id" value="<?= (int)$c['id_client_gros'] ?>">
                                    <input type="submit" value="Sup">
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</body>
</html>
