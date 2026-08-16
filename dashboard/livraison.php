<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Livraison</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
</head>
<body>
    <?php 
        ob_start();
        session_start();
        if(!isset( $_SESSION['utilisateur'])){
            header('location:connexion.php');
            exit;
        }
        require_once('database.php');
        include('include/menu.php');
     ?>
    <div class="site">
        <div class="barre">Tarif de livraison</div>
        <div class="delevery">
            <h3>modifier les prix de livraison</h3>
            <form method="POST">
                <input type="submit" value="modifier" name="modifier" id="mdf-delevery">
                <table>
                    <thead>
                        <th>Wilaya</th>
                        <th>Prix a domicile</th>
                        <th>Prix au bureau</th>
                    </thead>
                    <tbody>
                    <?php
                        $sqlStates = $pdo->prepare('SELECT * FROM delivery ORDER BY id');
                        $sqlStates->execute();
                        $rows = $sqlStates->fetchAll(PDO::FETCH_ASSOC);
                        foreach($rows as $row){
                    ?>
                        <tr>
                            <td><span><?=$row['wilaya']?></span></td>
                            <input type="hidden" name="id[]" value="<?=$row['id']?>">
                            <td><input type="number" name="domicile[]" value="<?=$row['domicile']?>"> DA</td>
                            <td><input type="number" name="bureau[]" value="<?=$row['bureau']?>"> DA</td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </form>
            <?php
                if(isset($_POST['modifier'])){
                    for($i = 0; $i < count($_POST['id']); $i++){
                        $sql = $pdo->prepare('UPDATE delivery SET domicile=?,bureau=? WHERE id=?');
                        $sql->execute([$_POST['domicile'][$i],$_POST['bureau'][$i],$_POST['id'][$i]]);
                    }
                    header('location:livraison.php');
                }
                ob_end_flush();
            ?>
        </div>
    </div>
</body>
</html>