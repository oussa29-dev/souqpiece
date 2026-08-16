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
     ?>

    
    <div class="site">
        <div class="barre">Sous categorie 2</div>
        <!--=============Produit=======================-->
        <div class="page-voiture">
        <?php
            if(isset($_GET['id'])){ 
                $id = $_GET['id'];
                $sqlCategorie = $pdo->prepare('SELECT * FROM sous_categorie WHERE id_sous_categorie=?');
                $sqlCategorie->execute([$id]);
                $categories = $sqlCategorie->fetch(PDO::FETCH_ASSOC); 
            ?>
                <h2>Modifier une sous categorie</h2>
                <form method="POST" enctype="multipart/form-data" >
                    <label for="sous_categorie">Sous categorie 2</label>
                    <input type="text" name="sous_categorie" placeholder="plaquette de frein ...ext" value="<?=$categories['libelle']?>">
                    <select name="categorie">
                        <?php
                            $sqlCategorie = 'SELECT * FROM categorie';
                            $query = $pdo->query($sqlCategorie);
                            $rows = $query->fetchAll();
                            foreach($rows as $row){
                                $selected = ($row['id_categorie'] == $categories['id_categorie']) ? 'selected' : '';
                        ?>
                        <option value="<?= $row['id_categorie'] ?>"<?=$selected?> ><?= $row['libelle'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="image">image</label>
                    <input type="file" name="img">
                    <input type="submit" value="modifier" name="modifier">
                </form> <?php
            }else{
        ?>
        <h2>Ajouter une sous categorie</h2>
            <form method="POST" enctype="multipart/form-data" >
                <label for="sous_categorie">Sous categorie 2</label>
                <input type="text" name="sous_categorie2" placeholder="plaquette de frein ...ext">
                <select name="id_categorie">
                    <?php
                        $sqlCategorie = 'SELECT * FROM categorie';
                        $query = $pdo->query($sqlCategorie);
                        $rows = $query->fetchAll();
                        foreach($rows as $row){
                    ?>
                    <option value="<?= $row['id_categorie'] ?>"><?= $row['libelle'] ?></option>
                    <?php } ?>
                </select>
                <select name="sous_categorie">
                    <?php
                        $sqlCategorie2 = 'SELECT * FROM sous_categorie';
                        $query2 = $pdo->query($sqlCategorie2);
                        $rows2 = $query2->fetchAll();
                        foreach($rows2 as $row2){
                    ?>
                    <option value="<?= $row2['id_sous_categorie'] ?>"><?= $row2['libelle'] ?></option>
                    <?php } ?>
                </select>
                <input type="submit" value="ajouter" name="ajouter">
            </form>
            
            <?php }
                if(isset($_POST['ajouter'])){
                    $libelle = $_POST['sous_categorie2'];
                    $sous = $_POST['sous_categorie'];
                    $id_categorie = $_POST['id_categorie'];
                    if(!empty($libelle)){
                        $sqlState = $pdo->prepare('INSERT INTO sous_categorie2 VALUES(null,?,?,?)');
                        $sqlState->execute([$id_categorie,$sous,$libelle]);
                        echo 'insertion avec succes !';
                    }
                    else{
                        ?>
                        <div class="erreur">
                                <p>veuillez saiser les informations</p>
                            </div>   
                        <?php
                    }   
                }
                if(isset($_POST['modifier'])){
                    $sous = $_POST['sous_categorie'];
                    $categorie = $_POST['categorie'];
                    // ===========image php========
                    function uploadImage($inputName) {
                        $file = $_FILES[$inputName];
                        $fileName = $file['name'];
                        $fileTmpName = $file['tmp_name'];
                        // Si le fichier est vide, retourne une chaîne vide
                        if (empty($fileName)) {
                            return '';
                        }
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $uniqueName = uniqid('', true) . '.' . $fileExt;
                        $fileDestination = '../img/categories/sous_categorie/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $img = uploadImage('img');
                    $sqlModifie = 'UPDATE sous_categorie SET libelle=?, id_categorie=?';
                    $params = [$sous,$categorie];
                    
                    if(!empty($img)){
                        $sqlModifie .= ', img=?';
                        $params[] = $img;
                    }
                    // Ajouter la clause WHERE
                    $sqlModifie .= ' WHERE id_sous_categorie=?';
                    $params[] = $id;

                    $Modifie = $pdo->prepare($sqlModifie);
                    $updated = $Modifie->execute($params);
                    if($updated){
                        header('location:sous-categorie.php');
                    }
                    else{
                        echo "ERROR";
                    }
                }
            ?>
            <h2>Liste des sous categorie</h2>
            <div class="filtre">
                <h4>filtrer selon la categorie</h4>
                <form method="POST">
                    <select name="libCategorie">
                        <option value="tous">Tous</option>
                    <?php
                        foreach($rows as $row){
                            $selected = ($_POST['categorie'] == $row['libelle']) ? 'selected' : '';
                    ?>
                        <option value="<?= $row['id_categorie'] ?>"<?= $selected ?>><?= $row['libelle'] ?></option>
                    <?php } ?>
                    </select>
                    <input type="submit" value="filtrer" name="filtrer">
                </form>
            </div>
            <table class="afficher-marque" style="width:100%">
                <thead>
                    <tr>
                        <th>Sous categorie 2</th>
                        <th>Sous categorie 1</th>
                        <th>Categorie</th>
                        <th>Modifier</th>
                        <th>Supprimer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        
                        if(isset($_POST['filtrer'])){
                            $cate = $_POST['idCategorie'];
                            if($cate === 'tous'){
                                $sqlSous = $pdo->prepare('SELECT * FROM sous_categorie2');
                                $sqlSous->execute();
                                $sous = $sqlSous->fetchAll(PDO::FETCH_ASSOC);
                            }
                            else{

                                $sqlSous = $pdo->prepare('SELECT * FROM sous_categorie2 WHERE id_categorie = ?');
                                $sqlSous->execute([$idCategorie]);
                                $sous = $sqlSous->fetchAll(PDO::FETCH_ASSOC);
                            }
                        }else{
                            $sqlSous = $pdo->prepare('SELECT * FROM sous_categorie2');
                            $sqlSous->execute();
                            $sous = $sqlSous->fetchAll(PDO::FETCH_ASSOC);
                        
                        }
                        foreach($sous as $sou){
                            $sqlSous1 = $pdo->prepare('SELECT * FROM sous_categorie WHERE id_sous_categorie=?');
                            $sqlSous1->execute([$sou['id_sous_categorie']]);
                            $sous_cate = $sqlSous1->fetch();
                    ?>
                    <tr>
                        <td><?= $sou['libelle'] ?></td>
                        <td><?= $sous_cate['libelle'] ?></td>
                        <?php
                            $requeteCate = $pdo->prepare('SELECT categorie.libelle FROM categorie JOIN sous_categorie ON categorie.id_categorie = ? ');
                            $requeteCate->execute([$sou['id_categorie']]);
                            $libelle = $requeteCate->fetchColumn();
                        ?>
                        <td><span class="spanBlack"><?= $libelle ?></span></td>
                        <td><a href="sous-categorie2.php?id=<?=$sou['id_sous_categorie2']?>" class="btn-mod">Mod</a></td>
                        <td><a href="supprimer/sup-sous2.php?id=<?=$sou['id_sous_categorie2']?>" class="btn-sup">Sup</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html> 