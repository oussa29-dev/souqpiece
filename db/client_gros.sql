-- Souqpiece - table client_gros (2026-10-13)
--
-- Wholesale customers, recognised by their WhatsApp number so the
-- assistant (WhatsApp channel) can give them the wholesale price instead of
-- the retail one. Managed by the store from Dashboard > Clients gros.
--
-- telephone is stored normalised in international form without "+"
-- (213550123456), the format WhatsApp gives for the sender, so a lookup is
-- a plain equality. code_client is the customer's code in the store's own
-- software, used later to fill the order file (bon) for that software.

CREATE TABLE client_gros (
    id_client_gros INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    code_client VARCHAR(50) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_client_gros_telephone (telephone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
