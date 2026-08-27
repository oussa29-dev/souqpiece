<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">

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
        require_once('include/pvd_extraction.php');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        include('include/menu.php');
        $paysConnus = pvd_liste_pays_connus();
        

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
                    pvd.description,
                    pvd.annee_debut,
                    pvd.annee_fin,
                    pvd.marque_texte,
                    pvd.notes_libres
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

            // Validation faite avant le rendu du formulaire (pas seulement au
            // moment de l'ecriture) pour deux raisons : afficher les erreurs
            // en haut de page, et reafficher ce que l'admin vient de taper -
            // pas les anciennes valeurs en base - si la soumission est
            // rejetee. $erreurs vide = rien a bloquer ; le bloc de traitement
            // plus bas ne touche la base que si $erreurs reste vide.
            $erreurs = [];
            $genericCoche = empty($voituresAssociees);
            if (isset($_POST['modifier'])) {
                $produit['libelle'] = $_POST['libelle'] ?? $produit['libelle'];
                $produit['marquepiece'] = $_POST['marquepiece'] ?? $produit['marquepiece'];
                $produit['prix'] = $_POST['prix'] ?? $produit['prix'];
                $produit['id_categorie'] = $_POST['categorie'] ?? $produit['id_categorie'];
                $produit['id_sous_categorie'] = $_POST['sous_categorie'] ?? $produit['id_sous_categorie'];

                if (trim($_POST['libelle'] ?? '') === '') {
                    $erreurs[] = 'Le libellé est obligatoire.';
                }
                if (trim((string)($_POST['prix'] ?? '')) === '') {
                    $erreurs[] = 'Le prix est obligatoire.';
                }
                if (trim($_POST['marquepiece'] ?? '') === '') {
                    $erreurs[] = 'La marque de la pièce est obligatoire.';
                }
                if (($_POST['categorie'] ?? 'categorie') === 'categorie' || trim($_POST['categorie'] ?? '') === '') {
                    $erreurs[] = 'La catégorie est obligatoire.';
                }

                $refsExistantes = array_filter($_POST['ref_existing'] ?? [], fn($v) => trim($v) !== '');
                $refsNouvelles = array_filter($_POST['references'] ?? [], fn($v) => trim($v) !== '');
                if (empty($refsExistantes) && empty($refsNouvelles)) {
                    $erreurs[] = 'Au moins une référence est obligatoire.';
                }

                $paysAutrePost = trim($_POST['pays_origine_produit_autre'] ?? '');
                $paysPost = $paysAutrePost !== '' ? $paysAutrePost : ($_POST['pays_origine_produit'] ?? '');
                if (trim($paysPost) === '') {
                    $erreurs[] = "Le pays d'origine est obligatoire.";
                }

                $genericCoche = isset($_POST['produit_generique']);
                $modelesExistants = array_filter($_POST['modele_existing'] ?? [], fn($v) => !empty($v));
                $modelesNouveaux = array_filter($_POST['modele'] ?? [], fn($v) => !empty($v));
                if (!$genericCoche && empty($modelesExistants) && empty($modelesNouveaux)) {
                    $erreurs[] = 'Sélectionnez au moins un véhicule, ou cochez "Produit générique".';
                }
                foreach (array_keys($modelesExistants) as $idVoitureCle) {
                    if (trim($_POST['annee_debut_existing'][$idVoitureCle] ?? '') === '') {
                        $erreurs[] = "L'année de début est obligatoire pour chaque véhicule sélectionné.";
                        break;
                    }
                }
                foreach (array_keys($modelesNouveaux) as $idx) {
                    if (trim($_POST['annee_debut'][$idx] ?? '') === '') {
                        $erreurs[] = "L'année de début est obligatoire pour chaque véhicule sélectionné.";
                        break;
                    }
                }
            }

            $paysActuel = $_POST['pays_origine_produit'] ?? ($produit['pays_origine'] ?? null);
            
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
    
                <?php if (!empty($erreurs)): ?>
                    <div class="erreur" style="background:#fbeceb;border:1px solid #b3261e;color:#b3261e;padding:10px 14px;border-radius:6px;margin:0 0 14px;">
                        <ul style="margin:0;padding-inline-start:1.2em;">
                            <?php foreach ($erreurs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="POST" id="form-produit" enctype="multipart/form-data" >

                    <label for="produit">Produit</label>
                    <input type="text" name="libelle" placeholder="entrer le produit" value="<?=$produit['libelle']?>" required>
                    <label for="produit">Marque piece</label>
                    <input type="text" name="marquepiece" placeholder="entrer la marque du produit" value="<?=$produit['marquepiece']?>" required>
                    <label for="prix">Prix</label>
                    <input type="number" name="prix" placeholder="Prix" min="0" value="<?=$produit['prix']?>" required>
                    <label for="pays_origine_produit">Pays d'origine</label>
                    <select name="pays_origine_produit" id="pays_origine_produit">
                        <option value="">Non renseigné</option>
                        <?php foreach ($paysConnus as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $paysActuel === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="pays_origine_produit_autre" placeholder="Autre pays (si absent de la liste)"><br>
                    <?php
                        $sqlRef = $pdo->prepare('SELECT * FROM reference WHERE id_produit=?');
                        $sqlRef->execute([$id]);
                        $refs = $sqlRef->fetchAll();
                        foreach($refs as $ref){
                    ?>
                    <div class="reference-row">
                        <input type="text" name="ref_existing[<?= $ref['id_reference'] ?>]" placeholder="référence" value="<?=$ref['reference']?>">
                    </div>
                    <?php } ?>

                    <div id="reference-container"></div>
                    <button type="button" onclick="addReferenceField()" id="but-ref">Ajouter une référence</button>

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

                            <label for="annee_debut_existing_<?= $voiture['id_voiture'] ?>">Année début :</label>
                            <input type="number" name="annee_debut_existing[<?= $voiture['id_voiture'] ?>]" id="annee_debut_existing_<?= $voiture['id_voiture'] ?>" min="1970" max="2026" value="<?= htmlspecialchars($voiture['annee_debut'] ?? '') ?>">

                            <label for="annee_fin_existing_<?= $voiture['id_voiture'] ?>">Année fin :</label>
                            <input type="number" name="annee_fin_existing[<?= $voiture['id_voiture'] ?>]" id="annee_fin_existing_<?= $voiture['id_voiture'] ?>" min="1970" max="2026" value="<?= htmlspecialchars($voiture['annee_fin'] ?? '') ?>">
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

                            <label for="annee_debut">Année début :</label>
                            <input type="number" name="annee_debut[]" id="annee_debut" min="1970" max="2026">

                            <label for="annee_fin">Année fin :</label>
                            <input type="number" name="annee_fin[]" id="annee_fin" min="1970" max="2026">
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
                    <label><input type="checkbox" name="produit_generique" id="produit_generique" <?= $genericCoche ? 'checked' : '' ?>> Produit générique, non lié à un véhicule spécifique</label><br>

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
                    <select name="categorie" id="categorieSelect" required>

                        <option value="" <?= empty($produit['id_categorie']) ? 'selected' : '' ?>>Sélectionner une catégorie</option>

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

                    <input type="submit" value="modifier" name="modifier">
    
                </form>
                <?php

                if(isset($_POST['modifier']) && empty($erreurs)){

                    // trie n'est plus dans le formulaire (retire volontairement -
                    // c'est une mise en avant editoriale, pas une saisie
                    // produit courante). Preserver la valeur existante plutot
                    // que l'ecraser avec un champ qui n'est plus soumis.
                    $trie = $produit['trie'];
                    $libelle = $_POST['libelle'];
                    $marquepiece = $_POST['marquepiece'];
                    $prix = $_POST['prix'];
                    $categorie = $_POST['categorie'];
                    $sous = $_POST['sous_categorie'];
                    $stock = $_POST['stock'];
                    $ref = $_POST['ref'];
                    $paysAutreProduit = trim($_POST['pays_origine_produit_autre'] ?? '');
                    $paysProduit = $paysAutreProduit !== ''
                        ? strtoupper($paysAutreProduit)
                        : (($_POST['pays_origine_produit'] ?? '') !== '' ? $_POST['pays_origine_produit'] : null);
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

                        $sqlModifier = 'UPDATE produit SET trie=? ,libelle=?,marquepiece=?,prix=?,id_categorie=?,id_sous_categorie=?,stock=?,pays_origine=?';
                        $params = [$trie,$libelle,$marquepiece,$prix,$categorie,$sous,$stock,$paysProduit];
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
                        
                        // Builds the 5 structured pvd fields (plus a composed
                        // description, kept in sync for existing display code
                        // - see PLAN_PVD_DESCRIPTION.md §3) from one row of
                        // POSTed form fields. "Autre pays" wins over the
                        // dropdown when filled, so an admin is never stuck if
                        // the country isn't in the known list yet. Looks the
                        // vehicle's own modele up by id rather than trusting
                        // a pre-loaded array, since the "new vehicle" row's
                        // id_voiture is only known once the form is submitted.
                        function pvd_lire_champs_structures(PDO $pdo, array $post, string $suffixe, $cle, int $idVoiture, string $libelleProduit, string $marquepieceProduit, ?string $notesActuelles, ?string $paysProduit): array
                        {
                            $modele = $pdo->prepare('SELECT modele FROM voiture WHERE id_voiture = ?');
                            $modele->execute([$idVoiture]);
                            $modeleLabel = trim($libelleProduit . ' ' . ($modele->fetchColumn() ?: ''));

                            // Suffix is only appended when non-empty: "existing"
                            // rows are keyed annee_debut_existing[...], but
                            // "new" rows use the plain annee_debut[...] name -
                            // shared with the add-product form's fields, since
                            // both forms are extended by the same addVoitureField()
                            // JS function and must agree on field names.
                            $suf = $suffixe !== '' ? '_' . $suffixe : '';
                            $anneeDebut = $post['annee_debut' . $suf][$cle] ?? '';
                            $anneeFin = $post['annee_fin' . $suf][$cle] ?? '';

                            $anneeDebut = $anneeDebut === '' ? null : (int)$anneeDebut;
                            $anneeFin = $anneeFin === '' ? null : (int)$anneeFin;
                            // Pays d'origine: single field at product level now
                            // (99.1% of multi-vehicle products already shared
                            // the same value across every one of their pvd
                            // rows) - applied to every vehicle link, not asked
                            // per vehicle.
                            $pays = $paysProduit;

                            // No more per-vehicle marque field: 98.3% of
                            // multi-vehicle products already had the same
                            // marque_texte on every one of their pvd rows,
                            // so asking for it again per vehicle was pure
                            // duplication. produit.marquepiece (entered once,
                            // next to the product name) drives the MARQUE
                            // line in the composed description, but is
                            // deliberately NOT mirrored into pvd.marque_texte
                            // - that column's job is to record what was
                            // independently written, for comparison against
                            // marquepiece on the decision page. Nothing is
                            // independently entered anymore, so it stays
                            // null for new/updated rows, same as before this
                            // field existed.
                            $marque = $marquepieceProduit;

                            // notesActuelles: the form no longer has a notes
                            // field (deliberately - PLAN_PVD_DESCRIPTION.md,
                            // "don't invite extra free text"), so there is
                            // nothing to read from $post here. Whatever the
                            // row already had in notes_libres is passed in by
                            // the caller and written back unchanged - saving
                            // an edit must never silently blank out a note
                            // that was there before this form existed.
                            $description = pvd_composer_description($modeleLabel, '', $anneeDebut, $anneeFin, $marque, $pays, $notesActuelles);

                            return [$anneeDebut, $anneeFin, null, $pays, $notesActuelles, $description];
                        }

                        $modeles_existing = $_POST['modele_existing'] ?? [];
                        $modeles_new = $_POST['modele'] ?? [];

                        if (!empty($modeles_existing)) {
                            foreach ($modeles_existing as $id_voiture => $new_id_voiture) {
                                if (empty($new_id_voiture)) {
                                     $pdo->prepare("DELETE FROM pvd WHERE id_produit = ? AND id_voiture=?")->execute([$id,$id_voiture]);
                                }else{
                                $sqlNotesActuelles = $pdo->prepare('SELECT notes_libres FROM pvd WHERE id_produit = ? AND id_voiture = ?');
                                $sqlNotesActuelles->execute([$id, $id_voiture]);
                                $notesActuelles = $sqlNotesActuelles->fetchColumn() ?: null;

                                [$anneeDebut, $anneeFin, $marqueTexte, $pays, $notes, $description] = pvd_lire_champs_structures($pdo, $_POST, 'existing', $id_voiture, (int)$new_id_voiture, $libelle, $marquepiece, $notesActuelles, $paysProduit);
                                // pays_origine n'est plus ecrit ici : c'est desormais
                                // produit.pays_origine (voir plus haut) qui porte
                                // cette information - la colonne pvd.pays_origine
                                // est gelee, jamais supprimee (meme convention que
                                // pvd.description).
                                $updateMod = $pdo->prepare("UPDATE pvd SET id_voiture = ?, description = ?, annee_debut = ?, annee_fin = ?, marque_texte = ?, notes_libres = ? WHERE id_produit = ? AND id_voiture = ?");
                                $updateMod->execute([$new_id_voiture,$description,$anneeDebut,$anneeFin,$marqueTexte,$notes,$id,$id_voiture]);
                                }
                            }
                        }
                        if (!empty($modeles_new)) {
                            foreach ($modeles_new as $index => $newMod) {
                                if (!empty($newMod)) {
                                    // New row, nothing to preserve - null is correct here.
                                    [$anneeDebut, $anneeFin, $marqueTexte, $pays, $notes, $description] = pvd_lire_champs_structures($pdo, $_POST, '', $index, (int)$newMod, $libelle, $marquepiece, null, $paysProduit);
                                    $sqlVoi = 'INSERT INTO pvd (id_produit,id_voiture,description,annee_debut,annee_fin,marque_texte,notes_libres) VALUES (?, ?, ?, ?, ?, ?, ?)';
                                    $insertMod = $pdo->prepare($sqlVoi);
                                    $insertMod->execute([$id,$newMod,$description,$anneeDebut,$anneeFin,$marqueTexte,$notes]);
                                }
                            }
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
        // Meme logique de validation precoce que la branche modification -
        // voir le commentaire equivalent plus haut.
        $erreurs = [];
        $genericCoche = false;
        if (isset($_POST['ajouter'])) {
            if (trim($_POST['libelle'] ?? '') === '') {
                $erreurs[] = 'Le libellé est obligatoire.';
            }
            if (trim((string)($_POST['prix'] ?? '')) === '') {
                $erreurs[] = 'Le prix est obligatoire.';
            }
            if (trim($_POST['marquepiece'] ?? '') === '') {
                $erreurs[] = 'La marque de la pièce est obligatoire.';
            }
            if (($_POST['categorie'] ?? 'categorie') === 'categorie' || trim($_POST['categorie'] ?? '') === '') {
                $erreurs[] = 'La catégorie est obligatoire.';
            }

            $refsPost = array_filter($_POST['references'] ?? [], fn($v) => trim($v) !== '');
            if (empty($refsPost)) {
                $erreurs[] = 'Au moins une référence est obligatoire.';
            }

            $paysAutrePost = trim($_POST['pays_origine_produit_autre'] ?? '');
            $paysPost = $paysAutrePost !== '' ? $paysAutrePost : ($_POST['pays_origine_produit'] ?? '');
            if (trim($paysPost) === '') {
                $erreurs[] = "Le pays d'origine est obligatoire.";
            }

            $genericCoche = isset($_POST['produit_generique']);
            $modelesNouveaux = array_filter($_POST['modele'] ?? [], fn($v) => !empty($v));
            if (!$genericCoche && empty($modelesNouveaux)) {
                $erreurs[] = 'Sélectionnez au moins un véhicule, ou cochez "Produit générique".';
            }
            foreach (array_keys($modelesNouveaux) as $idx) {
                if (trim($_POST['annee_debut'][$idx] ?? '') === '') {
                    $erreurs[] = "L'année de début est obligatoire pour chaque véhicule sélectionné.";
                    break;
                }
            }
        }
        $valLibelle = $_POST['libelle'] ?? '';
        $valMarquepiece = $_POST['marquepiece'] ?? '';
        $valPrix = $_POST['prix'] ?? '';
     ?>



    <!--===============================================

                        Ajouter

    ====================================================-->

    <div class="site">

        <div class="barre">Ajouter un produit</div>

        <!--=============Produit=======================-->

        <div class="page-voiture">

            <h3>Ajouter un produit</h3>

            <?php if (!empty($erreurs)): ?>
                <div class="erreur" style="background:#fbeceb;border:1px solid #b3261e;color:#b3261e;padding:10px 14px;border-radius:6px;margin:0 0 14px;">
                    <ul style="margin:0;padding-inline-start:1.2em;">
                        <?php foreach ($erreurs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="form-produit" enctype="multipart/form-data" >

                <label for="produit">Produit</label>

                <input type="text" name="libelle" placeholder="entrer le produit" value="<?= htmlspecialchars($valLibelle) ?>" required>

                <label for="marque">Marque piece</label>

                <input type="text" name="marquepiece" placeholder="entrer la marque du produit" value="<?= htmlspecialchars($valMarquepiece) ?>" required>

                <label for="prix">Prix</label>

                <input type="number" name="prix" placeholder="Prix" min="0" value="<?= htmlspecialchars($valPrix) ?>" required><br>

                <label for="pays_origine_produit">Pays d'origine</label>
                <select name="pays_origine_produit" id="pays_origine_produit">
                    <option value="">Sélectionner un pays</option>
                    <?php foreach (pvd_liste_pays_connus() as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= ($_POST['pays_origine_produit'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="pays_origine_produit_autre" placeholder="Autre pays (si absent de la liste)" value="<?= htmlspecialchars($_POST['pays_origine_produit_autre'] ?? '') ?>"><br>

                <div id="reference-container">
                    <div class="reference-row">
                        <label for="reference0">Référence 1</label>
                        <input type="text" name="references[]" placeholder="Entrer la référence 1" required>
                    </div>
                </div>
                <button type="button" onclick="addReferenceField()" id="but-ref">Ajouter une référence</button>
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

                        <label for="annee_debut">Année début :</label>
                        <input type="number" name="annee_debut[]" id="annee_debut" min="1970" max="2026">

                        <label for="annee_fin">Année fin :</label>
                        <input type="number" name="annee_fin[]" id="annee_fin" min="1970" max="2026">

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
                <label><input type="checkbox" name="produit_generique" id="produit_generique" <?= $genericCoche ? 'checked' : '' ?>> Produit générique, non lié à un véhicule spécifique</label><br>

                <select name="categorie" id="categorieSelect" required>

                    <option value="" <?= empty($_POST['categorie']) ? 'selected' : '' ?>>categorie</option>

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

                <input type="submit" value="ajouter" name="ajouter">

            </form>

            <?php
                if (isset($_POST['ajouter']) && empty($erreurs)) {

                    // trie n'est plus dans le formulaire (mise en avant
                    // editoriale, pas une saisie produit courante) - un
                    // nouveau produit n'est jamais mis en avant par defaut.
                    $trie = 0;
                    $libelle = $_POST['libelle'];
                    $marquepiece = $_POST['marquepiece'];
                    $prix = $_POST['prix'];
                    $categorie = $_POST['categorie'];
                    $sous = $_POST['sous_categorie'];
                    $stock = $_POST['stock'];
                    $paysAutreProduit = trim($_POST['pays_origine_produit_autre'] ?? '');
                    $paysProduit = $paysAutreProduit !== ''
                        ? strtoupper($paysAutreProduit)
                        : (($_POST['pays_origine_produit'] ?? '') !== '' ? $_POST['pays_origine_produit'] : null);

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
                        $columns = 'trie,id_categorie, id_sous_categorie, libelle, marquepiece, prix, stock, pays_origine';
                        $placeholders = '?, ?, ?, ?, ?, ?, ?, ?';
                        $params = [$trie, $categorie, $sous, $libelle, $marquepiece, $prix, $stock, $paysProduit];

                        foreach ($images as $column => $image) {

                            $columns .= ', ' . $column;

                            $placeholders .= ', ?';

                            $params[] = $image;

                        }

                        $sql = 'INSERT INTO produit (' . $columns . ') VALUES (' . $placeholders . ')';

                        $sqlProduit = $pdo->prepare($sql);

                        $sqlProduit->execute($params);
                        $id_produit = $pdo->lastInsertId();
                        
                        // Same field-composition logic as the edit form - see
                        // PLAN_PVD_DESCRIPTION.md §3. Declared here rather than
                        // shared because only one of the two top-level
                        // branches (edit vs add) of this file ever runs per
                        // request.
                        function pvd_lire_champs_structures(PDO $pdo, array $post, $cle, int $idVoiture, string $libelleProduit, string $marquepieceProduit, ?string $paysProduit): array
                        {
                            $modele = $pdo->prepare('SELECT modele FROM voiture WHERE id_voiture = ?');
                            $modele->execute([$idVoiture]);
                            $modeleLabel = trim($libelleProduit . ' ' . ($modele->fetchColumn() ?: ''));

                            $anneeDebut = $post['annee_debut'][$cle] ?? '';
                            $anneeFin = $post['annee_fin'][$cle] ?? '';

                            $anneeDebut = $anneeDebut === '' ? null : (int)$anneeDebut;
                            $anneeFin = $anneeFin === '' ? null : (int)$anneeFin;
                            $pays = $paysProduit;

                            // No per-vehicle marque field (see the modifier
                            // branch above for why) - produit.marquepiece
                            // drives the composed description's MARQUE line,
                            // but is not mirrored into pvd.marque_texte
                            // (nothing independently entered to record).
                            $marque = $marquepieceProduit;

                            // Brand new product being created here - there is
                            // no prior pvd row, so notes_libres has nothing to
                            // preserve and is always null (the form has no
                            // notes field by design - see the "existing" rows
                            // handler above for why that matters more there).
                            $description = pvd_composer_description($modeleLabel, '', $anneeDebut, $anneeFin, $marque, $pays, null);

                            return [$anneeDebut, $anneeFin, null, $pays, null, $description];
                        }

                        if (isset($_POST['modele']) && is_array($_POST['modele'])) {
                            foreach ($_POST['modele'] as $index => $modele) {
                                if (empty($modele)) {
                                    continue;
                                }
                                [$anneeDebut, $anneeFin, $marqueTexte, $pays, $notes, $description] = pvd_lire_champs_structures($pdo, $_POST, $index, (int)$modele, $libelle, $marquepiece, $paysProduit);
                                // pays_origine n'est plus ecrit ici : voir le
                                // commentaire equivalent dans la branche modifier.
                                $sqlVoi = 'INSERT INTO pvd (id_produit,id_voiture,description,annee_debut,annee_fin,marque_texte,notes_libres) VALUES (?, ?, ?, ?, ?, ?, ?)';
                                $stmtVoi = $pdo->prepare($sqlVoi);
                                $stmtVoi->execute([$id_produit,$modele,$description,$anneeDebut,$anneeFin,$marqueTexte,$notes]);
                            }
                        //    header('Location: produit.php');

                        } elseif (empty($_POST['produit_generique'])) {

                            echo 'Veuillez choisir au moins une voiture';

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

        // Chaque référence sur sa propre ligne (row bloc), pour ne pas
        // s'empiler à côté du bouton "Ajouter une référence".
        const row = document.createElement('div');
        row.classList.add('reference-row');

        const label = document.createElement('label');
        label.setAttribute('for', 'reference' + referenceCount);
        label.textContent = 'Référence ' + referenceCount;

        const input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.setAttribute('name', 'references[]');
        input.setAttribute('placeholder', 'Entrer la référence ' + referenceCount);

        row.appendChild(label);
        row.appendChild(input);
        container.appendChild(row);
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

        // Année début/fin sur la meme ligne que marque/modele - meme groupe
        // (voiture-group), pas une zone separee ailleurs sur la page.
        const anneeDebutLabel = document.createElement('label');
        anneeDebutLabel.setAttribute('for', 'annee_debut' + voitureCount);
        anneeDebutLabel.textContent = 'Année début :';

        const anneeDebutInput = document.createElement('input');
        anneeDebutInput.type = 'number';
        anneeDebutInput.name = 'annee_debut[]';
        anneeDebutInput.id = 'annee_debut' + voitureCount;
        anneeDebutInput.min = '1970';
        anneeDebutInput.max = '2026';

        const anneeFinLabel = document.createElement('label');
        anneeFinLabel.setAttribute('for', 'annee_fin' + voitureCount);
        anneeFinLabel.textContent = 'Année fin :';

        const anneeFinInput = document.createElement('input');
        anneeFinInput.type = 'number';
        anneeFinInput.name = 'annee_fin[]';
        anneeFinInput.id = 'annee_fin' + voitureCount;
        anneeFinInput.min = '1970';
        anneeFinInput.max = '2026';

        // Ajoute les éléments au conteneur div
        voitureDiv.appendChild(label);
        voitureDiv.appendChild(marqueSelect);
        voitureDiv.appendChild(modeleSelect);
        voitureDiv.appendChild(anneeDebutLabel);
        voitureDiv.appendChild(anneeDebutInput);
        voitureDiv.appendChild(anneeFinLabel);
        voitureDiv.appendChild(anneeFinInput);

        // Ajoute le conteneur div au conteneur principal
        container.appendChild(voitureDiv);
    }
</script>




</body>

</html>