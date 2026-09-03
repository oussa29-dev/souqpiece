-- Souqpiece - suppression de pvd.annee_debut / pvd.annee_fin (2026-09-03)
--
-- Decision explicite (pas la convention "gelee, jamais supprimee" utilisee
-- pour pvd.description/pvd.pays_origine) : le produit ne demande plus
-- jamais d'annee par piece - on se fie uniquement a la plage de production
-- du vehicule (voiture.annee_debut/annee_fin, remplie via
-- dashboard/voiture_annee_import.php a partir d'un fichier fournisseur).
--
-- Retire en meme temps que ces colonnes :
--   - Le champ "Annee debut/fin" par vehicule dans dashboard/ajouter-produit.php
--     (formulaire produit) et sa validation obligatoire.
--   - La partie "annee" de l'onglet "Donnees manquantes" (renomme "Pays
--     manquant") de dashboard/pvd-decisions.php.
--
-- Champ toujours ecrit par dashboard/pvd_structuration_couche1.php (backfill
-- historique couche 1, deja execute - un re-run recreerait ces colonnes,
-- pas un souci puisque ce script n'est pas destine a etre relance).

ALTER TABLE pvd DROP COLUMN annee_debut;
ALTER TABLE pvd DROP COLUMN annee_fin;
