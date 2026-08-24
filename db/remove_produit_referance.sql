-- Souqpiece - suppression de produit.referance (colonne morte)
--
-- Premiere conception, primitive, d'un numero de reference constructeur :
-- une seule reference par produit, stockee directement sur la table
-- produit (et mal orthographiee - "referance" avec un a). Remplacee par
-- la table `reference` (relation un-a-plusieurs, necessaire puisqu'un
-- produit peut porter plusieurs references OEM, et qu'une meme reference
-- peut correspondre a plusieurs produits vendus sous des marques
-- differentes). Verifie avant suppression :
--   - aucune occurrence de "referance" dans tout le code PHP (grep -ri)
--   - aucun index ne porte sur cette colonne
--   - la ou elle est remplie (4 997 produits sur 24 458), sa valeur
--     duplique exactement ce qui existe deja dans la table `reference`
--     pour le meme produit (echantillon verifie)
--   - le formulaire d'ajout/edition produit (dashboard/ajouter-produit.php)
--     teste en conditions reelles apres suppression : creation de produit
--     toujours fonctionnelle, sa requete INSERT ne mentionnait deja pas
--     cette colonne.

ALTER TABLE produit DROP COLUMN referance;
