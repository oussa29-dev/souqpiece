<?php
// Auto-categorisation/liaison-vehicule pour l'import stock Excel
// (dashboard/stock.php) - voir db/import_designation.sql pour le contexte
// complet et pourquoi un dictionnaire fixe n'etait pas fiable ici.
//
// Le LLM ne choisit jamais librement : on lui donne la liste fermee des
// categories/sous-categories/vehicules qui existent reellement en base, et
// toute reponse pointant vers un id hors de ces listes est rejetee ici,
// jamais faite confiance aveuglement.

require_once __DIR__ . '/../../ai/llm/factory.php';

function import_designation_normaliser(string $designation): string
{
    return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $designation)));
}

function import_lister_categories(PDO $pdo): array
{
    return $pdo->query('SELECT id_categorie, libelle FROM categorie ORDER BY id_categorie')->fetchAll(PDO::FETCH_ASSOC);
}

function import_lister_sous_categories(PDO $pdo): array
{
    return $pdo->query('SELECT id_sous_categorie, id_categorie, libelle FROM sous_categorie ORDER BY id_categorie, id_sous_categorie')->fetchAll(PDO::FETCH_ASSOC);
}

function import_lister_vehicules(PDO $pdo): array
{
    return $pdo->query('SELECT v.id_voiture, m.libelle AS marque, v.modele FROM voiture v JOIN marque m ON m.id_marque = v.id_marque ORDER BY v.id_voiture')->fetchAll(PDO::FETCH_ASSOC);
}

// [designation_norm => ligne cache] pour tout ce qui est deja connu.
function import_designation_cache_lookup(PDO $pdo, array $designationsNorm): array
{
    $designationsNorm = array_values(array_unique($designationsNorm));
    if (empty($designationsNorm)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($designationsNorm), '?'));
    $stmt = $pdo->prepare("SELECT * FROM import_designation WHERE designation_norm IN ($placeholders)");
    $stmt->execute($designationsNorm);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['designation_norm']] = $row;
    }
    return $result;
}

function import_designation_vehicules_cache(PDO $pdo, array $idsImportDesignation): array
{
    $idsImportDesignation = array_values(array_unique(array_filter($idsImportDesignation)));
    if (empty($idsImportDesignation)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($idsImportDesignation), '?'));
    $stmt = $pdo->prepare("SELECT id_import_designation, id_voiture FROM import_designation_voiture WHERE id_import_designation IN ($placeholders)");
    $stmt->execute($idsImportDesignation);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['id_import_designation']][] = (int)$row['id_voiture'];
    }
    return $result;
}

function import_classification_prompt_systeme(array $categories, array $sousCategories, array $vehicules): string
{
    $cat = implode("\n", array_map(fn($c) => "{$c['id_categorie']}: {$c['libelle']}", $categories));
    $sousCat = implode("\n", array_map(fn($s) => "{$s['id_sous_categorie']} (categorie {$s['id_categorie']}): {$s['libelle']}", $sousCategories));
    $veh = implode("\n", array_map(fn($v) => "{$v['id_voiture']}: {$v['marque']} {$v['modele']}", $vehicules));

    return <<<PROMPT
Tu classes des designations de pieces automobiles (catalogue algerien, texte
souvent abrege/mal orthographie) pour un import de stock. Pour CHAQUE
designation numerotee, tu dois choisir UNIQUEMENT parmi les identifiants
listes ci-dessous - jamais un identifiant qui n'est pas dans ces listes,
jamais de texte libre a la place d'un identifiant.

CATEGORIES (id: libelle):
$cat

SOUS-CATEGORIES (id (categorie X): libelle):
$sousCat

VEHICULES (id: marque modele):
$veh

Pour chaque designation, determine :
- id_categorie : l'id de la categorie la plus probable, ou null si aucune ne convient.
- id_sous_categorie : l'id d'une sous-categorie de la MEME categorie, ou null.
- id_voitures : distingue bien deux cas differents.
  1. Plusieurs vehicules VRAIMENT DIFFERENTS sont cites (ex. "COROLLA ZRE182
     AURIS ZER151", ou "QASHQAI J11 X-TRAIL T32") : liste tous leurs id.
  2. Le meme modele existe en plusieurs variantes/codes-chassis dans la liste
     VEHICULES (ex. 4 lignes "COROLLA ..." differentes) et rien dans le texte
     ne permet de savoir laquelle : ne les mets PAS toutes - laisse
     id_voitures vide et confiant:false plutot que de toutes les attacher.
     Ne choisis une seule variante que si un indice du texte (annee, code
     moteur/chassis explicite) correspond clairement a elle seule.
  N'invente jamais un vehicule proche si le bon modele/code chassis n'est pas
  dans la liste.
- generique : true UNIQUEMENT si tu es sur que cette piece ne cible reellement
  AUCUN vehicule specifique (ex. "ACCOUPLEMENT HYDRAULIQUE" seul, sans aucune
  mention de vehicule ni de modele dans le texte). false dans tous les autres
  cas, y compris quand tu n'es simplement pas parvenu a determiner le
  vehicule - ne confonds jamais "je n'ai pas trouve" avec "il n'y en a pas".
- confiant : true seulement si tu es raisonnablement sur de id_categorie ET
  (id_voitures contient uniquement des vehicules dont tu es vraiment sur, OU
  generique est true) - jamais true si tu as hesite entre plusieurs variantes
  et les as toutes mises, et jamais true si id_voitures est vide sans que
  generique soit true (ca voudrait dire "je ne sais juste pas", pas "aucun
  vehicule ne s'applique"). false sinon (mets quand meme ta meilleure
  estimation, elle sera montree a un humain pour verification, jamais
  appliquee seule).

Tu dois repondre avec EXACTEMENT une entree par ligne d'entree, meme pour
une ligne qui ressemble a un en-tete de colonne ou a du texte non pertinent
(mets alors id_categorie:null, id_sous_categorie:null, id_voitures:[],
generique:false, confiant:false) - ne saute et ne fusionne jamais une ligne.
Le champ "designation" doit reproduire EXACTEMENT (caractere par caractere)
le texte de la ligne d'entree correspondante, sans le numero.

Reponds UNIQUEMENT avec un tableau JSON, un objet par ligne, sans texte
autour :
[{"designation":"AILE COROLLA 2003 D","id_categorie":26,"id_sous_categorie":null,"id_voitures":[54],"generique":false,"confiant":true}, {"designation":"ACCOUPLEMENT HYDRAULIQUE","id_categorie":25,"id_sous_categorie":null,"id_voitures":[],"generique":true,"confiant":true}, ...]
PROMPT;
}

// Appelle le LLM par lots (evite un prompt trop long) et retourne
// [designation_norm => ['id_categorie'=>?,'id_sous_categorie'=>?,'id_voitures'=>[],'confiant'=>bool]]
// UNIQUEMENT pour les designations qui n'etaient pas deja en cache.
// $onLotTermine, si fourni, est appele apres CHAQUE lot (succes ou echec) avec
// (resultats_de_ce_lot, designations_de_ce_lot, index_lot_1_base, total_lots) -
// permet a l'appelant (import_classification_resoudre) d'ecrire chaque lot en
// cache immediatement plutot que d'attendre la fin de tous les lots : sur un
// gros fichier, un script qui meurt en cours (limite de temps, panne) ne doit
// jamais perdre les appels LLM deja payes et traites.
function import_classification_demander_llm(array $designationsNorm, array $categories, array $sousCategories, array $vehicules, int $tailleLot = 80, ?callable $onLotTermine = null): array
{
    if (empty($designationsNorm)) {
        return [];
    }

    $config = require __DIR__ . '/../../ai/config.php';
    // 'enabled' est le coupe-circuit de l'assistant client (chat.php/widget.php)
    // - ne doit jamais bloquer la classification d'import, qui est une
    // fonctionnalite admin totalement independante (ex. l'assistant client
    // peut rester desactive en production pendant que l'import fonctionne).
    // Coupe-circuit dedie, optionnel : absent = active par defaut.
    if (!($config['import_classification_enabled'] ?? true)) {
        return [];
    }
    // Provider dedie a la classification d'import, independant du provider
    // de l'assistant client - voir ai/config.php. Retombe sur le provider
    // principal si non defini.
    $config['provider'] = $config['import_classification_provider'] ?? $config['provider'];
    $provider = ai_make_provider($config);
    $systemPrompt = import_classification_prompt_systeme($categories, $sousCategories, $vehicules);

    $categorieIds = array_map(fn($c) => (int)$c['id_categorie'], $categories);
    $sousCategorieParCategorie = [];
    foreach ($sousCategories as $s) {
        $sousCategorieParCategorie[(int)$s['id_categorie']][] = (int)$s['id_sous_categorie'];
    }
    $vehiculeIds = array_map(fn($v) => (int)$v['id_voiture'], $vehicules);

    $resultats = [];
    $lots = array_chunk($designationsNorm, $tailleLot);
    $totalLots = count($lots);
    foreach ($lots as $indexLot => $lot) {
        $liste = implode("\n", array_map(fn($i, $d) => "$i. $d", array_keys($lot), $lot));
        try {
            $reponse = $provider->converse(
                $systemPrompt,
                [],
                $liste,
                [],
                fn($nom, $args) => [],
                1
            );
        } catch (Throwable $e) {
            // Panne LLM : on ne bloque jamais l'import pour ca, ce lot restera
            // simplement non resolu (produits crees quand meme, a verifier plus tard).
            if ($onLotTermine !== null) {
                $onLotTermine([], $lot, $indexLot + 1, $totalLots);
            }
            continue;
        }

        $texte = trim($reponse['text'] ?? '');
        // Certains modeles entourent le JSON de ```json ... ``` malgre la consigne.
        $texte = preg_replace('/^```(?:json)?|```$/m', '', $texte);
        $decode = json_decode(trim($texte), true);
        if (!is_array($decode)) {
            if ($onLotTermine !== null) {
                $onLotTermine([], $lot, $indexLot + 1, $totalLots);
            }
            continue;
        }

        // Rematche par texte normalise, jamais par position - un LLM qui
        // saute ou fusionne une ligne (deja observe sur une ligne qui
        // ressemblait a un en-tete) decalerait sinon toutes les reponses
        // suivantes sans qu'on puisse le detecter.
        $lotParNorm = array_flip($lot);
        $resultatsLot = [];

        foreach ($decode as $item) {
            // Certains modeles renvoient le numero de ligne colle au texte
            // ("0. KIT EMB...") malgre la consigne de ne pas le reproduire -
            // le retirer avant de comparer, plutot que de perdre tout le lot.
            $texteBrut = preg_replace('/^\d+[.)]\s*/', '', (string)($item['designation'] ?? ''));
            $texteRenvoye = import_designation_normaliser($texteBrut);
            if ($texteRenvoye === '' || !isset($lotParNorm[$texteRenvoye])) {
                continue;
            }
            $designationNorm = $lot[$lotParNorm[$texteRenvoye]];

            $idCategorie = $item['id_categorie'] ?? null;
            if (!in_array((int)$idCategorie, $categorieIds, true)) {
                $idCategorie = null;
            }

            $idSousCategorie = $item['id_sous_categorie'] ?? null;
            if ($idCategorie === null || !in_array((int)$idSousCategorie, $sousCategorieParCategorie[(int)$idCategorie] ?? [], true)) {
                $idSousCategorie = null;
            }

            $idVoitures = [];
            foreach ((array)($item['id_voitures'] ?? []) as $idVoiture) {
                if (in_array((int)$idVoiture, $vehiculeIds, true)) {
                    $idVoitures[] = (int)$idVoiture;
                }
            }

            // Garde-fou : une designation qui reference reellement plus de 2
            // vehicules distincts est rare (ex. Corolla+Auris) - au-dela,
            // c'est plus probablement le modele qui a attache toutes les
            // variantes d'un meme nom faute de savoir laquelle choisir (deja
            // observe en test). Dans le doute, ne jamais appliquer seul.
            //
            // "vehicule vide" ne veut dire "aucun vehicule ne s'applique" que
            // si le modele l'a explicitement confirme (generique:true) -
            // sinon un id_voitures vide veut juste dire "je n'ai pas trouve",
            // ce qui ne doit jamais etre traite comme resolu (le produit se
            // retrouverait categorise mais sans vehicule, sans jamais
            // repasser par la revue humaine).
            $generique = !empty($item['generique']);
            $vehiculeDetermine = !empty($idVoitures) || $generique;
            $confiant = !empty($item['confiant']) && $idCategorie !== null && count($idVoitures) <= 2 && $vehiculeDetermine;

            $resultatsLot[$designationNorm] = [
                'id_categorie' => $idCategorie !== null ? (int)$idCategorie : null,
                'id_sous_categorie' => $idSousCategorie !== null ? (int)$idSousCategorie : null,
                'id_voitures' => $idVoitures,
                'confiant' => $confiant,
            ];
        }

        $resultats += $resultatsLot;

        if ($onLotTermine !== null) {
            $onLotTermine($resultatsLot, $lot, $indexLot + 1, $totalLots);
        }
    }

    return $resultats;
}

// Point d'entree principal : pour une liste de designations brutes (texte
// original tel qu'il apparait dans l'Excel), retourne
// [designation_brute => ['id_import_designation'=>int,'id_categorie'=>?,'id_sous_categorie'=>?,'id_voitures'=>[],'statut'=>string]]
// et ecrit en cache tout ce qui vient d'etre resolu par le LLM.
function import_classification_resoudre(PDO $pdo, array $designationsBrutes, ?callable $onLot = null): array
{
    $parNorm = [];
    foreach ($designationsBrutes as $brute) {
        $brute = trim($brute);
        if ($brute === '') {
            continue;
        }
        $parNorm[import_designation_normaliser($brute)] = $brute;
    }
    if (empty($parNorm)) {
        return [];
    }

    $cache = import_designation_cache_lookup($pdo, array_keys($parNorm));
    $normsAResoudre = array_values(array_diff(array_keys($parNorm), array_keys($cache)));

    if (!empty($normsAResoudre)) {
        $categories = import_lister_categories($pdo);
        $sousCategories = import_lister_sous_categories($pdo);
        $vehicules = import_lister_vehicules($pdo);

        $insert = $pdo->prepare('INSERT INTO import_designation (designation, designation_norm, id_categorie, id_sous_categorie, statut, source) VALUES (?, ?, ?, ?, ?, ?)');
        $insertVehicule = $pdo->prepare('INSERT INTO import_designation_voiture (id_import_designation, id_voiture) VALUES (?, ?)');

        // Ecrit chaque lot en cache des qu'il revient, pas apres la fin de
        // tous les lots - sur un gros fichier avec beaucoup de designations
        // jamais vues, un script qui meurt en cours (limite de temps, panne
        // reseau) ne doit jamais perdre les lots deja traites et payes.
        import_classification_demander_llm(
            $normsAResoudre,
            $categories,
            $sousCategories,
            $vehicules,
            80,
            function (array $resultatsLot, array $lotNorms, int $indexLot, int $totalLots) use ($pdo, $insert, $insertVehicule, $parNorm, &$cache, $onLot) {
                foreach ($lotNorms as $norm) {
                    $r = $resultatsLot[$norm] ?? ['id_categorie' => null, 'id_sous_categorie' => null, 'id_voitures' => [], 'confiant' => false];
                    $statut = $r['confiant'] ? 'resolu' : 'a_verifier';
                    $insert->execute([$parNorm[$norm], $norm, $r['id_categorie'], $r['id_sous_categorie'], $statut, 'llm']);
                    $idImportDesignation = (int)$pdo->lastInsertId();
                    foreach ($r['id_voitures'] as $idVoiture) {
                        $insertVehicule->execute([$idImportDesignation, $idVoiture]);
                    }
                    $cache[$norm] = [
                        'id_import_designation' => $idImportDesignation,
                        'designation_norm' => $norm,
                        'id_categorie' => $r['id_categorie'],
                        'id_sous_categorie' => $r['id_sous_categorie'],
                        'statut' => $statut,
                    ];
                }
                if ($onLot !== null) {
                    $onLot($indexLot, $totalLots);
                }
            }
        );
    }

    $idsCache = array_map(fn($c) => (int)$c['id_import_designation'], $cache);
    $vehiculesParDesignation = import_designation_vehicules_cache($pdo, $idsCache);

    $resultat = [];
    foreach ($parNorm as $norm => $brute) {
        $c = $cache[$norm] ?? null;
        if ($c === null) {
            continue;
        }
        $resultat[$brute] = [
            'id_import_designation' => (int)$c['id_import_designation'],
            'id_categorie' => $c['id_categorie'] !== null ? (int)$c['id_categorie'] : null,
            'id_sous_categorie' => $c['id_sous_categorie'] !== null ? (int)$c['id_sous_categorie'] : null,
            'id_voitures' => $vehiculesParDesignation[(int)$c['id_import_designation']] ?? [],
            'statut' => $c['statut'],
        ];
    }
    return $resultat;
}

// A appeler juste apres avoir insere un nouveau produit issu de l'import,
// pour que la file de revision puisse, en cas de correction humaine plus
// tard, retrouver et corriger ce produit-la aussi.
function import_designation_tracer_produit(PDO $pdo, int $idImportDesignation, int $idProduit): void
{
    $pdo->prepare('INSERT IGNORE INTO import_designation_produit (id_import_designation, id_produit) VALUES (?, ?)')
        ->execute([$idImportDesignation, $idProduit]);
}
