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

// French equivalents of an Arabic/Darija word, or null. Also tries the word
// without a leading definite article ("الفرامل" -> "فرامل").
function ai_arabic_alias_lookup(string $term): ?array
{
    $aliases = ai_search_arabic_aliases();
    $trimmed = trim($term);
    if (isset($aliases[$trimmed])) {
        return $aliases[$trimmed];
    }
    if (mb_substr($trimmed, 0, 2) === 'ال' && isset($aliases[mb_substr($trimmed, 2)])) {
        return $aliases[mb_substr($trimmed, 2)];
    }
    return null;
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

    $arabic = ai_arabic_alias_lookup($term);
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
function ai_fuzzy_libelle_fallback(PDO $pdo, string $searchText, int $limit = 20): array
{
    $normalized = ai_normalize_term($searchText);
    if (mb_strlen($normalized) < 4) {
        return []; // too short for edit-distance to be a meaningful signal
    }

    $firstChar = mb_substr($normalized, 0, 1);
    $stmt = $pdo->prepare('SELECT id_produit, libelle FROM produit WHERE libelle LIKE ? AND prix > 0 LIMIT 2000');
    $stmt->execute([ai_escape_like($firstChar) . '%']);
    $candidates = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $maxDistance = max(2, (int)ceil(mb_strlen($normalized) * 0.3));
    $scored = [];
    foreach ($candidates as $id => $libelle) {
        $bestDistance = PHP_INT_MAX;
        foreach (preg_split('/\s+/', ai_normalize_term($libelle)) as $word) {
            if ($word === '') {
                continue;
            }
            $bestDistance = min($bestDistance, levenshtein($normalized, $word));
        }
        if ($bestDistance <= $maxDistance) {
            $scored[$id] = $bestDistance;
        }
    }
    asort($scored);
    return array_slice(array_keys($scored), 0, $limit);
}

function ai_search_products(PDO $pdo, string $query, ?int $id_voiture = null, ?int $id_sous_categorie = null, int $limit = 8, ?int $min_price = null, ?int $max_price = null): array
{
    $limit = max(1, min($limit, 20));

    // Same normalization as product.php: spaces -> * -> multi-term AND search
    // across reference, pvd.description and produit.libelle.
    $searchTerm = trim($query);
    $normalized = str_replace(' ', '*', $searchTerm);
    $terms = array_filter(array_map('trim', explode('*', $normalized)));
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
    foreach (array_merge(array_values($parCode), $parDesignation) as $r) {
        if (!$contredit($r)) {
            $equivalents[$r['id_produit']] = $equivalents[$r['id_produit']] ?? $r;
        }
    }
    // Stable: available first, code matches still ahead within each group.
    $equivalents = array_values($equivalents);
    usort($equivalents, fn($a, $b) => ((int)$b['stock'] > 0) <=> ((int)$a['stock'] > 0));

    return array_map(fn($r) => array_merge($r, [
        'meme_designation' => true,
        'libelle' => trim($r['libelle']) . ' (réf. ' . trim($r['reference']) . ')',
    ]), array_slice(ai_sans_doublons($pdo, $equivalents), 0, 10));
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
    foreach (preg_split('/\s+/u', trim($free_text)) as $token) {
        if ($token === '') {
            continue;
        }
        if (preg_match('/^(19|20)\d{2}$/', $token)) {
            $annee = (int)$token;
            continue;
        }
        $arabe = ai_arabic_alias_lookup($token);
        if ($arabe === null && ai_est_arabe($token)) {
            // Car name in Arabic letters, read by sound (هيلكس = HILUX).
            $phonetique = ai_correspondances_phonetiques($pdo, $token, ai_vocabulaire_vehicules($pdo));
            $arabe = $phonetique ?: null;
        }
        $needleTokens[$token] = mb_strtoupper($arabe !== null ? $arabe[0] : $token);
    }

    $scored = [];
    $tokensUtiles = [];
    foreach ($all as $row) {
        $haystack = mb_strtoupper($row['marque'] . ' ' . $row['modele']);
        $score = 0;
        foreach ($needleTokens as $original => $token) {
            // Also letters and digits only: "XLI2024" for "XLI 2024" (production
            // 06/10, the customer's answer was not recognised and the model
            // asked for the Corolla model again).
            $compact = fn($t) => preg_replace('/[^A-Z0-9]/u', '', $t);
            if (mb_strpos($haystack, $token) !== false
                || (strlen($compact($token)) >= 4 && strpos($compact($haystack), $compact($token)) !== false)) {
                $score += strlen($token);
                $tokensUtiles[$original] = true;
            }
        }
        if ($score > 0) {
            $scored[] = $row + ['score' => $score];
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
