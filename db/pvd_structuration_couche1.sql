-- Souqpiece - PLAN_PVD_DESCRIPTION.md, couche 1
--
-- Ajoute 5 colonnes structurees a pvd et les remplit par extraction
-- mecanique du texte libre existant dans pvd.description. Purement
-- additif : pvd.description n'est ni modifiee ni relue differemment par
-- aucune page existante apres cette etape - aucun comportement du site
-- ne change.
--
-- L'ecriture reelle des donnees se fait via le script PHP
-- dashboard/pvd_structuration_couche1.php (dry-run par defaut, --apply
-- pour ecrire), pas par ce fichier SQL seul : le remplissage des colonnes
-- necessite l'extraction en PHP (dashboard/include/pvd_extraction.php),
-- pas une simple requete SQL. Ce fichier documente le DDL et sert de
-- reference pour rejouer la structure sur un autre environnement.
--
-- Resultat mesure en local (2026-08-24), sur 19 922 lignes pvd :
--   annee_debut rempli   : 17 523 (88,0%)
--   marque_texte rempli  : 17 689 (88,8%)
--   pays_origine rempli  : 17 645 (88,6%) - dictionnaire de 116 variantes
--                            orthographiques, 1 seule valeur ("D",
--                            manifestement une coquille) non reconnue
--   notes_libres remplie : 2 043 (10,3%) - lignes sans aucun motif
--                            ANNEE/MARQUE/MADE IN, texte original copie
--                            integralement pour ne rien perdre

ALTER TABLE pvd ADD COLUMN annee_debut SMALLINT NULL;
ALTER TABLE pvd ADD COLUMN annee_fin SMALLINT NULL;
ALTER TABLE pvd ADD COLUMN marque_texte VARCHAR(100) NULL;
ALTER TABLE pvd ADD COLUMN pays_origine VARCHAR(50) NULL;
ALTER TABLE pvd ADD COLUMN notes_libres TEXT NULL;

-- Puis executer : php dashboard/pvd_structuration_couche1.php --apply
