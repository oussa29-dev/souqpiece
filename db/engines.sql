-- Souqpiece - conversion moteur MyISAM -> InnoDB (N3b)
--
-- MyISAM ne supporte ni les contraintes de cle etrangere, ni le crash
-- recovery, ni le verrouillage au niveau ligne (verrouille toute la table
-- a chaque ecriture). C'est la cause racine des 44 000+ lignes orphelines
-- supprimees en N1b : rien n'empechait une suppression dans une table
-- parente de laisser des references mortes ailleurs.
--
-- Purement structurel : convertit le moteur de stockage sans toucher aux
-- donnees, colonnes, index ou contenu. Verifie au prealable (2026-08-19) :
-- aucun index FULLTEXT/SPATIAL (MyISAM-only), aucune contrainte FK
-- existante, aucun LOCK TABLES ni SQL_CALC_FOUND_ROWS dans le code
-- (fonctionnalites dependantes du moteur) - conversion sans effet de bord
-- attendu.
--
-- Rejouer ce fichier est sans risque : ALTER TABLE ... ENGINE=InnoDB sur
-- une table deja InnoDB est un no-op.
--
-- Applique en local le 2026-08-19 : 12 tables, 0 -> 592ms chacune selon la
-- taille, comptages de lignes identiques avant/apres, 68/68 tests de
-- regression assistant IA + 6 pages du site verifiees HTTP 200 apres coup.

ALTER TABLE categorie       ENGINE=InnoDB;
ALTER TABLE commande        ENGINE=InnoDB;
ALTER TABLE contact         ENGINE=InnoDB;
ALTER TABLE delivery        ENGINE=InnoDB;
ALTER TABLE marque          ENGINE=InnoDB;
ALTER TABLE panier          ENGINE=InnoDB;
ALTER TABLE produit         ENGINE=InnoDB;
ALTER TABLE setting         ENGINE=InnoDB;
ALTER TABLE sous_categorie  ENGINE=InnoDB;
ALTER TABLE sous_categorie2 ENGINE=InnoDB;
ALTER TABLE utilisateur     ENGINE=InnoDB;
ALTER TABLE voiture         ENGINE=InnoDB;

-- pvd, reference, recherche, ai_conversation etaient deja InnoDB avant
-- cette etape (verifie via information_schema.TABLES) - non repetees ici.
