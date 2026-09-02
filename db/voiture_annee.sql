-- Souqpiece - plages d'annees de production par vehicule (2026-08-30)
--
-- annee_debut/annee_fin ajoutees a voiture : la generation/plateforme reelle
-- du vehicule (fait mondial stable, ex. "Toyota Hilux LN145 : 1997-2005"),
-- pas une compatibilite piece par piece comme pvd.annee_debut/fin qui reste
-- inchangee et distincte.
--
-- Une premiere tentative de deviner ces plages a partir de pvd.annee_debut/fin
-- existant (min/max ou seuil de frequence par annee) a ete mesuree et rejetee :
-- les resultats etaient soit trop larges (bruit d'extraction de l'ancien texte
-- libre, ex. min/max donnait 1983-2013 pour une Starlet EP90 reellement produite
-- 1991-99), soit trop etroits pour les vehicules populaires/longue duree (le
-- filtrage par seuil ecrasait la vraie plage des modeles avec beaucoup de
-- donnees, ex. Patrol Y61 reduit a 2005-2007 alors que reellement 1997-2024+).
--
-- Valeurs ecrites ici : recherche web par code chassis (le plus souvent une
-- reference fiable et stable - Wikipedia/wikis specialises/catalogues
-- constructeur), executee par agent, un vehicule a la fois. Seules les lignes
-- a confiance haute ou moyenne sont ecrites (voir
-- dashboard/voiture_annee_migration.php pour la liste complete avec sources).
-- Laissees NULL, a completer via le fichier vehicule*plage-annee a venir :
--   - 12 vehicules sans code chassis exploitable (nom trop generique couvrant
--     plusieurs generations sans rapport, ex. PAJERO, FORTUNER, TERRANO)
--   - ~12 vehicules a confiance basse (plusieurs codes chassis de generations
--     differentes regroupes dans une seule ligne voiture.modele)
--   - id_voiture=124 "SUNNY 2 B10" : anomalie detectee (1966-1970 ressorti,
--     incoherent avec SUNNY 1 N16 [2000-2012] et SUNNY 3 N17 [2010-2019] -
--     "B10" est probablement une coquille dans voiture.modele lui-meme,
--     a verifier avant toute ecriture, annee ou pas).

ALTER TABLE voiture ADD COLUMN annee_debut SMALLINT NULL;
ALTER TABLE voiture ADD COLUMN annee_fin SMALLINT NULL;

-- Puis executer : php dashboard/voiture_annee_migration.php --apply
