-- Souqpiece - table ai_mot_appris (2026-11-16)
--
-- Words the store owner teaches the assistant from Dashboard > Apprendre a
-- l'IA: a customer's word (mot, any script, stored lowercase) -> the word
-- the catalog writes (equivalent). type 'piece' for part words, 'voiture'
-- for vehicle words. Read by ai_mots_appris() in ai/tools.php, on top of the
-- built-in dictionary of ai/search_aliases.php.

CREATE TABLE ai_mot_appris (
    id_mot INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('piece', 'voiture') NOT NULL,
    mot VARCHAR(60) NOT NULL,
    equivalent VARCHAR(100) NOT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY mot_equivalent (mot, equivalent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
