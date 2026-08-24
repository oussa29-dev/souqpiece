-- Souqpiece - suppression de produit.description (colonne morte)
--
-- Doublon de pvd.description, jamais nettoye. produit.description est
-- vide sur 77,3% des produits (18 906/24 458) ; la ou elle est remplie,
-- son contenu duplique mot pour mot ce qui existe deja dans pvd.description
-- pour le meme produit (echantillon verifie sur les produits #19 et #27).
--
-- La vraie description, celle affichee au client, vit dans pvd.description
-- - une par couple (produit, vehicule), lue par produit.php ligne 45 avec
-- un texte de repli generique si vide. Verifie avant suppression :
--   - aucune occurrence de produit.description dans tout le code PHP
--     (recherche exhaustive de tous les acces ['description'], chacun
--     retrace jusqu'a pvd, jamais produit)
--   - aucun index sur cette colonne
--   - formulaire d'ajout produit (dashboard/ajouter-produit.php) teste en
--     conditions reelles apres suppression : creation de produit et de
--     sa description par vehicule (table pvd) toujours fonctionnelles.

ALTER TABLE produit DROP COLUMN description;
