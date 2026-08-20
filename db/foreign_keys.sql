-- Souqpiece - contraintes de cle etrangere (N3c)
--
-- Derniere piece du chantier engage en N1b/N3a/N3b : MyISAM ne supportait
-- pas les FK, ce qui a permis aux 44 000+ lignes orphelines nettoyees en
-- N1b de s'accumuler. Maintenant que tout est InnoDB (N3b), on peut poser
-- de vraies contraintes qui empechent ce probleme de revenir.
--
-- ON DELETE CASCADE (pas RESTRICT) : dashboard/supprimer/sup-produit.php
-- et sup-voiture.php suppriment la ligne parente sans nettoyer les
-- lignes enfants et sans try/catch. RESTRICT ferait planter ces actions
-- du dashboard des qu'une ligne liee existe. CASCADE reproduit ce que
-- l'admin attend deja (le produit/vehicule disparait completement) tout
-- en empechant les orphelins de reapparaitre.
--
-- Volontairement exclues (verifie le 2026-08-20, voir N3C_CLES_ETRANGERES.md) :
--   - panier.id_produit -> produit.id_produit : types incompatibles
--     (varchar(255) vs int) - bug preexistant distinct, hors perimetre.
--   - panier.id_voiture -> voiture.id_voiture : id_voiture=0 est une
--     sentinelle "aucun vehicule" (36 lignes) sans ligne voiture id=0 -
--     la contrainte casserait dessus immediatement.
--   - produit.id_categorie / id_sous_categorie -> categorie / sous_categorie :
--     meme piege du sentinel 0 (11 011 produits non classes) - travail
--     de catalogue (N4), pas une contrainte a poser aujourd'hui.
--
-- Rejouer ce fichier echoue proprement avec "Duplicate key name" si les
-- contraintes existent deja - sans risque.

ALTER TABLE reference
  ADD CONSTRAINT fk_reference_produit
  FOREIGN KEY (id_produit) REFERENCES produit(id_produit)
  ON DELETE CASCADE;

ALTER TABLE pvd
  ADD CONSTRAINT fk_pvd_produit
  FOREIGN KEY (id_produit) REFERENCES produit(id_produit)
  ON DELETE CASCADE;

ALTER TABLE pvd
  ADD CONSTRAINT fk_pvd_voiture
  FOREIGN KEY (id_voiture) REFERENCES voiture(id_voiture)
  ON DELETE CASCADE;

ALTER TABLE voiture
  ADD CONSTRAINT fk_voiture_marque
  FOREIGN KEY (id_marque) REFERENCES marque(id_marque)
  ON DELETE CASCADE;

ALTER TABLE sous_categorie
  ADD CONSTRAINT fk_sous_categorie_categorie
  FOREIGN KEY (id_categorie) REFERENCES categorie(id_categorie)
  ON DELETE CASCADE;
