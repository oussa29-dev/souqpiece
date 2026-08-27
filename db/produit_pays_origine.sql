-- Souqpiece - PLAN_PVD_DESCRIPTION.md, suite couche 2 (2026-08-27)
--
-- pays_origine deplace de pvd (par vehicule) vers produit (par produit) :
-- mesure sur 19 922 lignes pvd, 99,1% des produits multi-vehicules avaient
-- deja la meme valeur de pays_origine sur toutes leurs lignes pvd - c'est
-- une caracteristique du produit, pas du vehicule.
--
-- pvd.pays_origine n'est pas supprimee (meme convention que pvd.description :
-- gelee, plus jamais lue ni ecrite par le code applicatif a partir de
-- maintenant, mais conservee en base).
--
-- Ecriture reelle via dashboard/produit_pays_origine_migration.php
-- (dry-run par defaut, --apply pour ecrire) : le remplissage necessite de
-- calculer, par produit, la valeur la plus frequente parmi ses lignes pvd
-- (pas une simple requete SQL a une ligne, a cause des rares produits ou
-- les vehicules ne sont pas unanimes).

ALTER TABLE produit ADD COLUMN pays_origine VARCHAR(50) NULL;

-- Puis executer : php dashboard/produit_pays_origine_migration.php --apply
