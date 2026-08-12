<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voiture</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/script.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <script src="https://kit.fontawesome.com/abbd21db44.js" crossorigin="anonymous"></script>
</head>
<body>
    <!--=============== navbar=================-->
    <?php 
        require_once('dashboard/database.php');
        $id = $_GET['id'];
        $sql = $pdo->prepare('SELECT * FROM marque WHERE id_marque =?');
        $sql->execute([$id]);
        $marque = $sql->fetch(PDO::FETCH_ASSOC);
        include('partie/navbar.php') ;
    ?>   
    <!--==============voiture==================-->
    <div class="section-voiture">
        <div class="titre"><h1>Quelle est votre <span>voiture</span></h1></div>
        <div class="bande">
            <a href="index.php">> Acceuil</a>
            <a href="voiture.php?id=<?=$id?>"> > <?=$marque['libelle']?></a>
        </div>
        <div class="cars">
            <?php
                $sqlStates = $pdo->prepare('SELECT * FROM voiture WHERE id_marque=? ORDER BY modele');
                $sqlStates->execute([$id]);
                $voitures = $sqlStates->fetchAll(PDO::FETCH_ASSOC);

                $sqlCategorie = 'SELECT id_categorie FROM categorie LIMIT 1';
                $query = $pdo->query($sqlCategorie);
                $first_row = $query->fetch(PDO::FETCH_ASSOC);
                foreach($voitures as $voiture){
            ?>
            <div class="car">
                <a href="categorie.php?id=<?=$voiture['id_voiture']?>&sous=<?=$first_row['id_categorie']?>">
                    <div class="car-img-wrap">
                        <img src="img/voiture/<?=$voiture['img']?>" loading="lazy" alt="Pièces de <?=$voiture['modele']?>">
                    </div>
                    <div class="car-label">
                        <h3><?=$voiture['modele']?></h3>
                        <span>Voir pièces</span>
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