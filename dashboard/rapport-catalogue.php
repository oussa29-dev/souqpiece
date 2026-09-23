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
        .rc-groupe-a{background-color:#fff}
        .rc-groupe-b{background-color:#f4f6f8}
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
            'sans_reference' => [
                'label' => 'Sans référence',
                'desc'  => "Produits disponibles sans aucune ligne dans `reference` : un import stock (voir dashboard/stock.php) ne peut jamais les retrouver dans le fichier du fournisseur, ils sont donc exclus de la réconciliation automatique et doivent être vérifiés à la main - ajouter la référence manquante, ou marquer non disponible si l'article n'existe plus.",
                'count' => "SELECT COUNT(*) FROM produit p WHERE p.stock = 1 AND NOT EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit)",
                'list'  => "SELECT p.* FROM produit p WHERE p.stock = 1 AND NOT EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit) ORDER BY p.id_produit DESC LIMIT ? OFFSET ?",
            ],
            // Fiable seulement a partir du premier import "Stock complet"
            // lance apres l'ajout de derniere_verification_stock (voir
            // db/produit_derniere_verification_stock.sql) - avant ca la
            // colonne est NULL pour tout le catalogue et cet onglet
            // n'a aucun sens.
            'produits_fantomes' => [
                'label' => 'Produits fantômes',
                'desc'  => "Produits disponibles, avec une référence enregistrée, mais que le dernier import « Stock complet » n'a jamais retrouvée dans le fichier du fournisseur - probablement absents du logiciel de gestion du stock. Différent de « Sans référence » : ici la référence existe, elle n'a simplement jamais été confirmée.",
                'count' => "SELECT COUNT(*) FROM produit p WHERE p.stock = 1 AND p.derniere_verification_stock IS NULL AND EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit)",
                'list'  => "SELECT p.* FROM produit p WHERE p.stock = 1 AND p.derniere_verification_stock IS NULL AND EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit) ORDER BY p.id_produit DESC LIMIT ? OFFSET ?",
            ],
            // Vue groupee - traitee a part (voir plus bas) car sa pagination
            // se fait par GROUPE et non par ligne comme les 6 vues ci-dessus.
            // 'count' reste une simple requete compatible avec la boucle
            // generique des badges (pas de fenetrage necessaire pour compter).
            // Meme reference ET meme marque = la meme piece entree en double
            // dans le catalogue (mesure : 1224 groupes, 2826 produits) - une
            // definition differente et complementaire de l'onglet "Doublons
            // probables" (libelle+marque+prix), qui rate 1761 de ces produits.
            'ref_doublons' => [
                'label' => 'Doublons (référence+marque)',
                'desc'  => "Produits partageant EXACTEMENT la même référence et la même marque - la même pièce entrée en double, qu'aucun import ne distingue jamais. Regroupés ci-dessous. Affiche par défaut les groupes ayant encore un exemplaire disponible.",
                'count' => "SELECT COUNT(*) FROM (SELECT 1 FROM reference r JOIN produit p ON p.id_produit = r.id_produit GROUP BY TRIM(r.reference), TRIM(p.marquepiece) HAVING COUNT(DISTINCT p.id_produit) > 1 AND SUM(p.stock = 1) > 0) t",
            ],
            // Meme marque + reference identique une fois la ponctuation
            // retiree (ex. "MD2183" / "MD-2183"), mais l'ecriture EXACTE
            // differe - c'est ce qui fait echouer la correspondance exacte
            // des imports. Volontairement restreint a la MEME marque :
            // grouper par reference normalisee seule (sans la marque)
            // donnait 1689 groupes/5049 produits, mais 1435 de ces groupes
            // (85%) melangeaient en fait 2+ marques differentes - des
            // produits reellement distincts partageant un numero par
            // coincidence, pas une variante d'ecriture de la meme piece.
            // Avec la marque, la vraie mesure est 335 groupes, 336 avec
            // au moins un exemplaire disponible.
            'ref_variantes' => [
                'label' => 'Références similaires',
                'desc'  => "Même marque, même référence une fois la ponctuation retirée, mais écrite différemment - un import ne peut jamais faire le lien entre les deux écritures. Pas de suppression ici : corriger la référence qui ne correspond pas à celle du logiciel (« Mod »), les deux fiches se retrouveront ensuite dans l'onglet Doublons si elles décrivent bien la même pièce.",
                'count' => "SELECT COUNT(*) FROM (SELECT REGEXP_REPLACE(UPPER(TRIM(r.reference)), '[^A-Z0-9]', '') norm, TRIM(p.marquepiece) mq FROM reference r JOIN produit p ON p.id_produit = r.id_produit GROUP BY norm, mq HAVING COUNT(DISTINCT TRIM(r.reference)) > 1 AND SUM(p.stock = 1) > 0) t",
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

        // Les vues "ref_doublons" et "ref_variantes" se paginent par GROUPE
        // (un groupe = 2+ lignes affichees ensemble), pas par ligne comme
        // les 6 autres vues - elles ne peuvent donc pas partager leur
        // simple LIMIT/OFFSET generique.
        $estRefDoublons = ($vue === 'ref_doublons');
        $estRefVariantes = ($vue === 'ref_variantes');
        $vueGroupee = $estRefDoublons || $estRefVariantes;
        $tousLesGroupes = $vueGroupee && isset($_GET['tous']);
        // Pas de suppression en masse sur "ref_variantes" - le geste attendu
        // est de corriger une reference, pas de supprimer un produit (voir
        // le texte de l'onglet et PLAN_RAPPORT_CATALOGUE_EXTENSIONS.md).
        $masquerSelection = $estRefVariantes;

        if ($vueGroupee) {
            $groupesParPage = 15;
            // "tous=1" inclut aussi les groupes entierement epuises (aucun
            // exemplaire disponible) - moins urgent, replie derriere ce lien
            // plutot que devant par defaut (voir PLAN_RAPPORT_CATALOGUE_EXTENSIONS.md).
            $filtreDispo = $tousLesGroupes ? '' : ' AND SUM(p.stock = 1) > 0';

            if ($estRefDoublons) {
                $sqlCount = "SELECT COUNT(*) FROM (SELECT 1 FROM reference r JOIN produit p ON p.id_produit = r.id_produit
                             GROUP BY TRIM(r.reference), TRIM(p.marquepiece) HAVING COUNT(DISTINCT p.id_produit) > 1$filtreDispo) t";
                // DENSE_RANK() doit etre calcule dans un CTE SEPARE du GROUP
                // BY/HAVING qui filtre les groupes : les combiner dans le
                // meme bloc fait remonter silencieusement 0 ligne sur
                // MariaDB 10.4/10.11 (verifie - COUNT(DISTINCT ...) en
                // HAVING + fenetrage dans le meme SELECT), sans la moindre
                // erreur SQL pour le signaler.
                $sqlListe = "WITH groupes_valides AS (
                        SELECT TRIM(r.reference) cle, TRIM(p.marquepiece) mq
                        FROM reference r JOIN produit p ON p.id_produit = r.id_produit
                        GROUP BY TRIM(r.reference), TRIM(p.marquepiece)
                        HAVING COUNT(DISTINCT p.id_produit) > 1$filtreDispo
                    ),
                    groupes AS (
                        SELECT cle, mq, DENSE_RANK() OVER (ORDER BY cle, mq) AS rang FROM groupes_valides
                    )
                    SELECT DISTINCT g.rang, p.*, r.reference AS reference_affichee,
                           (p.img1 <> '') a_photo, (p.id_categorie <> 0) a_categorie,
                           EXISTS(SELECT 1 FROM pvd WHERE pvd.id_produit = p.id_produit) a_vehicule
                    FROM groupes g
                    JOIN reference r ON TRIM(r.reference) = g.cle
                    JOIN produit p ON p.id_produit = r.id_produit AND TRIM(p.marquepiece) = g.mq
                    WHERE g.rang > ? AND g.rang <= ?
                    ORDER BY g.rang, p.id_produit";
            } else {
                // ref_variantes : meme marque, meme reference une fois la
                // ponctuation retiree, mais l'ecriture EXACTE differe.
                // Restreint a la MEME marque (voir commentaire sur la
                // definition de $vues['ref_variantes']) - sans quoi deux
                // produits reellement distincts de marques differentes
                // partageant un numero par coincidence seraient a tort
                // presentes comme "la meme piece mal ecrite".
                $sqlCount = "SELECT COUNT(*) FROM (SELECT REGEXP_REPLACE(UPPER(TRIM(r.reference)), '[^A-Z0-9]', '') norm, TRIM(p.marquepiece) mq
                             FROM reference r JOIN produit p ON p.id_produit = r.id_produit
                             GROUP BY norm, mq HAVING COUNT(DISTINCT TRIM(r.reference)) > 1$filtreDispo) t";
                $sqlListe = "WITH groupes_valides AS (
                        SELECT REGEXP_REPLACE(UPPER(TRIM(r.reference)), '[^A-Z0-9]', '') cle, TRIM(p.marquepiece) mq
                        FROM reference r JOIN produit p ON p.id_produit = r.id_produit
                        GROUP BY cle, mq
                        HAVING COUNT(DISTINCT TRIM(r.reference)) > 1$filtreDispo
                    ),
                    groupes AS (
                        SELECT cle, mq, DENSE_RANK() OVER (ORDER BY cle, mq) AS rang FROM groupes_valides
                    )
                    SELECT DISTINCT g.rang, p.*, r.reference AS reference_affichee,
                           (p.img1 <> '') a_photo, (p.id_categorie <> 0) a_categorie,
                           EXISTS(SELECT 1 FROM pvd WHERE pvd.id_produit = p.id_produit) a_vehicule
                    FROM groupes g
                    JOIN reference r ON REGEXP_REPLACE(UPPER(TRIM(r.reference)), '[^A-Z0-9]', '') = g.cle
                    JOIN produit p ON p.id_produit = r.id_produit AND TRIM(p.marquepiece) = g.mq
                    WHERE g.rang > ? AND g.rang <= ?
                    ORDER BY g.rang, p.id_produit";
            }

            $totalGroupes = (int)$pdo->query($sqlCount)->fetchColumn();
            $totalPages = max(1, (int)ceil($totalGroupes / $groupesParPage));
            $offsetGroupes = ($page - 1) * $groupesParPage;

            $stmt = $pdo->prepare($sqlListe);
            $stmt->bindValue(1, $offsetGroupes, PDO::PARAM_INT);
            $stmt->bindValue(2, $offsetGroupes + $groupesParPage, PDO::PARAM_INT);
            $stmt->execute();
            $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare($courant['list']);
            $stmt->bindValue(1, $itemsPerPage, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalItems = $counts[$vue];
            $totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
        }
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

            <p class="rc-desc"><?= $courant['desc'] ?>
                <?php if ($vueGroupee): ?>
                    <?php if ($tousLesGroupes): ?>
                        <a href="?vue=<?= urlencode($vue) ?>">Revenir aux groupes ayant un exemplaire disponible</a>.
                    <?php else: ?>
                        <a href="?vue=<?= urlencode($vue) ?>&tous=1">Voir aussi les groupes entièrement épuisés</a>.
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <form method="POST" action="supprimer/sup-produits-masse.php" id="rc-bulk-form">
                <input type="hidden" name="vue" value="<?= htmlspecialchars($vue) ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">
                <?php if ($tousLesGroupes): ?>
                    <input type="hidden" name="tous" value="1">
                <?php endif; ?>

                <?php if (!$masquerSelection): ?>
                <div class="rc-bulk-bar">
                    <label><input type="checkbox" id="rc-select-all"> Tout sélectionner (page courante)</label>
                    <button type="submit" id="rc-bulk-btn" class="rc-bulk-btn" disabled>Supprimer la sélection</button>
                </div>
                <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th class="rc-checkbox-col"></th>
                        <th>image</th>
                        <th>Produit</th>
                        <?php if ($vueGroupee): ?>
                            <th>référence</th>
                        <?php endif; ?>
                        <th>Prix</th>
                        <th>marque</th>
                        <th>modele</th>
                        <th>categorie</th>
                        <th>sous categorie</th>
                        <th>stock</th>
                        <?php if ($vueGroupee): ?>
                            <th>quantité</th>
                            <th>complet ?</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                    if (empty($produits)) {
                        echo '<tr><td colspan="12">Rien à afficher pour cette vue.</td></tr>';
                    } else {
                        $rangPrecedent = null;
                        $indexGroupe = 0;
                        foreach ($produits as $produit) {
                            $nouveauGroupe = $vueGroupee && $produit['rang'] !== $rangPrecedent;
                            if ($vueGroupee) {
                                if ($nouveauGroupe) {
                                    $indexGroupe++;
                                }
                                $rangPrecedent = $produit['rang'];
                            }
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
                    $classeGroupe = $vueGroupee ? ($indexGroupe % 2 === 0 ? 'rc-groupe-a' : 'rc-groupe-b') : '';
                    $styleDebutGroupe = $nouveauGroupe && $indexGroupe > 1 ? 'border-top:2px solid #999;' : '';
                ?>
                        <tr<?= $classeGroupe ? ' class="' . $classeGroupe . '"' : '' ?><?= $styleDebutGroupe ? ' style="' . $styleDebutGroupe . '"' : '' ?>>
                            <td class="rc-checkbox-col"><?php if (!$masquerSelection): ?><input type="checkbox" class="rc-row-check" name="ids[]" value="<?= (int)$produit['id_produit'] ?>"><?php endif; ?></td>
                            <td><img src="../img/produit/<?= htmlspecialchars($produit['img1']) ?>"></td>
                            <td><?= htmlspecialchars($produit['libelle']) ?></td>
                            <?php if ($vueGroupee): ?>
                                <td><?= htmlspecialchars($produit['reference_affichee']) ?></td>
                            <?php endif; ?>
                            <td><span class="spanGreen"><?= (int)$produit['prix'] ?> DA</span></td>
                            <td><?= htmlspecialchars($marque) ?></td>
                            <td><?= htmlspecialchars($voiture) ?></td>
                            <td><?= htmlspecialchars($categorie) ?></td>
                            <td><?= htmlspecialchars($sous) ?></td>
                            <td><span class="spanOrange"><?= $produit['stock'] == 1 ? 'disponible' : 'non disponible' ?></span></td>
                            <?php if ($vueGroupee): ?>
                                <td><?= $produit['quantite'] === null ? '—' : (int)$produit['quantite'] ?></td>
                                <td><?= ($produit['a_photo'] ? '📷' : '—') . ' ' . ($produit['a_categorie'] ? '🏷' : '—') . ' ' . ($produit['a_vehicule'] ? '🚗' : '—') ?></td>
                            <?php endif; ?>
                            <td><a href="ajouter-produit.php?id=<?= (int)$produit['id_produit'] ?>" class="btn-mod">Mod</a></td>
                            <td><a href="supprimer/sup-produit.php?id=<?= (int)$produit['id_produit'] ?>&vue=<?= urlencode($vue) ?><?= $tousLesGroupes ? '&tous=1' : '' ?>&page=<?= $page ?>" class="btn-sup" onclick="return confirm('Supprimer ce produit ?');">Sup</a></td>
                        </tr>
                <?php
                        }
                    }
                ?>
                </tbody>
            </table>
            </form>

            <?php
                $baseUrl = $_SERVER['PHP_SELF'] . '?vue=' . urlencode($vue) . ($tousLesGroupes ? '&tous=1' : '') . '&';

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
