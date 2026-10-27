-- Souqpiece - table ai_reglage (2026-10-27)
--
-- Assistant switches changed from the dashboard, not from ai/config.php
-- (which the site cannot rewrite safely). First one: 'tarif_gros' = '1' gives
-- the numbers of Dashboard > Clients gros the wholesale price on WhatsApp;
-- absent or '0' = everyone gets the retail price. Toggled from the
-- developer view of Dashboard > Conversations only.

CREATE TABLE ai_reglage (
    cle VARCHAR(50) NOT NULL PRIMARY KEY,
    valeur VARCHAR(255) NOT NULL,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
