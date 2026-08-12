<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/script.js"></script>
    <title>SOUQPIECE | Produits</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <script src="https://kit.fontawesome.com/abbd21db44.js" crossorigin="anonymous"></script>
</head>
<body>

    <!--============================================================
                    header
    =============================================================-->
    <?php 
        require_once('dashboard/database.php');
        include('partie/navbar.php'); 
    ?>
    <!--============================================================
                    Produit
    =============================================================-->
    <div class="headProd">
        <h1>SOUQPIECE</h1>
        <p>Trouvez les meilleures pièces de rechange pour vos véhicules.</p>
    </div>
    <div class="page-produit">
        <div class="text">
            <h2>Decouvré nos produits</h2>
        </div>
            <div class="filtre">
                <form method="GET">
                    <select name="marque" id="marqueSelect">
                        <option value="">marque</option>
                        <?php
                            $sqlMarque = 'SELECT * FROM marque';
                            $query = $pdo->query($sqlMarque);
                            $marqueLibs = $query->fetchAll();
                            foreach($marqueLibs as $marqueLib){
                        ?>
                        <option value="<?= $marqueLib['id_marque'] ?>"><?= $marqueLib['libelle'] ?></option>
                        <?php } ?>
                    </select>
                    <select name="id_voiture" id="modeleSelect">
                        <option value="">modele</option>
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
                        xhr.open('GET', 'dashboard/get_modele.php?marque_id=' + marqueId, true);
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
                    </select>
                    <select name="sous" id="sousSelect">
                        <option value="">sous categorie</option>
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
                        xhr.open('GET', 'dashboard/get_sous.php?categorie_id=' + categorieId, true);
                        xhr.send();
                        });
                    </script>
                    <!--======-->
                </form>
            </div>
            <div class="recherche">
                <form method="GET" id="searchForm">
                    <div class="search-autocomplete">
                        <input type="text" placeholder="Référence ou description" name="query" id="searchInput" autocomplete="off">
                        <div class="suggestions-box"></div>
                    </div>
                    <input type="submit" name="rechercher" value="Rechercher">
                </form>
            </div>
        <!--===============tout les produit===============================-->
        <div class="product-grid">
            <?php
                $itemsPerPage = 12; // Nombre de produits par page
                $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1; // Page actuelle
                $offset = ($page - 1) * $itemsPerPage; // Calcul de l'offset
            
                $conditions = [];
                $params = [];

                // Gestion des filtres par `id_voiture` et `sous_categorie`
                if (isset($_GET['id_voiture']) && !empty($_GET['id_voiture'])) {
                    $conditions[] = 'pvd.id_voiture = ?';
                    $params[] = (int)$_GET['id_voiture'];
                }
                if (isset($_GET['sous']) && !empty($_GET['sous'])) {
                    $conditions[] = 'produit.id_sous_categorie = ?';
                    $params[] = (int)$_GET['sous'];
                }
                if(isset($_GET['id_categorie']) && !empty($_GET['id_categorie'])){
                    $conditions[] = 'produit.id_categorie = ?';
                    $params[] = (int)$_GET['id_categorie'];
                }

                if (isset($_GET['query'])) {
                    $searchTerm = trim($_GET['query']);
                    
                    // Normalisation : remplacer les espaces par des * pour unifier le traitement
                    $normalizedSearchTerm = str_replace(' ', '*', $searchTerm);
                    
                    // Gestion de la recherche avec étoiles (*) ou espaces
                    if (strpos($normalizedSearchTerm, '*') !== false) {
                        // Séparation des termes par *
                        $terms = explode('*', $normalizedSearchTerm);
                        $terms = array_map('trim', $terms);
                        $terms = array_filter($terms); // Supprime les termes vides
                        
                        // Recherche par référence
                        $refConditions = [];
                        $refParams = [];
                        foreach ($terms as $term) {
                            $refConditions[] = 'reference LIKE ?';
                            $refParams[] = '%' . $term . '%';
                        }
                        $sqlRef = $pdo->prepare('SELECT id_produit FROM reference WHERE ' . implode(' AND ', $refConditions));
                        $sqlRef->execute($refParams);
                        $idProdsRef = $sqlRef->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Recherche par description
                        $descConditions = [];
                        $descParams = [];
                        foreach ($terms as $term) {
                            $descConditions[] = 'description LIKE ?';
                            $descParams[] = '%' . $term . '%';
                        }
                        $sqlDesc = $pdo->prepare('SELECT DISTINCT pvd.id_produit FROM pvd WHERE ' . implode(' AND ', $descConditions));
                        $sqlDesc->execute($descParams);
                        $idProdsDesc = $sqlDesc->fetchAll(PDO::FETCH_COLUMN);

                        // Recherche par libellé
                        $libelleConditions = [];
                        $libelleParams = [];
                        foreach ($terms as $term) {
                            $libelleConditions[] = 'libelle LIKE ?';
                            $libelleParams[] = '%' . $term . '%';
                        }
                        $sqlLibbelle = $pdo->prepare('SELECT id_produit FROM produit WHERE ' . implode(' AND ', $libelleConditions));
                        $sqlLibbelle->execute($libelleParams);
                        $idProdsLibbelle = $sqlLibbelle->fetchAll(PDO::FETCH_COLUMN);
                        
                        $idProds = array_unique(array_merge($idProdsRef, $idProdsDesc, $idProdsLibbelle));
                        
                        if (!empty($idProds)) {
                            $placeholders = str_repeat('?,', count($idProds) - 1) . '?';
                            $conditions[] = 'produit.id_produit IN (' . $placeholders . ')';
                            $params = array_merge($params, $idProds);
                        }else {
                            // Forcer la requête à ne rien trouver si aucun ID ne correspond à la recherche
                            $conditions[] = 'produit.id_produit = 0';
                        }
                    } else {
                        // Recherche normale (sans étoiles et sans espaces significatifs) - votre code original
                        $ref = $searchTerm;
                        $sqlRef = $pdo->prepare('SELECT id_produit FROM reference WHERE reference LIKE ?');
                        $sqlRef->execute(['%' . $ref . '%']);
                        $idProdsRef = $sqlRef->fetchAll(PDO::FETCH_COLUMN);
                        
                        $sqlDesc = $pdo->prepare('SELECT DISTINCT pvd.id_produit FROM pvd WHERE description LIKE ?');
                        $sqlDesc->execute(['%' . $ref . '%']);
                        $idProdsDesc = $sqlDesc->fetchAll(PDO::FETCH_COLUMN);

                        $sqlLibbelle = $pdo->prepare('SELECT id_produit FROM produit WHERE libelle LIKE ?');
                        $sqlLibbelle->execute(['%' . $ref . '%']);
                        $idProdsLibbelle = $sqlLibbelle->fetchAll(PDO::FETCH_COLUMN);
                        
                        $idProds = array_unique(array_merge($idProdsRef, $idProdsDesc, $idProdsLibbelle));
                        
                        if (!empty($idProds)) {
                            $placeholders = str_repeat('?,', count($idProds) - 1) . '?';
                            $conditions[] = 'produit.id_produit IN (' . $placeholders . ')';
                            $params = array_merge($params, $idProds);
                        }else {
                            // Forcer la requête à ne rien trouver si aucun ID ne correspond à la recherche
                            $conditions[] = 'produit.id_produit = 0';
                        }
                    }
                }
                
                // Générer la requête SQL avec les filtres
                $query = '
                    SELECT 
                        produit.*, 
                        pvd.id_voiture as voiture,
                        v.modele,
                        m.libelle as marque_nom,
                        c.libelle as categorie_nom
                    FROM produit
                    LEFT JOIN pvd ON produit.id_produit = pvd.id_produit
                    LEFT JOIN voiture v ON pvd.id_voiture = v.id_voiture
                    LEFT JOIN marque m ON v.id_marque = m.id_marque
                    LEFT JOIN categorie c ON produit.id_categorie = c.id_categorie
                ';
                if (!empty($conditions)) {
                    $query .= ' WHERE ' . implode(' AND ', $conditions);
                }
                // On ajoute un GROUP BY pour éviter les doublons si un produit est dans plusieurs PVD
                $query .= ' GROUP BY produit.id_produit';
                $query .= ' LIMIT ' . (int)$itemsPerPage . ' OFFSET ' . (int)$offset;
            
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);



                // Si aucun produit trouvé
                if (empty($produits)) {
                    if(empty($idProds)){
                        $sqlRech = $pdo->prepare('INSERT INTO recherche(mot) VALUE(?)');
                        $sqlRech->execute([$ref]);
                    }
                    echo "<p>Aucun produit trouvé pour votre recherche.</p>";
                } 

                foreach($produits as $produit){
                    
                    $marque = $produit['marque_nom'];
                    $modele = $produit['modele'];
                    $categorie = $produit['categorie_nom'];

                    if($produit['img1'] == null){
                        $produit['img1'] = 'aucune.png';
                    }

                ?>
                <div class="product-card <?= $produit['stock'] == 0 ? 'out-of-stock' : '' ?>">
                    <a href="produit.php?id=<?=$produit['id_produit']?>&id_voiture=<?=$produit['voiture']?>" class="product-link">
                        <?php if($produit['stock'] == 0): ?>
                        <div class="stock-badge">Épuisé</div>
                        <?php endif; ?>
                        
                        <div class="product-image-container">
                            <img src="img/produit/<?=$produit['img1']?>" alt="<?=$produit['libelle']?>" loading="lazy" class="product-image">
                        </div>
                        
                        <div class="product-info">
                            <h3 class="product-title"><?=$produit['libelle']?></h3>
                            <div class="product-meta">
                                <span class="product-brand"><?=$marque?></span>
                                <span class="product-model"><?=$modele?></span>
                            </div>
                            <div class="product-footer">
                                <span class="product-price"><?=number_format($produit['prix'], 0, ',', ' ')?> DA</span>
                                <button class="add-to-cart">
                                    <i class="fas fa-shopping-cart"></i>
                                </button>
                            </div>
                        </div>
                    </a>
                </div>
                <?php } 
            ?>
        </div>
        
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
            
            $baseUrl = '?'; // Base URL pour les liens de pagination
            
            /*if(isset($_GET['id_voiture']) || isset($_GET['sous']) || isset($_GET['id_categorie']) || isset($_POST['modele']) || isset($_POST['sous_categorie'])){

                if (isset($_GET['id_voiture'])) $baseUrl .= 'id_voiture=' . urlencode($_GET['id_voiture']) . '&';
                if (isset($_GET['sous'])) $baseUrl .= 'sous=' . urlencode($_GET['sous']) . '&';
                if (isset($_GET['id_categorie'])) $baseUrl .= 'id_categorie=' . urlencode($_GET['id_categorie']) . '&';
                if (isset($_POST['modele'])) {
                    if($_POST['modele'] !== "modele")
                        $baseUrl .= 'id_voiture=' . urlencode($_POST['modele']) . '&';
                }
                if (isset($_POST['sous_categorie'])){
                    if($_POST['sous_categorie'] !== "sous_categorie")
                        $baseUrl .= 'sous=' . urlencode($_POST['sous_categorie']) . '&';
                }
            }else{
                  if (isset($_GET['query'])) $baseUrl .= 'query=' . urlencode($_GET['query']) . '&';
            }*/
            
            if (!empty($_GET)) {
                foreach ($_GET as $key => $value) {
                    if ($key !== 'page') { // Exclure le paramètre "page" pour reconstruire correctement l'URL
                        $baseUrl .= urlencode($key) . '=' . urlencode($value) . '&';
                    }
                }
            }
            
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

 <!--=========================================================
                        footer
    ========================================================-->
	<?php include('partie/footer.php') ?>


</body>
</html>