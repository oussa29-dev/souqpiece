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
                try {
                    // Charger le fichier Excel
                    $spreadsheet = IOFactory::load($fichier_tmp);
                    $sheet = $spreadsheet->getActiveSheet();
                    
                    // Initialisation des compteurs
                    $newProductsCount = 0;
                    $updatedProductsCount = 0;
                    $errors = [];
                    
                    // Démarrer une transaction
                    $pdo->beginTransaction();
        
                    foreach ($sheet->getRowIterator(2) as $row) {
                        $rowIndex = $row->getRowIndex();
                        
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
                        } else {
                            // Insertion d'un nouveau produit avec gestion des erreurs
                            try {
                                // Insertion du produit - CORRECTION: utiliser les bonnes variables
                                $insertProductSql = "INSERT INTO produit (libelle, marquepiece, prix, stock) VALUES (?, ?, ?, ?)";
                                $insertProductStmt = $pdo->prepare($insertProductSql);
                                $insertProductStmt->execute([$libelle, $marque, $prix, $stock]);
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