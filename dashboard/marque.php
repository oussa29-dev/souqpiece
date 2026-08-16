<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Marque</title>
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

        <div class="barre">Marque de voiture</div>
        <div class="page-voiture">
            <?php
                if(isset($_GET['id'])){
                    $id = $_GET['id'];
                    $sqlMarque = $pdo->prepare('SELECT * FROM marque WHERE id_marque=?');
                    $sqlMarque->execute([$id]);
                    $marque = $sqlMarque->fetch(PDO::FETCH_ASSOC); ?>
                    <h2>Modifier une marque de voiture</h2>
                    <form method="POST" enctype="multipart/form-data" >
                        <label for="brand">Marque</label>
                        <input type="text" name="brand" placeholder="Audi BMW ...ext" value="<?=$marque['libelle']?>">
                        <label for="logo">Logo</label>
                        <input type="file" name="logo">
                        <input type="submit" value="modifier" name="modifier">
                    </form>
                <?php
                }else{
            ?>
            <h2>Ajouter une marque de voiture</h2>
            <form method="POST" enctype="multipart/form-data" >
                <label for="brand">Marque</label>
                <input type="text" name="brand" placeholder="Audi BMW ...ext">
                <label for="logo">Logo</label>
                <input type="file" name="logo">
                <input type="submit" value="ajouter" name="ajouter">
            </form>
            <?php  }
                if(isset($_POST['ajouter'])){
                    $brand = $_POST['brand'];
                    /*========upload img=========*/
                    function uploadImage($inputName) {
                        $file = $_FILES[$inputName];
                        $fileName = $file['name'];
                        $fileTmpName = $file['tmp_name'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $uniqueName = uniqid('', true) . '.' . $fileExt;
                        $fileDestination = '../img/logo/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $logo = uploadImage('logo');
                    if(!empty($brand)){
                        $sqlState = $pdo->prepare('INSERT INTO marque VALUES(null,?,?)');
                        $sqlState->execute([$brand,$logo]);
                        ?>    
                        <script>
                            swal({
                                title: "Insersion avec succes!",
                                text: "La marque <?=$brand?> a ete bien ajoute!",
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
                if(isset($_POST['modifier'])){
                    $brand = $_POST['brand'];
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
                        $fileDestination = '../img/logo/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $logo = uploadImage('logo');
                    $sqlModifie = 'UPDATE marque SET libelle=?';
                    $params = [$brand];
                    
                    if(!empty($img1)){
                        $sqlModifie .= ', logo=?';
                        $params[] = $logo;
                    }
                    // Ajouter la clause WHERE
                    $sqlModifie .= ' WHERE id_marque=?';
                    $params[] = $id;

                    $Modifie = $pdo->prepare($sqlModifie);
                    $updated = $Modifie->execute($params);
                    if($updated){
                        ?>    
                        <script>
                            swal({
                                title: "Modification avec succes!",
                                text: "La marque <?=$brand?> a ete bien modifie!",
                                icon: "success",
                            });
                        </script><?php
                    }
                    else{
                        echo "ERROR";
                    }
                }
            ?>
            <h2>Liste des marques disponible</h2>
            <table class="afficher-marque">
                <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Marque</th>
                        <th>Voiture</th>
                        <th>Modifier</th>
                        <th>Supprimer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $sql = 'SELECT * FROM marque ORDER BY libelle';
                        $query = $pdo->query($sql);
                        $rows = $query->fetchAll();
                        foreach($rows as $row){
                    ?>
                    <tr>
                        <td><img src="../img/logo/<?= $row['logo'] ?>"></td>
                        <td><?= $row['libelle'] ?></td>
                        <td><span class="nbrows">
                        <?php
                            $sqlVoiture = $pdo->prepare('SELECT COUNT(*) FROM voiture WHERE id_marque=?');
                            $sqlVoiture->execute([$row['id_marque']]);
                            $nbVoiture = $sqlVoiture->fetchColumn();
                            echo $nbVoiture ;
                        ?></span>
                        </td>
                        <td><a href="marque.php?id=<?=$row['id_marque']?>" class="btn-mod">Mod</a></td>
                        <td><a href="supprimer/sup-marque.php?id=<?=$row['id_marque']?>" class="btn-sup">Sup</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>


    </div>
</body>
</html>