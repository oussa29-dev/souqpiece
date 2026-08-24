-- Souqpiece - suppression de sous_categorie2 (fonctionnalite abandonnee)
--
-- Troisieme niveau de classement commence puis jamais termine : 2 lignes
-- de test seulement ("Test", "BLOCK NUM 1"), aucune colonne id_sous_categorie2
-- sur produit (donc aucun produit ne peut jamais y etre rattache), page
-- d'admin dashboard/sous-categorie2.php deja retiree du menu (lien
-- commente dans dashboard/categorie.php) et dont le bouton "Supprimer"
-- pointait vers un fichier qui n'existe meme pas
-- (dashboard/supprimer/sup-sous2.php).
--
-- Verifie avant suppression : aucune contrainte de cle etrangere reelle
-- (seule la cle primaire de la table elle-meme apparaissait dans
-- information_schema.KEY_COLUMN_USAGE).

DROP TABLE sous_categorie2;
