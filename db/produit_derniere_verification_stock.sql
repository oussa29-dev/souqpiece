-- Souqpiece - ajout de produit.derniere_verification_stock (2026-09-23)
--
-- Rien dans le schema ne memorisait, avant cet ajout, si une reference
-- avait ete vue lors du dernier import "Stock complet" - la seule
-- approximation possible (quantite IS NULL) melange, mesure, des
-- produits jamais importes avec des produits touches par un doublon ou
-- une variante de ponctuation deja resolue ailleurs (voir
-- PLAN_RAPPORT_CATALOGUE_EXTENSIONS.md, section 4). Ajout purement
-- additif : NULL veut dire "jamais confirme par un import stock
-- complet", pose a chaque UPDATE ou INSERT resultant d'un import dans
-- stock_importer_stock_complet() (dashboard/stock.php).

ALTER TABLE produit ADD COLUMN derniere_verification_stock DATETIME NULL DEFAULT NULL AFTER quantite;
