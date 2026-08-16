<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Voiture</title>
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

        <div class="barre">Modele de voiture</div>
        <div class="page-voiture">
            <?php
                if(isset($_GET['id'])){
                    $id = $_GET['id'];
                    $requete = $pdo->prepare('SELECT * FROM voiture WHERE id_voiture=?');
                    $requete->execute([$id]);
                    $car = $requete->fetch(PDO::FETCH_ASSOC); ?>
                    <h2>Modifier un modele de voiture</h2>
                    <form method="POST" enctype="multipart/form-data">
                        <label for="brand">marque</label>
                        <select name="brand">
                            <?php
                                $sql = 'SELECT * FROM marque';
                                $query = $pdo->query($sql);
                                $rows = $query->fetchAll();
                                foreach($rows as $row){
                                    $selected = ($row['id_marque'] == $car['id_marque']) ? 'selected' : '';
                            ?>
                            <option value="<?=$row['id_marque']?>"<?=$selected?>><?=$row['libelle']?></option>
                            <?php } ?>
                        </select>
                        <label for="modele">modele</label>
                        <input type="text" name="modele" placeholder="Rs6 , Q3 ... ext" value="<?=$car['modele']?>">
                        <label for="voiture">image voiture</label>
                        <input type="file" name="voiture">
                        <input type="submit" value="modifier" name="modifier">
                    </form>
                <?php
                }else{
            ?>
            <h2>Ajouter un modele de voiture</h2>
            <form method="POST" enctype="multipart/form-data">
                <label for="brand">marque</label>
                <select name="brand">
                    <?php
                        $sql = 'SELECT * FROM marque';
                        $query = $pdo->query($sql);
                        $rows = $query->fetchAll();
                        foreach($rows as $row){
                    ?>
                    <option value="<?=$row['id_marque']?>"><?=$row['libelle']?></option>
                    <?php } ?>
                </select>
                <label for="modele">modele</label>
                <input type="text" name="modele" placeholder="Rs6 , Q3 ... ext">
                <label for="voiture">image voiture</label>
                <input type="file" name="voiture">
                <input type="submit" value="ajouter" name="ajouter">
            </form>
            <?php }
                if(isset($_POST['ajouter'])){
                    $brand = $_POST['brand'];
                    $modele = $_POST['modele'];
                    /*========upload img=========*/
                    function uploadImage($inputName) {
                        $file = $_FILES[$inputName];
                        $fileName = $file['name'];
                        $fileTmpName = $file['tmp_name'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $uniqueName = uniqid('', true) . '.' . $fileExt;
                        $fileDestination = '../img/voiture/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $voiture = uploadImage('voiture');
                    if(!empty($brand) & !empty($modele)){
                        $sqlState = $pdo->prepare('INSERT INTO voiture VALUES(null,?,?,?)');
                        $sqlState->execute([$brand,$modele,$voiture]);
                        ?>    
                        <script>
                            swal({
                                title: "Insersion avec succes!",
                                text: "La voiture <?=$modele?> a ete bien ajoute!",
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
                    $modele = $_POST['modele'];
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
                        $fileDestination = '../img/voiture/' . $uniqueName;
                        move_uploaded_file($fileTmpName, $fileDestination);
                        return $uniqueName;
                    }
                    $img = uploadImage('voiture');
                    $sqlModifie = 'UPDATE voiture SET modele=?, id_marque=?';
                    $params = [$modele,$brand];
                    
                    if(!empty($img)){
                        $sqlModifie .= ', img=?';
                        $params[] = $img;
                    }
                    // Ajouter la clause WHERE
                    $sqlModifie .= ' WHERE id_voiture=?';
                    $params[] = $id;

                    $Modifie = $pdo->prepare($sqlModifie);
                    $updated = $Modifie->execute($params);
                    if($updated){
                        ?>    
                        <script>
                            swal({
                                title: "Modification avec succes!",
                                text: "La voiture <?=$modele?> a ete bien modifie!",
                                icon: "success",
                            });
                        </script><?php
                    }
                    else{
                        echo "ERROR";
                    }
                }
            ?>
            <h2>Liste des voitures disponible</h2>
            <div class="filtre">
                <h4>filtrer selon la marque</h4>
                <form method="GET">
                    <select name="marque" id="marque">
                        <option value="all">Tous</option>
                    <?php
                    foreach($rows as $row){
                        $selected = ($_GET['marque'] == $row['id_marque']) ? 'selected' : '';
                    ?>
                        <option value="<?=$row['id_marque']?>"<?= $selected ?>><?=$row['libelle']?></option>
                    <?php } ?>
                    </select>
                    <input type="submit" value="filtrer" name="filtrer">
                </form>

                <?php
                    $marque = $_GET['marque'];
                    // Définissez le nombre d'éléments par page
                    $limit = 10;
                    
                    // Déterminez la page actuelle
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $offset = ($page - 1) * $limit;
                    
                    // Récupérez le nombre total de voitures en fonction du filtre
                    if (isset($_GET['filtrer']) && $_GET['marque'] !== 'all' || $_GET['marque']) {
                        $marque = $_GET['marque'];

                        // Compter les voitures filtrées
                        $sqlCount = $pdo->prepare('SELECT COUNT(*) FROM voiture WHERE id_marque = ?');
                        $sqlCount->execute([$marque]);
                        $total = $sqlCount->fetchColumn();
                
                        // Récupérer les voitures avec la pagination appliquée
                        $sqlVoiture = $pdo->prepare('SELECT *, marque.libelle as marque FROM voiture JOIN marque ON voiture.id_marque = marque.id_marque WHERE voiture.id_marque = ? ORDER BY modele LIMIT ' . $limit . ' OFFSET ' . $offset);
                        $sqlVoiture->execute([$marque]);
                    } else {
                        // Compter toutes les voitures
                        $sqlCount = $pdo->query('SELECT COUNT(*) FROM voiture');
                        $total = $sqlCount->fetchColumn();
                
                        // Récupérer toutes les voitures avec la pagination appliquée
                        $sqlVoiture = $pdo->query('SELECT *, marque.libelle as marque FROM voiture JOIN marque ON voiture.id_marque = marque.id_marque ORDER BY modele LIMIT ' . $limit . ' OFFSET ' . $offset);
                    }
                
                    // Calcul du nombre de pages
                    $totalPages = ceil($total / $limit);
                    $rowsVoiture = $sqlVoiture->fetchAll();
                ?>
            </div>
            <table class="afficher-marque">
                <thead>
                    <tr>
                        <th>image</th>
                        <th>Modele</th>
                        <th>Marque</th>
                        <th>Modifier</th>
                        <th>Supprimer</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                    foreach($rowsVoiture as $rowVoiture){
                ?>
                    <tr>
                        <td><img src="../img/voiture/<?= $rowVoiture['img'] ?>"></td>
                        <td><?= $rowVoiture['modele'] ?></td>
                        <td><span class="spanBlack"><?= $rowVoiture['marque'] ?></span></td>
                        <td><a href="voiture.php?id=<?=$rowVoiture['id_voiture']?>" class="btn-mod">Mod</a></td>
                        <td><a href="supprimer/sup-voiture.php?id=<?=$rowVoiture['id_voiture']?>" class="btn-sup">Sup</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?marque=<?=$marque?>&page=<?= $page - 1 ?>">Précédent</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?marque=<?=$marque?>&page=<?= $i ?>" <?= ($i == $page) ? 'class="active"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?marque=<?=$marque?>&page=<?= $page + 1 ?>">Suivant</a>
                <?php endif; ?>
            </div>
        </div>


    </div>
</body>
</html>