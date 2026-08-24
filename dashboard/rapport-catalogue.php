<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Dashboard Rapport catalogue</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <style>
        .rc-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:10px 1px 20px}
        .rc-tab{text-decoration:none;color:#333;background:#eee;padding:8px 14px;border-radius:8px;font-size:14px}
        .rc-tab b{font-variant-numeric:tabular-nums}
        .rc-tab.active{background-color:rgb(24,185,24);color:#fff}
        .rc-desc{margin:0 1px 18px;color:#555;font-size:14px;max-width:70ch}
        .rc-bulk-bar{display:flex;align-items:center;gap:14px;margin:0 1px 12px}
        .rc-bulk-bar label{display:flex;align-items:center;gap:6px;font-size:14px;color:#333}
        .rc-bulk-btn{border:none;cursor:pointer;text-decoration:none;color:#fff;background-color:#c0392b;padding:8px 14px;border-radius:8px;font-size:14px;font-family:inherit}
        .rc-bulk-btn:disabled{background-color:#ccc;cursor:not-allowed}
        .rc-checkbox-col{width:32px;text-align:center}
    </style>
</head>
<body>
    <?php
        session_start();
        if (!isset($_SESSION['utilisateur'])) {
            header('location:connexion.php');
            exit;
        }
        require_once('database.php');
        include('include/menu.php');

        // Read-only report over the audit's "catalogue completion" findings
        // (N4). Each view surfaces one specific gap and links straight into
        // the existing produit edit form - no new write path introduced.
        $vues = [
            'sans_vehicule' => [
                'label' => 'Sans véhicule',
                'desc'  => 'Produits disponibles avec un prix réel mais aucune ligne dans pvd : payés, en stock, mais invisibles à toute navigation par voiture.',
                'count' => "SELECT COUNT(*) FROM produit p WHERE p.prix > 0 AND p.stock = 1 AND NOT EXISTS (SELECT 1 FROM pvd WHERE pvd.id_produit = p.id_produit)",
                'list'  => "SELECT p.* FROM produit p WHERE p.prix > 0 AND p.stock = 1 AND NOT EXISTS (SELECT 1 FROM pvd WHERE pvd.id_produit = p.id_produit) ORDER BY p.id_produit DESC LIMIT ? OFFSET ?",
            ],
            'sans_categorie' => [
                'label' => 'Sans catégorie',
                'desc'  => 'Produits disponibles avec id_categorie = 0 : absents de toute navigation par catégorie.',
                'count' => "SELECT COUNT(*) FROM produit WHERE id_categorie = 0 AND stock = 1",
                'list'  => "SELECT * FROM produit WHERE id_categorie = 0 AND stock = 1 ORDER BY id_produit DESC LIMIT ? OFFSET ?",
            ],
            'doublons' => [
                'label' => 'Doublons probables',
                'desc'  => 'Produits disponibles, même libellé, même marque, même prix. Regroupés ci-dessous - à arbitrer manuellement (fusionner, différencier, ou supprimer).',
                'count' => "SELECT SUM(c) FROM (SELECT COUNT(*) c FROM produit WHERE stock = 1 GROUP BY libelle, marquepiece, prix HAVING COUNT(*) > 1) t",
                'list'  => "SELECT p.* FROM produit p INNER JOIN (SELECT libelle, marquepiece, prix FROM produit WHERE stock = 1 GROUP BY libelle, marquepiece, prix HAVING COUNT(*) > 1) d ON p.libelle = d.libelle AND p.marquepiece = d.marquepiece AND p.prix = d.prix WHERE p.stock = 1 ORDER BY p.libelle, p.marquepiece, p.prix, p.id_produit LIMIT ? OFFSET ?",
            ],
            'prix' => [
                'label' => 'Prix douteux',
                'desc'  => 'Produits disponibles avec un prix à 0, à 1 DA, ou sous 100 DA : valeurs manifestement provisoires.',
                'count' => "SELECT COUNT(*) FROM produit WHERE (prix = 0 OR prix = 1 OR (prix > 1 AND prix < 100)) AND stock = 1",
                'list'  => "SELECT * FROM produit WHERE (prix = 0 OR prix = 1 OR (prix > 1 AND prix < 100)) AND stock = 1 ORDER BY prix ASC, id_produit DESC LIMIT ? OFFSET ?",
            ],
            'image' => [
                'label' => 'Sans image',
                'desc'  => 'Produits disponibles sans image principale (img1 vide) : fiche produit sans visuel côté client.',
                'count' => "SELECT COUNT(*) FROM produit WHERE (img1 IS NULL OR img1 = '') AND stock = 1",
                'list'  => "SELECT * FROM produit WHERE (img1 IS NULL OR img1 = '') AND stock = 1 ORDER BY id_produit DESC LIMIT ? OFFSET ?",
            ],
        ];

        $vue = isset($_GET['vue']) && isset($vues[$_GET['vue']]) ? $_GET['vue'] : 'sans_vehicule';
        $courant = $vues[$vue];

        $itemsPerPage = 30;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $itemsPerPage;

        $counts = [];
        foreach ($vues as $key => $def) {
            $counts[$key] = (int)$pdo->query($def['count'])->fetchColumn();
        }

        $stmt = $pdo->prepare($courant['list']);
        $stmt->bindValue(1, $itemsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalItems = $counts[$vue];
        $totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
    ?>

    <div class="site">
        <div class="barre">Rapport catalogue</div>
        <div class="page-produit">
            <h3>Complétion du catalogue</h3>
            <p class="rc-desc">Constats mesurés lors de l'audit du 17/08 (N4 du plan d'assainissement) - travail de saisie, aucune correction automatique n'est appliquée ici. Cliquer "Mod" ouvre la fiche produit existante.</p>

            <div class="rc-tabs">
                <?php foreach ($vues as $key => $def): ?>
                    <a class="rc-tab<?= $key === $vue ? ' active' : '' ?>" href="?vue=<?= $key ?>"><?= $def['label'] ?> (<b><?= number_format($counts[$key], 0, ',', ' ') ?></b>)</a>
                <?php endforeach; ?>
            </div>

            <p class="rc-desc"><?= $courant['desc'] ?></p>

            <form method="POST" action="supprimer/sup-produits-masse.php" id="rc-bulk-form">
                <input type="hidden" name="vue" value="<?= htmlspecialchars($vue) ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">

                <div class="rc-bulk-bar">
                    <label><input type="checkbox" id="rc-select-all"> Tout sélectionner (page courante)</label>
                    <button type="submit" id="rc-bulk-btn" class="rc-bulk-btn" disabled>Supprimer la sélection</button>
                </div>

            <table>
                <thead>
                    <tr>
                        <th class="rc-checkbox-col"></th>
                        <th>image</th>
                        <th>Produit</th>
                        <th>Prix</th>
                        <th>marque</th>
                        <th>modele</th>
                        <th>categorie</th>
                        <th>sous categorie</th>
                        <th>stock</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                    if (empty($produits)) {
                        echo '<tr><td colspan="10">Rien à afficher pour cette vue.</td></tr>';
                    } else {
                        foreach ($produits as $produit) {
                            $sqlPvdVoiture = $pdo->prepare('SELECT id_voiture FROM pvd WHERE id_produit = ? LIMIT 1');
                            $sqlPvdVoiture->execute([$produit['id_produit']]);
                            $idVoiture = $sqlPvdVoiture->fetchColumn();

                            $marque = '—';
                            $voiture = '—';
                            if ($idVoiture) {
                                $sqlVoiture = $pdo->prepare('SELECT modele, id_marque FROM voiture WHERE id_voiture = ?');
                                $sqlVoiture->execute([$idVoiture]);
                                $rowVoiture = $sqlVoiture->fetch(PDO::FETCH_ASSOC);
                                if ($rowVoiture) {
                                    $voiture = $rowVoiture['modele'];
                                    $sqlMarque = $pdo->prepare('SELECT libelle FROM marque WHERE id_marque = ?');
                                    $sqlMarque->execute([$rowVoiture['id_marque']]);
                                    $marque = $sqlMarque->fetchColumn() ?: '—';
                                }
                            }

                            $categorie = '—';
                            if ($produit['id_categorie'] != 0) {
                                $sqlCate = $pdo->prepare('SELECT libelle FROM categorie WHERE id_categorie = ?');
                                $sqlCate->execute([$produit['id_categorie']]);
                                $categorie = $sqlCate->fetchColumn() ?: '—';
                            }

                            $sous = '—';
                            if ($produit['id_sous_categorie'] != 0) {
                                $sqlSous = $pdo->prepare('SELECT libelle FROM sous_categorie WHERE id_sous_categorie = ?');
                                $sqlSous->execute([$produit['id_sous_categorie']]);
                                $sous = $sqlSous->fetchColumn() ?: '—';
                            }
                ?>
                        <tr>
                            <td class="rc-checkbox-col"><input type="checkbox" class="rc-row-check" name="ids[]" value="<?= (int)$produit['id_produit'] ?>"></td>
                            <td><img src="../img/produit/<?= htmlspecialchars($produit['img1']) ?>"></td>
                            <td><?= htmlspecialchars($produit['libelle']) ?></td>
                            <td><span class="spanGreen"><?= (int)$produit['prix'] ?> DA</span></td>
                            <td><?= htmlspecialchars($marque) ?></td>
                            <td><?= htmlspecialchars($voiture) ?></td>
                            <td><?= htmlspecialchars($categorie) ?></td>
                            <td><?= htmlspecialchars($sous) ?></td>
                            <td><span class="spanOrange"><?= $produit['stock'] == 1 ? 'disponible' : 'non disponible' ?></span></td>
                            <td><a href="ajouter-produit.php?id=<?= (int)$produit['id_produit'] ?>" class="btn-mod">Mod</a></td>
                            <td><a href="supprimer/sup-produit.php?id=<?= (int)$produit['id_produit'] ?>&vue=<?= urlencode($vue) ?>&page=<?= $page ?>" class="btn-sup" onclick="return confirm('Supprimer ce produit ?');">Sup</a></td>
                        </tr>
                <?php
                        }
                    }
                ?>
                </tbody>
            </table>
            </form>

            <?php
                $baseUrl = $_SERVER['PHP_SELF'] . '?vue=' . urlencode($vue) . '&';

                echo '<div class="pagination">';
                if ($page > 1) {
                    echo '<a href="' . $baseUrl . 'page=1">1</a>';
                }
                if ($page > 3) {
                    echo '<span class="dots">...</span>';
                }
                $start = max(2, $page - 1);
                $end = min($totalPages - 1, $page + 1);
                for ($i = $start; $i <= $end; $i++) {
                    if ($i == $page) {
                        echo '<span class="current-page">' . $i . '</span>';
                    } else {
                        echo '<a href="' . $baseUrl . 'page=' . $i . '">' . $i . '</a>';
                    }
                }
                if ($page < $totalPages - 2) {
                    echo '<span class="dots">...</span>';
                }
                if ($page < $totalPages) {
                    echo '<a href="' . $baseUrl . 'page=' . $totalPages . '">' . $totalPages . '</a>';
                }
                if ($page < $totalPages) {
                    echo '<a href="' . $baseUrl . 'page=' . ($page + 1) . '">Suivant</a>';
                }
                echo '</div>';
            ?>
        </div>
    </div>

    <script>
        (function () {
            var selectAll = document.getElementById('rc-select-all');
            var bulkBtn = document.getElementById('rc-bulk-btn');
            var form = document.getElementById('rc-bulk-form');
            var rowChecks = Array.prototype.slice.call(document.getElementsByClassName('rc-row-check'));

            function refreshButton() {
                var n = rowChecks.filter(function (c) { return c.checked; }).length;
                bulkBtn.disabled = n === 0;
                bulkBtn.textContent = n === 0 ? 'Supprimer la sélection' : 'Supprimer la sélection (' + n + ')';
                selectAll.checked = n > 0 && n === rowChecks.length;
            }

            selectAll.addEventListener('change', function () {
                rowChecks.forEach(function (c) { c.checked = selectAll.checked; });
                refreshButton();
            });

            rowChecks.forEach(function (c) { c.addEventListener('change', refreshButton); });

            form.addEventListener('submit', function (e) {
                var n = rowChecks.filter(function (c) { return c.checked; }).length;
                if (n === 0 || !confirm('Supprimer ' + n + ' produit(s) sélectionné(s) ? Cette action est irréversible.')) {
                    e.preventDefault();
                }
            });

            refreshButton();
        })();
    </script>
</body>
</html>
