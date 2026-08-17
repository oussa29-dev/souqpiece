<?php
// Read-only data-access tools for the AI assistant.
// Every function here does SELECT only, via prepared statements. None of
// them may INSERT/UPDATE/DELETE on store tables (produit, pvd, reference,
// panier, commande, ...). The only table this layer ever writes to is
// ai_conversation, and that happens in chat.php, not here.

require_once __DIR__ . '/search_aliases.php';

// MySQL's LIKE treats % and _ as wildcards even in user-supplied text, so a
// literal underscore or percent in a search term (real example in this
// catalog: reference "550458_VW000") would silently broaden the match to
// "any character here" instead of matching literally.
function ai_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// True if every character in $term is representable in ISO-8859-1
// (latin1) - i.e. safe to bind as a query parameter against reference.reference
// or pvd.description, both latin1-encoded columns. Arabic (and most other
// non-Western-European text) is not representable and round-trips lossily
// through this conversion, which is exactly the cheap, reliable way to
// detect it without a character-by-character allow-list.
function ai_is_latin1_safe(string $term): bool
{
    $roundTrip = @mb_convert_encoding(mb_convert_encoding($term, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
    return $roundTrip === $term;
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

// Expands one search term to itself plus any known spelling variant,
// common misspelling, or Arabic/Darija translation from the small
// dictionary in search_aliases.php. Pure dictionary lookup - no fuzzy
// computation, no database access, effectively free.
function ai_expand_term_variants(string $term): array
{
    $variants = [$term];

    $arabicAliases = ai_search_arabic_aliases();
    $trimmed = trim($term);
    if (isset($arabicAliases[$trimmed])) {
        $variants = array_merge($variants, $arabicAliases[$trimmed]);
    }

    $normalized = ai_normalize_term($term);
    foreach (ai_search_synonym_groups() as $group) {
        if (in_array($normalized, $group, true)) {
            $variants = array_merge($variants, $group);
            break;
        }
    }

    return array_values(array_unique($variants));
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
    if (empty($terms)) {
        $terms = [$searchTerm];
    }

    // reference/description are latin1-encoded columns that cannot even
    // store Arabic characters - binding a non-latin1-representable term as
    // a query parameter against them throws a hard "illegal mix of
    // collations" PDOException (found while testing this exact feature: a
    // raw Arabic term crashed the reference search). Since such a term is
    // guaranteed to never match those columns anyway, skip querying them
    // instead of crashing.
    $latin1Safe = array_reduce($terms, fn($ok, $t) => $ok && ai_is_latin1_safe($t), true);

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
    $idProdsRef = [];
    if ($latin1Safe) {
        $refConditions = [];
        $refParams = [];
        foreach ($terms as $term) {
            $refConditions[] = 'reference LIKE ?';
            $refParams[] = '%' . ai_escape_like($term) . '%';
        }
        $sqlRef = $pdo->prepare('SELECT id_produit FROM reference WHERE ' . implode(' AND ', $refConditions));
        $sqlRef->execute($refParams);
        $idProdsRef = $sqlRef->fetchAll(PDO::FETCH_COLUMN);
    }

    // description/libelle expand each term to known spelling variants,
    // synonyms and Arabic/Darija translations (each term's variants OR'd
    // together, terms still AND'd together) - reference numbers do not,
    // since expanding e.g. "demarreur" synonyms into an OEM-code search is
    // meaningless and would only add noise/cost with zero benefit.
    $idProdsDesc = [];
    if ($latin1Safe) {
        // pvd.description is also latin1 - same crash risk as reference.
        $descConditions = [];
        $descParams = [];
        foreach ($terms as $term) {
            $orParts = [];
            foreach (ai_expand_term_variants($term) as $variant) {
                $orParts[] = 'description LIKE ?';
                $descParams[] = '%' . ai_escape_like($variant) . '%';
            }
            $descConditions[] = '(' . implode(' OR ', $orParts) . ')';
        }
        $sqlDesc = $pdo->prepare('SELECT DISTINCT pvd.id_produit FROM pvd WHERE ' . implode(' AND ', $descConditions));
        $sqlDesc->execute($descParams);
        $idProdsDesc = $sqlDesc->fetchAll(PDO::FETCH_COLUMN);
    }

    // produit.libelle is utf8mb4 - safe for Arabic/any script, always searched.
    $libConditions = [];
    $libParams = [];
    foreach ($terms as $term) {
        $orParts = [];
        foreach (ai_expand_term_variants($term) as $variant) {
            $orParts[] = 'libelle LIKE ?';
            $libParams[] = '%' . ai_escape_like($variant) . '%';
        }
        $libConditions[] = '(' . implode(' OR ', $orParts) . ')';
    }
    $sqlLib = $pdo->prepare('SELECT id_produit FROM produit WHERE ' . implode(' AND ', $libConditions));
    $sqlLib->execute($libParams);
    $idProdsLib = $sqlLib->fetchAll(PDO::FETCH_COLUMN);

    $idProds = array_values(array_unique(array_merge($idProdsRef, $idProdsDesc, $idProdsLib)));

    if (empty($idProds)) {
        // Alias-expanded exact search found nothing - last-resort bounded
        // fuzzy fallback (see ai_fuzzy_libelle_fallback docblock).
        $idProds = ai_fuzzy_libelle_fallback($pdo, implode(' ', $terms), $limit);
        if (empty($idProds)) {
            return [];
        }
    }

    $conditions = ['produit.id_produit IN (' . implode(',', array_fill(0, count($idProds), '?')) . ')'];
    $params = $idProds;

    // V1 explicitly excludes zero-priced rows from assistant results.
    $conditions[] = 'produit.prix > 0';

    if ($id_voiture !== null) {
        $conditions[] = 'pvd.id_voiture = ?';
        $params[] = $id_voiture;
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
    $orderBy = ($min_price !== null || $max_price !== null)
        ? 'produit.prix ASC, produit.stock DESC, produit.id_produit ASC'
        : 'produit.stock DESC, produit.id_produit ASC';

    $sql = '
        SELECT
            produit.id_produit, produit.libelle, produit.marquepiece,
            produit.prix, produit.stock, produit.img1,
            pvd.id_voiture AS voiture_id,
            v.modele, m.libelle AS marque_nom, c.libelle AS categorie_nom
        FROM produit
        LEFT JOIN pvd ON produit.id_produit = pvd.id_produit
        LEFT JOIN voiture v ON pvd.id_voiture = v.id_voiture
        LEFT JOIN marque m ON v.id_marque = m.id_marque
        LEFT JOIN categorie c ON produit.id_categorie = c.id_categorie
        WHERE ' . implode(' AND ', $conditions) . '
        GROUP BY produit.id_produit
        ORDER BY ' . $orderBy . '
        LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['url'] = 'produit.php?id=' . $row['id_produit']
            . (!empty($row['voiture_id']) ? '&id_voiture=' . $row['voiture_id'] : '');
    }
    return $rows;
}

function ai_lookup_by_reference(PDO $pdo, string $reference): array
{
    $reference = trim($reference);

    $sql = 'SELECT r.reference, p.id_produit, p.libelle, p.marquepiece, p.prix, p.stock
            FROM reference r
            JOIN produit p ON p.id_produit = r.id_produit
            WHERE r.reference = ? AND p.prix > 0
            ORDER BY p.stock DESC
            LIMIT 20';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reference]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $sql = 'SELECT r.reference, p.id_produit, p.libelle, p.marquepiece, p.prix, p.stock
                FROM reference r
                JOIN produit p ON p.id_produit = r.id_produit
                WHERE r.reference LIKE ? AND p.prix > 0
                ORDER BY p.stock DESC
                LIMIT 20';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['%' . ai_escape_like($reference) . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Group by marquepiece so the caller can present alternatives clearly
    // instead of picking one silently (43.6% of references are ambiguous).
    $grouped = [];
    foreach ($rows as $row) {
        $row['url'] = 'produit.php?id=' . $row['id_produit'];
        $brand = $row['marquepiece'] !== '' ? $row['marquepiece'] : 'NON SPECIFIE';
        $grouped[$brand][] = $row;
    }
    return $grouped;
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
        $stmt = $pdo->query('SELECT v.id_voiture, v.modele, m.libelle AS marque
                              FROM voiture v JOIN marque m ON m.id_marque = v.id_marque');
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $needle = mb_strtoupper(trim($free_text));
    $needleTokens = array_filter(preg_split('/\s+/', $needle));

    $scored = [];
    foreach ($all as $row) {
        $haystack = mb_strtoupper($row['marque'] . ' ' . $row['modele']);
        $score = 0;
        foreach ($needleTokens as $token) {
            if ($token !== '' && mb_strpos($haystack, $token) !== false) {
                $score += strlen($token);
            }
        }
        if ($score > 0) {
            $scored[] = $row + ['score' => $score];
        }
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $matches = array_slice($scored, 0, 5);

    $unique = count($matches) <= 1
        || (count($matches) >= 2 && $matches[0]['score'] > $matches[1]['score']);

    return ['unique' => $unique, 'matches' => $matches];
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
        'stock' => $produit['stock'],
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
