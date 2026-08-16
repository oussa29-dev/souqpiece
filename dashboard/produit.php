<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Dashboard Produits</title>
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
        <div class="barre">Produit</div>
        <!--=============Produit=======================-->
        <div class="page-produit">
            <h3>Liste des produits</h3>
            <!--======filtre========-->
            <div class="filtre">
                <form method="GET">
                    <select name="marque" id="marqueSelect">
                        <option value="marque">marque</option>
                        <?php
                            $sqlMarque = 'SELECT * FROM marque';
                            $query = $pdo->query($sqlMarque);
                            $marqueLibs = $query->fetchAll();
                            foreach($marqueLibs as $marqueLib){
                        ?>
                        <option value="<?= $marqueLib['id_marque'] ?>"><?= $marqueLib['libelle'] ?></option>
                        <?php } ?>
                    </select>
                    <select name="modele" id="modeleSelect">
                        <option value="modele">modele</option>
                    </select>
                    <script>
                     document.getElementById('marqueSelect').addEventListener('change', function() {
                        var marqueId = this.value; // Récupère l'ID de la marque sélectionnée
                        
                        // Envoie une requête AJAX pour récupérer toutes les voitures correspondantes
                        var xhr = new XMLHttpRequest();
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === XMLHttpRequest.DONE) {
                                if (xhr.status === 200) {
                                    // Met à jour le contenu du deuxième select avec les voitures récupérées
                                    var voitures = JSON.parse(xhr.responseText);
                                    var modeleSelect = document.getElementById('modeleSelect');
                                    modeleSelect.innerHTML = ''; // Réinitialise le select
                                    voitures.forEach(function(voiture) {
                                        var option = document.createElement('option');
                                        option.value = voiture.id_voiture; // Utilisez l'ID de la voiture comme valeur
                                        option.textContent = voiture.modele; // Utilisez le nom de la voiture comme texte
                                        modeleSelect.appendChild(option);
                                    });
                                } else {
                                    console.error('Erreur lors de la récupération des voitures');
                                }
                            }
                        };
                        xhr.open('GET', 'get_modele.php?marque_id=' + marqueId, true);
                        xhr.send();
                        });
                    </script>
                    <select name="categorie" id="categorieSelect">
                        <option value="categorie">categorie</option>
                        <?php
                            $sqlCategorie = 'SELECT * FROM categorie';
                            $queryCate = $pdo->query($sqlCategorie);
                            $cateLibs = $queryCate->fetchAll();
                            foreach($cateLibs as $cateLib){
                        ?>
                        <option value="<?= $cateLib['id_categorie'] ?>"><?= $cateLib['libelle'] ?></option>
                        <?php } ?>
                        <option value="0">Sans categorie</option>
                    </select>
                    <select name="sous_categorie" id="sousSelect">
                        <option value="sous_categorie">sous categorie</option>
                    </select>
                    <input type="submit" value="filtrer" name="filtrer">
                    <br>
                    <script>
                    document.getElementById('categorieSelect').addEventListener('change', function() {
                        var categorieId = this.value; // Récupère l'ID de la marque sélectionnée
                        
                        // Envoie une requête AJAX pour récupérer toutes les sous correspondantes
                        var xhr = new XMLHttpRequest();
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === XMLHttpRequest.DONE) {
                                if (xhr.status === 200) {
                                    // Met à jour le contenu du deuxième select avec les sous récupérées
                                    var sous = JSON.parse(xhr.responseText);
                                    var sousSelect = document.getElementById('sousSelect');
                                    sousSelect.innerHTML = ''; // Réinitialise le select
                                    sous.forEach(function(sous) {
                                        var option = document.createElement('option');
                                        option.value = sous.id_sous_categorie; // Utilisez l'ID de la sous comme valeur
                                        option.textContent = sous.libelle; // Utilisez le nom de la sous comme texte
                                        sousSelect.appendChild(option);
                                    });
                                } else {
                                    console.error('Erreur lors de la récupération des sous categorie');
                                }
                            }
                        };
                        xhr.open('GET', 'get_sous.php?categorie_id=' + categorieId, true);
                        xhr.send();
                        });
                    </script>
                    <!--======-->
                </form>
            </div>
            <div class="rechercher-prod">
                <form method="GET">
                    <input type="text" name="reference" placeholder="référence produit">
                    <input type="submit" name="rechercher" value="rechercher">
                </form>
            </div>
        
            <a href="ajouter-produit.php" class="btn-site">Ajouter un produit</a>
            <table>
                <thead>
                    <tr>
                        <th>image</th>
                        <th>Produit</th>
                        <th>Prix</th>
                        <th>marque</th>
                        <th>modele</th>
                        <th>categorie</th>
                        <th>sous categorie</th>
                        <th>stock</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                    // Nombre de produits par page
                    $itemsPerPage = 30;
                
                    // Page actuelle
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $offset = ($page > 1) ? ($page * $itemsPerPage) - $itemsPerPage : 0;
                
                    // Définition des filtres
                    $conditions = [];
                    $params = [];
                    $marqueSelect = isset($_POST['marque']) ? $_POST['marque'] : (isset($_GET['marque']) ? $_GET['marque'] : "marque");
                    $modeleSelect = isset($_POST['modele']) ? $_POST['modele'] : (isset($_GET['modele']) ? $_GET['modele'] : "modele");
                    $categorieSelect = isset($_POST['categorie']) ? $_POST['categorie'] : (isset($_GET['categorie']) ? $_GET['categorie'] : "categorie");
                    $sousSelect = isset($_POST['sous_categorie']) ? $_POST['sous_categorie'] : (isset($_GET['sous_categorie']) ? $_GET['sous_categorie'] : "sous_categorie");
                    $reference = isset($_POST['reference']) ? $_POST['reference'] : (isset($_GET['reference']) ? $_GET['reference'] : "");
                
                    // Ajouter des conditions en fonction des filtres
                    if ($modeleSelect != "modele") {
                        $conditions[] = 'pvd.id_voiture = :modele';
                        $params[':modele'] = $modeleSelect;
                    }
                    if ($categorieSelect != "categorie") {
                        $conditions[] = 'produit.id_categorie = :categorie';
                        $params[':categorie'] = $categorieSelect;
                    }
                    if ($sousSelect != "sous_categorie") {
                        $conditions[] = 'produit.id_sous_categorie = :sous_categorie';
                        $params[':sous_categorie'] = $sousSelect;
                    }
                
                    // Recherche par référence
                    if (!empty($reference)) {
                        $conditions[] = 'produit.id_produit IN (SELECT id_produit FROM reference WHERE reference LIKE :reference)';
                        $params[':reference'] = '%' . $reference . '%';
                    }
                
                    // Construire la requête SQL avec les conditions
                    $query = '
                        SELECT produit.*, pvd.id_voiture as voiture
                        FROM produit
                        LEFT JOIN pvd ON produit.id_produit = pvd.id_produit
                    ';
                    if (!empty($conditions)) {
                        $query .= ' WHERE ' . implode(' AND ', $conditions);
                    }
                    $query .= ' LIMIT ' . (int)$itemsPerPage . ' OFFSET ' . (int)$offset;
                
                    // Préparer et exécuter la requête
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                    // Si aucun produit trouvé
                    if (empty($produits)) {
                        echo "<p>Aucun produit trouvé pour votre recherche.</p>";
                    } else {
                        foreach ($produits as $produit) {
                            // Récupération des informations supplémentaires du produit
                            $sqlMarque = $pdo->prepare('SELECT libelle FROM marque WHERE id_marque = (SELECT id_marque FROM voiture WHERE id_voiture = ?)');
                            $sqlMarque->execute([$produit['voiture']]);
                            $marque = $sqlMarque->fetchColumn();
                    
                            $sqlModele = $pdo->prepare('SELECT modele FROM voiture WHERE id_voiture=?');
                            $sqlModele->execute([$produit['voiture']]);
                            $voiture = $sqlModele->fetchColumn();
                    
                            $sqlCate = $pdo->prepare('SELECT libelle FROM categorie WHERE id_categorie=?');
                            $sqlCate->execute([$produit['id_categorie']]);
                            $categorie = $sqlCate->fetchColumn();
                    
                            $sqlSous = $pdo->prepare('SELECT libelle FROM sous_categorie WHERE id_sous_categorie=?');
                            $sqlSous->execute([$produit['id_sous_categorie']]);
                            $sous = $sqlSous->fetchColumn();
                    ?>
                            <tr>
                                <td><img src="../img/produit/<?=$produit['img1']?>"></td>
                                <td><?=$produit['libelle']?></td>
                                <td><span class="spanGreen"><?=$produit['prix']?> DA</span></td>
                                <td><?=$marque?></td>
                                <td><?=$voiture?></td>
                                <td><?=$categorie?></td>
                                <td><?=$sous?></td>
                                <td><span class="spanOrange"><?= $produit['stock'] == 1 ? 'disponible' : 'non disponible' ?></span></td>
                                <td><a href="ajouter-produit.php?id=<?=$produit['id_produit']?>" class="btn-mod">Mod</a></td>
                                <td><a href="supprimer/sup-produit.php?id=<?=$produit['id_produit']?>" class="btn-sup">Sup</a></td>
                            </tr>
                    <?php
                        }
                    }
                ?>
                </tbody>

                </table>

               <?php
            // Gestion de la pagination
            $totalQuery = '
                SELECT COUNT(*)
                FROM produit
                LEFT JOIN pvd ON produit.id_produit = pvd.id_produit
            ';
            if (!empty($conditions)) {
                $totalQuery .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $totalStmt = $pdo->prepare($totalQuery);
            $totalStmt->execute($params);
            $totalItems = $totalStmt->fetchColumn();
            $totalPages = ceil($totalItems / $itemsPerPage);
            
            $baseUrl = $_SERVER['PHP_SELF'] . '?'; 
            if (isset($_GET['modele'])) $baseUrl .= 'modele=' . urlencode($_GET['modele']) . '&';
            if (isset($_GET['sous_categorie'])) $baseUrl .= 'sous_categorie=' . urlencode($_GET['sous_categorie']) . '&';
            if (isset($_GET['categorie'])) $baseUrl .= 'categorie=' . urlencode($_GET['categorie']) . '&';
            if (isset($_GET['reference'])) $baseUrl .= 'reference=' . urlencode($_GET['reference']) . '&';

            // Affichage de la pagination
            echo '<div class="pagination">';
            
            // Bouton vers la première page
            if ($page > 1) {
                echo '<a href="' . $baseUrl . 'page=1">1</a>';
            }
            
            // Ajout de "..." si la page actuelle est éloignée du début
            if ($page > 3) {
                echo '<span class="dots">...</span>';
            }
            
            // Pages autour de la page actuelle
            $start = max(2, $page - 1);
            $end = min($totalPages - 1, $page + 1);
            
            for ($i = $start; $i <= $end; $i++) {
                if ($i == $page) {
                    echo '<span class="current-page">' . $i . '</span>';
                } else {
                    echo '<a href="' . $baseUrl . 'page=' . $i . '">' . $i . '</a>';
                }
            }
            
            // Ajout de "..." si la page actuelle est éloignée de la fin
            if ($page < $totalPages - 2) {
                echo '<span class="dots">...</span>';
            }
            
            // Bouton vers la dernière page
            if ($page < $totalPages) {
                echo '<a href="' . $baseUrl . 'page=' . $totalPages . '">' . $totalPages . '</a>';
            }
            
            // Bouton "Suivant"
            if ($page < $totalPages) {
                echo '<a href="' . $baseUrl . 'page=' . ($page + 1) . '">Suivant</a>';
            }
            
            echo '</div>';
        ?>
            

        </div>

    </div>


</body>
</html>