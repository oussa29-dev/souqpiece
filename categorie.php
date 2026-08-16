<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorie</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/script.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/fontawesome/css/all.min.css">
</head>
<body>
    <!--=============== navbar=================-->
        <?php 
            require_once('dashboard/database.php');
            include('partie/navbar.php');
            $id = $_GET['id'];
            $sqlStates = $pdo->prepare('SELECT * FROM voiture WHERE id_voiture=?');
            $sqlStates->execute([$id]);
            $voiture = $sqlStates->fetch(PDO::FETCH_ASSOC);

            $id_marque = $voiture['id_marque'];
            $sqlMarque = $pdo->prepare('SELECT * FROM marque WHERE id_marque=?');
            $sqlMarque->execute([$id_marque]);
            $marque = $sqlMarque->fetch(PDO::FETCH_ASSOC);

        ?>   
    <!--==============categorie==================-->
    <div class="page-categorie">
        <div class="titre"><h1>Choisir la <span>categorie</span></h1></div>
        <div class="bande">
            <a href="index.php">> Acceuil</a>
            <a href="voiture.php?id=<?=$marque['id_marque']?>">> <?= $marque['libelle'] ?> <?php ?></a>
            <?php if ($voiture) { ?>
            <a href="">> <?= $voiture['modele'] ?></a>
            <?php } ?>
        </div>
        <div class="menu-categorie">
            <?php
                $sqlCategorie = 'SELECT * FROM categorie ORDER BY id_categorie';
                $query = $pdo->query($sqlCategorie);
                $categories = $query->fetchAll(PDO::FETCH_ASSOC);
                // Récupérer la valeur du paramètre "sous" dans l'URL
                $id_categorie_url = isset($_GET['sous']) ? $_GET['sous'] : null;

                foreach($categories as $categorie){
                    $id_categorie = $categorie['id_categorie'];
                    // Vérifier si le paramètre "sous" correspond à l'ID de la catégorie actuelle
                    $class = ($id_categorie_url == $id_categorie) ? 'active' : '';
                    echo '<a href="categorie.php?id='.$id.'&sous='.$id_categorie.'" class="'.$class.'" " onclick="toggleActive(this)">'.$categorie['libelle'].'</a>';
                    $first = false;
                }
            ?>
        </div>
        <div class="categorie-container">
        <?php
            $sqlSous = $pdo->prepare('SELECT * FROM sous_categorie WHERE id_categorie=?');
            $sqlSous->execute([$id_categorie_url]);
            $sous = $sqlSous->fetchAll(PDO::FETCH_ASSOC);
            foreach($sous as $sou){
        ?>
            <div class="categorie-item">
                <a href="product.php?id_voiture=<?=$id?>&sous=<?=$sou['id_sous_categorie']?>">
                    <div class="categorie-img-wrap">
                        <img src="img/categories/sous_categorie/<?=$sou['img']?>" loading="lazy" alt="<?=$sou['libelle']?>">
                    </div>
                    <div class="categorie-label">
                        <h3><?=$sou['libelle']?></h3>
                        <i>›</i>
                    </div>
                </a>
            </div>
        <?php } ?>
        </div>
    </div>

    
    <!--=========================================================
                        footer
    ========================================================-->
    <?php include('partie/footer.php') ?>
    
</body>
</html>