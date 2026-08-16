<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
</head>
<body>
    <?php 
        session_start();
        if(!isset( $_SESSION['utilisateur'])){
            header('location:connexion.php');
            exit;
        }
        require_once('database.php');
        include('include/menu.php');
        $id = $_GET['id'];
        $sqlStates = $pdo->prepare('SELECT * FROM commande WHERE id_commande=?');
        $sqlStates->execute([$id]);
        $personne = $sqlStates->fetch(PDO::FETCH_ASSOC);
     ?>

    
    <div class="site">
        <div class="barre">Commande</div>
        <!--=============Commande=======================-->
        <div class="page-produit">
            <h3>Detail de la commande</h3>
            <table class="table-detail">
                <tr>
                    <td>Nom</td>
                    <td><?=$personne['nom']?></td>
                </tr>
                <tr>
                    <td>Telephone</td>
                    <td>0<?=$personne['telephone']?></td>
                </tr>
                <tr>
                    <td>Wilaya</td>
                    <?php 
                        $sqlWilaya = $pdo->prepare('SELECT wilaya FROM delivery WHERE id=?');
                        $sqlWilaya->execute([$personne['wilaya']]);
                        $wilaya = $sqlWilaya->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <td><?=$wilaya['wilaya']?></td>
                </tr>
                <tr>
                    <td>Commune</td>
                    <td><?=$personne['commune']?></td>
                </tr>
            </table>
       <?php
            $sqlDetail = $pdo->prepare('SELECT * FROM panier WHERE id_session=? AND status=true');
            $sqlDetail->execute([$personne['id_session']]);
            $details = $sqlDetail->fetchAll(PDO::FETCH_ASSOC);
            $i = 1;
            
            // Charger les données JSON
            $jsonFile = 'data/stock.json'; // À adapter
            $jsonData = json_decode(file_get_contents($jsonFile), true);
            
            foreach($details as $detail) {
                if($detail['id_voiture'] != 0) {
                    // Produit de la base de données
                    $sqlProduit = $pdo->prepare('SELECT produit.*,voiture.*,reference.reference as ref,categorie.libelle as cateLib,sous_categorie.libelle as souLib 
                        FROM produit,voiture,reference,categorie,sous_categorie 
                        WHERE produit.id_produit=? 
                        AND voiture.id_voiture =? AND reference.id_produit=produit.id_produit
                        AND categorie.id_categorie = produit.id_categorie
                        AND sous_categorie.id_sous_categorie = produit.id_sous_categorie');
                    $sqlProduit->execute([$detail['id_produit'], $detail['id_voiture']]);
                    $produit = $sqlProduit->fetch(PDO::FETCH_ASSOC);
                    
                    $libelle = $produit['libelle'];
                    $reference = $produit['ref'];
                    $marquePiece = $produit['marquepiece'];
                    $prix = $produit['prix'];
                    
                    $sqlMarque = $pdo->prepare('SELECT libelle FROM marque WHERE id_marque=?');
                    $sqlMarque->execute([$produit['id_marque']]);
                    $marque = $sqlMarque->fetch(PDO::FETCH_ASSOC);
                    $marqueVoiture = $marque['libelle'];
                    
                    $modele = $produit['modele'];
                    $categorie = $produit['cateLib'];
                    $sousCategorie = $produit['souLib'];
                }
                else{
                    // Cas où id_voiture = 0 : requête sans la jointure voiture
                    $sqlProduit = $pdo->prepare('SELECT produit.*,reference.reference as ref
                        FROM produit,reference 
                        WHERE produit.id_produit=? 
                        AND reference.id_produit=produit.id_produit');
                    $sqlProduit->execute([$detail['id_produit']]);
                    $produit = $sqlProduit->fetch(PDO::FETCH_ASSOC);
                    $libelle = $produit['libelle'];
                    $reference = $produit['ref'];
                    $marquePiece = $produit['marquepiece'];
                    $prix = $produit['prix'];

                }
                // else {
                //     // Produit du JSON
                //     $reference = $detail['id_produit'];
                //     $produitJson = null;
                    
                //     foreach($jsonData as $item) {
                //         if($item['reference'] == $reference) {
                //             $produitJson = $item;
                //             break;
                //         }
                //     }
                    
                //     if($produitJson) {
                //         $libelle = $produitJson['marque'] . ' (JSON)';
                //         $reference = $produitJson['reference'];
                //         $marquePiece = $produitJson['marque'];
                //         $prix = $produitJson['prix'];
                        
                //         // Ces informations ne sont pas disponibles pour les produits JSON
                //         $marqueVoiture = 'Non spécifiée';
                //         $modele = 'Non spécifié';
                //         $categorie = 'Non spécifiée';
                //         $sousCategorie = 'Non spécifiée';
                //     } else {
                //         // Produit non trouvé - vous pourriez choisir de l'ignorer
                //         continue;
                //     }
                // }
                
                $prixTotal = $prix * $detail['quantite'];
            ?>
                <h3>Produit <?= $i ?></h3>
                <table class="table-detail">
                    <tr>
                        <td>Produit</td>
                        <td><?= htmlspecialchars($libelle) ?></td>
                    </tr>
                    <tr>
                        <td>Référence pièce</td>
                        <td><?= htmlspecialchars($reference) ?></td>
                    </tr>
                    <tr>
                        <td>Marque pièce</td>
                        <td><?= htmlspecialchars($marquePiece) ?></td>
                    </tr>
                    <tr>
                        <td>Quantité</td>
                        <td><?= $detail['quantite'] ?></td>
                    </tr>
                    <tr>
                        <td>Prix total</td>
                        <td><?= $prixTotal ?> DA</td>
                    </tr>
                    <tr>
                        <td>Marque voiture</td>
                        <td><?= htmlspecialchars($marqueVoiture) ?></td>
                    </tr>
                    <tr>
                        <td>Modèle</td>
                        <td><?= htmlspecialchars($modele) ?></td>
                    </tr>
                    <tr>
                        <td>Catégorie</td>
                        <td><?= htmlspecialchars($categorie) ?></td>
                    </tr>
                    <tr>
                        <td>Sous catégorie</td>
                        <td><?= htmlspecialchars($sousCategorie) ?></td>
                    </tr>
                </table>
                <?php 
                    $i++; 
            } 
        ?>
        </div>

    </div>


</body>
</html>