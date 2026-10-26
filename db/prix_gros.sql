-- Souqpiece - colonne produit.prix_gros (2026-10-26)
--
-- Wholesale price = exactly the "PV Gros" column of the stock file (store
-- owner, 13/10), given by the assistant to registered wholesale customers
-- (table client_gros) instead of the retail price. Written at every stock
-- import (dashboard/stock.php). NULL = unknown: the assistant then gives the
-- retail price and says the wholesale price is available from the store.
-- prix_gros_estime = 1 when the value was computed back from the retail
-- price (dashboard/include/prix_gros.php) rather than read from a stock file.

ALTER TABLE produit
    ADD COLUMN prix_gros INT NULL DEFAULT NULL AFTER prix,
    ADD COLUMN prix_gros_estime TINYINT(1) NOT NULL DEFAULT 0 AFTER prix_gros;
