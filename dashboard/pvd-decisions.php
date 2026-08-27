<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <title>Dashboard Decisions PVD</title>
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
        .rc-bulk-btn{border:none;cursor:pointer;text-decoration:none;color:#fff;background-color:#2f5fd0;padding:8px 14px;border-radius:8px;font-size:14px;font-family:inherit}
        .rc-bulk-btn:disabled{background-color:#ccc;cursor:not-allowed}
        .rc-checkbox-col{width:32px;text-align:center}
        .pd-row{border:1px solid #ddd;border-radius:6px;padding:12px;margin:0 0 12px;background:#fff}
        .pd-row .pd-libelle{font-weight:600;margin-bottom:2px}
        .pd-row .pd-ref{font-size:12px;color:#666;font-family:monospace;margin-bottom:8px}
        .pd-row .pd-compare{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:8px;font-size:14px}
        .pd-row .pd-compare span b{font-variant-numeric:tabular-nums}
        .pd-choix{display:flex;flex-wrap:wrap;gap:16px;align-items:center;font-size:14px}
        .pd-choix label{display:flex;align-items:center;gap:5px}
        .pd-choix input[type=text]{width:160px;box-sizing:border-box}
        .pd-save-bar{position:sticky;bottom:0;background:#fff;padding:10px 0;border-top:2px solid #ddd;margin-top:10px}
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
        require_once('include/pvd_extraction.php');
        include('include/menu.php');

        // Couche 2 of PLAN_PVD_DESCRIPTION.md: arbitrate what couche 1's
        // mechanical extraction could not decide on its own - never an
        // automatic overwrite, always an explicit human choice per row
        // (or per lot, only where a safe default exists - see "rapide").
        $onglet = $_GET['onglet'] ?? 'rapide';
        $onglets = ['rapide' => 'Marque - lot rapide', 'lent' => 'Marque - lot lent', 'completion' => 'Données manquantes'];
        if (!isset($onglets[$onglet])) {
            $onglet = 'rapide';
        }

        $itemsPerPage = 30;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $itemsPerPage;

        // --- Traitement des soumissions ---
        $message = '';

        if (isset($_POST['appliquer_rapide']) && !empty($_POST['ids'])) {
            $stmt = $pdo->prepare('SELECT id_pvd, id_produit, marque_texte FROM pvd WHERE id_pvd = ?');
            $update = $pdo->prepare('UPDATE produit SET marquepiece = ? WHERE id_produit = ?');
            $n = 0;
            foreach ($_POST['ids'] as $idPvd) {
                $stmt->execute([(int)$idPvd]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['marque_texte'] !== null) {
                    $update->execute([$row['marque_texte'], $row['id_produit']]);
                    $n++;
                }
            }
            $message = "$n produit(s) mis à jour.";
        }

        if (isset($_POST['enregistrer_lent'])) {
            $n = 0;
            foreach ($_POST['choix'] ?? [] as $idPvd => $choix) {
                $idPvd = (int)$idPvd;
                $stmt = $pdo->prepare('SELECT p.id_produit, pv.marque_texte FROM pvd pv JOIN produit p ON p.id_produit = pv.id_produit WHERE pv.id_pvd = ?');
                $stmt->execute([$idPvd]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }
                $nouvelleValeur = null;
                if ($choix === 'adopter') {
                    $nouvelleValeur = $row['marque_texte'];
                } elseif ($choix === 'nouvelle') {
                    $nouvelleValeur = trim($_POST['nouvelle_valeur'][$idPvd] ?? '');
                }
                if ($nouvelleValeur !== null && $nouvelleValeur !== '') {
                    $pdo->prepare('UPDATE produit SET marquepiece = ? WHERE id_produit = ?')->execute([$nouvelleValeur, $row['id_produit']]);
                    $n++;
                }
                // 'garder' : rien a faire, marque_texte reste archive tel quel.
            }
            $message = "$n décision(s) appliquée(s).";
        }

        if (isset($_POST['completer'])) {
            $n = 0;
            // Annee : toujours par vehicule (pvd) - inchange.
            foreach ($_POST['pvd'] ?? [] as $idPvd => $champs) {
                $idPvd = (int)$idPvd;
                $anneeDebut = $champs['annee_debut'] !== '' ? (int)$champs['annee_debut'] : null;
                $anneeFin = $champs['annee_fin'] !== '' ? (int)$champs['annee_fin'] : null;
                if ($anneeDebut === null) {
                    continue;
                }
                $current = $pdo->prepare('SELECT annee_debut, annee_fin FROM pvd WHERE id_pvd = ?');
                $current->execute([$idPvd]);
                $row = $current->fetch(PDO::FETCH_ASSOC);
                $update = $pdo->prepare('UPDATE pvd SET annee_debut = ?, annee_fin = ? WHERE id_pvd = ?');
                $update->execute([
                    $anneeDebut ?? $row['annee_debut'],
                    $anneeFin ?? $row['annee_fin'],
                    $idPvd,
                ]);
                $n++;
            }
            // Pays : par produit (voir db/produit_pays_origine.sql) - une
            // seule mise a jour par produit, meme si plusieurs de ses
            // vehicules apparaissent sur cette page.
            foreach ($_POST['produit'] ?? [] as $idProduit => $champs) {
                $idProduit = (int)$idProduit;
                $paysAutre = trim($champs['pays_origine_autre'] ?? '');
                $pays = $paysAutre !== '' ? strtoupper($paysAutre) : (($champs['pays_origine'] ?? '') !== '' ? $champs['pays_origine'] : null);
                if ($pays === null) {
                    continue;
                }
                $pdo->prepare('UPDATE produit SET pays_origine = ? WHERE id_produit = ?')->execute([$pays, $idProduit]);
                $n++;
            }
            $message = "$n complétion(s) enregistrée(s).";
        }

        // --- Comptes pour les onglets ---
        $countVide = (int)$pdo->query("SELECT COUNT(*) FROM pvd p JOIN produit pr ON pr.id_produit = p.id_produit WHERE p.marque_texte IS NOT NULL AND TRIM(pr.marquepiece) = ''")->fetchColumn();

        $conflits = $pdo->query("
            SELECT p.id_pvd, p.id_produit, TRIM(pr.marquepiece) AS marquepiece, p.marque_texte
            FROM pvd p
            JOIN produit pr ON pr.id_produit = p.id_produit
            WHERE p.marque_texte IS NOT NULL
              AND TRIM(pr.marquepiece) != ''
              AND UPPER(TRIM(pr.marquepiece)) != p.marque_texte
        ")->fetchAll(PDO::FETCH_ASSOC);

        $idsTypo = [];
        $idsReel = [];
        foreach ($conflits as $c) {
            $a = strtoupper($c['marquepiece']);
            $b = $c['marque_texte'];
            $d = levenshtein($a, $b);
            $maxlen = max(strlen($a), strlen($b));
            if ($maxlen > 0 && $d <= 2 && $d / $maxlen < 0.4) {
                $idsTypo[] = $c['id_pvd'];
            } else {
                $idsReel[] = $c['id_pvd'];
            }
        }

        $countRapide = $countVide + count($idsTypo);
        $countLent = count($idsReel);
        $countCompletion = (int)$pdo->query('SELECT COUNT(*) FROM pvd pv JOIN produit pr ON pr.id_produit = pv.id_produit WHERE pv.annee_debut IS NULL OR pr.pays_origine IS NULL')->fetchColumn();

        $paysConnus = pvd_liste_pays_connus();
    ?>

    <div class="site">
        <div class="barre">Décisions PVD</div>
        <div class="page-produit">
            <h3>Structuration de pvd.description - décisions humaines</h3>
            <p class="rc-desc">PLAN_PVD_DESCRIPTION.md, couche 2. Aucune valeur n'est jamais écrasée automatiquement - chaque changement ici est un choix explicite.</p>

            <?php if ($message): ?>
                <div class="note" style="background:#e8f4ee;border:1px solid #1c6b4a;padding:8px 12px;border-radius:6px;margin:0 0 14px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="rc-tabs">
                <a class="rc-tab<?= $onglet === 'rapide' ? ' active' : '' ?>" href="?onglet=rapide">Marque - lot rapide (<b><?= $countRapide ?></b>)</a>
                <a class="rc-tab<?= $onglet === 'lent' ? ' active' : '' ?>" href="?onglet=lent">Marque - lot lent (<b><?= $countLent ?></b>)</a>
                <a class="rc-tab<?= $onglet === 'completion' ? ' active' : '' ?>" href="?onglet=completion">Données manquantes (<b><?= $countCompletion ?></b>)</a>
            </div>

            <?php if ($onglet === 'rapide'): ?>
                <p class="rc-desc">Produits sans marque enregistrée (<?= $countVide ?>) ou avec une faute de frappe probable (<?= count($idsTypo) ?>) entre <code>produit.marquepiece</code> et la marque lue dans l'ancienne description. Sélection pré-cochée avec la correction suggérée - à vérifier avant d'appliquer.</p>
                <form method="POST">
                    <div class="rc-bulk-bar">
                        <label><input type="checkbox" id="rc-select-all"> Tout sélectionner</label>
                        <button type="submit" name="appliquer_rapide" id="rc-bulk-btn" class="rc-bulk-btn">Appliquer la sélection</button>
                    </div>
                    <?php
                        $idsRapide = array_merge(
                            $pdo->query("SELECT p.id_pvd FROM pvd p JOIN produit pr ON pr.id_produit = p.id_produit WHERE p.marque_texte IS NOT NULL AND TRIM(pr.marquepiece) = ''")->fetchAll(PDO::FETCH_COLUMN),
                            $idsTypo
                        );
                        if (empty($idsRapide)) {
                            echo '<p>Rien à traiter dans ce lot.</p>';
                        } else {
                            $pageIds = array_slice($idsRapide, $offset, $itemsPerPage);
                            $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
                            $rows = $pdo->prepare("SELECT p.id_pvd, pr.libelle, TRIM(pr.marquepiece) AS marquepiece, p.marque_texte, GROUP_CONCAT(DISTINCT ref.reference SEPARATOR ', ') AS refs FROM pvd p JOIN produit pr ON pr.id_produit = p.id_produit LEFT JOIN reference ref ON ref.id_produit = pr.id_produit WHERE p.id_pvd IN ($placeholders) GROUP BY p.id_pvd");
                            $rows->execute($pageIds);
                            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    ?>
                        <div class="pd-row">
                            <div class="pd-libelle"><?= htmlspecialchars($r['libelle']) ?></div>
                            <div class="pd-ref">Référence(s) : <?= $r['refs'] ? htmlspecialchars($r['refs']) : '(aucune)' ?></div>
                            <div class="pd-compare">
                                <span>Actuel : <b><?= $r['marquepiece'] === '' ? '(vide)' : htmlspecialchars($r['marquepiece']) ?></b></span>
                                <span>→ Suggéré : <b><?= htmlspecialchars($r['marque_texte']) ?></b></span>
                            </div>
                            <label><input type="checkbox" class="rc-row-check" name="ids[]" value="<?= $r['id_pvd'] ?>" checked> Appliquer cette correction</label>
                        </div>
                    <?php
                            }
                            $totalPages = max(1, (int)ceil(count($idsRapide) / $itemsPerPage));
                            echo pd_pagination($page, $totalPages, $onglet);
                        }
                    ?>
                </form>

            <?php elseif ($onglet === 'lent'): ?>
                <p class="rc-desc">Désaccords sans correction évidente entre <code>produit.marquepiece</code> et la marque lue dans l'ancienne description. Choisir explicitement pour chaque ligne.</p>
                <form method="POST">
                    <?php
                        if (empty($idsReel)) {
                            echo '<p>Rien à traiter dans ce lot.</p>';
                        } else {
                            $pageIds = array_slice($idsReel, $offset, $itemsPerPage);
                            $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
                            $rows = $pdo->prepare("SELECT p.id_pvd, pr.libelle, TRIM(pr.marquepiece) AS marquepiece, p.marque_texte, GROUP_CONCAT(DISTINCT ref.reference SEPARATOR ', ') AS refs FROM pvd p JOIN produit pr ON pr.id_produit = p.id_produit LEFT JOIN reference ref ON ref.id_produit = pr.id_produit WHERE p.id_pvd IN ($placeholders) GROUP BY p.id_pvd");
                            $rows->execute($pageIds);
                            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    ?>
                        <div class="pd-row">
                            <div class="pd-libelle"><?= htmlspecialchars($r['libelle']) ?></div>
                            <div class="pd-ref">Référence(s) : <?= $r['refs'] ? htmlspecialchars($r['refs']) : '(aucune)' ?></div>
                            <div class="pd-compare">
                                <span>Actuel : <b><?= htmlspecialchars($r['marquepiece']) ?></b></span>
                                <span>Description : <b><?= htmlspecialchars($r['marque_texte']) ?></b></span>
                            </div>
                            <div class="pd-choix">
                                <label><input type="radio" name="choix[<?= $r['id_pvd'] ?>]" value="garder" checked> Garder <?= htmlspecialchars($r['marquepiece']) ?></label>
                                <label><input type="radio" name="choix[<?= $r['id_pvd'] ?>]" value="adopter"> Adopter <?= htmlspecialchars($r['marque_texte']) ?></label>
                                <label><input type="radio" name="choix[<?= $r['id_pvd'] ?>]" value="nouvelle"> Nouvelle valeur :
                                    <input type="text" name="nouvelle_valeur[<?= $r['id_pvd'] ?>]" placeholder="marque correcte">
                                </label>
                            </div>
                        </div>
                    <?php
                            }
                            $totalPages = max(1, (int)ceil(count($idsReel) / $itemsPerPage));
                            echo pd_pagination($page, $totalPages, $onglet);
                    ?>
                        <div class="pd-save-bar">
                            <button type="submit" name="enregistrer_lent" class="rc-bulk-btn">Enregistrer les décisions de cette page</button>
                        </div>
                    <?php
                        }
                    ?>
                </form>

            <?php else: ?>
                <p class="rc-desc">Lignes sans année et/ou sans pays d'origine. Complète ce qui est connaissable ; laisse vide sinon.</p>
                <form method="POST">
                    <?php
                        $rows = $pdo->prepare('SELECT pv.id_pvd, pr.id_produit, pr.libelle, pr.pays_origine, v.modele, pv.annee_debut, pv.annee_fin, pv.notes_libres, GROUP_CONCAT(DISTINCT ref.reference SEPARATOR \', \') AS refs FROM pvd pv JOIN produit pr ON pr.id_produit = pv.id_produit JOIN voiture v ON v.id_voiture = pv.id_voiture LEFT JOIN reference ref ON ref.id_produit = pr.id_produit WHERE pv.annee_debut IS NULL OR pr.pays_origine IS NULL GROUP BY pv.id_pvd ORDER BY pv.id_pvd LIMIT ? OFFSET ?');
                        $rows->bindValue(1, $itemsPerPage, PDO::PARAM_INT);
                        $rows->bindValue(2, $offset, PDO::PARAM_INT);
                        $rows->execute();
                        $liste = $rows->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($liste)) {
                            echo '<p>Rien à compléter.</p>';
                        }
                        foreach ($liste as $r) {
                    ?>
                        <div class="pd-row">
                            <div class="pd-libelle"><?= htmlspecialchars($r['libelle']) ?> — <?= htmlspecialchars($r['modele']) ?></div>
                            <div class="pd-ref">Référence(s) : <?= $r['refs'] ? htmlspecialchars($r['refs']) : '(aucune)' ?></div>
                            <?php if (!empty($r['notes_libres'])): ?>
                                <p class="rc-desc" style="margin:0 0 8px;">Texte original : <?= htmlspecialchars($r['notes_libres']) ?></p>
                            <?php endif; ?>
                            <div class="pvd-structure" style="margin:0 0 6px;">
                                <div class="pvd-champ">
                                    <label>Année début</label>
                                    <input type="number" name="pvd[<?= $r['id_pvd'] ?>][annee_debut]" min="1970" max="2026" value="<?= htmlspecialchars($r['annee_debut'] ?? '') ?>">
                                </div>
                                <div class="pvd-champ">
                                    <label>Année fin (optionnel)</label>
                                    <input type="number" name="pvd[<?= $r['id_pvd'] ?>][annee_fin]" min="1970" max="2026" value="<?= htmlspecialchars($r['annee_fin'] ?? '') ?>">
                                </div>
                                <?php if ($r['pays_origine'] === null): ?>
                                    <!-- Pays : propriete du produit, pas du vehicule - une
                                         valeur saisie ici s'applique a tous les vehicules
                                         de ce produit (voir db/produit_pays_origine.sql).
                                         Champ absent si le produit a deja un pays. -->
                                    <div class="pvd-champ">
                                        <label>Pays d'origine (produit)</label>
                                        <select name="produit[<?= $r['id_produit'] ?>][pays_origine]">
                                            <option value="">Non renseigné</option>
                                            <?php foreach ($paysConnus as $p): ?>
                                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="pvd-champ">
                                        <label>Autre pays</label>
                                        <input type="text" name="produit[<?= $r['id_produit'] ?>][pays_origine_autre]" placeholder="Autre pays">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php
                        }
                        if (!empty($liste)) {
                            $totalPages = max(1, (int)ceil($countCompletion / $itemsPerPage));
                            echo pd_pagination($page, $totalPages, $onglet);
                    ?>
                        <div class="pd-save-bar">
                            <button type="submit" name="completer" class="rc-bulk-btn">Enregistrer cette page</button>
                        </div>
                    <?php
                        }
                    ?>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($onglet === 'rapide'): ?>
    <script>
        (function () {
            var selectAll = document.getElementById('rc-select-all');
            var bulkBtn = document.getElementById('rc-bulk-btn');
            var rowChecks = Array.prototype.slice.call(document.getElementsByClassName('rc-row-check'));
            function refresh() {
                var n = rowChecks.filter(function (c) { return c.checked; }).length;
                bulkBtn.disabled = n === 0;
                bulkBtn.textContent = n === 0 ? 'Appliquer la sélection' : 'Appliquer la sélection (' + n + ')';
                if (selectAll) selectAll.checked = n > 0 && n === rowChecks.length;
            }
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    rowChecks.forEach(function (c) { c.checked = selectAll.checked; });
                    refresh();
                });
            }
            rowChecks.forEach(function (c) { c.addEventListener('change', refresh); });
            refresh();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
function pd_pagination(int $page, int $totalPages, string $onglet): string
{
    $base = '?onglet=' . urlencode($onglet) . '&';
    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a href="' . $base . 'page=1">1</a>';
    }
    if ($page > 3) {
        $html .= '<span class="dots">...</span>';
    }
    $start = max(2, $page - 1);
    $end = min($totalPages - 1, $page + 1);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i == $page ? '<span class="current-page">' . $i . '</span>' : '<a href="' . $base . 'page=' . $i . '">' . $i . '</a>';
    }
    if ($page < $totalPages - 2) {
        $html .= '<span class="dots">...</span>';
    }
    if ($page < $totalPages) {
        $html .= '<a href="' . $base . 'page=' . $totalPages . '">' . $totalPages . '</a>';
    }
    if ($page < $totalPages) {
        $html .= '<a href="' . $base . 'page=' . ($page + 1) . '">Suivant</a>';
    }
    return $html . '</div>';
}
