-- Souqpiece - colonne ai_whatsapp_recu.nom_profil (2026-10-26)
--
-- The customer's WhatsApp profile name, sent by Meta with every message
-- (contacts[].profile.name), so Dashboard > Conversations can list each
-- WhatsApp customer by name and number (store owner's request, 26/10).
-- NULL for messages received before this column, or when Meta sends none.

ALTER TABLE ai_whatsapp_recu
    ADD COLUMN nom_profil VARCHAR(100) NULL DEFAULT NULL AFTER telephone;
