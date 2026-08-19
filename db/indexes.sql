-- Souqpiece - index de performance
--
-- Purement additif : un index ne modifie aucune donnée, ne change aucun
-- resultat de requete, et ne peut casser aucune page. Applicable a chaud
-- en production. Sur MyISAM la table est brievement verrouillee pendant
-- la creation - quelques millisecondes a cette taille (24k lignes).
--
-- Etat initial du projet : aucun index en dehors des cles primaires.
-- Chaque ligne ci-dessous corrige un scan de table complet mesure.
--
-- Rejouer ce fichier est sans risque : "duplicate key name" signifie
-- simplement que l'index existe deja.

-- ---------------------------------------------------------------------
-- Applique en production le 2026-08-17 (assistant IA)
-- Sans ces deux index, une recherche large de l'assistant prend
-- jusqu'a 3078 ms au lieu de ~120 ms. ai/tools.php en depend.
-- ---------------------------------------------------------------------
CREATE INDEX idx_pvd_id_produit ON pvd(id_produit);
CREATE INDEX idx_pvd_id_voiture ON pvd(id_voiture);

-- ---------------------------------------------------------------------
-- Lot 2 - mesures avant/apres sur copie locale de la production
-- ---------------------------------------------------------------------

-- reference : 61 010 lignes scannees a chaque recherche par reference.
-- Chemin exact de ai_lookup_by_reference : 21,65 ms -> 0,57 ms.
CREATE INDEX idx_reference_reference  ON reference(reference);

-- Jointure reference -> produit, presente dans toutes les recherches.
CREATE INDEX idx_reference_id_produit ON reference(id_produit);

-- panier : interroge par partie/navbar.php a CHAQUE chargement de page.
-- Gain faible aujourd'hui (431 lignes) mais preventif : la table grandit
-- avec le trafic et la requete est sur le chemin critique du site.
CREATE INDEX idx_panier_session ON panier(id_session);

-- Navigation par categorie / sous-categorie : 12,89 ms -> 1,19 ms.
CREATE INDEX idx_produit_categorie ON produit(id_categorie);
CREATE INDEX idx_produit_souscat   ON produit(id_sous_categorie);

-- Pages vehicules (voiture.php).
CREATE INDEX idx_voiture_marque ON voiture(id_marque);
