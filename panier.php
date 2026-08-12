<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Panier</title>

    <link rel="stylesheet" href="css/style.css">

    <script src="js/prix.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">

    <script src="https://kit.fontawesome.com/abbd21db44.js" crossorigin="anonymous"></script>

    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>

</head>

<body>

<?php 

        include('partie/navbar.php');

        require_once('dashboard/database.php');

        $session_id = session_id();

        if(isset($_POST['acheter'])){
            if (isset($_POST['from_json']) && $_POST['from_json'] == 1) {
                // Gestion des produits JSON
                $reference = $_POST['produit_json'];
                $quantite = $_POST['quantite'];
                
                $sqlStates = $pdo->prepare('SELECT * FROM panier WHERE id_produit=? AND id_voiture=? AND id_session=? AND status=?');
    
                $sqlStates->execute([$reference,0,$session_id,false]);
    
                $fetch = $sqlStates->fetch(PDO::FETCH_ASSOC);
    
                if(!$fetch){
    
                    $sqlInsert = $pdo->prepare('INSERT INTO panier (id_session,id_produit,id_voiture,quantite,status) VALUES(?,?,?,?,?)');
    
                    $sqlInsert->execute([$session_id,$reference,0,$quantite,false]);                 
    
                }

            } else {
                $id_produit = $_POST['produit'];
                $id_voiture = isset($_POST['id_voiture']) ? $_POST['id_voiture'] : 0;
                $quantite = $_POST['quantite'];
    
                $sqlStates = $pdo->prepare('SELECT * FROM panier WHERE id_produit=? AND id_voiture=? AND id_session=? AND status=?');
    
                $sqlStates->execute([$id_produit,$id_voiture,$session_id,false]);
    
                $fetch = $sqlStates->fetch(PDO::FETCH_ASSOC);
    
                if(!$fetch){
    
                    $sqlInsert = $pdo->prepare('INSERT INTO panier (id_session,id_produit,id_voiture,quantite,status) VALUES(?,?,?,?,?)');
    
                    $sqlInsert->execute([$session_id,$id_produit,$id_voiture,$quantite,false]);                 
    
                }
            }
        }

        if(isset($_POST['add_cart']) ){
            if (isset($_POST['from_json']) && $_POST['from_json'] == 1) {
                // Gestion des produits JSON
                $reference = $_POST['produit_json'];
                $quantite = $_POST['quantite'];
                
                $sqlStates = $pdo->prepare('SELECT * FROM panier WHERE id_produit=? AND id_session=? AND status=?');
    
                $sqlStates->execute([$reference,$session_id,false]);
    
                $fetch = $sqlStates->fetch(PDO::FETCH_ASSOC);
    
                if($fetch){
    
                    header('location:produit.php?id='.$reference.'&from_json=1');
    
                    exit;
    
                }
    
                $sqlInsert = $pdo->prepare('INSERT INTO panier (id_session,id_produit,id_voiture,quantite,status) VALUES(?,?,?,?,?)');
    
                $sqlInsert->execute([$session_id,$reference,0,$quantite,false]);
    
                header('location:produit.php?id='.$reference.'&from_json=1&added=exists');
    
                exit;      
            } else {
                $id_produit = $_POST['produit'];
                $id_voiture = $_POST['id_voiture'];
                $quantite = $_POST['quantite'];
    
                $sqlStates = $pdo->prepare('SELECT * FROM panier WHERE id_produit=? AND id_voiture=? AND id_session=? AND status=?');
    
                $sqlStates->execute([$id_produit,$id_voiture,$session_id,false]);
    
                $fetch = $sqlStates->fetch(PDO::FETCH_ASSOC);
    
                if($fetch){
    
                    header('location:produit.php?id='.$id_produit.'&id_voiture='.$id_voiture.'');
    
                    exit;
    
                }
    
                $sqlInsert = $pdo->prepare('INSERT INTO panier (id_session,id_produit,id_voiture,quantite,status) VALUES(?,?,?,?,?)');
    
                $sqlInsert->execute([$session_id,$id_produit,$id_voiture,$quantite,false]);
    
                header('location:produit.php?id='.$id_produit. '&id_voiture='.$id_voiture.'&added=exists');
    
                exit;      
            }
        }

?>  <h1 id="text-panier">Voir le panier</h1>



    <div class="panier">

    <form method="POST">
        <table>
            <thead>
                <th>Image</th>
                <th>Produit</th>
                <th>Prix</th>
                <th>Quantite</th>
                <th>Total</th>
                <th>Sup</th>
            </thead>
            <tbody>
            <?php
                $prixtotal = 0;
                
                // Récupérer tous les articles du panier
                $sqlPanier = $pdo->prepare('SELECT * FROM panier WHERE id_session=? AND status=false');
                $sqlPanier->execute([$session_id]);
                $rows = $sqlPanier->fetchAll(PDO::FETCH_ASSOC);
                
                // Charger les données JSON
                $jsonFile = 'dashboard/data/stock.json';
                $jsonData = json_decode(file_get_contents($jsonFile), true);
                
                foreach($rows as $row){
                    // Vérifier si c'est un produit de la BDD ou du JSON
                    //if($row['id_voiture'] != 0) {
                    // Produit de la BDD
                    $sqlProduit = $pdo->prepare('SELECT * FROM produit WHERE id_produit=?');
                    $sqlProduit->execute([$row['id_produit']]);
                    $produit = $sqlProduit->fetch(PDO::FETCH_ASSOC);
                    
                    $libelle = $produit['libelle'];
                    $prix = $produit['prix'];
                    if($row['id_voiture'] != 0) {
                        $image = $produit['img1'];
                    }else{
                        $image = 'aucune.png';
                    }
                    $type = 'bdd';
                    
                    $total = $prix * $row['quantite'];
                    $prixtotal += $total;
            ?>
                    <tr>
                        <td><img src="img/produit/<?= $image ?>"></td>
                        <td><?= $libelle ?></td>
                        <td><?= $prix ?> DA</td>
                        <td>
                            <input type="hidden" name="id_produit[]" value="<?= $row['id_produit'] ?>">
                            <input type="hidden" name="type_produit[]" value="<?= $type ?>">
                            <input type="number" name="quantite[]" min="1" value="<?= $row['quantite'] ?>">
                        </td>
                        <td><span class="prix-total"><?= $total ?> DA</span></td>
                        <td>
                            <a href="vider.php?id=<?= $row['id_produit'] ?>&type=<?= $type ?>">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </td>
                    </tr>
            <?php
                }
            ?>
            </tbody>
        </table>
        <div class="panier-footer">
            <input type="submit" id="valider" name="valider" value="Valider la commande">
        </div>
    </form>

    </div>

    <?php

        if(isset($_POST['valider'])){

            for($i = 0; $i < count($_POST['quantite']);$i++){

                $sql = $pdo->prepare('UPDATE panier SET quantite=? WHERE id_produit=? AND id_session=?');

                $sql->execute([$_POST['quantite'][$i],$_POST['id_produit'][$i],$session_id]);

            }

            header('location:panier.php');

        }

    ?>

    <!--=========================================================

                        commande

    =========================================================-->

    <h1 id="text-commande">Valider la commande</h1>

    <div class="commande">

        <form method="POST">

            <h3>Entrer vos information ici</h3>

            <input type="hidden" name="id_session" value="<?=$session_id?>">

            <input type="text" name="nom" placeholder="Nom" required>

            <input type="tel" name="telephone" placeholder="N째 telephone" maxlength="10" required>

            <select name="wilaya" id="wilayaSelect">

                <option value="60">Wilaya</option>

                <?php

                    $sqlDelevery = $pdo->prepare('SELECT * FROM delivery');

                    $sqlDelevery->execute();

                    $lines = $sqlDelevery->fetchAll(PDO::FETCH_ASSOC);

                    foreach($lines as $line){

                ?>

                <option value="<?=$line['id']?>"><?=$line['wilaya']?></option>

                <?php } ?>

            </select>

            <input type="text" name="commune" placeholder="Commune">

            <div class="resume">

                <table>

                    <tr>

                        <td>prix des produit</td>

                        <td><?=$prixtotal?> DA</td>

                    </tr>

                    <tr>

                        <td>

                            <p>prix de livraison</p>

                            <div class="prixlivraison">

                                <div class="choix">

                                    <input type="radio" id="domicile" name="livraison" value="domicile" checked>

                                    <label for="domicile">A domicile</label>

                                    

                                </div>

                                <div class="choix">                                   

                                    <input type="radio" id="bureau" name="livraison" value="bureau"> 

                                    <label for="bureau">Au bureau</label>

                                </div>                                                                             

                            </div>

                        </td>

                        <td id="price"></td>

                    </tr>

                    <tr>

                        <td>prix total</td>

                        <td id="prixTotal"><?= $prixtotal ?></td>

                    </tr>

                </table>

            </div>

            <input type="submit" value="Acheter" name="commander">

        </form>

    </div>

    <?php

        if(isset($_POST['commander'])){

            $sqlVerefier = $pdo->prepare('SELECT * FROM panier WHERE id_session=? AND status=false');

            $sqlVerefier->execute([$session_id]);

            if($sqlVerefier->rowCount() >= 1){

                $nom = $_POST['nom'];

                $telephone = $_POST['telephone'];

                $wilaya = $_POST['wilaya'];

                $commune = $_POST['commune'];

                $livraison = $_POST['livraison'];

                $idSession = $_POST['id_session']; 

            

                $sqlStates = $pdo->prepare('INSERT INTO commande (nom,telephone,wilaya,commune,livraison,id_session) VALUES(?,?,?,?,?,?)');

                $sqlStates->execute([$nom,$telephone,$wilaya,$commune,$livraison,$idSession]);
                
                $sqlStatus = $pdo->prepare('UPDATE panier SET status=true WHERE id_session=? AND status=false ');
                $sqlStatus->execute([$session_id]);
                ?>    

                <script>

                    swal({

                        title: "Commande faite!",

                        text: "Merci <?=$_POST['nom']?> pour votre commande!",

                        icon: "success",

                    });

                </script><?php  }

        }

    ?>





   <!--=========================================================

                        footer

    ========================================================-->

    <?php include('partie/footer.php') ?>

</body>

</html>