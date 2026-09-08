-- Souqpiece - ajout de produit.quantite (2026-09-05)
--
-- Les 3 fichiers d'import (stock complet, ventes du jour, achats du
-- jour - voir PLAN_IMPORT_STOCK_3_FICHIERS.md) fournissent tous une
-- quantite reelle, alors que `produit.stock` n'a toujours ete qu'un
-- booleen (tinyint(1)). Ajout purement additif : `stock` reste la seule
-- colonne lue par le reste du site (badge "Epuise", bouton panier, tri
-- "disponibles d'abord"), simplement derivee de `quantite` a l'ecriture
-- (`stock = (quantite > 0) ? 1 : 0`).

ALTER TABLE produit ADD COLUMN quantite INT NULL DEFAULT NULL AFTER stock;
