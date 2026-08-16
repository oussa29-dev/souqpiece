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
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
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
        <div class="barre">Categorie</div>
        <!--=============Produit=======================-->
        <div class="page-voiture">
        <?php
            if(isset($_GET['id'])){
                $id = $_GET['id'];
                $sqlCategorie = $pdo->prepare('SELECT * FROM categorie WHERE id_categorie=?');
                $sqlCategorie->execute([$id]);
                $categories = $sqlCategorie->fetch(PDO::FETCH_ASSOC); 
                ?>
                <h2>Modifier une categorie</h2>
                <form method="POST" enctype="multipart/form-data" >
                    <label for="categoire">Categorie</label>
                    <input type="text" name="categorie" placeholder="Moteur frein ...ext" value="<?=$categories['libelle']?>">
                    <label for="categoire">Categorie en arabe</label>
                    <input type="text" name="categorie_ar" placeholder="اسم الصنف بالعربية" value="<?=$categories['arabe']?>">
                    <label for="image">image</label>
                    <input type="file" name="img">
                    <input type="submit" value="modifier" name="modifier">
                </form><?php
            }else{
        ?>
        <h2>Ajouter une categorie</h2>
            <form method="POST" enctype="multipart/form-data" >
                <label for="categoire">Categorie</label>
                <input type="text" name="categorie" placeholder="Moteur frein ...ext">
                <label for="categoire">Categorie en arabe</label>
                <input type="text" name="categorie_ar" placeholder="اسم الصنف بالعربية">
                <label for="image">image</label>
                <input type="file" name="img">
                <input type="submit" value="ajouter" name="ajouter">
            </form>
        <?php } ?>
            <?php
                if(isset($_POST['ajouter'])){
                    $categorie = $_POST['categorie'];
                    $categorie_ar = $_POST['categorie_ar'];
                    /*========upload img=========*/
                    function uploadImage($inputName) {
                        $file = $_FILES[$inputName];
                        $fileName = $file['name'];
                        $fileTmpName = $file['tmp_name'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $uniqueName = uniqid('', true) . '.' . $fileExt;
                        $fileDestination = '../img/categories/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $img = uploadImage('img');
                    if(!empty($categorie)){
                        $sqlStates = $pdo->prepare('INSERT INTO categorie (libelle,arabe,img)VALUES(?,?,?)');
                        $sqlStates->execute([$categorie,$categorie_ar,$img]);
                        ?>    
                        <script>
                            swal({
                                title: "Insertion avec succes!",
                                text: "La categorie <?=$categorie?> a ete bien ajoute!",
                                icon: "success",
                            });
                        </script><?php  
                    }
                    else{
                        ?>
                        <div class="erreur">
                                <p>veuillez saiser les informations</p>
                            </div>   
                        <?php
                    } 
                }
            ?>
            <?php
                if(isset($_POST['modifier'])){
                    $categorie = $_POST['categorie'];
                    $categorie_ar = $_POST['categorie_ar'];
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
                        $fileDestination = '../img/categories/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $img1 = uploadImage('img');

                    $sqlModifie = 'UPDATE categorie SET libelle=?, arabe=?';
                    $params = [$categorie,$categorie_ar];
                    
                    if(!empty($img1)){
                        $sqlModifie .= ', img=?';
                        $params[] = $img1;
                    }
                    // Ajouter la clause WHERE
                    $sqlModifie .= ' WHERE id_categorie=?';
                    $params[] = $id;

                    $Modifie = $pdo->prepare($sqlModifie);
                    $updated = $Modifie->execute($params);
                    if($updated){ ?>
                        <script>
                        swal({
                            title: "Modification avec succes!",
                            text: "La categorie <?=$categorie?> a ete bien modifie!",
                            icon: "success",
                        });
                    </script><?php 
                    }
                    else{
                        echo "ERROR";
                    }
                
                }
            ?>
            <a href="sous-categorie.php" class="btn-site">Sous categorie</a>
           <!-- <a href="sous-categorie2.php" class="btn-site">Sous categorie 2</a>-->
            <h2>Liste des categories</h2>
            <table class="afficher-marque">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Categorie</th>
                        <th>En arabe</th>
                        <th>Sous categorie</th>
                        <th>Modifier</th>
                        <th>Supprimer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $sql = 'SELECT * FROM categorie';
                        $query = $pdo->query($sql);
                        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach($rows as $row){
                    ?>
                    <tr>
                        <td><img src="../img/categories/<?=$row['img']?>" alt=""></td>
                        <td><?=$row['libelle']?></td>
                        <td><?=$row['arabe']?></td>
                        <td><span class="nbrows">
                        <?php
                            $sqlSous = $pdo->prepare('SELECT COUNT(*) FROM sous_categorie WHERE id_categorie=?');
                            $sqlSous->execute([$row['id_categorie']]);
                            $nbSous = $sqlSous->fetchColumn();
                            echo $nbSous ;
                        ?></span>
                        </td>
                        <td><a href="categorie.php?id=<?=$row['id_categorie']?>" class="btn-mod">Mod</a></td>
                        <td><a href="supprimer/sup-categorie.php?id=<?=$row['id_categorie']?>" class="btn-sup">Sup</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

    </div>


</body>
</html>