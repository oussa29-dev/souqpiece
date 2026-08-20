<?php
    require_once('../database.php');
    $id = $_GET['id'];
    $sql = $pdo->prepare('DELETE FROM produit WHERE id_produit=?');
    $sql->execute([$id]);

    // Delete links from rapport-catalogue.php pass their view/page back so
    // the admin stays on the same filtered list instead of landing on the
    // full unfiltered produit.php - only whitelisted view names are ever
    // followed, so this cannot become an open redirect.
    $vuesValides = ['sans_vehicule', 'sans_categorie', 'doublons', 'prix', 'image'];
    if (isset($_GET['vue']) && in_array($_GET['vue'], $vuesValides, true)) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        header('location:../rapport-catalogue.php?vue=' . urlencode($_GET['vue']) . '&page=' . $page);
    } else {
        header('location:../produit.php');
    }
?>