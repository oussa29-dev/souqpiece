<?php
    session_start();
    if (!isset($_SESSION['utilisateur'])) {
        header('location:../connexion.php');
        exit;
    }
    require_once('../database.php');

    // Bulk delete for dashboard/rapport-catalogue.php - unlike the single
    // sup-produit.php link, this can wipe hundreds of rows in one click, so
    // it requires an authenticated session (the single-delete endpoints
    // predate any such check on this codebase; not touched here) and only
    // accepts POST with explicit integer ids.
    $ids = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids']))));
    }

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = $pdo->prepare("DELETE FROM produit WHERE id_produit IN ($placeholders)");
        $sql->execute($ids);
    }

    // Same whitelisted-view redirect as sup-produit.php - keeps the admin
    // on the same tab/page instead of landing on produit.php.
    $vuesValides = ['sans_vehicule', 'sans_categorie', 'doublons', 'prix', 'image'];
    if (isset($_POST['vue']) && in_array($_POST['vue'], $vuesValides, true)) {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        header('location:../rapport-catalogue.php?vue=' . urlencode($_POST['vue']) . '&page=' . $page);
    } else {
        header('location:../rapport-catalogue.php');
    }
?>
