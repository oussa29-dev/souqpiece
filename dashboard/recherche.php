<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Recherche</title>
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

        <div class="barre">Resultat de recherche</div>
        <div class="page-voiture">
            <h2>Liste des mots recherche</h2>
            <table class="afficher-marque">
                <thead>
                    <tr>
                        <th>Datetime</th>
                        <th>ID</th>
                        <th>Mot</th>
                        <th>Supprimer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $sql = 'SELECT * FROM recherche ORDER BY id_recherche DESC';
                        $query = $pdo->query($sql);
                        $rows = $query->fetchAll();
                        foreach($rows as $row){
                    ?>
                    <tr>
                        <td><?= $row['datetime'] ?></td>
                        <td><?= $row['id_recherche'] ?></td>
                        <td><?= $row['mot'] ?></td>
                        <td><a href="supprimer/sup-rech.php?id=<?=$row['id_recherche']?>" class="btn-sup">Sup</a></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <style>
                table td{
                    height:20px;
                }
            </style>
        </div>


    </div>
</body>
</html>