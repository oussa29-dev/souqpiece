-- Souqpiece - table ai_whatsapp_recu (2026-10-13)
--
-- WhatsApp message ids already handled by ai/whatsapp.php. Meta redelivers
-- a webhook when it is not sure we received it (slow answer, network
-- glitch), and the customer must not get the same reply twice nor be
-- billed twice for the model. INSERT IGNORE on this key = "first time".

CREATE TABLE ai_whatsapp_recu (
    wamid VARCHAR(191) NOT NULL PRIMARY KEY,
    telephone VARCHAR(20) NOT NULL,
    recu_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_whatsapp_recu_date (recu_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
