<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Stock</title>
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

        <div class="barre">Stock des produits</div>
    <?php
        // Inclure les fichiers nécessaires pour PhpSpreadsheet
        require '../vendor/autoload.php';
        require_once 'include/import_classification.php';
        require_once 'include/pvd_extraction.php';

        use PhpOffice\PhpSpreadsheet\IOFactory;
        
        // if (isset($_POST['modifier'])) {
        //     if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] == UPLOAD_ERR_OK) {
        //         $fichier_tmp = $_FILES['fichier']['tmp_name'];
        //         try {
        //             // Charger le fichier Excel
        //             $spreadsheet = IOFactory::load($fichier_tmp);
        //             $sheet = $spreadsheet->getActiveSheet();
                    
        //             // Initialisation des compteurs
        //             $newProductsCount = 0;
        //             $updatedProductsCount = 0;
        //             $errors = [];
                    
        //             // Démarrer une transaction
        //             $pdo->beginTransaction();
        
        //             foreach ($sheet->getRowIterator(2) as $row) {
        //                 // Récupération des données du fichier Excel
        //                 $reference = trim($sheet->getCell('A' . $row->getRowIndex())->getValue());
        //                 $libelle = trim($sheet->getCell('B' . $row->getRowIndex())->getValue());
        //                 $marque = trim($sheet->getCell('C' . $row->getRowIndex())->getValue());
        //                 $quant = (int)$sheet->getCell('D' . $row->getRowIndex())->getValue();
        //                 $prix = (float)$sheet->getCell('G' . $row->getRowIndex())->getValue();
        //                 $stock = ($quant > 0) ? 1 : 0;
     
        //                 // Validation des données obligatoires
        //                 if (empty($reference)) {
        //                     $errors[] = "Ligne {$row->getRowIndex()}: Référence manquante";
        //                     continue;
        //                 }
                        
        //                 if (empty($libelle)) {
        //                     $errors[] = "Ligne {$row->getRowIndex()}: Libellé manquant pour la référence $reference";
        //                     continue;
        //                 }
                        
        //                 // Vérifier si la référence existe déjà
        //                 $sql = "SELECT r.id_reference, r.id_produit, p.marquepiece 
        //                         FROM reference r 
        //                         JOIN produit p ON r.id_produit = p.id_produit 
        //                         WHERE TRIM(r.reference) = ?";
        //                 $stmt = $pdo->prepare($sql);
        //                 $stmt->execute([$reference]);
        //                 $existingRefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
        //                 $productExists = false;
        //                 $matchingProductId = null;
                        
        //                 foreach ($existingRefs as $existing) {
        //                     if ($existing['marquepiece'] == $marque) {
        //                         $productExists = true;
        //                         $matchingProductId = $existing['id_produit'];
        //                         break;
        //                     }
        //                 }
                        
        //                 if ($productExists) {
        //                     // Mise à jour du produit existant
        //                     $updateSql = "UPDATE produit 
        //                                  SET prix = ?, stock = ? 
        //                                  WHERE id_produit = ?";
        //                     $updateStmt = $pdo->prepare($updateSql);
        //                     $updateStmt->execute([$prix, $stock, $matchingProductId]);
        //                     $updatedProductsCount++;
        //                 } else {
        //                     // Insertion d'un nouveau produit avec gestion des erreurs
        //                     $insertProductSql = "INSERT INTO produit (libelle, marquepiece, prix, stock) VALUES (?, ?, ?, ?)";
        //                     $insertProductStmt = $pdo->prepare($insertProductSql);
                            
        //                     try {
        //                         // Exécuter l'insertion produit
        //                         $insertProductStmt->execute([$libelle, $marque, $prix, $stock]);
        //                         $newProductId = $pdo->lastInsertId();
                                
        //                         // Vérifier que l'ID est valide
        //                         if ($newProductId <= 0) {
        //                             throw new Exception("Échec de l'insertion du produit - ID invalide");
        //                         }
                                
        //                         // Insertion de la référence
        //                         $insertRefSql = "INSERT INTO reference (reference, id_produit) VALUES (?, ?)";
        //                         $insertRefStmt = $pdo->prepare($insertRefSql);
        //                         $insertRefStmt->execute([$reference, $newProductId]);
                                
        //                         // Vérifier que la référence a bien été insérée
        //                         if ($insertRefStmt->rowCount() === 0) {
        //                             throw new Exception("Échec de l'insertion de la référence");
        //                         }
                                
        //                         $newProductsCount++;
        //                     } catch (Exception $e) {
        //                         $errors[] = "Ligne {$row->getRowIndex()}: " . $e->getMessage();
        //                         // Annuler cette insertion mais continuer avec les autres lignes
        //                         $pdo->rollBack();
        //                         $pdo->beginTransaction(); // Redémarrer la transaction pour les lignes suivantes
        //                         continue;
        //                     }
        //                 }
        //             }
                    
        //             // Valider la transaction
        //             $pdo->commit();
                    
        //             // Affichage des résultats
        //             echo "<div class='result-message'>";
        //             if ($newProductsCount > 0) {
        //                 echo "<p style='color: green;'>$newProductsCount nouveaux produits ajoutés.</p>";
        //             }
        //             if ($updatedProductsCount > 0) {
        //                 echo "<p style='color: green;'>$updatedProductsCount produits mis à jour.</p>";
        //             }
        //             if (empty($errors)) {
        //                 echo "<p style='color: green;'>Import terminé avec succès.</p>";
        //             } else {
        //                 echo "<p style='color: orange;'>Import terminé avec quelques erreurs :</p>";
        //                 echo "<ul>";
        //                 foreach ($errors as $error) {
        //                     echo "<li>$error</li>";
        //                 }
        //                 echo "</ul>";
        //             }
        //             echo "</div>";
                    
        //         } catch (Exception $e) {
        //             // Annuler la transaction en cas d'erreur
        //             $pdo->rollBack();
        //             echo "<p style='color: red;'>Erreur lors de l'import : " . $e->getMessage() . "</p>";
        //         }
        //     } else {
        //         echo "<p style='color: red;'>Veuillez télécharger un fichier valide.</p>";
        //     }
        // }
        
        if (isset($_POST['modifier'])) {
            if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] == UPLOAD_ERR_OK) {
                $fichier_tmp = $_FILES['fichier']['tmp_name'];
                // Un fichier fournisseur reel peut depasser plusieurs milliers
                // de lignes - la limite par defaut de 120s (max_execution_time)
                // coupe le script en pleine transaction avant la fin (deja
                // observe : PHP Fatal error, transaction annulee automatiquement,
                // aucune donnee ecrite - echec propre mais total). 10 minutes.
                set_time_limit(600);

                // Barre de progression : pas d'AJAX/websocket dans ce projet,
                // donc on desactive la bufferisation et on pousse des <script>
                // au fur et a mesure - chacun s'execute des son arrivee dans
                // le navigateur et met a jour la meme barre en place. Marche
                // sur de l'hebergement mutualise classique, aucune dependance
                // en plus.
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @ini_set('zlib.output_compression', '0');
                @ini_set('implicit_flush', '1');
                ob_implicit_flush(true);

                echo '<div style="margin:14px 1px;max-width:520px;">'
                    . '<div style="background:#eee;border-radius:6px;height:20px;overflow:hidden;">'
                    . '<div id="import-bar" style="background:rgb(24,185,24);height:100%;width:0%;transition:width .3s;"></div>'
                    . '</div>'
                    . '<p id="import-texte" style="margin:6px 0;color:#555;font-size:14px;">Préparation...</p>'
                    . '</div>';
                flush();

                function import_progress(string $texte, float $pourcentage): void
                {
                    // Repousse la limite d'execution a chaque appel plutot que
                    // de fixer une seule valeur globale au depart - un gros
                    // fichier avec beaucoup de designations jamais vues peut
                    // depasser n'importe quelle limite fixe (deja observe :
                    // 600s depassees en pleine classification). Tant que
                    // l'ecart entre deux appels reste sous 600s, le script
                    // peut tourner aussi longtemps que necessaire.
                    set_time_limit(600);
                    $pourcentage = max(0, min(100, $pourcentage));
                    echo '<script>'
                        . 'document.getElementById("import-bar").style.width="' . $pourcentage . '%";'
                        . 'document.getElementById("import-texte").innerText=' . json_encode($texte) . ';'
                        . '</script>' . "\n";
                    flush();
                }

                function import_eta(float $debut, int $fait, int $total): string
                {
                    if ($fait === 0) {
                        return '';
                    }
                    $ecoule = microtime(true) - $debut;
                    $restant = ($ecoule / $fait) * ($total - $fait);
                    if ($restant < 60) {
                        return ' - environ ' . (int)round($restant) . 's restantes';
                    }
                    return ' - environ ' . (int)round($restant / 60) . ' min restantes';
                }

                try {
                    // Charger le fichier Excel
                    $spreadsheet = IOFactory::load($fichier_tmp);
                    $sheet = $spreadsheet->getActiveSheet();

                    // getHighestRow() reflete la dimension globale de la
                    // feuille (mise en forme, cellule isolee tres bas...) et
                    // peut etre bien plus grande que la derniere ligne
                    // reellement remplie - deja observe : 18084 rapporte par
                    // getHighestRow() alors que les colonnes utiles s'arretent
                    // a 8500, ce qui faisait boucler inutilement sur ~9500
                    // lignes vides (lecture de cellules a chaque fois) et
                    // ralentissait l'import pour rien. getHighestDataRow()
                    // donne la vraie derniere ligne de donnees par colonne.
                    $derniereLigneUtile = max(
                        $sheet->getHighestDataRow('A'),
                        $sheet->getHighestDataRow('C')
                    );

                    // Auto-categorisation/liaison-vehicule (voir
                    // db/import_designation.sql) : une passe a part, AVANT la
                    // transaction d'import, pour que le cache LLM soit ecrit
                    // meme si l'import lui-meme echoue ensuite (ne jamais
                    // repayer un appel LLM deja fait).
                    $designationsSheet = [];
                    foreach ($sheet->getRowIterator(2, $derniereLigneUtile) as $ligneDesignation) {
                        $texte = trim((string)$sheet->getCell('C' . $ligneDesignation->getRowIndex())->getCalculatedValue());
                        if ($texte !== '') {
                            $designationsSheet[$texte] = true;
                        }
                    }

                    import_progress('Classification des désignations (0/' . count($designationsSheet) . ')...', 0);
                    $debutClassification = microtime(true);
                    $classifications = import_classification_resoudre($pdo, array_keys($designationsSheet), function (int $lotsFait, int $lotsTotal) use ($debutClassification) {
                        $pourcentage = $lotsTotal > 0 ? ($lotsFait / $lotsTotal) * 50 : 50;
                        import_progress(
                            "Classification des désignations, lot $lotsFait/$lotsTotal" . import_eta($debutClassification, $lotsFait, $lotsTotal),
                            $pourcentage
                        );
                    });
                    import_progress('Classification terminée. Import des produits...', 50);

                    // Initialisation des compteurs
                    $newProductsCount = 0;
                    $updatedProductsCount = 0;
                    $errors = [];

                    // Démarrer une transaction
                    $pdo->beginTransaction();

                    $totalLignes = $derniereLigneUtile - 1;
                    $ligneCourante = 0;
                    $debutImport = microtime(true);

                    foreach ($sheet->getRowIterator(2, $derniereLigneUtile) as $row) {
                        $rowIndex = $row->getRowIndex();
                        $ligneCourante++;
                        if ($ligneCourante % 200 === 0 || $ligneCourante === $totalLignes) {
                            import_progress(
                                "Import des produits, ligne $ligneCourante/$totalLignes" . import_eta($debutImport, $ligneCourante, $totalLignes),
                                50 + ($totalLignes > 0 ? ($ligneCourante / $totalLignes) * 50 : 50)
                            );
                        }

                        // Récupération des données du fichier Excel - CORRECTION ICI
                        $reference = trim($sheet->getCell('A' . $rowIndex)->getCalculatedValue() ?? '');
                        $libelle = trim($sheet->getCell('C' . $rowIndex)->getCalculatedValue() ?? '');
                        $marque = trim($sheet->getCell('D' . $rowIndex)->getCalculatedValue() ?? '');
                        $quant = (int)($sheet->getCell('E' . $rowIndex)->getCalculatedValue() ?? 0);
                       // $prix = (float)($sheet->getCell('G' . $rowIndex)->getCalculatedValue() ?? 0);
                        $prix_initial = (float)$sheet->getCell('H' . $rowIndex)->getCalculatedValue();
                        $prix = $prix_initial;
                        $stock = ($quant > 0) ? 1 : 0;
                        
                        if($prix_initial > 0 AND $prix_initial <= 2000){
                            $prix *= 1.5;
                        }
                        if($prix_initial > 2000 AND $prix_initial <= 4000){
                            $prix *= 1.4;
                        }
                        if($prix_initial > 4000 AND $prix_initial <= 6000){
                            $prix *= 1.35;
                        }
                        if($prix_initial > 6000 AND $prix_initial <= 8000){
                            $prix *= 1.3;
                        }
                        if($prix_initial > 8000 AND $prix_initial <= 15000){
                            $prix *= 1.25;
                        }
                        if($prix_initial > 15000 AND $prix_initial <= 30000){
                            $prix *= 1.2;
                        }
                        if($prix_initial > 30000 AND $prix_initial <= 50000){
                            $prix *= 1.15;
                        }
                        if($prix_initial > 50000 AND $prix_initial <= 60000){
                            $prix *= 1.12;
                        }
                        if($prix > 60000){
                            $prix *= 1.11;
                        }
                        
                        // Debug - affichage temporaire pour vérifier les données
                        // echo "Ligne $rowIndex: Ref='$reference', Lib='$libelle', Marque='$marque', Quant=$quant, Prix=$prix<br>";
                        
                        // Validation des données obligatoires
                        if (empty($reference)) {
                            $errors[] = "Ligne $rowIndex: Référence manquante";
                            continue;
                        }
                        
                        if (empty($libelle)) {
                            $errors[] = "Ligne $rowIndex: Libellé manquant pour la référence $reference";
                            continue;
                        }
                        
                        // Vérifier si la référence existe déjà
                        $sql = "SELECT r.id_reference, r.id_produit, p.marquepiece 
                                FROM reference r 
                                JOIN produit p ON r.id_produit = p.id_produit 
                                WHERE TRIM(r.reference) = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$reference]);
                        $existingRefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $productExists = false;
                        $matchingProductId = null;
                        
                        foreach ($existingRefs as $existing) {
                            if (trim($existing['marquepiece']) == trim($marque)) {
                                $productExists = true;
                                $matchingProductId = $existing['id_produit'];
                                break;
                            }
                        }
                        
                        if ($productExists) {
                            // Mise à jour du produit existant
                            $updateSql = "UPDATE produit
                                         SET prix = ?, stock = ?
                                         WHERE id_produit = ?";
                            $updateStmt = $pdo->prepare($updateSql);
                            $updateStmt->execute([$prix, $stock, $matchingProductId]);
                            $updatedProductsCount++;

                            // Trace aussi les produits deja existants vers leur
                            // designation (pas seulement les nouveaux crees
                            // ci-dessous) - sinon une correction humaine plus
                            // tard sur la page de revision ne peut jamais
                            // atteindre les produits qui existaient deja avant
                            // cet import (98% des lignes reelles mesurees).
                            $classificationExistant = $classifications[$libelle] ?? null;
                            if ($classificationExistant !== null) {
                                import_designation_tracer_produit($pdo, $classificationExistant['id_import_designation'], (int)$matchingProductId);
                            }
                        } else {
                            // Insertion d'un nouveau produit avec gestion des erreurs
                            try {
                                // Categorie/sous-categorie/vehicules suggeres par l'auto-
                                // classification (voir db/import_designation.sql) - appliques
                                // uniquement si statut='resolu' (confiance suffisante pour ne
                                // pas passer par la revue humaine). Un statut 'a_verifier' ne
                                // doit jamais ecrire quoi que ce soit sur le produit reel - 0/
                                // aucun vehicule, meme convention "non categorise" que le reste
                                // du catalogue, jamais bloquant pour la creation du produit.
                                $classification = $classifications[$libelle] ?? null;
                                $classificationResolue = $classification !== null && $classification['statut'] === 'resolu';
                                $idCategorie = $classificationResolue ? $classification['id_categorie'] : 0;
                                $idSousCategorie = $classificationResolue ? $classification['id_sous_categorie'] : 0;

                                // Insertion du produit - CORRECTION: utiliser les bonnes variables
                                $insertProductSql = "INSERT INTO produit (libelle, marquepiece, prix, stock, id_categorie, id_sous_categorie) VALUES (?, ?, ?, ?, ?, ?)";
                                $insertProductStmt = $pdo->prepare($insertProductSql);
                                $insertProductStmt->execute([$libelle, $marque, $prix, $stock, $idCategorie, $idSousCategorie]);
                                $newProductId = $pdo->lastInsertId();

                                // Vérifier que l'ID est valide
                                if ($newProductId <= 0) {
                                    throw new Exception("Échec de l'insertion du produit - ID invalide");
                                }

                                // Insertion de la référence - CORRECTION: utiliser $reference, pas $marque
                                $insertRefSql = "INSERT INTO reference (reference, id_produit) VALUES (?, ?)";
                                $insertRefStmt = $pdo->prepare($insertRefSql);
                                $insertRefStmt->execute([$reference, $newProductId]);

                                // Vérifier que la référence a bien été insérée
                                if ($insertRefStmt->rowCount() === 0) {
                                    throw new Exception("Échec de l'insertion de la référence");
                                }

                                if ($classification !== null) {
                                    // Trace toujours, meme non resolu - c'est ce qui permet a
                                    // l'onglet de revision de rattraper ce produit plus tard.
                                    import_designation_tracer_produit($pdo, $classification['id_import_designation'], (int)$newProductId);

                                    if ($classificationResolue && !empty($classification['id_voitures'])) {
                                        $sqlModele = $pdo->prepare('SELECT modele FROM voiture WHERE id_voiture = ?');
                                        $insertPvd = $pdo->prepare('INSERT INTO pvd (id_produit, id_voiture, description) VALUES (?, ?, ?)');
                                        foreach ($classification['id_voitures'] as $idVoiture) {
                                            $sqlModele->execute([$idVoiture]);
                                            $modeleVoiture = $sqlModele->fetchColumn() ?: '';
                                            $description = pvd_composer_description($libelle, $modeleVoiture, null, null, $marque, null, null);
                                            $insertPvd->execute([$newProductId, $idVoiture, $description]);
                                        }
                                    }
                                }

                                $newProductsCount++;
                            } catch (Exception $e) {
                                $errors[] = "Ligne $rowIndex: " . $e->getMessage();
                                // Ne pas faire rollback ici, juste continuer
                                continue;
                            }
                        }
                    }
                    
                    // Valider la transaction
                    $pdo->commit();
                    import_progress('Import terminé.', 100);

                    // Affichage des résultats
                    echo "<div class='result-message'>";
                    if ($newProductsCount > 0) {
                        echo "<p style='color: green;'>$newProductsCount nouveaux produits ajoutés.</p>";
                    }
                    if ($updatedProductsCount > 0) {
                        echo "<p style='color: green;'>$updatedProductsCount produits mis à jour.</p>";
                    }
                    if (empty($errors)) {
                        echo "<p style='color: green;'>Import terminé avec succès.</p>";
                    } else {
                        echo "<p style='color: orange;'>Import terminé avec quelques erreurs :</p>";
                        echo "<ul>";
                        foreach ($errors as $error) {
                            echo "<li>$error</li>";
                        }
                        echo "</ul>";
                    }
                    echo "</div>";
                    
                } catch (Exception $e) {
                    // Annuler la transaction en cas d'erreur
                    $pdo->rollBack();
                    echo "<p style='color: red;'>Erreur lors de l'import : " . $e->getMessage() . "</p>";
                }
            } else {
                echo "<p style='color: red;'>Veuillez télécharger un fichier valide.</p>";
            }
        }
        
    ?>
        
        <div class="page-voiture">
            <h1>En train de test</h1>
            <h2>Télécharger votre fichier pour modifier le stock</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="fichier">
                <input type="submit" name="modifier" value="modifier">
            </form>
        </div>


    </div>
</body>
</html>