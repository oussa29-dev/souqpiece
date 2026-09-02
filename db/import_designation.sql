-- Souqpiece - auto-categorisation/liaison vehicule a l'import stock (Excel)
--
-- Probleme mesure (2026-08-30) : l'import Excel (dashboard/stock.php) cree
-- des produits avec seulement libelle/marquepiece/prix/stock - jamais de
-- categorie ni de vehicule, car le fichier fournisseur n'a pas ces colonnes.
-- La colonne "Designation" encode neanmoins souvent le type de piece et le
-- vehicule en texte libre (ex. "AILE COROLLA 05/07 D").
--
-- Le catalogue existant n'est pas une base fiable pour deviner ca par
-- dictionnaire : seulement 3 categories au total, 38% des produits deja
-- sans categorie, et les rares produits "AILE" categorises pointent vers
-- des sous-categories incoherentes. Donc classification par LLM (contraint
-- a choisir uniquement parmi les categories/sous-categories/vehicules qui
-- existent reellement en base - jamais de texte libre en sortie), avec ce
-- cache qui memorise chaque texte "Designation" deja traite pour ne jamais
-- repayer un appel LLM sur le meme texte, et qui s'auto-corrige quand un
-- humain valide/corrige une suggestion (voir pvd-decisions.php, onglet
-- "Import : a verifier").

CREATE TABLE import_designation (
    id_import_designation INT AUTO_INCREMENT PRIMARY KEY,
    designation VARCHAR(255) NOT NULL,
    designation_norm VARCHAR(255) NOT NULL,
    id_categorie INT NULL,
    id_sous_categorie INT NULL,
    -- 'resolu' : applique automatiquement aux futurs produits crees avec ce
    -- texte. 'a_verifier' : suggestion du LLM affichee pour confirmation
    -- humaine avant d'etre appliquee a quoi que ce soit.
    statut ENUM('resolu', 'a_verifier') NOT NULL DEFAULT 'a_verifier',
    source ENUM('llm', 'humain') NOT NULL DEFAULT 'llm',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_designation_norm (designation_norm),
    KEY idx_import_designation_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_designation_voiture (
    id_import_designation INT NOT NULL,
    id_voiture INT NOT NULL,
    PRIMARY KEY (id_import_designation, id_voiture),
    CONSTRAINT fk_idv_designation FOREIGN KEY (id_import_designation) REFERENCES import_designation(id_import_designation) ON DELETE CASCADE,
    CONSTRAINT fk_idv_voiture FOREIGN KEY (id_voiture) REFERENCES voiture(id_voiture) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trace quels produits ont ete crees depuis quelle designation, pour qu'une
-- correction humaine ulterieure (onglet de revision) se repercute sur tous
-- les produits deja crees avec ce texte, pas seulement les futurs imports.
CREATE TABLE import_designation_produit (
    id_import_designation INT NOT NULL,
    id_produit INT NOT NULL,
    PRIMARY KEY (id_import_designation, id_produit),
    CONSTRAINT fk_idp_designation FOREIGN KEY (id_import_designation) REFERENCES import_designation(id_import_designation) ON DELETE CASCADE,
    CONSTRAINT fk_idp_produit FOREIGN KEY (id_produit) REFERENCES produit(id_produit) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
