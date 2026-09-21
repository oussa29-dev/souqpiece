-- Souqpiece - journal des imports (2026-09-21)
--
-- Pourquoi : un import qui ne fait rien (mauvais fichier, fichier vide,
-- requete interrompue) ne laisse aucune trace - la page disparait au
-- rechargement et rien n'est ecrit en base. Cas reel : le boss a importe un
-- fichier ventes, la page a repondu "Import termine avec succes" sans lire
-- une seule ligne, et impossible ensuite de savoir quel fichier ni quand.
--
-- Chaque import (stock complet, ventes, achats, photos) ecrit ici UNE ligne
-- des son demarrage (avant toute transaction, donc conservee meme si
-- l'import est annule), completee a la fin. Une ligne restee "en_cours"
-- signifie que la requete est morte en route.
--
-- Table purement additive : aucune autre table n'est modifiee, et le code
-- continue de fonctionner si elle n'existe pas encore.

CREATE TABLE IF NOT EXISTS import_journal (
  id_import_journal INT NOT NULL AUTO_INCREMENT,
  type_import VARCHAR(20) NOT NULL,
  nom_fichier VARCHAR(255) NOT NULL,
  taille_octets INT NOT NULL DEFAULT 0,
  statut ENUM('en_cours','termine','vide','annule','echec') NOT NULL DEFAULT 'en_cours',
  lignes_lues INT DEFAULT NULL,
  crees INT DEFAULT NULL,
  mis_a_jour INT DEFAULT NULL,
  anomalies INT DEFAULT NULL,
  message VARCHAR(500) DEFAULT NULL,
  utilisateur VARCHAR(100) DEFAULT NULL,
  debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fin DATETIME DEFAULT NULL,
  PRIMARY KEY (id_import_journal),
  KEY idx_debut (debut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
