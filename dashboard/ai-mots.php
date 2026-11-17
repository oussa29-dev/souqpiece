<?php
// Teach the assistant words (table ai_mot_appris): a customer's word -> the
// word the catalog writes, for parts and for vehicles. Read by
// ai_mots_appris() in ai/tools.php on top of the built-in dictionary of
// ai/search_aliases.php. A word whose catalog equivalent finds nothing is
// refused: a typo would otherwise send every search for it nowhere. Login
// checked before any output; changes go through POST with a per-session
// token.
session_start();
if (!isset($_SESSION['utilisateur'])) {
    header('location:connexion.php');
    exit;
}
require_once('database.php');
require_once(__DIR__ . '/../ai/tools.php');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['jeton_ai_mots'])) {
    $_SESSION['jeton_ai_mots'] = bin2hex(random_bytes(16));
}
$jeton = $_SESSION['jeton_ai_mots'];

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function like($s)
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

// What a catalog word finds: number of priced products whose name contains
// it, or the vehicles whose brand + model contain it.
function am_produits(PDO $pdo, string $equivalent): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM produit WHERE prix > 0 AND libelle LIKE ?');
    $stmt->execute([like($equivalent)]);
    return (int)$stmt->fetchColumn();
}

function am_voitures(PDO $pdo, string $equivalent): array
{
    $stmt = $pdo->prepare("SELECT CONCAT(m.libelle, ' ', v.modele) FROM voiture v JOIN marque m ON m.id_marque = v.id_marque
                           WHERE CONCAT(m.libelle, ' ', v.modele) LIKE ? ORDER BY m.libelle, v.modele");
    $stmt->execute([like($equivalent)]);
    return array_map(fn($v) => preg_replace('/\s+/', ' ', trim($v)), $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$tableExiste = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_mot_appris'")->fetchColumn();

$erreurs = [];
$saisie = ['type' => 'piece', 'mot' => '', 'equivalent' => ''];

if ($tableExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($jeton, (string)($_POST['jeton'] ?? ''))) {
        $erreurs[] = 'Session expirée, rechargez la page et recommencez.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'supprimer' && $id > 0) {
            $pdo->prepare('DELETE FROM ai_mot_appris WHERE id_mot = ?')->execute([$id]);
            header('location:ai-mots.php?msg=supprime');
            exit;
        }
        if ($action === 'ajouter') {
            $saisie = [
                'type' => ($_POST['type'] ?? '') === 'voiture' ? 'voiture' : 'piece',
                'mot' => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)($_POST['mot'] ?? '')))),
                'equivalent' => mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($_POST['equivalent'] ?? '')))),
            ];
            if ($saisie['mot'] === '' || mb_strlen($saisie['mot']) > 60) {
                $erreurs[] = 'Écrivez le mot du client (60 caractères maximum).';
            } elseif (strpos($saisie['mot'], ' ') !== false) {
                $erreurs[] = 'Le mot du client doit être un seul mot, sans espace : l\'IA lit les mots un par un.';
            }
            if ($saisie['equivalent'] === '' || mb_strlen($saisie['equivalent']) > 100) {
                $erreurs[] = 'Écrivez le mot du catalogue (100 caractères maximum).';
            }
            if (!$erreurs && ai_cle_mot_appris($saisie['mot']) === ai_cle_mot_appris($saisie['equivalent'])) {
                $erreurs[] = 'Les deux mots sont identiques : l\'IA le trouve déjà.';
            }
            if (!$erreurs) {
                $trouve = $saisie['type'] === 'voiture'
                    ? count(am_voitures($pdo, $saisie['equivalent']))
                    : am_produits($pdo, $saisie['equivalent']);
                if ($trouve === 0) {
                    $erreurs[] = $saisie['type'] === 'voiture'
                        ? '« ' . $saisie['equivalent'] . ' » ne correspond à aucune voiture (marque ou modèle). Vérifiez l\'orthographe, comme dans le menu Voiture.'
                        : '« ' . $saisie['equivalent'] . ' » ne se trouve dans le nom d\'aucun produit. Vérifiez l\'orthographe, comme elle est écrite dans le catalogue.';
                }
            }
            if (!$erreurs) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_mot_appris WHERE mot = ? AND equivalent = ?');
                $stmt->execute([$saisie['mot'], $saisie['equivalent']]);
                if ($stmt->fetchColumn()) {
                    $erreurs[] = 'Ce mot est déjà enregistré.';
                }
            }
            if (!$erreurs) {
                $pdo->prepare('INSERT INTO ai_mot_appris (type, mot, equivalent) VALUES (?, ?, ?)')
                    ->execute([$saisie['type'], $saisie['mot'], $saisie['equivalent']]);
                header('location:ai-mots.php?msg=ajoute');
                exit;
            }
        }
    }
}

$mots = $tableExiste ? $pdo->query('SELECT * FROM ai_mot_appris ORDER BY type, mot, equivalent')->fetchAll(PDO::FETCH_ASSOC) : [];

// Try a customer's sentence: what the assistant's product search returns.
$essai = trim((string)($_GET['essai'] ?? ''));
$resultatsEssai = $essai !== '' ? ai_search_products($pdo, mb_substr($essai, 0, 200), null, null, 10) : [];

$messages = ['ajoute' => 'Mot ajouté. L\'IA le comprend dès maintenant.', 'supprime' => 'Mot supprimé.'];
$message = $messages[$_GET['msg'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Apprendre à l'IA</title>
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <style>
        .am-aide{color:#555;font-size:14px;max-width:80ch;margin:0 0 16px;line-height:1.5}
        .am-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:10px}
        .am-form label{display:flex;flex-direction:column;gap:4px;font-size:14px}
        .page-voiture .am-form input[type=text],.page-voiture .am-form select{margin:0;width:220px;height:36px}
        .page-voiture .am-form input[type=submit]{margin:0;width:auto;padding:0 18px}
        .am-fleche{align-self:center;font-size:20px;color:#888;padding-top:18px}
        .am-msg{padding:10px 14px;border-radius:8px;margin:10px 0;font-size:14px}
        .am-ok{background:#e7f6e7;color:#1d6b1d}
        .am-err{background:#fde2e2;color:#a00707}
        .am-err ul{margin:0;padding-left:18px}
        .am-table{width:100%;margin-top:12px}
        .am-table td{height:auto;width:auto}
        .am-type{border-radius:999px;padding:3px 10px;font-size:13px;color:#fff;white-space:nowrap}
        .am-type.piece{background:#2f6fb3}
        .am-type.voiture{background:#7a4fb3}
        .am-trouve{font-size:13px;color:#555}
        .am-zero{color:#a00707;font-weight:bold}
        .page-voiture .am-table input[type=submit]{margin:0;width:auto;height:auto;padding:4px 8px;font-size:14px;background:rgb(160,7,7);border-radius:4px}
        .am-essai{display:flex;gap:8px;align-items:center;margin:8px 0}
        .page-voiture .am-essai input[type=text]{margin:0;width:320px}
        .page-voiture .am-essai input[type=submit]{margin:0;width:auto;padding:0 14px}
        .am-resultats{margin:6px 0 0;padding-left:20px;font-size:14px;line-height:1.7}
        .am-dispo{color:rgb(24,140,24)}
        .am-nondispo{color:#888}
        .am-section{margin-top:30px}
        details.am-connus{margin-top:30px;font-size:14px}
        details.am-connus summary{cursor:pointer;font-weight:bold}
        .am-connus-tables{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-top:12px}
        .am-connus-tables .am-table{width:auto;min-width:320px;flex:1;margin-top:0}
        .am-connus-tables td{text-align:left}
        .am-connus-tables td.am-ar{text-align:right;font-size:15px}
    </style>
</head>
<body>
    <?php include('include/menu.php'); ?>
    <div class="site">
        <div class="barre">Apprendre à l'IA</div>
        <div class="page-voiture">
            <p class="am-aide">Quand un client écrit un mot que l'IA ne comprend pas, ajoutez-le ici avec le mot <b>tel qu'il est écrit dans le catalogue</b>.
                Exemples : pièce <b>شان</b> → <b>CHAIN</b>, voiture <b>تيسيس</b> → <b>T6</b>.
                Un mot peut avoir plusieurs équivalents : ajoutez-le une fois pour chacun. L'IA l'utilise sur le site et sur WhatsApp dès l'ajout.</p>

            <?php if (!$tableExiste): ?>
                <div class="am-msg am-err">La table ai_mot_appris n'existe pas encore : lancer <code>php dashboard/ai_mot_appris_migration.php --apply</code> sur le serveur.</div>
            <?php else: ?>

            <?php if ($message): ?><div class="am-msg am-ok"><?= h($message) ?></div><?php endif; ?>
            <?php if ($erreurs): ?>
                <div class="am-msg am-err"><ul><?php foreach ($erreurs as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <h2>Ajouter un mot</h2>
            <form method="POST" class="am-form" action="ai-mots.php">
                <input type="hidden" name="jeton" value="<?= h($jeton) ?>">
                <input type="hidden" name="action" value="ajouter">
                <label>Type
                    <select name="type">
                        <option value="piece" <?= $saisie['type'] === 'piece' ? 'selected' : '' ?>>Pièce</option>
                        <option value="voiture" <?= $saisie['type'] === 'voiture' ? 'selected' : '' ?>>Voiture</option>
                    </select>
                </label>
                <label>Mot du client
                    <input type="text" name="mot" value="<?= h($saisie['mot']) ?>" maxlength="60" required dir="auto" placeholder="ex. شان">
                </label>
                <span class="am-fleche">→</span>
                <label>Mot du catalogue
                    <input type="text" name="equivalent" value="<?= h($saisie['equivalent']) ?>" maxlength="100" required placeholder="ex. CHAIN">
                </label>
                <input type="submit" value="Ajouter">
            </form>

            <h2 class="am-section">Mots appris (<?= count($mots) ?>)</h2>
            <table class="am-table">
                <tr><th>Type</th><th>Mot du client</th><th>Mot du catalogue</th><th>Trouve</th><th>Ajouté le</th><th></th></tr>
                <?php if (!$mots): ?>
                    <tr><td colspan="6">Aucun mot ajouté pour l'instant.</td></tr>
                <?php endif; ?>
                <?php foreach ($mots as $m):
                    if ($m['type'] === 'voiture') {
                        $voitures = am_voitures($pdo, $m['equivalent']);
                        $n = count($voitures);
                        $detail = $n . ' voiture(s)' . ($n ? ' : ' . implode(', ', array_slice($voitures, 0, 3)) . ($n > 3 ? '…' : '') : '');
                    } else {
                        $n = am_produits($pdo, $m['equivalent']);
                        $detail = $n . ' produit(s)';
                    }
                ?>
                    <tr>
                        <td><span class="am-type <?= h($m['type']) ?>"><?= $m['type'] === 'voiture' ? 'Voiture' : 'Pièce' ?></span></td>
                        <td dir="auto"><?= h($m['mot']) ?></td>
                        <td><?= h($m['equivalent']) ?></td>
                        <td class="am-trouve <?= $n ? '' : 'am-zero' ?>"><?= h($detail) ?></td>
                        <td class="am-trouve"><?= h(date('d/m/Y', strtotime($m['cree_le']))) ?></td>
                        <td>
                            <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer « <?= h(addslashes($m['mot'])) ?> → <?= h(addslashes($m['equivalent'])) ?> » ?');">
                                <input type="hidden" name="jeton" value="<?= h($jeton) ?>">
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= (int)$m['id_mot'] ?>">
                                <input type="submit" value="Sup">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <h2 class="am-section">Essayer</h2>
            <p class="am-aide">Écrivez une demande comme un client (pièce et voiture) pour voir ce que la recherche de l'IA trouve.</p>
            <form method="GET" class="am-essai">
                <input type="text" name="essai" value="<?= h($essai) ?>" placeholder="ex. شان hilux" dir="auto">
                <input type="submit" value="Essayer">
            </form>
            <?php if ($essai !== ''): ?>
                <?php if (!$resultatsEssai): ?>
                    <p class="am-trouve am-zero">Aucun produit trouvé.</p>
                <?php else: ?>
                    <ol class="am-resultats">
                        <?php foreach ($resultatsEssai as $p): ?>
                            <li><?= h(trim($p['libelle'])) ?><?= !empty($p['marquepiece']) ? ' - ' . h($p['marquepiece']) : '' ?>
                                - <?= (int)$p['prix'] ?> DA
                                - <span class="<?= $p['disponible'] ? 'am-dispo' : 'am-nondispo' ?>"><?= $p['disponible'] ? 'disponible' : 'non disponible' ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endif; ?>

            <details class="am-connus" open>
                <summary>Mots que l'IA connaît déjà (inutile de les ajouter)</summary>
                <?php
                // Arabic words grouped by the catalog word they mean.
                $parCatalogue = [];
                foreach (ai_search_arabic_aliases() as $mot => $equivalents) {
                    $parCatalogue[mb_strtoupper(implode(', ', $equivalents))][] = $mot;
                }
                ?>
                <div class="am-connus-tables">
                    <table class="am-table">
                        <tr><th>Mot du catalogue</th><th>Mots du client</th></tr>
                        <?php foreach ($parCatalogue as $catalogue => $motsClient): ?>
                            <tr><td><?= h($catalogue) ?></td><td dir="rtl" class="am-ar"><?= h(implode('  ·  ', $motsClient)) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                    <table class="am-table">
                        <tr><th>Orthographes équivalentes</th></tr>
                        <?php foreach (ai_search_synonym_groups() as $groupe): ?>
                            <tr><td><?= h(mb_strtoupper(implode(' = ', $groupe))) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </details>
        </div>
    </div>
</body>
</html>
