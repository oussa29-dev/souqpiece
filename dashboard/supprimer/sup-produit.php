<?php
    require_once('../database.php');
    $id = $_GET['id'];
    $sql = $pdo->prepare('DELETE FROM produit WHERE id_produit=?');
    $sql->execute([$id]);

    // Delete links from rapport-catalogue.php pass their view/page back so
    // the admin stays on the same filtered list instead of landing on the
    // full unfiltered produit.php - only whitelisted view names are ever
    // followed, so this cannot become an open redirect.
    // sans_reference et ref_doublons manquaient ici depuis leur ajout -
    // sans ce whitelist, supprimer depuis ces deux onglets renvoyait vers
    // la vue par defaut au lieu de rester sur l'onglet filtre.
    $vuesValides = ['sans_vehicule', 'sans_categorie', 'doublons', 'prix', 'image', 'sans_reference', 'ref_doublons', 'ref_variantes', 'produits_fantomes'];
    if (isset($_GET['vue']) && in_array($_GET['vue'], $vuesValides, true)) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $tous = (isset($_GET['tous']) && in_array($_GET['vue'], ['ref_doublons', 'ref_variantes'], true)) ? '&tous=1' : '';
        header('location:../rapport-catalogue.php?vue=' . urlencode($_GET['vue']) . '&page=' . $page . $tous);
    } else {
        header('location:../produit.php');
    }
?>