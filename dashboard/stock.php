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
        // Force le mode exception, comme ajouter-produit.php. Le fichier de
        // connexion n'est pas versionne (chaque machine a le sien) et celui de
        // la production ne fixe pas ce mode : sous PHP 7.4 le defaut est alors
        // SILENT, une requete SQL refusee est ignoree sans message et l'import
        // annonce quand meme "N produits mis a jour" (reproduit : une mise a
        // jour refusee par la base etait comptee comme reussie). En mode
        // exception, l'import s'arrete, la transaction est annulee et l'echec
        // apparait a l'ecran et dans le journal.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        include('include/menu.php');
     ?>


    <div class="site">

        <div class="barre">Stock des produits</div>
    <?php
        // Inclure les fichiers nécessaires pour PhpSpreadsheet
        require '../vendor/autoload.php';
        require_once 'include/import_classification.php';
        require_once 'include/pvd_extraction.php';
        require_once 'include/import_format.php';
        require_once 'include/import_photos.php';
        require_once 'include/import_journal.php';

        use PhpOffice\PhpSpreadsheet\IOFactory;

        // Barre de progression : pas d'AJAX/websocket dans ce projet, donc
        // on desactive la bufferisation et on pousse des <script> au fur
        // et a mesure - chacun s'execute des son arrivee dans le
        // navigateur et met a jour la meme barre en place. Marche sur de
        // l'hebergement mutualise classique, aucune dependance en plus.
        function import_demarrer_affichage(): void
        {
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
        }

        function import_progress(string $texte, float $pourcentage): void
        {
            // Repousse la limite d'execution a chaque appel plutot que de
            // fixer une seule valeur globale au depart - un gros fichier
            // avec beaucoup de designations jamais vues peut depasser
            // n'importe quelle limite fixe (deja observe : 600s depassees
            // en pleine classification). Tant que l'ecart entre deux
            // appels reste sous 600s, le script peut tourner aussi
            // longtemps que necessaire.
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

        // Meme bareme de marge que la version precedente du code,
        // reutilise a l'identique pour le stock complet et pour les
        // nouveaux produits crees via les achats du jour (voir remarque
        // dans le rapport de session : ce bareme s'applique au prix
        // trouve dans la colonne "PV Gros"/"P.Vente Moyen" du fichier,
        // pas au prix d'achat).
        function stock_appliquer_marge(float $prixInitial): float
        {
            $prix = $prixInitial;
            if ($prixInitial > 0 && $prixInitial <= 2000) {
                $prix *= 1.5;
            }
            if ($prixInitial > 2000 && $prixInitial <= 4000) {
                $prix *= 1.4;
            }
            if ($prixInitial > 4000 && $prixInitial <= 6000) {
                $prix *= 1.35;
            }
            if ($prixInitial > 6000 && $prixInitial <= 8000) {
                $prix *= 1.3;
            }
            if ($prixInitial > 8000 && $prixInitial <= 15000) {
                $prix *= 1.25;
            }
            if ($prixInitial > 15000 && $prixInitial <= 30000) {
                $prix *= 1.2;
            }
            if ($prixInitial > 30000 && $prixInitial <= 50000) {
                $prix *= 1.15;
            }
            if ($prixInitial > 50000 && $prixInitial <= 60000) {
                $prix *= 1.12;
            }
            if ($prix > 60000) {
                $prix *= 1.11;
            }
            return $prix;
        }

        // Identifie TOUS les produits correspondant a une reference+marque -
        // dedupe par produit puis ne retombe sur la marque que si la
        // reference est reellement partagee par plusieurs produits
        // distincts (voir commit "Fix duplicate product creation on
        // marque-corrected reimports").
        //
        // Renvoie une liste et non un seul id : le catalogue contient de
        // vrais doublons (meme reference ET meme marque sur plusieurs
        // lignes produit - mesure : 1224 groupes couvrant 2961 produits).
        // Pour une mise a jour de stock, ils designent tous la meme piece
        // physique en magasin : n'en mettre qu'un seul a jour laissait les
        // autres affiches "disponible" alors que le stock etait tombe a 0
        // (bug remonte par le boss apres un import ventes).
        //
        // @return int[] ids produits, vide si aucune correspondance sure.
        function stock_trouver_produits(PDO $pdo, string $reference, string $marque): array
        {
            $sql = "SELECT r.id_produit, p.marquepiece
                    FROM reference r
                    JOIN produit p ON r.id_produit = p.id_produit
                    WHERE TRIM(r.reference) = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$reference]);
            $existingRefs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $produitsDistincts = [];
            foreach ($existingRefs as $existing) {
                $produitsDistincts[$existing['id_produit']] = $existing['marquepiece'];
            }

            if (count($produitsDistincts) === 1) {
                return [(int)array_key_first($produitsDistincts)];
            }

            // Plusieurs produits distincts partagent cette reference : la
            // marque redevient necessaire pour savoir lesquels concernent
            // reellement cette ligne. Tous ceux qui correspondent sont
            // retournes (des doublons exacts doivent bouger ensemble), les
            // autres marques sont de vrais produits differents et ne
            // doivent jamais etre touches.
            $correspondants = [];
            foreach ($produitsDistincts as $idProduit => $marquepiece) {
                if (trim($marquepiece) == trim($marque)) {
                    $correspondants[] = (int)$idProduit;
                }
            }
            return $correspondants;
        }

        // Variante "un seul produit" - pour les usages ou un identifiant
        // unique suffit (savoir si le produit existe deja avant d'en creer
        // un, rattacher une photo). Les mises a jour de stock, elles,
        // doivent passer par stock_trouver_produits() pour toucher aussi
        // les doublons.
        function stock_trouver_produit(PDO $pdo, string $reference, string $marque): ?int
        {
            $ids = stock_trouver_produits($pdo, $reference, $marque);
            return $ids === [] ? null : $ids[0];
        }

        // Les imports ventes/achats ecrivent tout "Stock Actuel" tel quel :
        // sans cette colonne, une lecture "vide" valait 0 et mettait le
        // stock de tous les produits listes a 0 sans le moindre avertissement
        // cote achats (mesure sur un fichier de test). On refuse donc le
        // fichier entier avant la moindre ecriture - et avant tout appel
        // LLM de classification, qui serait paye pour rien.
        function stock_exiger_colonne_stock_actuel(array $descripteur, string $nomImport): void
        {
            if (!isset($descripteur['colonnes']['STOCK_ACTUEL'])) {
                throw new ImportFormatException(
                    "La colonne « Stock Actuel » est introuvable dans ce fichier ($nomImport). "
                    . 'Colonnes reconnues : ' . implode(', ', $descripteur['libelles_trouves']) . '. '
                    . "Import annulé, rien n'a été écrit."
                );
            }
        }

        // $lignesLues = nombre de lignes du fichier portant une reference. A 0,
        // le fichier ne contenait rien d'exploitable : afficher "succes" en vert
        // etait trompeur (cas reel du boss : un fichier ventes sans aucune
        // vente donnait exactement le meme ecran qu'un import reussi, sans
        // meme la ligne "produits mis a jour").
        function stock_afficher_rapport(int $crees, int $maj, array $erreurs, string $titreErreurs = 'Import terminé avec quelques erreurs :', ?int $lignesLues = null): void
        {
            echo "<div class='result-message'>";
            if ($lignesLues === 0) {
                echo "<p style='color: #d35400;font-weight:bold;'>Aucune ligne exploitable trouvée dans ce fichier : rien n'a été importé. "
                    . "Vérifiez que c'est le bon fichier (un export sans vente, sans achat ou sans stock est vide).</p>";
            }
            if ($crees > 0) {
                echo "<p style='color: green;'>$crees nouveaux produits ajoutés.</p>";
            }
            if ($maj > 0) {
                echo "<p style='color: green;'>$maj produits mis à jour.</p>";
            }
            if (empty($erreurs)) {
                if ($lignesLues !== 0) {
                    echo "<p style='color: green;'>Import terminé avec succès.</p>";
                }
            } else {
                echo "<p style='color: orange;'>$titreErreurs</p><ul>";
                foreach (array_slice($erreurs, 0, 300) as $erreur) {
                    echo "<li>" . htmlspecialchars($erreur) . "</li>";
                }
                if (count($erreurs) > 300) {
                    echo "<li>... et " . (count($erreurs) - 300) . " autre(s), non affichée(s).</li>";
                }
                echo "</ul>";
            }
            echo "</div>";
        }

        // ---------------------------------------------------------------
        // Import "Stock complet" : cree/met a jour les produits, classe les
        // nouvelles designations par LLM, puis reconcilie - tout produit
        // reference absent du fichier est marque hors stock (Phase 2 du
        // plan). C'est le seul des 3 boutons qui declenche la reconciliation.
        // ---------------------------------------------------------------
        function stock_importer_stock_complet(PDO $pdo, $sheet, array $descripteur, int $premiereLigne, int $derniereLigne): array
        {
            $lignesLues = 0;
            $designationsSheet = [];
            for ($ligne = $premiereLigne; $ligne <= $derniereLigne; $ligne++) {
                $texte = trim((string)(import_format_lire($sheet, $descripteur, $ligne, 'DESIGNATION') ?? ''));
                if ($texte !== '') {
                    $designationsSheet[$texte] = true;
                }
            }

            import_progress('Classification des désignations (0/' . count($designationsSheet) . ')...', 0);
            $debutClassification = microtime(true);
            $classifications = import_classification_resoudre($pdo, array_keys($designationsSheet), function (int $lotsFait, int $lotsTotal) use ($debutClassification) {
                $pourcentage = $lotsTotal > 0 ? ($lotsFait / $lotsTotal) * 45 : 45;
                import_progress(
                    "Classification des désignations, lot $lotsFait/$lotsTotal" . import_eta($debutClassification, $lotsFait, $lotsTotal),
                    $pourcentage
                );
            });
            import_progress('Classification terminée. Import des produits...', 45);

            $newProductsCount = 0;
            $updatedProductsCount = 0;
            $errors = [];
            $referencesVues = [];

            $pdo->beginTransaction();

            $totalLignes = $derniereLigne - $premiereLigne + 1;
            $ligneCourante = 0;
            $debutImport = microtime(true);

            for ($rowIndex = $premiereLigne; $rowIndex <= $derniereLigne; $rowIndex++) {
                $ligneCourante++;
                if ($ligneCourante % 200 === 0 || $ligneCourante === $totalLignes) {
                    import_progress(
                        "Import des produits, ligne $ligneCourante/$totalLignes" . import_eta($debutImport, $ligneCourante, $totalLignes),
                        45 + ($totalLignes > 0 ? ($ligneCourante / $totalLignes) * 45 : 45)
                    );
                }

                $reference = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'REFERENCE') ?? ''));
                $libelle = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'DESIGNATION') ?? ''));
                $marque = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'MARQUE') ?? ''));
                $quant = (int)import_format_nombre(import_format_lire($sheet, $descripteur, $rowIndex, 'QUANT'));
                $prixAchatVal = import_format_nombre(import_format_lire($sheet, $descripteur, $rowIndex, 'PRIX_ACHAT'));
                $prixInitial = import_format_nombre(import_format_lire($sheet, $descripteur, $rowIndex, 'PV_GROS'));

                if ($reference === '' && $libelle === '') {
                    // Ligne entierement vide - dispersees dans le fichier
                    // reel (509 mesurees), pas seulement en fin de fichier.
                    continue;
                }
                if ($reference === '') {
                    if ($prixAchatVal <= 0 && $prixInitial <= 0) {
                        // Ligne de continuation (fragment de designation
                        // sans reference ni prix, ex. "4WD AV") - 74
                        // mesurees dans le fichier reel, pas une vraie
                        // erreur, juste un residu de mise en forme du
                        // fichier source.
                        continue;
                    }
                    $errors[] = "Ligne $rowIndex: Référence manquante";
                    continue;
                }
                $lignesLues++;
                if ($libelle === '') {
                    $errors[] = "Ligne $rowIndex: Libellé manquant pour la référence $reference";
                    continue;
                }

                $referencesVues[$reference] = true;

                if ($quant < 0) {
                    $errors[] = "Ligne $rowIndex: quantité négative ($quant) pour $reference, ramenée à 0";
                    $quant = 0;
                }

                $prix = stock_appliquer_marge($prixInitial);
                $stock = ($quant > 0) ? 1 : 0;

                // Tous les produits correspondants, pas seulement le
                // premier : de vrais doublons (meme reference + meme
                // marque) designent la meme piece physique et doivent
                // tous refleter le meme stock.
                $matchingProductIds = stock_trouver_produits($pdo, $reference, $marque);

                if ($matchingProductIds !== []) {
                    $updateStmt = $pdo->prepare('UPDATE produit SET prix = ?, stock = ?, quantite = ? WHERE id_produit = ?');
                    $classificationExistant = $classifications[$libelle] ?? null;
                    foreach ($matchingProductIds as $matchingProductId) {
                        $updateStmt->execute([$prix, $stock, $quant, $matchingProductId]);
                        $updatedProductsCount++;

                        // Trace aussi les produits deja existants vers leur
                        // designation (pas seulement les nouveaux crees
                        // ci-dessous) - sinon une correction humaine plus tard
                        // sur la page de revision ne peut jamais atteindre les
                        // produits qui existaient deja avant cet import (98%
                        // des lignes reelles mesurees).
                        if ($classificationExistant !== null) {
                            import_designation_tracer_produit($pdo, $classificationExistant['id_import_designation'], $matchingProductId);
                        }
                    }
                } else {
                    try {
                        $classification = $classifications[$libelle] ?? null;
                        $classificationResolue = $classification !== null && $classification['statut'] === 'resolu';
                        $idCategorie = $classificationResolue ? $classification['id_categorie'] : 0;
                        $idSousCategorie = $classificationResolue ? $classification['id_sous_categorie'] : 0;

                        $insertProductStmt = $pdo->prepare('INSERT INTO produit (libelle, marquepiece, prix, stock, quantite, id_categorie, id_sous_categorie) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $insertProductStmt->execute([$libelle, $marque, $prix, $stock, $quant, $idCategorie, $idSousCategorie]);
                        $newProductId = $pdo->lastInsertId();

                        if ($newProductId <= 0) {
                            throw new Exception("Échec de l'insertion du produit - ID invalide");
                        }

                        $insertRefStmt = $pdo->prepare('INSERT INTO reference (reference, id_produit) VALUES (?, ?)');
                        $insertRefStmt->execute([$reference, $newProductId]);

                        if ($insertRefStmt->rowCount() === 0) {
                            throw new Exception("Échec de l'insertion de la référence");
                        }

                        if ($classification !== null) {
                            import_designation_tracer_produit($pdo, $classification['id_import_designation'], (int)$newProductId);

                            if ($classificationResolue && !empty($classification['id_voitures'])) {
                                $sqlModele = $pdo->prepare('SELECT modele, annee_debut, annee_fin FROM voiture WHERE id_voiture = ?');
                                $insertPvd = $pdo->prepare('INSERT INTO pvd (id_produit, id_voiture, description) VALUES (?, ?, ?)');
                                foreach ($classification['id_voitures'] as $idVoiture) {
                                    $sqlModele->execute([$idVoiture]);
                                    $voitureRow = $sqlModele->fetch(PDO::FETCH_ASSOC) ?: [];
                                    $modeleVoiture = $voitureRow['modele'] ?? '';
                                    $anneeDebut = isset($voitureRow['annee_debut']) ? (int)$voitureRow['annee_debut'] : null;
                                    $anneeFin = isset($voitureRow['annee_fin']) ? (int)$voitureRow['annee_fin'] : null;
                                    $description = pvd_composer_description($libelle, $modeleVoiture, $anneeDebut, $anneeFin, $marque, null, null);
                                    $insertPvd->execute([$newProductId, $idVoiture, $description]);
                                }
                            }
                        }

                        $newProductsCount++;
                    } catch (Exception $e) {
                        $errors[] = "Ligne $rowIndex: " . $e->getMessage();
                        continue;
                    }
                }
            }

            $pdo->commit();
            import_progress('Réconciliation du stock...', 92);

            // Reconciliation (Phase 2) : tout produit ayant au moins une
            // reference et absent de ce fichier est passe hors stock. Les
            // produits sans reference du tout sont exclus (impossible a
            // verifier) et listes a part pour un traitement manuel.
            $produitsZeroifies = 0;
            if (!empty($referencesVues)) {
                // Pas de ENGINE=MEMORY : ce moteur reserve une largeur
                // fixe par ligne pour un VARCHAR, ce qui fait exploser la
                // taille reelle avec ~17 000 references et depasse vite
                // max_heap_table_size (mesure : "table tmp_stock_refs is
                // full" sur le vrai fichier stock complet). Le moteur par
                // defaut (InnoDB) n'a pas cette limite.
                $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_stock_refs');
                $pdo->exec('CREATE TEMPORARY TABLE tmp_stock_refs (reference VARCHAR(255) NOT NULL, PRIMARY KEY (reference))');

                $insertTmp = $pdo->prepare('INSERT IGNORE INTO tmp_stock_refs (reference) VALUES ' . implode(',', array_fill(0, 500, '(?)')));
                $toutesRefs = array_keys($referencesVues);
                foreach (array_chunk($toutesRefs, 500) as $lot) {
                    if (count($lot) < 500) {
                        $pdo->prepare('INSERT IGNORE INTO tmp_stock_refs (reference) VALUES ' . implode(',', array_fill(0, count($lot), '(?)')))->execute($lot);
                    } else {
                        $insertTmp->execute($lot);
                    }
                }

                $reconcile = $pdo->prepare(
                    'UPDATE produit p
                     SET p.stock = 0, p.quantite = 0
                     WHERE EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit)
                       AND NOT EXISTS (
                             SELECT 1 FROM reference r2
                             JOIN tmp_stock_refs t ON t.reference = TRIM(r2.reference)
                             WHERE r2.id_produit = p.id_produit
                           )
                       AND (p.stock <> 0 OR p.quantite IS NULL OR p.quantite <> 0)'
                );
                $reconcile->execute();
                $produitsZeroifies = $reconcile->rowCount();

                $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_stock_refs');
            }

            // Meme perimetre que l'onglet "Sans reference" de
            // rapport-catalogue.php (stock=1) - un produit sans reference
            // deja marque non disponible n'a rien d'urgent a signaler ici.
            $sansReference = (int)$pdo->query(
                "SELECT COUNT(*) FROM produit p WHERE p.stock = 1 AND NOT EXISTS (SELECT 1 FROM reference r WHERE r.id_produit = p.id_produit)"
            )->fetchColumn();

            import_progress($lignesLues === 0 ? 'Aucune ligne à importer.' : 'Import terminé.', 100);

            stock_afficher_rapport($newProductsCount, $updatedProductsCount, $errors, 'Import terminé avec quelques erreurs :', $lignesLues);

            // Fichier sans aucune ligne exploitable : rien n'a ete importe, donc
            // pas de bilan de reconciliation a afficher (ce serait un faux "0
            // produit repasse hors stock" a cote d'un fichier vide).
            if ($lignesLues > 0) {
                echo "<div class='result-message'>";
                echo "<p style='color: green;'>$produitsZeroifies produit(s) absent(s) de ce fichier repassé(s) hors stock.</p>";
                if ($sansReference > 0) {
                    echo "<p style='color: orange;'>$sansReference produit(s) disponibles du catalogue n'ont aucune référence enregistrée - "
                        . "impossible de vérifier leur présence dans ce fichier, ils n'ont pas été touchés. "
                        . "<a href='rapport-catalogue.php?vue=sans_reference' target='_blank'>Voir la liste complète</a>.</p>";
                }
                echo '</div>';
            }

            return [
                'lignes_lues' => $lignesLues,
                'crees' => $newProductsCount,
                'maj' => $updatedProductsCount,
                'anomalies' => count($errors),
                'detail' => $lignesLues > 0 ? "$produitsZeroifies produit(s) absent(s) du fichier repassé(s) hors stock" : null,
            ];
        }

        // ---------------------------------------------------------------
        // Import "Ventes du jour" : ne cree jamais de produit. Ecrit
        // directement la colonne "Stock Actuel" du fichier (deja le
        // resultat final apres la vente, aucune arithmetique a faire).
        // ---------------------------------------------------------------
        function stock_importer_ventes(PDO $pdo, $sheet, array $descripteur, int $premiereLigne, int $derniereLigne): array
        {
            stock_exiger_colonne_stock_actuel($descripteur, 'ventes du jour');
            import_progress('Import des ventes du jour...', 20);

            $lignesLues = 0;
            $updatedCount = 0;
            $anomalies = [];
            $pdo->beginTransaction();

            for ($rowIndex = $premiereLigne; $rowIndex <= $derniereLigne; $rowIndex++) {
                $reference = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'REFERENCE') ?? ''));
                $designation = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'DESIGNATION') ?? ''));
                $marque = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'MARQUE') ?? ''));
                $stockActuelVal = import_format_lire($sheet, $descripteur, $rowIndex, 'STOCK_ACTUEL');

                if ($reference === '' && $designation === '') {
                    // Ligne de total en fin de fichier - a ignorer, pas une erreur.
                    continue;
                }
                if ($reference === '') {
                    $anomalies[] = "Ligne $rowIndex: référence manquante, vente ignorée";
                    continue;
                }
                $lignesLues++;
                if ($stockActuelVal === null || trim((string)$stockActuelVal) === '') {
                    $anomalies[] = "Ligne $rowIndex: Stock Actuel vide pour $reference, vente ignorée";
                    continue;
                }

                $stockActuel = (int)import_format_nombre($stockActuelVal);
                if ($stockActuel < 0) {
                    // Stock negatif cote logiciel (ex. vente enregistree avant
                    // l'entree en stock) : le site ne doit jamais stocker une
                    // quantite negative - meme regle que le stock complet.
                    $anomalies[] = "Ligne $rowIndex: stock actuel négatif ($stockActuel) pour $reference, ramené à 0";
                    $stockActuel = 0;
                }
                // Tous les produits correspondants : un vrai doublon laisse
                // de cote resterait affiche "disponible" alors que la piece
                // est a 0 en magasin (bug remonte par le boss).
                $idsProduits = stock_trouver_produits($pdo, $reference, $marque);

                if ($idsProduits === []) {
                    $anomalies[] = "Ligne $rowIndex: référence $reference introuvable dans le catalogue, vente ignorée";
                    continue;
                }

                $updateStmt = $pdo->prepare('UPDATE produit SET stock = ?, quantite = ? WHERE id_produit = ?');
                foreach ($idsProduits as $idProduit) {
                    $updateStmt->execute([$stockActuel > 0 ? 1 : 0, $stockActuel, $idProduit]);
                    $updatedCount++;
                }
            }

            $pdo->commit();
            import_progress($lignesLues === 0 ? 'Aucune ligne à importer.' : 'Import terminé.', 100);
            stock_afficher_rapport(0, $updatedCount, $anomalies, 'Import terminé, quelques lignes à vérifier :', $lignesLues);

            return ['lignes_lues' => $lignesLues, 'crees' => 0, 'maj' => $updatedCount, 'anomalies' => count($anomalies)];
        }

        // ---------------------------------------------------------------
        // Import "Achats du jour" : peut creer un produit (nouvel article
        // en inventaire), sinon met a jour uniquement stock/quantite -
        // ne touche jamais le prix d'un produit deja existant.
        // ---------------------------------------------------------------
        function stock_importer_achats(PDO $pdo, $sheet, array $descripteur, int $premiereLigne, int $derniereLigne): array
        {
            stock_exiger_colonne_stock_actuel($descripteur, 'achats du jour');
            $lignesLues = 0;

            $designationsSheet = [];
            for ($ligne = $premiereLigne; $ligne <= $derniereLigne; $ligne++) {
                $texte = trim((string)(import_format_lire($sheet, $descripteur, $ligne, 'DESIGNATION') ?? ''));
                if ($texte !== '') {
                    $designationsSheet[$texte] = true;
                }
            }

            import_progress('Classification des désignations (0/' . count($designationsSheet) . ')...', 0);
            $debutClassification = microtime(true);
            $classifications = import_classification_resoudre($pdo, array_keys($designationsSheet), function (int $lotsFait, int $lotsTotal) use ($debutClassification) {
                $pourcentage = $lotsTotal > 0 ? ($lotsFait / $lotsTotal) * 45 : 45;
                import_progress(
                    "Classification des désignations, lot $lotsFait/$lotsTotal" . import_eta($debutClassification, $lotsFait, $lotsTotal),
                    $pourcentage
                );
            });
            import_progress('Classification terminée. Import des achats...', 45);

            $newProductsCount = 0;
            $updatedProductsCount = 0;
            $errors = [];
            $pdo->beginTransaction();

            for ($rowIndex = $premiereLigne; $rowIndex <= $derniereLigne; $rowIndex++) {
                $reference = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'REFERENCE') ?? ''));
                $libelle = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'DESIGNATION') ?? ''));
                $marque = trim((string)(import_format_lire($sheet, $descripteur, $rowIndex, 'MARQUE') ?? ''));
                $stockActuelVal = import_format_lire($sheet, $descripteur, $rowIndex, 'STOCK_ACTUEL');

                if ($reference === '' && $libelle === '') {
                    continue;
                }
                if ($reference === '') {
                    $errors[] = "Ligne $rowIndex: référence manquante";
                    continue;
                }
                $lignesLues++;
                if ($stockActuelVal === null || trim((string)$stockActuelVal) === '') {
                    // Une cellule vide valait 0 et mettait le produit hors
                    // stock (ou creait un nouveau produit a quantite 0) sans
                    // rien signaler : on ignore la ligne et on le dit.
                    $errors[] = "Ligne $rowIndex: Stock Actuel vide pour $reference, ligne ignorée";
                    continue;
                }

                $stockActuel = (int)import_format_nombre($stockActuelVal);
                if ($stockActuel < 0) {
                    $errors[] = "Ligne $rowIndex: stock actuel négatif ($stockActuel) pour $reference, ramené à 0";
                    $stockActuel = 0;
                }
                $stock = $stockActuel > 0 ? 1 : 0;

                // Tous les produits correspondants (doublons inclus), meme
                // raison que pour les ventes et le stock complet.
                $idsProduits = stock_trouver_produits($pdo, $reference, $marque);

                if ($idsProduits !== []) {
                    // Produit deja connu : seul le stock/la quantite
                    // bougent, jamais le prix (la valeur "P.Vente Moyen"
                    // du jour n'est qu'une moyenne d'achat, pas une
                    // decision tarifaire).
                    $updateStmt = $pdo->prepare('UPDATE produit SET stock = ?, quantite = ? WHERE id_produit = ?');
                    $classificationExistant = $classifications[$libelle] ?? null;
                    foreach ($idsProduits as $idProduit) {
                        $updateStmt->execute([$stock, $stockActuel, $idProduit]);
                        $updatedProductsCount++;

                        if ($classificationExistant !== null) {
                            import_designation_tracer_produit($pdo, $classificationExistant['id_import_designation'], $idProduit);
                        }
                    }
                    continue;
                }

                if ($libelle === '') {
                    $errors[] = "Ligne $rowIndex: libellé manquant pour la référence $reference (nouvel article)";
                    continue;
                }

                try {
                    $prixInitial = import_format_nombre(import_format_lire($sheet, $descripteur, $rowIndex, 'PV_GROS'));
                    $prix = stock_appliquer_marge($prixInitial);

                    $classification = $classifications[$libelle] ?? null;
                    $classificationResolue = $classification !== null && $classification['statut'] === 'resolu';
                    $idCategorie = $classificationResolue ? $classification['id_categorie'] : 0;
                    $idSousCategorie = $classificationResolue ? $classification['id_sous_categorie'] : 0;

                    $insertProductStmt = $pdo->prepare('INSERT INTO produit (libelle, marquepiece, prix, stock, quantite, id_categorie, id_sous_categorie) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $insertProductStmt->execute([$libelle, $marque, $prix, $stock, $stockActuel, $idCategorie, $idSousCategorie]);
                    $newProductId = $pdo->lastInsertId();

                    if ($newProductId <= 0) {
                        throw new Exception("Échec de l'insertion du produit - ID invalide");
                    }

                    $insertRefStmt = $pdo->prepare('INSERT INTO reference (reference, id_produit) VALUES (?, ?)');
                    $insertRefStmt->execute([$reference, $newProductId]);
                    if ($insertRefStmt->rowCount() === 0) {
                        throw new Exception("Échec de l'insertion de la référence");
                    }

                    if ($classification !== null) {
                        import_designation_tracer_produit($pdo, $classification['id_import_designation'], (int)$newProductId);

                        if ($classificationResolue && !empty($classification['id_voitures'])) {
                            $sqlModele = $pdo->prepare('SELECT modele, annee_debut, annee_fin FROM voiture WHERE id_voiture = ?');
                            $insertPvd = $pdo->prepare('INSERT INTO pvd (id_produit, id_voiture, description) VALUES (?, ?, ?)');
                            foreach ($classification['id_voitures'] as $idVoiture) {
                                $sqlModele->execute([$idVoiture]);
                                $voitureRow = $sqlModele->fetch(PDO::FETCH_ASSOC) ?: [];
                                $modeleVoiture = $voitureRow['modele'] ?? '';
                                $anneeDebut = isset($voitureRow['annee_debut']) ? (int)$voitureRow['annee_debut'] : null;
                                $anneeFin = isset($voitureRow['annee_fin']) ? (int)$voitureRow['annee_fin'] : null;
                                $description = pvd_composer_description($libelle, $modeleVoiture, $anneeDebut, $anneeFin, $marque, null, null);
                                $insertPvd->execute([$newProductId, $idVoiture, $description]);
                            }
                        }
                    }

                    $newProductsCount++;
                } catch (Exception $e) {
                    $errors[] = "Ligne $rowIndex: " . $e->getMessage();
                    continue;
                }
            }

            $pdo->commit();
            import_progress($lignesLues === 0 ? 'Aucune ligne à importer.' : 'Import terminé.', 100);
            stock_afficher_rapport($newProductsCount, $updatedProductsCount, $errors, 'Import terminé avec quelques erreurs :', $lignesLues);

            return ['lignes_lues' => $lignesLues, 'crees' => $newProductsCount, 'maj' => $updatedProductsCount, 'anomalies' => count($errors)];
        }

        // ---------------------------------------------------------------
        // Import "Photos" : associe chaque image d'un zip a un produit par
        // reference+marque (nom de fichier REFERENCE_MARQUEPIECE_N.ext),
        // voir PLAN_IMPORT_PHOTOS.md. Ne cree jamais de produit - une
        // reference/marque introuvable est une anomalie a signaler.
        // ---------------------------------------------------------------
        function stock_importer_photos(PDO $pdo, string $cheminZip): array
        {
            $dossierTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'souqpiece_photos_' . uniqid('', true);

            try {
                $fichiers = photo_extraire_zip_securise($cheminZip, $dossierTemp);
            } catch (ImportPhotosException $e) {
                echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
                return ['lignes_lues' => 0, 'crees' => 0, 'maj' => 0, 'anomalies' => 0, 'statut' => 'echec', 'message' => $e->getMessage()];
            }
            $lignesLues = count($fichiers);

            import_progress('Analyse des ' . count($fichiers) . ' fichier(s) du zip...', 5);

            // Associe chaque fichier extrait a son nom d'origine (pas
            // seulement son chemin temporaire) pour un rapport lisible,
            // et trie par (reference, marque, imgnbr) - traitement
            // deterministe : le decalage vers l'emplacement libre suivant
            // (section 4 du plan) doit donner le meme resultat a chaque
            // import du meme lot, jamais dependant de l'ordre d'extraction
            // du zip.
            $analyses = [];
            $erreurs = [];
            foreach ($fichiers as $cheminExtrait) {
                $nomOriginal = basename($cheminExtrait);
                $parse = photo_parser_nom($nomOriginal);
                if ($parse === null) {
                    $erreurs[] = "$nomOriginal : nom de fichier non reconnu (attendu REFERENCE_MARQUEPIECE_N.jpg/.jpeg/.png)";
                    continue;
                }
                $analyses[] = array_merge($parse, ['chemin' => $cheminExtrait, 'nom' => $nomOriginal]);
            }
            usort($analyses, function ($a, $b) {
                return [$a['reference'], $a['marquepiece'], $a['imgnbr']] <=> [$b['reference'], $b['marquepiece'], $b['imgnbr']];
            });

            $dossierImages = __DIR__ . '/../img/produit/';
            if (!is_dir($dossierImages)) {
                @mkdir($dossierImages, 0755, true);
            }

            $emplacementsConnus = []; // id_produit => [1..10 => nom de fichier stocke]
            $produitsTouches = [];
            $imagesAppliquees = 0;
            $total = count($analyses);
            $traites = 0;
            $debut = microtime(true);

            foreach ($analyses as $item) {
                $traites++;
                if ($traites % 50 === 0 || $traites === $total) {
                    import_progress(
                        "Association des photos, $traites/$total" . import_eta($debut, $traites, $total),
                        5 + ($total > 0 ? ($traites / $total) * 90 : 90)
                    );
                }

                $idProduit = stock_trouver_produit($pdo, $item['reference'], $item['marquepiece']);
                if ($idProduit === null) {
                    $erreurs[] = "{$item['nom']} : produit introuvable pour référence « {$item['reference']} » / marque « {$item['marquepiece']} »";
                    continue;
                }

                if (!isset($emplacementsConnus[$idProduit])) {
                    $sqlImg = $pdo->prepare('SELECT img1, img2, img3, img4, img5, img6, img7, img8, img9, img10 FROM produit WHERE id_produit = ?');
                    $sqlImg->execute([$idProduit]);
                    $ligne = $sqlImg->fetch(PDO::FETCH_NUM) ?: array_fill(0, 10, '');
                    $emplacementsConnus[$idProduit] = array_combine(range(1, 10), $ligne);
                }

                $slot = photo_trouver_emplacement_libre($emplacementsConnus[$idProduit], $item['imgnbr']);
                if ($slot === null) {
                    $erreurs[] = "{$item['nom']} : produit #$idProduit déjà complet (10 photos), image ignorée";
                    continue;
                }

                $extension = strtolower(pathinfo($item['chemin'], PATHINFO_EXTENSION));
                $nouveauNom = uniqid('', true) . '.' . $extension;
                $destination = __DIR__ . '/../img/produit/' . $nouveauNom;

                if (!@rename($item['chemin'], $destination)) {
                    $erreurs[] = "{$item['nom']} : échec de la copie vers img/produit/";
                    continue;
                }

                $colonne = 'img' . $slot;
                $pdo->prepare("UPDATE produit SET $colonne = ? WHERE id_produit = ?")->execute([$nouveauNom, $idProduit]);

                $emplacementsConnus[$idProduit][$slot] = $nouveauNom;
                $produitsTouches[$idProduit] = true;
                $imagesAppliquees++;
            }

            photo_supprimer_dossier($dossierTemp);

            import_progress($lignesLues === 0 ? 'Aucune photo à importer.' : 'Import terminé.', 100);

            echo "<div class='result-message'>";
            if ($lignesLues === 0) {
                // Meme defaut que les imports Excel : un zip sans aucune image
                // exploitable affichait "succes" en vert.
                echo "<p style='color: #d35400;font-weight:bold;'>Aucune photo trouvée dans ce zip : rien n'a été importé. "
                    . "Vérifiez son contenu (fichiers .jpg, .jpeg ou .png nommés REFERENCE_MARQUEPIECE_N).</p>";
            }
            if ($imagesAppliquees > 0) {
                echo "<p style='color: green;'>$imagesAppliquees photo(s) appliquée(s) sur " . count($produitsTouches) . " produit(s).</p>";
            }
            if (empty($erreurs)) {
                if ($lignesLues !== 0) {
                    echo "<p style='color: green;'>Import terminé avec succès.</p>";
                }
            } else {
                echo "<p style='color: orange;'>Import terminé avec quelques anomalies :</p><ul>";
                foreach (array_slice($erreurs, 0, 300) as $erreur) {
                    echo '<li>' . htmlspecialchars($erreur) . '</li>';
                }
                if (count($erreurs) > 300) {
                    echo '<li>... et ' . (count($erreurs) - 300) . ' autre(s), non affichée(s).</li>';
                }
                echo '</ul>';
            }
            echo '</div>';

            return ['lignes_lues' => $lignesLues, 'crees' => 0, 'maj' => $imagesAppliquees, 'anomalies' => count($erreurs)];
        }

        if (isset($_POST['importer_photos'])) {
            if (isset($_FILES['fichier_photos']) && $_FILES['fichier_photos']['error'] == UPLOAD_ERR_OK) {
                set_time_limit(600);
                import_demarrer_affichage();
                // Trace ecrite AVANT tout traitement : elle survit meme si la
                // requete meurt en route (voir stock_journal_surveiller_arret).
                $journalId = stock_journal_debut($pdo, 'photos', (string)$_FILES['fichier_photos']['name'], (int)$_FILES['fichier_photos']['size']);
                stock_journal_surveiller_arret($pdo, $journalId);
                try {
                    $stats = stock_importer_photos($pdo, $_FILES['fichier_photos']['tmp_name']);
                    if (isset($stats['statut'])) {
                        stock_journal_fin($pdo, $journalId, $stats['statut'], $stats, $stats['message'] ?? null);
                    } else {
                        stock_journal_fin($pdo, $journalId, $stats['lignes_lues'] === 0 ? 'vide' : 'termine', $stats);
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    stock_journal_fin($pdo, $journalId, 'echec', [], $e->getMessage());
                    import_progress('Import annulé.', 100);
                    echo "<p style='color: red;'>Erreur lors de l'import : " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            } else {
                echo "<p style='color: red;'>Veuillez télécharger un fichier .zip valide.</p>";
            }
        }

        if (isset($_POST['importer']) && in_array($_POST['importer'], ['stock', 'ventes', 'achats'], true)) {
            $typeImport = $_POST['importer'];
            if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] == UPLOAD_ERR_OK) {
                $fichier_tmp = $_FILES['fichier']['tmp_name'];
                // Un fichier fournisseur reel peut depasser plusieurs milliers
                // de lignes - la limite par defaut de 120s (max_execution_time)
                // coupe le script en pleine transaction avant la fin (deja
                // observe : PHP Fatal error, transaction annulee automatiquement,
                // aucune donnee ecrite - echec propre mais total). 10 minutes,
                // reactives a chaque tick de progression (voir import_progress).
                set_time_limit(600);
                import_demarrer_affichage();
                // Trace ecrite AVANT tout traitement (et avant la transaction de
                // l'import) : elle survit meme si l'import est annule ou si la
                // requete meurt en route (voir stock_journal_surveiller_arret).
                $journalId = stock_journal_debut($pdo, $typeImport, (string)$_FILES['fichier']['name'], (int)$_FILES['fichier']['size']);
                stock_journal_surveiller_arret($pdo, $journalId);

                try {
                    $spreadsheet = IOFactory::load($fichier_tmp);
                    $sheet = $spreadsheet->getActiveSheet();

                    // Phase 0/1 du plan : ne jamais faire confiance a une
                    // lettre de colonne fixe. On detecte le type reel du
                    // fichier par le nom de ses colonnes et on verifie
                    // qu'il correspond au bouton clique - sinon on annule
                    // avant d'ecrire quoi que ce soit (voir
                    // dashboard/include/import_format.php).
                    $descripteur = import_format_detecter($sheet);
                    import_format_verifier_type($descripteur, $typeImport);

                    $colRef = $descripteur['colonnes']['REFERENCE'] ?? null;
                    $colDesig = $descripteur['colonnes']['DESIGNATION'] ?? null;
                    // getHighestRow() reflete la dimension globale de la
                    // feuille (mise en forme, cellule isolee tres bas...)
                    // et peut etre bien plus grande que la derniere ligne
                    // reellement remplie - deja observe : 18084 rapporte
                    // par getHighestRow() alors que les colonnes utiles
                    // s'arretent a 8500. getHighestDataRow() donne la
                    // vraie derniere ligne de donnees par colonne.
                    $derniereLigneUtile = max(
                        $colRef ? $sheet->getHighestDataRow($colRef) : 0,
                        $colDesig ? $sheet->getHighestDataRow($colDesig) : 0
                    );
                    $premiereLigne = $descripteur['premiere_ligne_donnees'];
                    if ($derniereLigneUtile < $premiereLigne) {
                        $derniereLigneUtile = $premiereLigne - 1;
                    }

                    if ($typeImport === 'stock') {
                        $stats = stock_importer_stock_complet($pdo, $sheet, $descripteur, $premiereLigne, $derniereLigneUtile);
                    } elseif ($typeImport === 'ventes') {
                        $stats = stock_importer_ventes($pdo, $sheet, $descripteur, $premiereLigne, $derniereLigneUtile);
                    } else {
                        $stats = stock_importer_achats($pdo, $sheet, $descripteur, $premiereLigne, $derniereLigneUtile);
                    }
                    stock_journal_fin($pdo, $journalId, $stats['lignes_lues'] === 0 ? 'vide' : 'termine', $stats);
                } catch (ImportFormatException $e) {
                    stock_journal_fin($pdo, $journalId, 'annule', [], $e->getMessage());
                    import_progress('Import annulé.', 100);
                    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
                } catch (Throwable $e) {
                    // Throwable et non Exception : une Error PHP (fonction
                    // inexistante sur la version du serveur, TypeError...) tuait
                    // la requete sans aucun message - c'est ce qui a cache le bug
                    // PHP 7.4 de l'import photos derriere une barre figee.
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    stock_journal_fin($pdo, $journalId, 'echec', [], $e->getMessage());
                    import_progress('Import annulé.', 100);
                    echo "<p style='color: red;'>Erreur lors de l'import : " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            } else {
                echo "<p style='color: red;'>Veuillez télécharger un fichier valide.</p>";
            }
        }

        // Historique des derniers imports (voir db/import_journal.sql), juste
        // sous le resultat de l'import en cours et au-dessus des formulaires.
        stock_journal_afficher($pdo);

    ?>

        <div class="page-voiture">
            <h1>Importer un fichier</h1>
            <h2>Choisissez le fichier puis le bouton correspondant à son contenu</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="fichier" required>
                <br><br>
                <input type="submit" name="importer" value="stock" style="margin-right:10px;">
                <label style="margin-right:25px;color:#666;">Stock complet</label>
                <input type="submit" name="importer" value="ventes" style="margin-right:10px;">
                <label style="margin-right:25px;color:#666;">Ventes du jour</label>
                <input type="submit" name="importer" value="achats" style="margin-right:10px;">
                <label style="color:#666;">Achats du jour</label>
                <p style="color:#888;font-size:13px;margin-top:10px;">
                    Le fichier est vérifié automatiquement : si ses colonnes ne
                    correspondent pas au bouton choisi, rien n'est importé.
                </p>
            </form>
        </div>

        <div class="page-voiture">
            <h1>Importer des photos</h1>
            <h2>Un fichier .zip contenant les photos, chacune nommée REFERENCE_MARQUEPIECE_N.jpg/.jpeg/.png (N = numéro de la photo, 1 à 10)</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="fichier_photos" accept=".zip" required>
                <br><br>
                <input type="submit" name="importer_photos" value="Importer les photos" style="margin-right:10px;">
                <p style="color:#888;font-size:13px;margin-top:10px;">
                    Exemple de nom de fichier : 16210-17050_ORIGINE_1.jpg. Si
                    l'emplacement demandé est déjà pris, la photo est rangée
                    dans le premier emplacement libre suivant.<br>
                    Si la référence ou la marque contient un « / », remplacez-le
                    par « ~ » dans le nom du fichier (ex. 84306-02190/ → 84306-02190~).
                    Pour un « * », remplacez-le par « ^ ».
                </p>
            </form>
        </div>


    </div>
</body>
</html>
