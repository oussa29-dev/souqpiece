<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="css/style.css">

    <title>Ajouter produit</title>

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
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        include('include/menu.php');
        

        if(isset($_GET['id'])){
            $id = $_GET['id'];

            $sqlStates = $pdo->prepare('SELECT * FROM produit WHERE id_produit=?');
            $sqlStates->execute([$id]);
            $produit = $sqlStates->fetch(PDO::FETCH_ASSOC);

            // Récupérer les voitures associées à ce produit
        /*    $sqlVoitures = $pdo->prepare('
                SELECT voiture.id_voiture, voiture.modele, marque.id_marque, marque.libelle AS marque_libelle
                FROM voiture
                INNER JOIN marque ON voiture.id_marque = marque.id_marque
                WHERE voiture.id_voiture IN (SELECT id_voiture FROM pvd WHERE id_produit = ? ORDER BY pvd.id_pvd)
            ');*/
            // Récupérer les voitures associées avec leurs descriptions
            $sqlVoituresAvecDesc = $pdo->prepare('
                SELECT 
                    voiture.id_voiture, 
                    voiture.modele, 
                    marque.id_marque, 
                    marque.libelle AS marque_libelle, 
                    pvd.description 
                FROM voiture
                INNER JOIN marque ON voiture.id_marque = marque.id_marque
                INNER JOIN pvd ON voiture.id_voiture = pvd.id_voiture
                WHERE pvd.id_produit = ?
                ORDER BY pvd.id_pvd
            ');
            $sqlVoituresAvecDesc->execute([$id]);
            $voituresAssociees = $sqlVoituresAvecDesc->fetchAll(PDO::FETCH_ASSOC);
        
         /*   $sqlDesc = $pdo->prepare('SELECT * FROM pvd WHERE id_produit=? ORDER BY id_pvd');
            $sqlDesc->execute([$id]);
            $descDispo = $sqlDesc->fetchAll();*/
            
            // Récupérer toutes les marques disponibles
            $sqlMarques = $pdo->query('SELECT * FROM marque');
            $marquesDisponibles = $sqlMarques->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch initial subcategories associated with the selected category
            $initialSousCategories = [];
            if ($produit) {
                $stmt2 = $pdo->prepare('SELECT id_sous_categorie, libelle FROM sous_categorie WHERE id_categorie = ?');
                $stmt2->execute([$produit['id_categorie']]);
                $initialSousCategories = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }
            ?>
            <div class="site">

                <div class="barre">Modifier un produit</div>
         

        <!--=============Produit=======================-->
            <div class="page-voiture">
    
                <h3>Modifier un produit</h3>
    
                <form method="POST" id="form-produit" enctype="multipart/form-data" >
    
                    <label for="produit">Produit</label>
                    <input type="text" name="libelle" placeholder="entrer le produit" value="<?=$produit['libelle']?>">
                    <label for="produit">Marque piece</label>
                    <input type="text" name="marquepiece" placeholder="entrer la marque du produit" value="<?=$produit['marquepiece']?>">
                    <label for="prix">Prix</label>
                    <input type="number" name="prix" placeholder="Prix" min="0" value="<?=$produit['prix']?>">
                    <label for="">Trie</label>
                    <input type="number" name="trie" max="6" min="0" placeholder="1--6" value="<?=$produit['trie']?>"><br>
                    <?php
                        $sqlRef = $pdo->prepare('SELECT * FROM reference WHERE id_produit=?');
                        $sqlRef->execute([$id]);
                        $refs = $sqlRef->fetchAll();
                        foreach($refs as $ref){
                    ?>
                    <input type="text" name="ref_existing[<?= $ref['id_reference'] ?>]" placeholder="référence" value="<?=$ref['reference']?>">
                    <?php } ?>
                    
                    <div id="reference-container">
                    <!--    <label for="reference0">Référence 1</label>
                        <input type="text" name="references[]" placeholder="Entrer la référence 1">-->
                    </div>
                    <button type="button" onclick="addReferenceField()" id="but-ref">Ajouter une référence</button><br>
                    
                    <?php foreach ($voituresAssociees as $voiture): ?>
                        <div class="voiture-group">
                            <label>Marque :</label>
                            <select name="marque_existing[<?= $voiture['id_voiture'] ?>]" id="marqueSelect_<?= $voiture['id_voiture'] ?>" data-voiture-id="<?= $voiture['id_voiture'] ?>">
                               <option value="">Sélectionner une marque</option>
                                <?php foreach ($marquesDisponibles as $marque): ?>
                                    <option value="<?= $marque['id_marque'] ?>" <?= $marque['id_marque'] == $voiture['id_marque'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($marque['libelle']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    
                            <label>Modèle :</label>
                            <select name="modele_existing[<?= $voiture['id_voiture'] ?>]" id="modeleSelect_<?= $voiture['id_voiture'] ?>">
                                <option value="">Sélectionner un modèle</option>
                                <?php
                                // Récupérer les modèles associés à la marque actuelle
                                $sqlModels = $pdo->prepare('SELECT id_voiture, modele FROM voiture WHERE id_marque = ?');
                                $sqlModels->execute([$voiture['id_marque']]);
                                $modelsDisponibles = $sqlModels->fetchAll(PDO::FETCH_ASSOC);
                            
                                foreach ($modelsDisponibles as $model):
                                ?>
                                    <option value="<?= $model['id_voiture'] ?>" <?= $model['id_voiture'] == $voiture['id_voiture'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($model['modele']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    
                    <div id="voiture-container">
                        <div class="voiture-group">
                            <label for="voiture">Voiture 1</label>
                            <select name="marque[]" id="marqueSelect">
            
                                <option value="marque">Sélectionner une marque</option>
            
                                <?php
            
                                    $sqlMarque = 'SELECT * FROM marque';
            
                                    $query = $pdo->query($sqlMarque);
            
                                    $marqueLibs = $query->fetchAll();
            
                                    foreach($marqueLibs as $marqueLib){
            
                                ?>
            
                                <option value="<?= $marqueLib['id_marque'] ?>"><?= $marqueLib['libelle'] ?></option>
            
                               <?php } ?>
            
                            </select>
                            <select name="modele[]" id="modeleSelect">
                                    <option value="modele">Sélectionner un modèle</option>
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
                        </div>
                    </div>
                    <button type="button" onclick="addVoitureField()" id="but-ref">Ajouter une voiture</button><br>
    
                    <script>
                        document.querySelectorAll('[id^="marqueSelect_"]').forEach(function(marqueSelect) {
                        marqueSelect.addEventListener('change', function() {
                            var marqueId = this.value; // Récupère l'ID de la marque sélectionnée
                            var voitureId = this.getAttribute('data-voiture-id'); // Récupère l'ID de la voiture associée
                    
                            // Envoie une requête AJAX pour récupérer toutes les voitures correspondantes
                            var xhr = new XMLHttpRequest();
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState === XMLHttpRequest.DONE) {
                                    if (xhr.status === 200) {
                                        // Met à jour le contenu du select correspondant
                                        var voitures = JSON.parse(xhr.responseText);
                                        var modeleSelect = document.getElementById('modeleSelect_' + voitureId);
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
                    });
                    
                </script>
                    <select name="categorie" id="categorieSelect">
    
                        <option value="categorie">categorie</option>
    
                        <?php
    
                            $sqlCategorie = 'SELECT * FROM categorie';
    
                            $queryCate = $pdo->query($sqlCategorie);
    
                            $cateLibs = $queryCate->fetchAll();
    
                            foreach($cateLibs as $cateLib){
    
                                if($cateLib['id_categorie'] == $produit['id_categorie']){ ?>
    
                                    <option value="<?= $cateLib['id_categorie'] ?>" selected><?= $cateLib['libelle'] ?></option>        
    
                        <?php }else{ ?>
    
                        <option value="<?= $cateLib['id_categorie'] ?>"><?= $cateLib['libelle'] ?></option>
    
                       <?php }   
    
                            } ?>
    
                    </select>
    
                    <select name="sous_categorie" id="sousSelect">
    
                        <?php
    
                        foreach ($initialSousCategories as $sousCate) {
    
                            $selected = ($sousCate['id_sous_categorie'] == $produit['id_sous_categorie']) ? 'selected' : '';
    
                            echo "<option value='" . htmlspecialchars($sousCate['id_sous_categorie']) . "' $selected>" . htmlspecialchars($sousCate['libelle']) . "</option>";
    
                        }
    
                        ?>
    
                    </select>
    
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
    
                    <select name="stock" id="">
    
                        <?php if($produit['stock'] == 1){ ?>
    
                        <option value="1" selected>Disponible</option>
    
                        <option value="0">Non disponible</option>
    
                        <?php }else{ ?>
    
                            <option value="1" >Disponible</option>
    
                            <option value="0" selected>Non disponible</option>
    
                            <?php } ?>
    
                    </select><br>
    
                    <!--========img================-->
    
                    <label for="image_produit">image 1</label>
                    <input type="file" name="img_produit">    
                    <label for="image_produit2">image 2</label>
                    <input type="file" name="img_produit2">
                    <label for="image_produit3">image 3</label>
                    <input type="file" name="img_produit3"><br>
                    <label for="image_produit3">image 4</label>
                    <input type="file" name="img_produit4">
                    <label for="image_produit3">image 5</label>
                    <input type="file" name="img_produit5">
                    <label for="image_produit3">image 6</label>
                    <input type="file" name="img_produit6"><br>
                    <label for="image_produit3">image 7</label>
                    <input type="file" name="img_produit7">
                    <label for="image_produit3">image 8</label>
                    <input type="file" name="img_produit8">
                    <label for="image_produit3">image 9</label>
                    <input type="file" name="img_produit9"><br>
                    <label for="image_produit3">image 10</label>
                    <input type="file" name="img_produit10">
                    <br>
    
                    <?php foreach ($voituresAssociees as $desc): ?>
                        <div class="row">
                            <label for="description">Description pour Voiture :</label>
                            <textarea name="description[<?= $desc['id_voiture']; ?>]" id="description-produit" placeholder="Entrer la description du produit"><?= htmlspecialchars($desc['description']) ?></textarea>
                        </div>
                    <?php endforeach; ?>
                    <div id="descDiv">
                        <div class="row">
        
                            <label for="description">Description du produit</label>
        
                            <textarea name="descNew[]" id="description-produit" placeholder="Entrer la description du produit"></textarea>
        
                        </div>
                    </div>
    
                    <input type="submit" value="modifier" name="modifier">
    
                </form>
                <?php

                if(isset($_POST['modifier'])){
                    
                    $trie = $_POST['trie'];
                    $libelle = $_POST['libelle'];
                    $marquepiece = $_POST['marquepiece'];
                    $prix = $_POST['prix'];
                    $categorie = $_POST['categorie'];
                    $sous = $_POST['sous_categorie'];
                    $stock = $_POST['stock'];
                    $ref = $_POST['ref'];
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

                        $fileDestination = '../img/produit/' . $uniqueName;

                        move_uploaded_file($fileTmpName, $fileDestination);

                        return $uniqueName;

                    }

                    $img = uploadImage('img_produit');
                    $img2 = uploadImage('img_produit2');
                    $img3 = uploadImage('img_produit3');
                    $img4 = uploadImage('img_produit4');
                    $img5 = uploadImage('img_produit5');
                    $img6 = uploadImage('img_produit6');
                    $img7 = uploadImage('img_produit7');
                    $img8 = uploadImage('img_produit8');
                    $img9 = uploadImage('img_produit9');
                    $img10 = uploadImage('img_produit10');
                    
                    $updateImg1 = !empty($_FILES['img_produit']['name']);
                    $updateImg2 = !empty($_FILES['img_produit2']['name']);
                    $updateImg3 = !empty($_FILES['img_produit3']['name']);
                    $updateImg4 = !empty($_FILES['img_produit4']['name']);
                    $updateImg5 = !empty($_FILES['img_produit5']['name']);
                    $updateImg6 = !empty($_FILES['img_produit6']['name']);
                    $updateImg7 = !empty($_FILES['img_produit7']['name']);
                    $updateImg8 = !empty($_FILES['img_produit8']['name']);
                    $updateImg9 = !empty($_FILES['img_produit9']['name']);
                    $updateImg10 = !empty($_FILES['img_produit10']['name']);

                    if(!empty($libelle) && !empty($prix)){

                        $sqlModifier = 'UPDATE produit SET trie=? ,libelle=?,marquepiece=?,prix=?,id_categorie=?,id_sous_categorie=?,stock=?';
                        $params = [$trie,$libelle,$marquepiece,$prix,$categorie,$sous,$stock]; 
                        // Mise à jour des images additionnelles si elles sont renseignées

                        if ($updateImg1) {

                            $sqlModifier .= ', img1=?';

                            $params[] = $img;

                        }

                        if ($updateImg2) {

                            $sqlModifier .= ', img2=?';

                            $params[] = $img2;

                        }

                        if ($updateImg3) {

                            $sqlModifier .= ', img3=?';

                            $params[] = $img3;

                        }

                        if ($updateImg4) {

                            $sqlModifier .= ', img4=?';

                            $params[] = $img4;

                        }

                        if ($updateImg5) {

                            $sqlModifier .= ', img5=?';

                            $params[] = $img5;

                        }

                        if ($updateImg6) {

                            $sqlModifier .= ', img6=?';

                            $params[] = $img6;

                        }

                        if ($updateImg7) {

                            $sqlModifier .= ', img7=?';

                            $params[] = $img7;

                        }

                        if ($updateImg8) {

                            $sqlModifier .= ', img8=?';

                            $params[] = $img8;

                        }

                        if ($updateImg9) {

                            $sqlModifier .= ', img9=?';

                            $params[] = $img9;

                        }

                        if ($updateImg10) {

                            $sqlModifier .= ', img10=?';

                            $params[] = $img10;

                        }

                        //Ajouter la clause WHERE

                        $sqlModifier .= ' WHERE id_produit=?';

                        $params[] = $id;

                        $sqlState = $pdo->prepare($sqlModifier);

                        $updated = $sqlState->execute($params);

                        if (!empty($_POST['ref_existing'])) {
                            foreach ($_POST['ref_existing'] as $id_ref => $value) {
                                if(empty($value)){
                                     $pdo->prepare("DELETE FROM reference WHERE id_reference = ?")->execute([$id_ref]);
                                }else{
                                $updateRef = $pdo->prepare("UPDATE reference SET reference = ? WHERE id_reference = ?");
                                $updateRef->execute([$value, $id_ref]);
                                    
                                }
                            }
                        }
                        
                        if (!empty($_POST['references'])) {
                            foreach ($_POST['references'] as $newRef) {
                                if (!empty($newRef)) {
                                    $insertRef = $pdo->prepare("INSERT INTO reference (id_produit, reference) VALUES (?, ?)");
                                    $insertRef->execute([$id, $newRef]);
                                }
                            }
                        }
                        
                        $descriptions = $_POST['description'] ?? []; 
                        $desc_new = $_POST['descNew'] ?? [];        
                        $modeles_existing = $_POST['modele_existing'] ?? []; 
                        $modeles_new = $_POST['modele'] ?? [];
                        
                        if (!empty($modeles_existing)) {
                            foreach ($modeles_existing as $id_voiture => $new_id_voiture) {
                                $description = $descriptions[$id_voiture] ?? '';
                                if (empty($new_id_voiture)) {
                                     $pdo->prepare("DELETE FROM pvd WHERE id_produit = ? AND id_voiture=?")->execute([$id,$id_voiture]);
                                }else{
                                $updateMod = $pdo->prepare("UPDATE pvd SET id_voiture = ?, description = ? WHERE id_produit = ? AND id_voiture = ?");
                                $updateMod->execute([$new_id_voiture,$description,$id,$id_voiture]);        
                                }
                            }
                        }               
                        if (!empty($modeles_new)) {
                            foreach ($modeles_new as $index => $newMod) {
                                $description = trim($desc_new[$index]);
                                if (!empty($newMod) && !empty($description)) {
                                    $sqlVoi = 'INSERT INTO pvd (id_produit,id_voiture,description) VALUES (?, ?, ?)';
                                    $insertMod = $pdo->prepare($sqlVoi);
                                    $insertMod->execute([$id,$newMod,$description]);
                                }
                            }
                        }else{
                            echo ' veuillez saisir la description et choisir la voiture ';
                        }
                       
                        if($updated){
                            header('location:produit.php');
                        }
                        else{
                            echo "ERROR";
                        }
                    }
                }
                ob_end_flush();
                ?>
            </div>
        </div>
        
    <?php }else{
     ?>



    <!--===============================================

                        Ajouter

    ====================================================-->

    <div class="site">

        <div class="barre">Ajouter un produit</div>

        <!--=============Produit=======================-->

        <div class="page-voiture">

            <h3>Ajouter un produit</h3>

            <form method="POST" id="form-produit" enctype="multipart/form-data" >

                <label for="produit">Produit</label>

                <input type="text" name="libelle" placeholder="entrer le produit">

                <label for="marque">Marque piece</label>

                <input type="text" name="marquepiece" placeholder="entrer la marque du produit">

                <label for="prix">Prix</label>

                <input type="number" name="prix" placeholder="Prix" min="0">
                <label for="">Trie</label>
                <input type="number" name="trie" max="6" min="0" placeholder="1--6"><br>
                
                <div id="reference-container">
                    <label for="reference0">Référence 1</label>
                    <input type="text" name="references[]" placeholder="Entrer la référence 1">
                </div>
                <button type="button" onclick="addReferenceField()" id="but-ref">Ajouter une référence</button><br>
                 <div id="voiture-container">
                    <div class="voiture-group">
                        <label for="voiture">Voiture 1</label>
                        <select name="marque[]" id="marqueSelect">
    
                        <option value="marque">Sélectionner une marque</option>
    
                        <?php
    
                            $sqlMarque = 'SELECT * FROM marque';
    
                            $query = $pdo->query($sqlMarque);
    
                            $marqueLibs = $query->fetchAll();
    
                            foreach($marqueLibs as $marqueLib){
    
                        ?>
    
                        <option value="<?= $marqueLib['id_marque'] ?>"><?= $marqueLib['libelle'] ?></option>
    
                       <?php } ?>
    
                    </select>
                
                        <select name="modele[]" id="modeleSelect">
    
                        <option value="modele">Sélectionner un modèle</option>
    
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
                    </div>
                </div>
                <button type="button" onclick="addVoitureField()" id="but-ref">Ajouter une voiture</button><br>
                
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

                <select name="sous_categorie" id="sousSelect">

                    <option value="sous_categorie">sous categorie</option>

                </select>

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

                <select name="stock" id="">

                    <option value="1">Disponible</option>

                    <option value="0">Non disponible</option>

                </select><br>

                <!--========img================-->

                <label for="image_produit">image 1</label>

                <input type="file" name="img_produit">

                <label for="image_produit2">image 2</label>

                <input type="file" name="img_produit2">

                <label for="image_produit3">image 3</label>

                <input type="file" name="img_produit3"><br>

                <label for="image_produit3">image 4</label>

                <input type="file" name="img_produit4">

                <label for="image_produit3">image 5</label>

                <input type="file" name="img_produit5">

                <label for="image_produit3">image 6</label>

                <input type="file" name="img_produit6"><br>

                <label for="image_produit3">image 7</label>

                <input type="file" name="img_produit7">

                <label for="image_produit3">image 8</label>

                <input type="file" name="img_produit8">

                <label for="image_produit3">image 9</label>

                <input type="file" name="img_produit9"><br>

                <label for="image_produit3">image 10</label>

                <input type="file" name="img_produit10">

                <br>
                <div id="descDiv">
                    <div class="row">
    
                        <label for="description">Description du produit</label>
    
                        <textarea name="description[]" id="description-produit" placeholder="Entrer la description du produit"></textarea>
    
                    </div>
                </div>
                
                <input type="submit" value="ajouter" name="ajouter">

            </form>

            <?php
                if (isset($_POST['ajouter'])) {

                    $trie = $_POST['trie'];
                    $libelle = $_POST['libelle'];
                    $marquepiece = $_POST['marquepiece'];
                    $prix = $_POST['prix'];
                    $categorie = $_POST['categorie'];
                    $sous = $_POST['sous_categorie'];
                    $stock = $_POST['stock'];

                    // Function to upload image if it exists
                    function uploadImage($inputName) {
                        if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] == 0) {
                            $file = $_FILES[$inputName];
                            $fileName = $file['name'];
                            $fileTmpName = $file['tmp_name'];
                            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $uniqueName = uniqid('', true) . '.' . $fileExt;
                            $fileDestination = '../img/produit/' . $uniqueName;
                            move_uploaded_file($fileTmpName, $fileDestination);
                            return $uniqueName;
                        }
                        return null;
                    }
                    // Upload images if they exist
                    $images = [];
                    for ($i = 0; $i <= 9; $i++) {
                        $inputName = 'img_produit' . ($i == 0 ? '' : ($i + 1));
                        $image = uploadImage($inputName);
                        if ($image) {
                            $images['img' . ($i + 1)] = $image;
                        }
                    }
                    if (!empty($libelle) && !empty($prix)) {
                        // Prepare the SQL statement dynamically based on available images
                        $columns = 'trie,id_categorie, id_sous_categorie, libelle, marquepiece, prix, stock';
                        $placeholders = '?, ?, ?, ?, ?, ?, ?';
                        $params = [$trie, $categorie, $sous, $libelle, $marquepiece, $prix, $stock];

                        foreach ($images as $column => $image) {

                            $columns .= ', ' . $column;

                            $placeholders .= ', ?';

                            $params[] = $image;

                        }

                        $sql = 'INSERT INTO produit (' . $columns . ') VALUES (' . $placeholders . ')';

                        $sqlProduit = $pdo->prepare($sql);

                        $sqlProduit->execute($params);
                        $id_produit = $pdo->lastInsertId();
                        
                        if (isset($_POST['modele']) && is_array($_POST['modele']) && isset($_POST['description']) && is_array($_POST['description'])) {
                            foreach ($_POST['modele'] as $index => $modele) {
                                $description = $_POST['description'][$index];
                                $sqlVoi = 'INSERT INTO pvd (id_produit,id_voiture,description) VALUES (?, ?, ?)';
                                $stmtVoi = $pdo->prepare($sqlVoi);
                                $stmtVoi->execute([$id_produit,$modele,$description]);

                            }
                        //    header('Location: produit.php');
    
                        } else {
    
                            echo 'Veuillez saisir la description svp';
    
                        }                        
                        
                        if (isset($_POST['references']) && is_array($_POST['references'])) {
                            foreach ($_POST['references'] as $reference) {
                                if (!empty($reference)) { // Vérifie que la référence n'est pas vide
                                    $sqlRef = 'INSERT INTO reference (reference, id_produit) VALUES (?, ?)';
                                    $stmtRef = $pdo->prepare($sqlRef);
                                    $stmtRef->execute([$reference, $id_produit]);
                                }
                            }
                        header('Location: produit.php');

                    } else {

                        echo 'Veuillez saisir le nom et le prix du produit svp';

                    }

                }
                    ob_end_flush();
                }
            ?>
        </div>
    </div>

    <?php } 
    ?>

<script>
    let referenceCount = 1; // Compteur pour les champs de référence

    function addReferenceField() {
        referenceCount++;
        const container = document.getElementById('reference-container');

        // Crée un nouvel élément label et input pour la nouvelle référence
        const label = document.createElement('label');
        label.setAttribute('for', 'reference' + referenceCount);
        label.textContent = 'Référence ' + referenceCount;

        const input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.setAttribute('name', 'references[]');
        input.setAttribute('placeholder', 'Entrer la référence ' + referenceCount);

        // Ajoute le label et l'input au conteneur
        container.appendChild(label);
        container.appendChild(input);
    }
</script>
<script>
    let voitureCount = 1; // Compteur pour les champs de voiture

    function addVoitureField() {
        voitureCount++;

        // Récupère le conteneur principal
        const container = document.getElementById('voiture-container');

        // Crée un nouvel élément div pour regrouper les sélections
        const voitureDiv = document.createElement('div');
        voitureDiv.classList.add('voiture-group');

        // Crée le label pour la nouvelle voiture
        const label = document.createElement('label');
        label.setAttribute('for', 'voiture' + voitureCount);
        label.textContent = 'Voiture ' + voitureCount;

        // Crée le premier select (marque)
        const marqueSelect = document.createElement('select');
        marqueSelect.setAttribute('name', 'marque[]');
        marqueSelect.id = 'marqueSelect' + voitureCount;

        // Ajoute une option par défaut
        const defaultOptionMarque = document.createElement('option');
        defaultOptionMarque.value = '';
        defaultOptionMarque.textContent = 'Sélectionner une marque';
        marqueSelect.appendChild(defaultOptionMarque);

        // Ajoute les options de marque dynamiquement depuis PHP
        const marques = <?php echo json_encode($marqueLibs); ?>;
        marques.forEach(function (marque) {
            const option = document.createElement('option');
            option.value = marque.id_marque;
            option.textContent = marque.libelle;
            marqueSelect.appendChild(option);
        });

        // Crée le deuxième select (modèle)
        const modeleSelect = document.createElement('select');
        modeleSelect.setAttribute('name', 'modele[]');
        modeleSelect.id = 'modeleSelect' + voitureCount;

        // Ajoute une option par défaut pour le modèle
        const defaultOptionModele = document.createElement('option');
        defaultOptionModele.value = '';
        defaultOptionModele.textContent = 'Sélectionner un modèle';
        modeleSelect.appendChild(defaultOptionModele);

        // Ajoute un événement pour charger les modèles dynamiquement
        marqueSelect.addEventListener('change', function () {
            const marqueId = this.value;

            // Requête AJAX pour obtenir les modèles correspondants
            const xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function () {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    if (xhr.status === 200) {
                        const voitures = JSON.parse(xhr.responseText);
                        modeleSelect.innerHTML = ''; // Réinitialise les options

                        const defaultOption = document.createElement('option');
                        defaultOption.value = '';
                        defaultOption.textContent = 'Sélectionner un modèle';
                        modeleSelect.appendChild(defaultOption);

                        voitures.forEach(function (voiture) {
                            const option = document.createElement('option');
                            option.value = voiture.id_voiture;
                            option.textContent = voiture.modele;
                            modeleSelect.appendChild(option);
                        });
                    } else {
                        console.error('Erreur lors de la récupération des modèles');
                    }
                }
            };

            xhr.open('GET', 'get_modele.php?marque_id=' + marqueId, true);
            xhr.send();
        });

        // Ajoute les éléments au conteneur div
        voitureDiv.appendChild(label);
        voitureDiv.appendChild(marqueSelect);
        voitureDiv.appendChild(modeleSelect);

        // Ajoute le conteneur div au conteneur principal
        container.appendChild(voitureDiv);

        // === Ajouter la description au-dessus ===
        const descDiv = document.getElementById('descDiv');
        const descriptionDiv = document.createElement('div');
        descriptionDiv.classList.add('row');

        const descriptionLabel = document.createElement('label');
        descriptionLabel.textContent = `Description pour Voiture ${voitureCount}`;
        descriptionDiv.appendChild(descriptionLabel);

        const descriptionTextarea = document.createElement('textarea');
        descriptionTextarea.id = 'description-produit';
        descriptionTextarea.name = 'description[]';
        descriptionTextarea.placeholder = 'Entrer la description du produit';
        descriptionDiv.appendChild(descriptionTextarea);

        // Ajoute la description au début du conteneur principal
        descDiv.appendChild(descriptionDiv);
    }
</script>




</body>

</html>