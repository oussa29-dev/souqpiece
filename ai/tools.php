<?php
// Read-only data-access tools for the AI assistant.
// Every function here does SELECT only, via prepared statements. None of
// them may INSERT/UPDATE/DELETE on store tables (produit, pvd, reference,
// panier, commande, ...). The only table this layer ever writes to is
// ai_conversation, and that happens in chat.php, not here.

require_once __DIR__ . '/search_aliases.php';
require_once __DIR__ . '/phonetic.php';

// MySQL's LIKE treats % and _ as wildcards even in user-supplied text, so a
// literal underscore or percent in a search term (real example in this
// catalog: reference "550458_VW000") would silently broaden the match to
// "any character here" instead of matching literally.
function ai_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// Lowercase + accent-fold ("démarreur" -> "demarreur") for comparing
// against the alias dictionary. NOT iconv('...TRANSLIT') - empirically
// verified on this stack to corrupt text instead of folding it cleanly
// (produced "d'emarreur", stray apostrophes/carets on other words), and
// iconv's TRANSLIT behavior is documented as locale/libc-dependent, which
// would risk differing between this Windows dev box and Linux production.
// An explicit map is slower to extend but identical everywhere.
function ai_normalize_term(string $term): string
{
    static $map = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];
    return strtr(mb_strtolower(trim($term)), $map);
}

// Key of a word taught from the dashboard: lowercase, accent-folded, and the
// Arabic letters customers write either way made one (أ/إ/آ -> ا, ة -> ه,
// ى -> ي).
function ai_cle_mot_appris(string $mot): string
{
    return strtr(preg_replace('/\s+/u', ' ', ai_normalize_term($mot)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
}

// Rows of ai_mot_appris (Dashboard > Apprendre a l'IA): type, mot,
// equivalent. Read once per request, on the first call with a PDO;
// $recharger re-reads it (tests). Empty when the table is missing
// (migration not run yet).
function ai_lignes_mots_appris(?PDO $pdo = null, bool $recharger = false): array
{
    static $lignes = null;
    if (($lignes === null || $recharger) && $pdo !== null) {
        $lignes = [];
        try {
            // false, not an exception, with production's silent PDO.
            $stmt = $pdo->query('SELECT type, mot, equivalent FROM ai_mot_appris ORDER BY id_mot');
            $lignes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            $lignes = [];
        }
    }
    return $lignes ?? [];
}

// Words taught by the store owner: [key => [catalog words]], every type.
function ai_mots_appris(?PDO $pdo = null, bool $recharger = false): array
{
    $mots = [];
    foreach (ai_lignes_mots_appris($pdo, $recharger) as $row) {
        $mots[ai_cle_mot_appris($row['mot'])][] = $row['equivalent'];
    }
    return $mots;
}

// Vehicles (id_voiture) whose brand + model contain this text, spaces
// ignored in number ("VIGO 4WD LAN25  KUN25" is stored with two).
function ai_voitures_contenant(PDO $pdo, string $texte): array
{
    static $voitures = null;
    if ($voitures === null) {
        $voitures = [];
        $stmt = $pdo->query('SELECT v.id_voiture, m.libelle AS marque, v.modele FROM voiture v JOIN marque m ON m.id_marque = v.id_marque');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $v) {
            $voitures[(int)$v['id_voiture']] = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($v['marque'] . ' ' . $v['modele'])));
        }
    }
    $texte = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($texte)));
    if ($texte === '') {
        return [];
    }
    return array_keys(array_filter($voitures, fn($nom) => mb_strpos($nom, $texte) !== false));
}

// Groups the customer's words into the phrases taught by the store owner.
// $mots: the customer's words in order. Returns [terms, id_voiture]:
// - a part phrase ("bras de force" -> TRIANGLE) becomes ONE term, whose
//   catalog word ai_arabic_alias_lookup() then adds;
// - vehicle words or phrases ("vigo turbo" -> VIGO 2WD KUN15 D4D) that
//   point to exactly one vehicle are taken out of the terms and give that
//   vehicle: product descriptions write "VIGO 2WD D4D", never the full
//   model name, so searching the text would find nothing;
// - anything else is left word by word, as before.
function ai_regrouper_expressions(PDO $pdo, array $mots): array
{
    $entrees = [];
    foreach (ai_lignes_mots_appris($pdo) as $row) {
        $cle = ai_cle_mot_appris($row['mot']);
        if ($row['type'] === 'voiture' || strpos($cle, ' ') !== false) {
            $entrees[$cle]['type'] = $row['type'] === 'voiture' ? 'voiture' : ($entrees[$cle]['type'] ?? 'piece');
            $entrees[$cle]['equivalents'][] = $row['equivalent'];
        }
    }
    if (!$entrees) {
        return [array_values($mots), null];
    }
    // Longest phrase first: "vigo turbo 4x4" before "vigo turbo".
    uksort($entrees, fn($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));
    $cles = array_map('ai_cle_mot_appris', $mots);

    $termes = [];
    $voiture = null;
    for ($i = 0, $n = count($mots); $i < $n;) {
        foreach ($entrees as $cle => $entree) {
            $longueur = substr_count($cle, ' ') + 1;
            if (implode(' ', array_slice($cles, $i, $longueur)) !== $cle) {
                continue;
            }
            if ($entree['type'] === 'voiture') {
                $ids = [];
                foreach ($entree['equivalents'] as $equivalent) {
                    $ids = array_merge($ids, ai_voitures_contenant($pdo, $equivalent));
                }
                $ids = array_values(array_unique($ids));
                if (count($ids) !== 1 || ($voiture !== null && $voiture !== $ids[0])) {
                    continue;
                }
                $voiture = $ids[0];
            } else {
                $termes[] = implode(' ', array_slice($mots, $i, $longueur));
            }
            $i += $longueur;
            continue 2;
        }
        $termes[] = $mots[$i];
        $i++;
    }
    return [$termes, $voiture];
}

// Catalog equivalents of a customer's word, or null: the built-in Arabic/
// Darija dictionary (search_aliases.php) plus the words taught from the
// dashboard, in any script. Also tries the word without a leading definite
// article ("الفرامل" -> "فرامل").
function ai_arabic_alias_lookup(string $term, ?PDO $pdo = null): ?array
{
    $aliases = ai_search_arabic_aliases();
    $appris = ai_mots_appris($pdo);
    $trimmed = trim($term);
    $trouves = [];
    foreach (array_unique([$trimmed, mb_substr($trimmed, 0, 2) === 'ال' ? mb_substr($trimmed, 2) : $trimmed]) as $mot) {
        if (isset($aliases[$mot])) {
            $trouves = array_merge($trouves, $aliases[$mot]);
        }
        $trouves = array_merge($trouves, $appris[ai_cle_mot_appris($mot)] ?? []);
        if ($trouves) {
            break;
        }
    }
    return $trouves ? array_values(array_unique($trouves)) : null;
}

// Expands one search term to itself plus any known spelling variant,
// common misspelling, or Arabic/Darija translation from the small
// dictionary in search_aliases.php. With $pdo, an Arabic-script word the
// dictionary doesn't know is also matched by sound against the catalog's
// words (ai/phonetic.php) - "فيزيبل" -> FUSIBLE without a dictionary entry.
function ai_expand_term_variants(string $term, ?PDO $pdo = null): array
{
    static $phonetique = [];
    $variants = [$term];

    $arabic = ai_arabic_alias_lookup($term, $pdo);
    if ($arabic !== null) {
        $variants = array_merge($variants, $arabic);
    } elseif ($pdo !== null && ai_est_arabe($term)) {
        $cle = trim($term);
        if (!isset($phonetique[$cle])) {
            $phonetique[$cle] = ai_correspondances_phonetiques($pdo, $cle);
        }
        $variants = array_merge($variants, $phonetique[$cle]);
    }

    // Spelling groups apply to the Arabic translations too ("فيلتر" ->
    // "filter" -> also "filtre").
    $groups = ai_search_synonym_groups();
    foreach ($variants as $variant) {
        $normalized = ai_normalize_term($variant);
        foreach ($groups as $group) {
            if (in_array($normalized, $group, true)) {
                $variants = array_merge($variants, $group);
                break;
            }
        }
    }

    // Plural and short spelling (store owner 28/10, "pochette de joints rav4"
    // found nothing: the catalog writes JOINT and POCHET): a word in letters
    // also matches without its final S/X, and from 7 letters without its
    // last two letters (POCHETTE -> POCHET, PLAQUETTES -> PLAQUET).
    // References and codes (any digit) are left as typed.
    foreach ($variants as $variant) {
        $mot = strtoupper(trim($variant));
        if (!preg_match('/^[A-Z]+$/', $mot)) {
            continue;
        }
        if (strlen($mot) >= 5 && preg_match('/[^S][SX]$/', $mot)) {
            $mot = substr($mot, 0, -1);
            $variants[] = $mot;
        }
        if (strlen($mot) >= 7) {
            $variants[] = substr($mot, 0, -2);
        }
    }

    // Searched as "contains" and "starts with": a variant beginning with
    // another one finds nothing more (DEMARREUR once DEMARRE is there) and
    // only slows the search down.
    $variants = array_values(array_unique($variants));
    $majuscules = array_map('mb_strtoupper', $variants);
    return array_values(array_filter($variants, function ($v, $i) use ($majuscules) {
        foreach ($majuscules as $j => $autre) {
            if ($j !== $i && $autre !== '' && $autre !== $majuscules[$i] && strpos($majuscules[$i], $autre) === 0) {
                return false;
            }
        }
        return true;
    }, ARRAY_FILTER_USE_BOTH));
}

// Catalog abbreviations of a term (search_aliases.php), through its spelling
// group and Arabic translation too ("embriage" -> embrayage -> EMB). Not
// through ai_expand_term_variants(): it keeps EMBRAYA and drops EMBRAYAGE.
function ai_abreviations_terme(string $term): array
{
    $table = ai_search_abbreviations();
    $mots = array_merge([$term], ai_arabic_alias_lookup($term) ?? []);
    foreach ($mots as $mot) {
        foreach (ai_search_synonym_groups() as $groupe) {
            if (in_array(ai_normalize_term($mot), $groupe, true)) {
                $mots = array_merge($mots, $groupe);
            }
        }
    }
    $abreviations = [];
    foreach ($mots as $mot) {
        $mot = ai_normalize_term($mot);
        foreach ([$mot, preg_replace('/s$/', '', $mot)] as $cle) {
            $abreviations = array_merge($abreviations, $table[$cle] ?? []);
        }
    }
    return array_values(array_unique($abreviations));
}

// An abbreviation matches only as a whole word: EMB must not find MEMBRANE
// or EMBOUT, nor D every "KIT D'EMBRAYAGE" (hence no apostrophe after it).
function ai_regexp_mot_entier(string $abreviation, bool $auDebut = false): string
{
    return ($auDebut ? '^' : '(^|[^A-Za-z0-9])') . preg_quote($abreviation) . "([^A-Za-z0-9']|$)";
}

// "(col LIKE ? OR col LIKE ? OR col REGEXP ?)" for one search term: its
// variants as substrings, its catalog abbreviations as whole words. Not the
// one-letter ones (G, D) in pvd.description: it ends with the part's brand,
// and "ORG D" made every original part "droite".
function ai_condition_terme(string $colonne, string $term, PDO $pdo, array &$params, bool $uneLettre = true): string
{
    $orParts = [];
    foreach (ai_expand_term_variants($term, $pdo) as $variant) {
        $orParts[] = "$colonne LIKE ?";
        $params[] = '%' . ai_escape_like($variant) . '%';
    }
    foreach (ai_abreviations_terme($term) as $abreviation) {
        if (strlen($abreviation) > 1 || $uneLettre) {
            $orParts[] = "$colonne REGEXP ?";
            $params[] = ai_regexp_mot_entier($abreviation);
        }
    }
    return '(' . implode(' OR ', $orParts) . ')';
}

// Last-resort fallback, only called when the alias-expanded exact search
// (reference + description + libelle, all variant-expanded) finds nothing.
// Bounded and cheap on purpose: pre-filters to rows whose libelle starts
// with the same first letter as the search term (avoids scanning the full
// ~24k-row produit table), fetches at most 2000 of those, then ranks only
// that small set by word-level Levenshtein distance in PHP - never in SQL,
// never over the whole catalog. Known limitation: a typo in the term's
// very first letter won't be caught by the first-letter pre-filter.
//
// EVERY word must match (store owner, "segment 2tr" listed segments for any
// engine: the whole text "segment 2tr" was compared to single words, and
// "segment" alone was close enough). Words of letters (4+) are matched by
// edit distance against the product name's words; codes and short words
// (2tr, av, 5l) must be there as typed, in the name, a description or a
// reference - a code with a typo is another part.
function ai_fuzzy_libelle_fallback(PDO $pdo, string $searchText, int $limit = 20): array
{
    $motsVides = ['de', 'du', 'des', 'd', 'la', 'le', 'les', 'l', 'pour', 'et', 'a', 'avec', 'en', 'sur'];
    $mots = array_values(array_filter(
        preg_split('/\s+/u', ai_normalize_term($searchText)),
        fn($m) => $m !== '' && !in_array($m, $motsVides, true)
    ));
    // The part word: the first word of 4+ letters, matched loosely (30%).
    $premier = null;
    foreach ($mots as $k => $m) {
        if (mb_strlen($m) >= 4 && !preg_match('/\d/', $m)) {
            $premier = $m;
            unset($mots[$k]);
            break;
        }
    }
    if ($premier === null) {
        return []; // nothing long enough for edit-distance to be a meaningful signal
    }

    $firstChar = mb_substr($premier, 0, 1);
    $stmt = $pdo->prepare('SELECT id_produit, libelle FROM produit WHERE libelle LIKE ? AND prix > 0 LIMIT 2000');
    $stmt->execute([ai_escape_like($firstChar) . '%']);
    $candidates = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $distance = function (string $mot, string $libelle): int {
        $meilleure = PHP_INT_MAX;
        foreach (preg_split('/\s+/', ai_normalize_term($libelle)) as $word) {
            if ($word !== '') {
                $meilleure = min($meilleure, levenshtein($mot, $word));
            }
        }
        return $meilleure;
    };
    $scored = [];
    $maxDistance = max(2, (int)ceil(mb_strlen($premier) * 0.3));
    foreach ($candidates as $id => $libelle) {
        $d = $distance($premier, $libelle);
        if ($d <= $maxDistance) {
            $scored[$id] = $d;
        }
    }

    // Every other word (the vehicle, a code, a side): there as typed in the
    // name, a description or a reference ("hilux" is often only in the
    // description, "2tr" too), or - a word of 4+ letters - one letter off
    // at most ("COASTR" in a description; but "COASTER" must not pass for
    // "CAPTEUR", as a looser match did).
    if ($mots && $scored) {
        $ids = array_map('intval', array_keys($scored));
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT p.id_produit, CONCAT_WS(' ', p.libelle,
                (SELECT GROUP_CONCAT(pvd.description SEPARATOR ' ') FROM pvd WHERE pvd.id_produit = p.id_produit),
                (SELECT GROUP_CONCAT(r.reference SEPARATOR ' ') FROM reference r WHERE r.id_produit = p.id_produit))
            FROM produit p WHERE p.id_produit IN ($marques)");
        $stmt->execute($ids);
        $textes = array_map('ai_normalize_term', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        foreach ($ids as $id) {
            $texte = $textes[$id] ?? '';
            foreach ($mots as $mot) {
                if (mb_strpos($texte, $mot) !== false) {
                    continue;
                }
                if (mb_strlen($mot) >= 4 && !preg_match('/\d/', $mot) && $distance($mot, preg_replace('/[^a-z0-9]+/', ' ', $texte)) <= 1) {
                    continue;
                }
                unset($scored[$id]);
                break;
            }
        }
    }
    asort($scored);
    return array_slice(array_keys($scored), 0, $limit);
}

// $moteur_vehicule: the engine resolve_vehicle understood ('turbo' or
// 'atmo'), passed on by the model - the query alone may no longer say it.
function ai_search_products(PDO $pdo, string $query, ?int $id_voiture = null, ?int $id_sous_categorie = null, int $limit = 8, ?int $min_price = null, ?int $max_price = null, ?string $moteur_vehicule = null): array
{
    $limit = max(1, min($limit, 20));

    // Same normalization as product.php: spaces -> * -> multi-term AND search
    // across reference, pvd.description and produit.libelle.
    $searchTerm = trim($query);
    $normalized = str_replace(' ', '*', $searchTerm);
    $terms = array_values(array_filter(array_map('trim', explode('*', $normalized))));
    // The engine the customer named (turbo/D4D or 5L/no turbo), read before
    // a taught phrase swallows the words ("vigo d4d" -> the vehicle).
    $moteur = ai_moteur_demande($terms);
    $carburant = ai_carburant_demande($terms);
    $vigoSeul = $moteur === null && $carburant !== 'essence' && (bool)array_filter($terms, 'ai_est_mot_vigo')
        && !array_filter($terms, fn($t) => preg_match('/^(KUN|LAN|LN)\d+$/i', $t));
    // Phrases taught by the store owner, before the linking words below are
    // dropped ("bras de force"). A vehicle phrase gives the vehicle, unless
    // it is the whole query (nothing left to search).
    [$regroupes, $voitureApprise] = ai_regrouper_expressions($pdo, $terms);
    if ($regroupes) {
        $terms = $regroupes;
        $id_voiture = $id_voiture ?? $voitureApprise;
    }
    // "turbo", "تيربو", "sans"/"بلا" before them describe the engine, they are
    // never in a product name: as text they made "culasse vigo turbo" find
    // nothing. The engine filter below uses them instead. D4D or 5L stay:
    // product names do write them.
    $sansMotsMoteur = [];
    foreach ($terms as $i => $t) {
        $attribut = ai_attribut_vehicule($t);
        $estMotTurbo = $attribut === 'turbo' && !preg_match('/^D-?4D$/i', $t);
        $estNegation = $attribut === 'sans' && ai_attribut_vehicule($terms[$i + 1] ?? '') === 'turbo';
        // Same for essence/diesel (product names write ESS, not ESSENCE) -
        // except gasoil, also a part word: FILTRE GAZOIL.
        $estCarburant = ai_mot_carburant($t) !== null && !preg_match('/^GA[SZ]OILE?$/i', $t);
        if (!$estMotTurbo && !$estNegation && !$estCarburant) {
            $sansMotsMoteur[] = $t;
        }
    }
    if ($sansMotsMoteur) {
        $terms = $sansMotsMoteur;
    }
    // Linking words are not part names: "pochette de joints" must not
    // require "DE" in the product, nor "plaquette تاع hilux" "تاع".
    $motsVides = ['DE', 'DU', 'DES', 'D', 'LA', 'LE', 'LES', 'L', 'POUR', 'ET', 'A', 'AVEC', 'EN', 'SUR', 'تاع', 'ديال', 'نتاع', 'متاع'];
    $utiles = array_filter($terms, fn($t) => !in_array(mb_strtoupper(trim($t, "'")), $motsVides, true));
    if ($utiles) {
        $terms = array_values($utiles);
    }
    if (empty($terms)) {
        $terms = [$searchTerm];
    }

    // Candidate-gathering queries are NOT capped - a fixed LIMIT here was
    // tried and reverted (see git history: "candidateCap = 150") after
    // proving it silently dropped real, in-stock, vehicle-linked products
    // (e.g. "frein" + Patrol Y61 returned 3 results capped vs. 8 with real
    // matches #5561/#9957/#73319 uncapped - all three were genuine, in
    // stock, correctly linked to that vehicle, and simply excluded by
    // sitting past position 150 in id_produit order). The final JOIN below
    // used to take 900ms-1.9s against a large unbounded candidate list
    // because pvd had no index on id_produit; two added indexes
    // (idx_pvd_id_produit, idx_pvd_id_voiture - see db/indexes.sql) bring
    // that down to ~140-280ms even for the broadest terms in this catalog
    // (up to ~920 raw candidates for "amortisseur"), which is what makes
    // removing the cap safe instead of just fast.
    $refConditions = [];
    $refParams = [];
    foreach ($terms as $term) {
        $refConditions[] = 'reference LIKE ?';
        $refParams[] = '%' . ai_escape_like($term) . '%';
    }
    $sqlRef = $pdo->prepare('SELECT id_produit FROM reference WHERE ' . implode(' AND ', $refConditions));
    $sqlRef->execute($refParams);
    $idProdsRef = $sqlRef->fetchAll(PDO::FETCH_COLUMN);

    // description/libelle expand each term to known spelling variants,
    // synonyms and Arabic/Darija translations (each term's variants OR'd
    // together, terms still AND'd together) - reference numbers do not,
    // since expanding e.g. "demarreur" synonyms into an OEM-code search is
    // meaningless and would only add noise/cost with zero benefit.
    $descConditions = [];
    $descParams = [];
    foreach ($terms as $term) {
        $descConditions[] = ai_condition_terme('description', $term, $pdo, $descParams, false);
    }
    $sqlDesc = $pdo->prepare('SELECT DISTINCT pvd.id_produit FROM pvd WHERE ' . implode(' AND ', $descConditions));
    $sqlDesc->execute($descParams);
    $idProdsDesc = $sqlDesc->fetchAll(PDO::FETCH_COLUMN);

    // produit.libelle, reference and pvd.description are all utf8mb4 (N3
    // migration) - safe for Arabic/any script, all always searched.
    $libConditions = [];
    $libParams = [];
    foreach ($terms as $term) {
        $libConditions[] = ai_condition_terme('libelle', $term, $pdo, $libParams);
    }
    $sqlLib = $pdo->prepare('SELECT id_produit FROM produit WHERE ' . implode(' AND ', $libConditions));
    $sqlLib->execute($libParams);
    $idProdsLib = $sqlLib->fetchAll(PDO::FETCH_COLUMN);

    $idProds = array_values(array_unique(array_merge($idProdsRef, $idProdsDesc, $idProdsLib)));

    if (empty($idProds)) {
        // Alias-expanded exact search found nothing - last-resort bounded
        // fuzzy fallback (see ai_fuzzy_libelle_fallback docblock).
        // With a vehicle/subcategory filter, most fuzzy candidates are
        // dropped by the final query - keep a wide pool, or "Vilbroqeun"
        // for the Coaster finds nothing although VILBROKEN is linked to it.
        $pool = ($id_voiture !== null || $id_sous_categorie !== null) ? 500 : $limit;
        $idProds = ai_fuzzy_libelle_fallback($pdo, implode(' ', $terms), $pool);
        if (empty($idProds)) {
            return [];
        }
    }

    $conditions = ['produit.id_produit IN (' . implode(',', array_fill(0, count($idProds), '?')) . ')'];
    $params = $idProds;

    // V1 explicitly excludes zero-priced rows from assistant results.
    $conditions[] = 'produit.prix > 0';

    if ($id_voiture !== null) {
        // Linked to this vehicle - or linked to no vehicle at all but naming
        // it: store owner, 13/10, "ARBRACAME HZJ80/105/75 1HZ" (in stock, no
        // pvd row yet) was found by the site search but never by the
        // assistant once the Land Cruiser HZJ80 was chosen. Only the
        // vehicle's distinctive words count (HZJ80, COASTER, NCP90 - not
        // CRUISER or YARIS, shared by 6 vehicles), so an unlinked part named
        // for another model does not slip in.
        $mots = ai_mots_distinctifs_vehicule($pdo, $id_voiture);
        if ($mots) {
            // A part naming the other drive is for the other version
            // ("CROISILLON VIGO 4WD" must not be offered for a Vigo 2WD).
            $exclusion = '';
            $modele = strtoupper((string)$pdo->query('SELECT modele FROM voiture WHERE id_voiture = ' . (int)$id_voiture)->fetchColumn());
            if (strpos($modele, '2WD') !== false || strpos($modele, '4W2') !== false) {
                $exclusion = " AND produit.libelle NOT LIKE '%4WD%' AND produit.libelle NOT LIKE '%4W4%'";
            } elseif (strpos($modele, '4WD') !== false || strpos($modele, '4W4') !== false) {
                $exclusion = " AND produit.libelle NOT LIKE '%2WD%' AND produit.libelle NOT LIKE '%4W2%'";
            }
            $conditions[] = '(pvd.id_voiture = ? OR (pvd.id_produit IS NULL AND (' . implode(' OR ', array_fill(0, count($mots), 'produit.libelle LIKE ?')) . ')' . $exclusion . '))';
            $params[] = $id_voiture;
            foreach ($mots as $mot) {
                $params[] = '%' . ai_escape_like($mot) . '%';
            }
        } else {
            $conditions[] = 'pvd.id_voiture = ?';
            $params[] = $id_voiture;
        }
    }
    // Engine: a part written for the other engine is for the other version
    // (store owner 22/11: "VIGO 4WD LAN25 KUN25" holds the D4D turbo and the
    // 5L in one vehicle, and "culasse vigo d4d" listed "CULASSE HILUX 5L
    // VIDE" first). Without an engine word, the vehicle's own engine decides
    // (KUN15 D4D: turbo; LAN15: not); a vehicle holding both stays neutral.
    // Then the engine resolve_vehicle understood, then the vehicle's own,
    // and last "vigo" alone, which means the 5L (store owner, 23/11: "كي يقول
    // vigo وحدها معناها 5L، كي يزيدلها d4d ولا تربو تتبدل").
    if ($moteur === null && in_array($moteur_vehicule, ['turbo', 'atmo'], true)) {
        $moteur = $moteur_vehicule;
    }
    if ($moteur === null && $id_voiture !== null) {
        $moteurVoiture = ai_turbo_voiture((string)$pdo->query('SELECT modele FROM voiture WHERE id_voiture = ' . (int)$id_voiture)->fetchColumn());
        $moteur = in_array($moteurVoiture, ['turbo', 'atmo'], true) ? $moteurVoiture : null;
    }
    if ($moteur === null && $vigoSeul) {
        $annees = array_filter($terms, fn($t) => preg_match('/^(19|20)\d{2}$/', $t));
        $moteur = ai_vigo_turbo_par_annee($annees ? (int)reset($annees) : null) ? 'turbo' : 'atmo';
    }
    if ($moteur !== null) {
        // Removed only when its name or descriptions name the other engine
        // and never the wanted one: "KUN25/LAN25" stays for both.
        $texte = "CONCAT_WS(' ', produit.libelle, (SELECT GROUP_CONCAT(px.description SEPARATOR ' ') FROM pvd px WHERE px.id_produit = produit.id_produit))";
        $conditions[] = "NOT ($texte REGEXP ? AND $texte NOT REGEXP ?)";
        $params[] = $moteur === 'turbo' ? AI_REGEXP_MOTEUR_ATMO : AI_REGEXP_MOTEUR_TURBO;
        $params[] = $moteur === 'turbo' ? AI_REGEXP_MOTEUR_TURBO : AI_REGEXP_MOTEUR_ATMO;
    }
    // Fuel: same rule - a part written only for the other fuel is left out
    // ("culasse vigo essence" must not list the D4D or 5L culasses).
    if ($carburant !== null) {
        $texte = "CONCAT_WS(' ', produit.libelle, (SELECT GROUP_CONCAT(px.description SEPARATOR ' ') FROM pvd px WHERE px.id_produit = produit.id_produit))";
        $conditions[] = "NOT ($texte REGEXP ? AND $texte NOT REGEXP ?)";
        $params[] = $carburant === 'essence' ? AI_REGEXP_DIESEL : AI_REGEXP_ESSENCE;
        $params[] = $carburant === 'essence' ? AI_REGEXP_ESSENCE : AI_REGEXP_DIESEL;
    }
    if ($id_sous_categorie !== null) {
        $conditions[] = 'produit.id_sous_categorie = ?';
        $params[] = $id_sous_categorie;
    }
    if ($min_price !== null) {
        $conditions[] = 'produit.prix >= ?';
        $params[] = $min_price;
    }
    if ($max_price !== null) {
        $conditions[] = 'produit.prix <= ?';
        $params[] = $max_price;
    }

    // A budget-constrained search implies price relevance matters more
    // than default stock-first ordering. id_produit ASC is a deterministic
    // tiebreaker - without it, MySQL's order among rows tied on stock (or
    // price) is unspecified, so which subset of matches survives LIMIT can
    // silently differ between two otherwise-identical calls (found via a
    // production conversation audit: a broad "frein" search returned a
    // completely different valid 8-row set seconds apart).
    // Relevance on the first term (the part name: "culasse" in "culasse
    // coaster"): a libelle that STARTS with it beats one that only contains
    // it - "culasse" listed JOINT CULASSE before CULASSE, "demarreur"
    // listed CONTACTEUR DEMARREUR first. Rows found only through reference
    // or description come last.
    $debut = [];
    $contient = [];
    $relevanceParams = [];
    foreach (ai_expand_term_variants(reset($terms), $pdo) as $variant) {
        $debut[] = 'produit.libelle LIKE ?';
        $relevanceParams[] = ai_escape_like($variant) . '%';
    }
    foreach (ai_abreviations_terme(reset($terms)) as $abreviation) {
        $debut[] = 'produit.libelle REGEXP ?';
        $relevanceParams[] = ai_regexp_mot_entier($abreviation, true);
    }
    $contient[] = ai_condition_terme('produit.libelle', reset($terms), $pdo, $relevanceParams);
    $relevance = 'CASE WHEN ' . implode(' OR ', $debut) . ' THEN 0 WHEN ' . implode(' OR ', $contient) . ' THEN 1 ELSE 2 END';

    // With a vehicle, parts linked to it come before parts only naming it.
    $lienAvant = $id_voiture !== null ? '(pvd.id_voiture IS NULL), ' : '';
    $orderBy = ($min_price !== null || $max_price !== null)
        ? 'produit.prix ASC, produit.stock DESC, ' . $lienAvant . $relevance . ', produit.id_produit ASC'
        : 'produit.stock DESC, ' . $lienAvant . $relevance . ', produit.id_produit ASC';

    $sql = '
        SELECT
            produit.id_produit, produit.libelle, produit.marquepiece,
            produit.prix, produit.stock, produit.img1,
            pvd.id_voiture AS voiture_id,
            SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT v.modele ORDER BY v.modele SEPARATOR \' / \'), \' / \', 3) AS vehicule_modele,
            MAX(m.libelle) AS vehicule_marque, c.libelle AS categorie_nom
        FROM produit
        LEFT JOIN pvd ON produit.id_produit = pvd.id_produit
        LEFT JOIN voiture v ON pvd.id_voiture = v.id_voiture
        LEFT JOIN marque m ON v.id_marque = m.id_marque
        LEFT JOIN categorie c ON produit.id_categorie = c.id_categorie
        WHERE ' . implode(' AND ', $conditions) . '
        GROUP BY produit.id_produit
        ORDER BY ' . $orderBy . '
        LIMIT ' . ($limit * 2);

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $relevanceParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A part linked to no vehicle and found by name must not name another
    // chassis: "POCHET JOINT RAV4 3SFE SXA1" and "... ACA20" were offered for
    // the RAV4 ALA30 (store owner, 28/10) because they say RAV4.
    if ($id_voiture !== null) {
        $codesVehicule = ai_codes_chassis((string)$pdo->query('SELECT modele FROM voiture WHERE id_voiture = ' . (int)$id_voiture)->fetchColumn());
        $rows = array_values(array_filter($rows, fn($r) => !empty($r['voiture_id'])
            || !array_diff(ai_codes_chassis((string)$r['libelle']), $codesVehicule)));
    }
    $rows = array_slice(ai_sans_doublons($pdo, $rows), 0, $limit);
    $rows = array_merge($rows, ai_meme_reference($pdo, $rows, $limit, $id_voiture));

    foreach ($rows as &$row) {
        $row = ai_disponibilite($row);
        $row['url'] = 'produit.php?id=' . $row['id_produit']
            . (!empty($row['voiture_id']) ? '&id_voiture=' . $row['voiture_id'] : '');
        if ($id_voiture !== null && empty($row['voiture_id']) && empty($row['meme_reference'])) {
            // Found by its name only: the catalog does not link it to the
            // vehicle yet.
            $row['lien_vehicule'] = 'nom_seulement';
        }
    }
    return $rows;
}

// Designation -> reference, the reverse of ai_equivalents_designation()
// (store owner, 07/11): the same part stored under another name - "MANO HP -
// HANSA" and "MANO HB PM - HANSA MALIYSIA" both carry 1460A037. Products
// sharing a reference code with the first results, same kind of part (same
// first word), at most 4, flagged meme_reference, reference in the name.
// Only when the search found fewer than asked: on a broad one ("demarreur")
// the list is already full and these only add noise. With a vehicle, only
// parts linked to it: "CONTACTEUR DEMARREUR YARIS NCP/90/92" shares a code
// with a Yaris NCP151 part.
function ai_meme_reference(PDO $pdo, array $rows, int $limit, ?int $id_voiture = null): array
{
    if (count($rows) >= $limit) {
        return [];
    }
    $tete = array_slice($rows, 0, 5);
    $ids = array_values(array_filter(array_map(fn($r) => (int)($r['id_produit'] ?? 0), $rows)));
    $idsTete = array_values(array_filter(array_map(fn($r) => (int)($r['id_produit'] ?? 0), $tete)));
    if (!$idsTete) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT reference FROM reference WHERE id_produit IN (' . implode(',', array_fill(0, count($idsTete), '?')) . ')');
    $stmt->execute($idsTete);
    $premiersMots = [];
    foreach ($tete as $r) {
        $premiersMots[substr(ai_premier_mot((string)$r['libelle']), 0, 5)] = true;
    }
    $memes = array_filter(
        ai_produits_par_codes($pdo, ai_codes_references($stmt->fetchAll(PDO::FETCH_COLUMN)), $ids),
        fn($r) => isset($premiersMots[substr(ai_premier_mot((string)$r['libelle']), 0, 5)])
    );
    if ($id_voiture !== null && $memes) {
        $lies = $pdo->query('SELECT DISTINCT id_produit FROM pvd WHERE id_voiture = ' . (int)$id_voiture . ' AND id_produit IN (' . implode(',', array_map(fn($r) => (int)$r['id_produit'], $memes)) . ')')->fetchAll(PDO::FETCH_COLUMN);
        $memes = array_map(
            fn($r) => $r + ['voiture_id' => $id_voiture],
            array_filter($memes, fn($r) => in_array($r['id_produit'], $lies))
        );
    }
    // Deduplicated together with the results: a copy of a result already
    // listed is not a new product.
    $nouveaux = array_filter(ai_sans_doublons($pdo, array_merge($rows, array_values($memes))), fn($r) => !in_array((int)$r['id_produit'], $ids, true));
    return array_map(fn($r) => array_merge($r, [
        'meme_reference' => true,
        'libelle' => trim($r['libelle']) . ' (réf. ' . trim($r['reference']) . ')',
    ]), array_slice(array_values($nouveaux), 0, 4));
}

// Each catalog duplicate once - same name, same part brand, same references
// (520 such groups in Rapport catalogue > Doublons): "KIT EMB MAZDA BT50/FORD
// T6 (2PCS) - LUK" was listed twice, available and not. Rows come ordered
// available first, so the first copy kept is the available one.
function ai_sans_doublons(PDO $pdo, array $rows): array
{
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['id_produit'] ?? 0), $rows))));
    if (count($ids) < 2) {
        return $rows;
    }
    $stmt = $pdo->prepare("SELECT id_produit, GROUP_CONCAT(DISTINCT REGEXP_REPLACE(UPPER(reference), '[^A-Z0-9]', '') ORDER BY REGEXP_REPLACE(UPPER(reference), '[^A-Z0-9]', '')) FROM reference WHERE id_produit IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY id_produit');
    $stmt->execute($ids);
    $references = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $vus = [];
    $resultat = [];
    foreach ($rows as $row) {
        $id = (int)($row['id_produit'] ?? 0);
        $cle = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string)$row['libelle']))) . '|'
            . mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string)$row['marquepiece']))) . '|'
            . ($references[$id] ?? "#$id");
        if (!isset($vus[$cle])) {
            $vus[$cle] = true;
            $resultat[] = $row;
        }
    }
    return $resultat;
}

// Chassis-like codes in a text: 3-4 letters then digits (ALA30, SXA1,
// ACA20, HZJ80, KZJ120, RAV4). Two-letter codes are left out: those are
// mostly engines (TD42, TB45, RD28, ZD30) shared by several models - "FREIN
// PATROL TD42 /TB45" is a Patrol Y61 part.
function ai_codes_chassis(string $texte): array
{
    preg_match_all('/(?<![A-Z0-9])[A-Z]{3,4}[0-9]{1,3}[A-Z]?(?![A-Z0-9])/', strtoupper($texte), $m);
    return array_values(array_unique($m[0]));
}

// Words of a vehicle's model name distinctive enough to find unlinked parts
// by name: 3+ characters, not a drive/engine/fuel word, and used by at most
// 3 vehicle names (YARIS and CRUISER are in 6, so they would let parts for
// other models in).
function ai_mots_distinctifs_vehicule(PDO $pdo, int $id_voiture): array
{
    static $modeles = null;
    static $frequence = [];
    if ($modeles === null) {
        $modeles = $pdo->query('SELECT id_voiture, modele FROM voiture')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($modeles as $modele) {
            foreach (array_unique(preg_split('/[^A-Z0-9]+/', strtoupper((string)$modele))) as $mot) {
                $frequence[$mot] = ($frequence[$mot] ?? 0) + 1;
            }
        }
    }
    $generiques = ['2WD', '4WD', '4W2', '4W4', 'D4D', 'ESS', 'ESSENCE', 'ESSANCE', 'DIESEL', 'AUTO', 'PLUS', 'NEW', 'SPORT'];
    $mots = [];
    foreach (array_unique(preg_split('/[^A-Z0-9]+/', strtoupper((string)($modeles[$id_voiture] ?? '')))) as $mot) {
        if (strlen($mot) >= 3 && !in_array($mot, $generiques, true) && ($frequence[$mot] ?? 0) <= 3) {
            $mots[] = $mot;
        }
    }
    return $mots;
}

function ai_lookup_by_reference(PDO $pdo, string $reference): array
{
    $reference = trim($reference);

    // Price-0 rows are kept (unlike search_products): a customer typing an
    // exact reference that exists must not be told "not found" - real case,
    // 13508-30011. They come back flagged, without price or link.
    $select = 'SELECT r.reference, p.id_produit, p.libelle, p.marquepiece, p.prix, p.stock
               FROM reference r
               JOIN produit p ON p.id_produit = r.id_produit';
    $order = ' ORDER BY p.prix > 0 DESC, p.stock DESC LIMIT 20';

    // Exact, contained, and typed without its dashes/spaces/dots
    // ("4351252020" for "43512-52020"), all together: the same part is stored
    // under variants of its reference ("11044-VK505", "11044-VK505/.",
    // "11044-VK505-OSH", "CH0599E/11044-VK505*") - store owner, 28/10, got
    // the single exact match (out of stock) and none of the 9 others.
    // "Contains", not equality - many stored references combine several
    // codes ("34416/43512-52020"). Available first, exact match first among
    // equals, each product once.
    $compacte = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($reference));
    $conditions = ['r.reference = ?', 'r.reference LIKE ?'];
    $params = [$reference, '%' . ai_escape_like($reference) . '%'];
    if (strlen($compacte) >= 5) {
        $conditions[] = "REGEXP_REPLACE(UPPER(r.reference), '[^A-Z0-9]', '') LIKE ?";
        $params[] = '%' . $compacte . '%';
    }
    $stmt = $pdo->prepare($select . ' WHERE ' . implode(' OR ', $conditions)
        . ' ORDER BY p.prix > 0 DESC, p.stock DESC, r.reference = ? DESC LIMIT 40');
    $stmt->execute(array_merge($params, [$reference]));
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[$row['id_produit']] = $rows[$row['id_produit']] ?? $row;
    }
    $rows = array_slice(array_values($rows), 0, 20);

    // Store owner, 07/10: "0C380-16400" was answered "not found" while the
    // catalog has 16400-0C381-AT / -MT (radiator Revo). The halves were
    // typed in reverse order, and one character differs.
    $segments = array_values(array_filter(
        preg_split('/[^A-Z0-9]+/', mb_strtoupper($reference)),
        fn($s) => strlen($s) >= 2
    ));
    $colonne = "REGEXP_REPLACE(UPPER(r.reference), '[^A-Z0-9]', '')";

    // 1. Every part present, in any order.
    if (empty($rows) && count($segments) >= 2) {
        $stmt = $pdo->prepare($select . ' WHERE ' . implode(' AND ', array_fill(0, count($segments), "$colonne LIKE ?")) . $order);
        $stmt->execute(array_map(fn($s) => "%$s%", $segments));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Then by designation: the same part under other references (other
    // brands), often the one in stock - store owner, 04/11: customers asking
    // by reference were told "not in stock" while the shop has it.
    if ($rows) {
        $exclus = array_column($rows, 'id_produit');
        $rows = ai_sans_doublons($pdo, $rows);
        $rows = array_merge($rows, ai_equivalents_designation($pdo, $rows, $exclus));
    }

    // 2. One character off in one part (any order for the others), only for
    // references long enough to be distinctive. Returned flagged - a close
    // reference is a suggestion to confirm, never presented as the same part.
    if (empty($rows) && strlen($compacte) >= 8) {
        $alternatives = [];
        $params = [];
        foreach ($segments as $k => $segment) {
            if (strlen($segment) < 4) {
                continue;
            }
            $variantes = [];
            for ($i = 0; $i < strlen($segment); $i++) {
                $variantes[] = "$colonne LIKE ?";
                $params[] = '%' . substr_replace($segment, '_', $i, 1) . '%';
            }
            $conditions = ['(' . implode(' OR ', $variantes) . ')'];
            foreach ($segments as $j => $autre) {
                if ($j !== $k) {
                    $conditions[] = "$colonne LIKE ?";
                    $params[] = "%$autre%";
                }
            }
            $alternatives[] = '(' . implode(' AND ', $conditions) . ')';
        }
        if ($alternatives) {
            $stmt = $pdo->prepare($select . ' WHERE ' . implode(' OR ', $alternatives) . $order);
            $stmt->execute($params);
            // The reference goes into the name too: the model always copies
            // the name, but left out a separate reference field even when
            // told to show it - and the customer needs it to compare.
            $rows = array_map(fn($r) => array_merge($r, [
                'reference_proche' => true,
                'libelle' => trim($r['libelle']) . ' (réf. ' . trim($r['reference']) . ')',
            ]), $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    // Group by marquepiece so the caller can present alternatives clearly
    // instead of picking one silently (43.6% of references are ambiguous).
    $grouped = [];
    foreach ($rows as $row) {
        $row = ai_disponibilite($row);
        if ((int)$row['prix'] <= 0) {
            // No id either: given one, the model built a product link itself
            // (eval_chat F), despite the prompt forbidding it.
            unset($row['id_produit']);
            $row['prix'] = null;
            $row['prix_non_disponible'] = true;
            $brand = trim((string)$row['marquepiece']) !== '' ? $row['marquepiece'] : 'NON SPECIFIE';
            $grouped[$brand][] = $row;
            continue;
        }
        $row['url'] = 'produit.php?id=' . $row['id_produit'];
        $brand = trim((string)$row['marquepiece']) !== '' ? $row['marquepiece'] : 'NON SPECIFIE';
        $grouped[$brand][] = $row;
    }
    return $grouped;
}

// The same part under other references, the two-step search the store owner
// asked for (04/11, 07/11: customers asking by reference were told "not in
// stock" while the shop has it): reference -> the designation of what it
// found -> back to the references. Two ways a product is the same part:
// - it carries one of the found references' codes - a stored reference often
//   lists the part's other codes ("CH0599E/11044-VK505*", "S6104/83530-10010")
//   - under the same kind of designation (same first word);
// - it has exactly the same designation (Ref1/Ref2/Ref3 "POCHET JOINT QD32"
//   from three brands, no code in common) AND fits the same vehicle. The
//   vehicle is required: "AMORTISSEUR AV" has 184 references for 62 cars, a
//   Hilux reference must not bring them all. When the found products are
//   linked to no vehicle, the designation must carry a code itself (QD32).
// Available first, code matches before name matches, at most 10, flagged,
// with their reference in the name (the model leaves a separate field out).
// $exclus: every product the reference itself found, duplicates included.
function ai_equivalents_designation(PDO $pdo, array $trouves, array $exclus): array
{
    $exclus = array_values(array_unique(array_map('intval', $exclus)));
    if (!$trouves || !$exclus) {
        return [];
    }
    $premiersMots = [];
    $designations = [];
    foreach ($trouves as $r) {
        $mot = ai_premier_mot((string)$r['libelle']);
        if (strlen($mot) >= 3) {
            // POCHETTE and POCHET, PLAQUETTE and PLAQUET: compared on 5 letters.
            $premiersMots[substr($mot, 0, 5)] = true;
        }
        $designation = ai_designation_normalisee((string)$r['libelle']);
        if ($designation !== '') {
            $designations[$designation] = true;
        }
    }

    $parCode = array_filter(
        ai_produits_par_codes($pdo, ai_codes_references(array_column($trouves, 'reference')), $exclus),
        fn($r) => isset($premiersMots[substr(ai_premier_mot((string)$r['libelle']), 0, 5)])
    );
    $parDesignation = ai_produits_meme_designation($pdo, array_keys($designations), $exclus);

    // A manufacturer code that contradicts the one asked for means another
    // part, same name and same car or not: 11044-VK500..VK504 are other
    // head gaskets than 11044-VK505, 43512-52050 another disc than -52020.
    $oemTrouves = ai_codes_oem(array_column($trouves, 'reference'));
    $contredit = function (array $r) use ($oemTrouves): bool {
        $complets = array_filter(array_keys(ai_codes_oem([$r['reference']])), fn($c) => strlen($c) === 10);
        if (array_intersect($complets, array_keys($oemTrouves))) {
            return false;
        }
        foreach ($complets as $code) {
            if (isset($oemTrouves[substr($code, 0, 5)])) {
                return true;
            }
        }
        return false;
    };
    $equivalents = [];
    foreach (array_merge(array_values($parCode), $parDesignation, ai_meme_piece_memes_voitures($pdo, $trouves, $exclus, $premiersMots)) as $r) {
        if (!$contredit($r)) {
            $equivalents[$r['id_produit']] = $equivalents[$r['id_produit']] ?? $r;
        }
    }
    // Stable: available first, code matches still ahead within each group.
    $equivalents = array_values($equivalents);
    usort($equivalents, fn($a, $b) => ((int)$b['stock'] > 0) <=> ((int)$a['stock'] > 0));

    return array_map(fn($r) => array_merge($r, [
        'meme_designation' => true,
        'libelle' => trim($r['libelle']) . (trim((string)$r['reference']) !== '' ? ' (réf. ' . trim($r['reference']) . ')' : ''),
    ]), array_slice(ai_sans_doublons($pdo, $equivalents), 0, 10));
}

// The same kind of part for the cars the reference fits, available ones only
// (store owner: "MD2260" - a front brake pad for the Vigo, out of stock -
// must lead to the Vigo front pads in stock, also when their name differs:
// "PLAQUETTE VIGO 2WD AV GDB3427"). Searched by the found designation on
// each of those cars, at most 3. Rows in the same shape as the others.
function ai_meme_piece_memes_voitures(PDO $pdo, array $trouves, array $exclus, array $premiersMots): array
{
    // Only when nothing the reference found is in stock - the case the store
    // owner described; it costs one search per car.
    if (array_filter($trouves, fn($r) => (int)($r['stock'] ?? 0) > 0)) {
        return [];
    }
    $marques = implode(',', array_fill(0, count($exclus), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT id_voiture FROM pvd WHERE id_produit IN ($marques) LIMIT 3");
    $stmt->execute($exclus);
    $voitures = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    // The kind of part and its side, not the whole name: "PLAQUETTE DE
    // FREIN AV" -> "PLAQUETTE AV", so "PLAQUETTE VIGO 2WD AV GDB3427" counts.
    $motsDesignation = preg_split('/[^A-Z0-9]+/', ai_designation_normalisee((string)($trouves[0]['libelle'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $cotes = array_intersect($motsDesignation, ['AV', 'AR', 'AVANT', 'ARRIERE', 'G', 'D', 'DR', 'SUP', 'INF', 'GAUCHE', 'DROITE']);
    $designation = trim(ai_premier_mot((string)($trouves[0]['libelle'] ?? '')) . ' ' . implode(' ', $cotes));
    if (!$voitures || $designation === '') {
        return [];
    }
    $exclusSet = array_flip(array_map('intval', $exclus));
    $lignes = [];
    foreach ($voitures as $voiture) {
        foreach (ai_search_products($pdo, $designation, $voiture, null, 8) as $r) {
            $id = (int)$r['id_produit'];
            if (empty($r['disponible']) || isset($exclusSet[$id]) || isset($lignes[$id])
                || !isset($premiersMots[substr(ai_premier_mot((string)$r['libelle']), 0, 5)])) {
                continue;
            }
            $lignes[$id] = ['reference' => '', 'id_produit' => $id, 'libelle' => $r['libelle'],
                'marquepiece' => $r['marquepiece'], 'prix' => $r['prix'], 'stock' => 1];
        }
    }
    if ($lignes) {
        $marques = implode(',', array_fill(0, count($lignes), '?'));
        $stmt = $pdo->prepare("SELECT id_produit, MIN(reference) FROM reference WHERE id_produit IN ($marques) GROUP BY id_produit");
        $stmt->execute(array_keys($lignes));
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $reference) {
            $lignes[$id]['reference'] = (string)$reference;
        }
    }
    return array_values($lignes);
}

function ai_premier_mot(string $libelle): string
{
    return preg_split('/[^A-Z]+/', mb_strtoupper(ai_normalize_term($libelle)), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
}

// "Bute emb  YARIS " and "BUTE EMB YARIS" are the same designation.
function ai_designation_normalisee(string $libelle): string
{
    return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($libelle)));
}

// The codes inside stored references: "CH0599E/11044-VK505*" holds CH0599E
// and 11044VK505. Only codes long enough to be distinctive (6+ characters,
// a digit) - "11044-" alone is shared by every Nissan head gasket.
function ai_codes_references(array $references): array
{
    $codes = [];
    foreach ($references as $reference) {
        foreach (preg_split('/[\/,;*()\s]+/', mb_strtoupper((string)$reference)) as $partie) {
            $code = preg_replace('/[^A-Z0-9]/', '', $partie);
            if (strlen($code) >= 6 && preg_match('/[0-9]/', $code)) {
                $codes[$code] = true;
            }
        }
    }
    return array_slice(array_keys($codes), 0, 15);
}

// Manufacturer codes in the Toyota / Nissan form "43512-52020" (5 + 5), as
// keys "4351252020", plus each one's first part ("43512") so a code with the
// same first part and another ending can be told apart.
function ai_codes_oem(array $references): array
{
    $codes = [];
    foreach ($references as $reference) {
        preg_match_all('/(?<![A-Z0-9])([0-9]{5})-?([A-Z0-9]{5})(?![A-Z0-9])/', mb_strtoupper((string)$reference), $m, PREG_SET_ORDER);
        foreach ($m as $code) {
            $codes[$code[1] . $code[2]] = true;
            $codes[$code[1]] = true;
        }
    }
    return $codes;
}

// Priced products whose stored reference contains one of $codes.
function ai_produits_par_codes(PDO $pdo, array $codes, array $exclus): array
{
    if (!$codes) {
        return [];
    }
    // One REGEXP over the compacted reference rather than a LIKE per code:
    // the compaction then runs once per row (codes are A-Z0-9 only).
    $sql = "SELECT MIN(r.reference) AS reference, p.id_produit, p.libelle, p.marquepiece, p.prix, p.stock
            FROM produit p
            JOIN reference r ON r.id_produit = p.id_produit
            WHERE REGEXP_REPLACE(UPPER(r.reference), '[^A-Z0-9]', '') REGEXP ?
              AND p.prix > 0"
            . ($exclus ? ' AND p.id_produit NOT IN (' . implode(',', array_fill(0, count($exclus), '?')) . ')' : '') . "
            GROUP BY p.id_produit
            ORDER BY p.stock DESC, p.id_produit
            LIMIT 40";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([implode('|', $codes)], $exclus));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Priced products with exactly one of these designations, fitting a vehicle
// the excluded (found) products fit - see ai_equivalents_designation().
function ai_produits_meme_designation(PDO $pdo, array $designations, array $exclus): array
{
    if (!$designations || !$exclus) {
        return [];
    }
    $marquesExclus = implode(',', array_fill(0, count($exclus), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT id_voiture FROM pvd WHERE id_produit IN ($marquesExclus)");
    $stmt->execute($exclus);
    $voitures = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$voitures) {
        $designations = array_values(array_filter($designations, fn($d) => preg_match('/[0-9]/', $d)));
        if (!$designations) {
            return [];
        }
    }

    $sql = "SELECT MIN(r.reference) AS reference, p.id_produit, p.libelle, p.marquepiece, p.prix, p.stock
            FROM produit p
            JOIN reference r ON r.id_produit = p.id_produit
            WHERE UPPER(REGEXP_REPLACE(TRIM(p.libelle), '[[:space:]]+', ' ')) IN (" . implode(',', array_fill(0, count($designations), '?')) . ")
              AND p.id_produit NOT IN ($marquesExclus)
              AND p.prix > 0";
    $params = array_merge($designations, $exclus);
    if ($voitures) {
        $sql .= ' AND EXISTS (SELECT 1 FROM pvd WHERE pvd.id_produit = p.id_produit AND pvd.id_voiture IN (' . implode(',', array_fill(0, count($voitures), '?')) . '))';
        $params = array_merge($params, $voitures);
    }
    $sql .= ' GROUP BY p.id_produit ORDER BY p.stock DESC, p.id_produit LIMIT 40';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// A customer's word that describes the vehicle rather than naming it:
// '4WD', '2WD', 'turbo', 'sans' (before turbo: "sans turbo", "بلا تيربو"),
// or null.
function ai_attribut_vehicule(string $mot): ?string
{
    $mot = mb_strtoupper(trim($mot));
    if (preg_match('/^(4[X*×]4|4WD|4W4|كاتكات|كاتكا)$/u', $mot)) {
        return '4WD';
    }
    if (preg_match('/^(4[X*×]2|2WD|4W2)$/u', $mot)) {
        return '2WD';
    }
    if (in_array($mot, ['TURBO', 'D4D', 'تيربو', 'توربو', 'طوربو', 'تربو', 'تيربوا'], true)) {
        return 'turbo';
    }
    if (in_array($mot, ['SANS', 'BLA', 'BALA', 'بلا', 'بدون'], true)) {
        return 'sans';
    }
    return null;
}

// Engine written in a product's name or description, as whole words: the
// Toyota D-4D turbo diesel (D4D, KUN.., 1KD, 2KD, TURBO) or the engines
// without turbo (5L, 3L, 2L, LAN.., LN..).
const AI_REGEXP_MOTEUR_TURBO = '(^|[^A-Z0-9])(D-?4D|KUN[0-9]*|1KD|2KD|TURBO)([^A-Z0-9]|$)';
const AI_REGEXP_MOTEUR_ATMO = '(^|[^A-Z0-9])(5L|3L|2L|LAN[0-9]+|LN[0-9]+)([^A-Z0-9]|$)';

// The engine a customer's words name: 'turbo' (turbo, D4D, 1KD, 2KD,
// تيربو...), 'atmo' (5L, 3L, 2L, "sans turbo", "بلا تيربو"), or null (none,
// or both).
function ai_moteur_demande(array $mots): ?string
{
    $turbo = false;
    $atmo = false;
    $mots = array_values($mots);
    foreach ($mots as $i => $mot) {
        $majuscule = mb_strtoupper(trim($mot));
        $attribut = ai_attribut_vehicule($mot);
        if ($attribut === 'sans' && ai_attribut_vehicule($mots[$i + 1] ?? '') === 'turbo') {
            $atmo = true;
        } elseif (($attribut === 'turbo' || in_array($majuscule, ['1KD', '2KD', 'D-4D'], true))
            && ai_attribut_vehicule($mots[$i - 1] ?? '') !== 'sans') {
            $turbo = true;
        } elseif (in_array($majuscule, ['5L', '3L', '2L'], true)) {
            $atmo = true;
        }
    }
    return $turbo === $atmo ? null : ($turbo ? 'turbo' : 'atmo');
}

// Engine of a Vigo named without engine word: the 5L until 2008, the D4D
// turbo after (store owner, 24/11: "تحبس في 2008، من بعد تجي d4d"). No year:
// the 5L. Returns whether it is the turbo.
const AI_VIGO_DERNIERE_ANNEE_5L = 2008;

function ai_vigo_turbo_par_annee(?int $annee): bool
{
    return $annee !== null && $annee > AI_VIGO_DERNIERE_ANNEE_5L;
}

// Fuel written in a product's name or description, as whole words: petrol
// (ESS, ESSENCE, petrol engines 2TR, 1TR, TGN.., KA24DE, 4G..) or diesel
// (DIESEL, GAZOIL, diesel engines D4D, 1KD, 2KD, 5L, 3L, 2L, 1HZ, 1KZ, QD32,
// TD27, ZD30, YD25, 4D56, 4M40, KUN.., LAN.., LN..).
const AI_REGEXP_ESSENCE = '(^|[^A-Z0-9])(ESS|ESSENCE|ESSANCE|BENZINE|PETROL|2TR|1TR|2TR-FE|1TR-FE|2TRE|1TRE|TGN[0-9]*|KA24DE|4G[0-9]{2}|1NZ|2NZ|1ZZ|3SZ|1NR)([^A-Z0-9]|$)';
const AI_REGEXP_DIESEL = '(^|[^A-Z0-9])(DIESEL|GASOIL|GAZOIL|D-?4D|1KD|2KD|5L|3L|2L|1HZ|1KZ|QD32|TD27|ZD30|YD25|4D56|4M40|1GD|2GD|DCI|HDI|TDI|KUN[0-9]*|LAN[0-9]+|LN[0-9]+)([^A-Z0-9]|$)';

// The fuel a customer's words name: 'essence' (essence, ess, بنزين,
// ليصانص, a petrol engine code: 2TR...), 'diesel' (diesel, gasoil, مازوت,
// a diesel engine code: D4D, 5L, QD32...), or null (none, or both).
function ai_carburant_demande(array $mots): ?string
{
    $essence = false;
    $diesel = false;
    foreach ($mots as $mot) {
        $carburant = ai_mot_carburant($mot);
        $majuscule = mb_strtoupper(trim($mot));
        $essence = $essence || $carburant === 'essence' || preg_match('/^(2TR|1TR|2TR-?FE|1TR-?FE|KA24DE|4G\d{2}|1NZ|2NZ|1ZZ|3SZ|1NR)$/', $majuscule);
        $diesel = $diesel || $carburant === 'diesel' || ai_attribut_vehicule($mot) === 'turbo'
            || preg_match('/^(1KD|2KD|5L|3L|2L|1HZ|1KZ|QD32|TD27|ZD30|YD25|4D56|4M40|1GD|2GD)$/', $majuscule);
    }
    return $essence === $diesel ? null : ($essence ? 'essence' : 'diesel');
}

// A fuel word: 'essence', 'diesel' or null. "gasoil" is also a part word
// (FILTRE GAZOIL), so it names the diesel but stays in the searched text.
function ai_mot_carburant(string $mot): ?string
{
    $mot = mb_strtoupper(trim($mot));
    if (in_array($mot, ['ESSENCE', 'ESSANCE', 'ESS', 'BENZINE', 'PETROL', 'بنزين', 'ليصانص', 'ليسانس', 'اسانس', 'ايسانس', 'ايسونس', 'ليسونس', 'اسونس'], true)) {
        return 'essence';
    }
    if (in_array($mot, ['DIESEL', 'DIZEL', 'GASOIL', 'GAZOIL', 'GAZOILE', 'MAZOUT', 'مازوت', 'ديزل', 'قازوال', 'غازوال', 'كازوال'], true)) {
        return 'diesel';
    }
    return null;
}

// A word that looks like an engine code - 5L, 2TR, 1KD, 2KD-FTV, 1HZ, 4D56,
// QD32, YD25 - rather than a part word or a year.
function ai_est_code_moteur(string $mot): bool
{
    $mot = mb_strtoupper(trim($mot));
    return !preg_match('/^(19|20)\d{2}$/', $mot) && !preg_match('/^D-?4D$/', $mot)
        && (bool)preg_match('/^(\d[A-Z]{1,3}(-[A-Z]{1,4})?|\d[A-Z]\d{2}|[A-Z]{2}\d{2}[A-Z]{0,2})$/', $mot);
}

// Vehicles an engine code belongs to, read from the descriptions of the
// parts linked to them: "5L" -> Vigo LAN15 (178 parts say it), Hilux
// LN145/LN166; "2TR" -> Vigo LAN15; "QD32" -> Pick Up QD32. A vehicle
// counts from 3 parts and 20% of the most-cited one, so a part linked by
// mistake (one "5L" part on the Vigo D4D) does not make it a 5L.
function ai_voitures_par_code_moteur(PDO $pdo, string $code): array
{
    static $cache = [];
    $code = mb_strtoupper(trim($code));
    if (!isset($cache[$code])) {
        $stmt = $pdo->prepare('SELECT id_voiture, COUNT(*) FROM pvd WHERE description REGEXP ? GROUP BY id_voiture');
        $stmt->execute(['(^|[^A-Z0-9])' . preg_quote($code) . '([^A-Z0-9]|$)']);
        $compte = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $max = $compte ? max($compte) : 0;
        $cache[$code] = array_map('intval', array_keys(array_filter($compte, fn($n) => $n >= 3 && $n >= 0.2 * $max)));
    }
    return $cache[$code];
}

// The customer's word for the Vigo alone ("vigo", "فيقو", "فيغو"), not a
// phrase containing it.
function ai_est_mot_vigo(string $mot): bool
{
    if (ai_normalize_term($mot) === 'vigo') {
        return true;
    }
    return in_array('vigo', array_map('ai_normalize_term', ai_search_arabic_aliases()[trim($mot)] ?? []), true);
}

// Drive written in a model name: '4WD', '2WD' or null (not written).
function ai_traction_voiture(string $modele): ?string
{
    $modele = mb_strtoupper($modele);
    if (preg_match('/\b(4WD|4W4)\b/', $modele)) {
        return '4WD';
    }
    if (preg_match('/\b(2WD|4W2)\b/', $modele)) {
        return '2WD';
    }
    return null;
}

// Engine written in a model name: 'turbo' (TURBO, D4D, Toyota KUN = D-4D),
// 'atmo' (Toyota LAN = 2L/3L/5L, no turbo), 'mixte' (both codes on one
// row: "VIGO 4WD LAN25 KUN25") or null (not written).
function ai_turbo_voiture(string $modele): ?string
{
    $modele = mb_strtoupper($modele);
    $turbo = (bool)preg_match('/TURBO|D4D|\bKUN\d/', $modele);
    $atmo = (bool)preg_match('/\bLAN\d/', $modele);
    if ($turbo && $atmo) {
        return 'mixte';
    }
    return $turbo ? 'turbo' : ($atmo ? 'atmo' : null);
}

// The ONE question to ask when several vehicles remain, in the store
// owner's order (17/11): the year, then 4x2 or 4x4, then turbo or not -
// each only if the customer has not said it and the answer would narrow
// the candidates. 'modele' when none would: list them to choose.
function ai_question_vehicule(array $candidats, bool $anneeConnue, bool $tractionConnue, bool $turboConnu): string
{
    // A year narrows them only if some year belongs to some candidates and
    // not others (NCP90 2007-2011 and NCP92 2007-2014: 2012 does).
    if (!$anneeConnue) {
        $avecAnnees = array_filter($candidats, fn($v) => $v['annee_debut'] !== null && $v['annee_fin'] !== null);
        if (count($avecAnnees) > 1) {
            $debut = min(array_map(fn($v) => (int)$v['annee_debut'], $avecAnnees));
            $fin = max(array_map(fn($v) => (int)$v['annee_fin'], $avecAnnees));
            for ($an = $debut; $an <= $fin; $an++) {
                $dedans = count(array_filter($avecAnnees, fn($v) => (int)$v['annee_debut'] <= $an && $an <= (int)$v['annee_fin']));
                if ($dedans > 0 && $dedans < count($avecAnnees)) {
                    return 'annee';
                }
            }
        }
    }
    $tractions = array_unique(array_filter(array_map(fn($v) => ai_traction_voiture($v['modele']), $candidats)));
    if (!$tractionConnue && count($tractions) > 1) {
        return 'traction';
    }
    $turbos = array_unique(array_filter(array_map(fn($v) => ai_turbo_voiture($v['modele']), $candidats)));
    if (!$turboConnu && count($turbos) > 1) {
        return 'turbo';
    }
    return 'modele';
}

// Returns ['unique' => bool, 'matches' => [...]]. `unique` is the
// deterministic signal the assistant prompt is required to obey (see rule
// 9 in prompt.php) instead of judging case-by-case whether a vehicle name
// needs disambiguation - true when exactly one candidate matched, OR when
// the top-scoring candidate's score strictly beats the runner-up's (the
// customer gave enough detail - a model code, trim, etc. - to single one
// out). False on a tie at the top score: "Yaris" alone matches 5 distinct
// models with an identical score, which is exactly the case that needs a
// clarifying question rather than silently combining all 5.
function ai_resolve_vehicle(PDO $pdo, string $free_text): array
{
    static $all = null;
    if ($all === null) {
        $stmt = $pdo->query('SELECT v.id_voiture, v.modele, m.libelle AS marque, v.annee_debut, v.annee_fin
                              FROM voiture v JOIN marque m ON m.id_marque = v.id_marque');
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Customers give a year ("Corolla 2008"), almost never a chassis code -
    // every voiture row has its production years, so a year narrows the
    // candidates instead of being matched as text.
    $annee = null;
    $needleTokens = [];
    // A phrase taught by the store owner ("vigo turbo") is read as one word.
    $mots = array_values(array_filter(preg_split('/\s+/u', trim($free_text)), fn($t) => $t !== ''));
    $phrases = array_filter(array_keys(ai_mots_appris($pdo)), fn($cle) => strpos($cle, ' ') !== false);
    usort($phrases, fn($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));
    $tokens = [];
    for ($i = 0, $n = count($mots); $i < $n;) {
        foreach ($phrases as $phrase) {
            $longueur = substr_count($phrase, ' ') + 1;
            if (ai_cle_mot_appris(implode(' ', array_slice($mots, $i, $longueur))) === $phrase) {
                $tokens[] = implode(' ', array_slice($mots, $i, $longueur));
                $i += $longueur;
                continue 2;
            }
        }
        $tokens[] = $mots[$i++];
    }
    // 4x2/4x4 and turbo narrow the candidates like the year, they are not
    // matched as text: "vigo turbo" scored the Nissan "PICK UP TURBO 2WD"
    // (5 letters) above every Vigo (4).
    $traction = null;
    $turbo = null;
    $carburant = null;
    $appris = ai_mots_appris($pdo);
    foreach ($tokens as $i => $token) {
        if (preg_match('/^(19|20)\d{2}$/', $token)) {
            $annee = (int)$token;
            continue;
        }
        // A word the store owner taught is read as he taught it, before the
        // built-in meaning of turbo/D4D/4x4 ("d4d" -> a Vigo was ignored).
        $attribut = isset($appris[ai_cle_mot_appris($token)]) ? null : ai_attribut_vehicule($token);
        if ($attribut === 'sans' && ai_attribut_vehicule($tokens[$i + 1] ?? '') === 'turbo') {
            $turbo = false;
            continue;
        }
        if ($attribut === '4WD' || $attribut === '2WD') {
            $traction = $attribut;
            continue;
        }
        if ($attribut === 'turbo') {
            $turbo = $turbo ?? true;
            continue;
        }
        // Essence / diesel narrow the candidates too ("pick up essence").
        $motCarburant = isset($appris[ai_cle_mot_appris($token)]) ? null : ai_mot_carburant($token);
        if ($motCarburant !== null) {
            $carburant = $carburant === null || $carburant === $motCarburant ? $motCarburant : 'les_deux';
            continue;
        }
        $arabe = ai_arabic_alias_lookup($token, $pdo);
        if ($arabe === null && ai_est_arabe($token)) {
            // Car name in Arabic letters, read by sound (هيلكس = HILUX).
            $phonetique = ai_correspondances_phonetiques($pdo, $token, ai_vocabulaire_vehicules($pdo));
            $arabe = $phonetique ? [$phonetique[0]] : null;
        }
        // The word itself (Latin letters) and every equivalent from the
        // dictionary - a word taught from the dashboard can have several.
        $needleTokens[$token] = array_values(array_unique(array_map('mb_strtoupper', array_merge(ai_est_arabe($token) ? [] : [$token], $arabe ?? [$token]))));
    }

    $scored = [];
    $tokensUtiles = [];
    foreach ($all as $row) {
        $haystack = mb_strtoupper(preg_replace('/\s+/u', ' ', $row['marque'] . ' ' . $row['modele']));
        $score = 0;
        foreach ($needleTokens as $original => $candidats) {
            // Also letters and digits only: "XLI2024" for "XLI 2024" (production
            // 06/10, the customer's answer was not recognised and the model
            // asked for the Corolla model again).
            // From the start of a word only: "L200" was found inside
            // "X-TRAIL 2007" (XTRAI-L200-7) and pushed the L200s out.
            $compact = fn($t) => preg_replace('/[^A-Z0-9]/u', '', $t);
            $mots = array_values(array_filter(array_map($compact, explode(' ', $haystack)), fn($m) => $m !== ''));
            $debutsDeMot = [];
            foreach ($mots as $k => $m) {
                $debutsDeMot[] = implode('', array_slice($mots, $k));
            }
            $meilleur = 0;
            foreach ($candidats as $token) {
                $c = $compact($token);
                $compactTrouve = strlen($c) >= 4 && count(array_filter($debutsDeMot, fn($d) => strpos($d, $c) === 0)) > 0;
                if (mb_strpos($haystack, $token) !== false || $compactTrouve) {
                    $meilleur = max($meilleur, strlen($token));
                }
            }
            if ($meilleur > 0) {
                $score += $meilleur;
                $tokensUtiles[$original] = true;
            }
        }
        if ($score > 0) {
            $scored[] = $row + ['score' => $score];
        }
    }

    // An engine code no vehicle name holds (5L, 2TR, 1HZ, 4D56) names the
    // vehicles whose parts say it: "hilux 5L" keeps the 5L Hilux, "2tr" alone
    // gives the Vigo 2TR.
    foreach (array_keys($needleTokens) as $original) {
        if (isset($tokensUtiles[$original]) || !ai_est_code_moteur($original)) {
            continue;
        }
        $idsCode = ai_voitures_par_code_moteur($pdo, $original);
        if (!$idsCode) {
            continue;
        }
        $tokensUtiles[$original] = true;
        if ($scored) {
            $filtre = array_values(array_filter($scored, fn($row) => in_array((int)$row['id_voiture'], $idsCode, true)));
            $scored = $filtre ?: $scored;
        } else {
            foreach ($all as $row) {
                if (in_array((int)$row['id_voiture'], $idsCode, true)) {
                    $scored[] = $row + ['score' => strlen($original)];
                }
            }
        }
    }
    if ($carburant === null) {
        $carburant = ai_carburant_demande(array_keys($needleTokens));
    }
    if (in_array($carburant, ['essence', 'diesel'], true)) {
        $autre = $carburant === 'essence' ? AI_REGEXP_DIESEL : AI_REGEXP_ESSENCE;
        $voulu = $carburant === 'essence' ? AI_REGEXP_ESSENCE : AI_REGEXP_DIESEL;
        $filtre = array_values(array_filter($scored, function ($row) use ($autre, $voulu) {
            // A vehicle named TURBO is a diesel here (Nissan PICK UP TURBO).
            $nom = str_replace('TURBO', 'DIESEL', mb_strtoupper($row['modele']));
            return !(preg_match('/' . $autre . '/u', $nom) && !preg_match('/' . $voulu . '/u', $nom));
        }));
        $scored = $filtre ?: $scored;
    }

    // "vigo" alone means the 5L (store owner, 23/11) - unless the customer
    // named the engine or another vehicle word (a chassis code: "vigo
    // kun15") matched. After 2008 there is no 5L: a later year is the D4D.
    // Not with essence: a petrol Vigo is the 2TR, neither 5L nor D4D.
    if ($turbo === null && $carburant !== 'essence') {
        $motsVigo = array_filter(array_keys($needleTokens), 'ai_est_mot_vigo');
        if ($motsVigo && !array_diff(array_keys($tokensUtiles), $motsVigo)) {
            $turbo = ai_vigo_turbo_par_annee($annee);
        }
    }

    if ($annee !== null) {
        $dansLesAnnees = array_values(array_filter($scored, function ($row) use ($annee) {
            return $row['annee_debut'] !== null && $row['annee_fin'] !== null
                && (int)$row['annee_debut'] <= $annee && $annee <= (int)$row['annee_fin'];
        }));
        // A year outside every candidate's range is more likely a typo than a
        // reason to answer "no vehicle" - keep the unfiltered list then.
        if ($dansLesAnnees !== []) {
            $scored = $dansLesAnnees;
        }
    }
    // Same for the drive and the turbo: only candidates written for the
    // OTHER drive / engine are dropped (a model without either in its name
    // stays), and never all of them.
    if ($traction !== null) {
        $filtre = array_values(array_filter($scored, fn($row) => !in_array(ai_traction_voiture($row['modele']), [$traction === '4WD' ? '2WD' : '4WD'], true)));
        $scored = $filtre ?: $scored;
    }
    if ($turbo !== null) {
        $filtre = array_values(array_filter($scored, fn($row) => ai_turbo_voiture($row['modele']) !== ($turbo ? 'atmo' : 'turbo')));
        $scored = $filtre ?: $scored;
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $matches = array_slice($scored, 0, 5);

    foreach ($matches as &$match) {
        $match['label'] = trim($match['marque'] . ' ' . preg_replace('/\s+/', ' ', $match['modele']))
            . ($match['annee_debut'] !== null ? ' (' . $match['annee_debut'] . '-' . $match['annee_fin'] . ')' : '');
    }
    unset($match);

    $unique = count($matches) === 1
        || (count($matches) >= 2 && $matches[0]['score'] > $matches[1]['score']);

    // Words that matched no vehicle at all are most likely the part: a
    // customer's "اكس تري فيقو" (axe etrier, Vigo) was sent whole as the
    // vehicle, and once the Vigo was chosen the part was never searched
    // (production 04/10).
    $horsVehicule = array_values(array_diff(array_keys($needleTokens), array_keys($tokensUtiles)));

    $retour = ['unique' => $unique, 'matches' => $matches, 'not_vehicle_words' => $horsVehicule];
    // The engine understood - the model passes it to search_products, whose
    // query no longer says it once the vehicle is chosen ("VIGO 4WD LAN25
    // KUN25" holds both engines).
    if ($turbo !== null && $matches) {
        $retour['engine'] = $turbo ? 'turbo' : 'non_turbo';
    }
    if (!$unique && count($matches) >= 2) {
        $retour['question'] = ai_question_vehicule(
            array_values(array_filter($scored, fn($row) => $row['score'] === $matches[0]['score'])),
            $annee !== null, $traction !== null, $turbo !== null
        );
    }
    // No word is a vehicle: the model sent the part as the vehicle ("اكس
    // اتري" after a Yaris was already chosen) and then told the customer the
    // "car" was not recognised instead of searching the part.
    if ($matches === [] && $horsVehicule !== []) {
        $retour['hint'] = 'None of these words is a vehicle - they are almost certainly the PART the customer wants. Call search_products with them now, using the vehicle already identified in this conversation if there is one. Only ask for the vehicle if none is known yet.';
    }
    return $retour;
}

// Registered wholesale customer (client_gros, WhatsApp channel): every
// product in a tool result gets the wholesale price (produit.prix_gros = the
// stock file's PV Gros) in `prix`, tagged tarif "gros". A product without a
// known wholesale price keeps its retail price, tagged tarif "detail" - the
// prompt then says the wholesale price is available from the store. The
// wholesale price is never read for anyone else.
function ai_appliquer_tarif_gros(PDO $pdo, array $resultat): array
{
    $ids = [];
    array_walk_recursive($resultat, function ($valeur, $cle) use (&$ids) {
        if ($cle === 'id_produit' && is_scalar($valeur)) {
            $ids[(int)$valeur] = true;
        }
    });
    if (!$ids) {
        return $resultat;
    }
    $marques = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id_produit, prix_gros FROM produit WHERE id_produit IN ($marques)");
    $stmt->execute(array_keys($ids));
    $prixGros = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $remplacer = function (array $noeud) use (&$remplacer, $prixGros): array {
        if (isset($noeud['id_produit']) && is_scalar($noeud['id_produit']) && array_key_exists('prix', $noeud) && $noeud['prix'] !== null) {
            $gros = (int)($prixGros[(int)$noeud['id_produit']] ?? 0);
            if ($gros > 0) {
                $noeud['prix'] = $gros;
                $noeud['tarif'] = 'gros';
            } else {
                $noeud['tarif'] = 'detail';
            }
        }
        foreach ($noeud as $cle => $valeur) {
            if (is_array($valeur)) {
                $noeud[$cle] = $remplacer($valeur);
            }
        }
        return $noeud;
    };
    return $remplacer($resultat);
}

// The model gets availability, never the quantity: it once turned
// "stock": "0" into "disponible" (production 18/10, DEMARREUR DJ for the
// Corolla NDE180), and quantities must not reach customers anyway (prompt
// rule 1).
function ai_disponibilite(array $row): array
{
    $row['disponible'] = (int)($row['stock'] ?? 0) > 0;
    unset($row['stock']);
    return $row;
}

function ai_get_product(PDO $pdo, int $id_produit, ?int $id_voiture = null): ?array
{
    $stmt = $pdo->prepare('
        SELECT produit.*, sc.libelle AS sous_categorie_nom
        FROM produit
        LEFT JOIN sous_categorie sc ON sc.id_sous_categorie = produit.id_sous_categorie
        WHERE produit.id_produit = ?
    ');
    $stmt->execute([$id_produit]);
    $produit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$produit || (int)$produit['prix'] === 0) {
        return null;
    }

    if ($id_voiture !== null) {
        $stmt = $pdo->prepare('SELECT description, id_voiture FROM pvd WHERE id_produit = ? AND id_voiture = ?');
        $stmt->execute([$id_produit, $id_voiture]);
    } else {
        $stmt = $pdo->prepare('SELECT description, id_voiture FROM pvd WHERE id_produit = ? LIMIT 1');
        $stmt->execute([$id_produit]);
    }
    $pvd = $stmt->fetch(PDO::FETCH_ASSOC);

    $voiture = $id_voiture ?? ($pvd['id_voiture'] ?? null);

    return [
        'id_produit' => $produit['id_produit'],
        'libelle' => $produit['libelle'],
        'marquepiece' => $produit['marquepiece'],
        'prix' => $produit['prix'],
        'disponible' => (int)$produit['stock'] > 0,
        'sous_categorie' => $produit['sous_categorie_nom'],
        'description' => $pvd['description'] ?? null,
        'url' => 'produit.php?id=' . $produit['id_produit'] . '&id_voiture=' . $voiture,
    ];
}

function ai_list_categories(PDO $pdo, ?int $id_voiture = null): array
{
    if ($id_voiture !== null) {
        $stmt = $pdo->prepare('
            SELECT DISTINCT c.id_categorie, c.libelle AS categorie, sc.id_sous_categorie, sc.libelle AS sous_categorie
            FROM produit p
            JOIN pvd pv ON pv.id_produit = p.id_produit
            JOIN categorie c ON c.id_categorie = p.id_categorie
            JOIN sous_categorie sc ON sc.id_sous_categorie = p.id_sous_categorie
            WHERE pv.id_voiture = ? AND p.prix > 0
            ORDER BY c.libelle, sc.libelle
        ');
        $stmt->execute([$id_voiture]);
    } else {
        $stmt = $pdo->query('
            SELECT c.id_categorie, c.libelle AS categorie, sc.id_sous_categorie, sc.libelle AS sous_categorie
            FROM categorie c
            JOIN sous_categorie sc ON sc.id_categorie = c.id_categorie
            ORDER BY c.libelle, sc.libelle
        ');
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ai_get_delivery_price(PDO $pdo, string $wilaya, string $mode): ?array
{
    $mode = strtolower(trim($mode)) === 'bureau' ? 'bureau' : 'domicile';

    $stmt = $pdo->prepare('SELECT wilaya, domicile, bureau FROM delivery WHERE wilaya LIKE ? LIMIT 1');
    $stmt->execute(['%' . ai_escape_like(trim($wilaya)) . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'wilaya' => $row['wilaya'],
        'mode' => $mode,
        'prix' => (int)$row[$mode],
    ];
}
