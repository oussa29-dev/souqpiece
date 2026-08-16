<?php
// Read-only data-access tools for the AI assistant.
// Every function here does SELECT only, via prepared statements. None of
// them may INSERT/UPDATE/DELETE on store tables (produit, pvd, reference,
// panier, commande, ...). The only table this layer ever writes to is
// ai_conversation, and that happens in chat.php, not here.

// MySQL's LIKE treats % and _ as wildcards even in user-supplied text, so a
// literal underscore or percent in a search term (real example in this
// catalog: reference "550458_VW000") would silently broaden the match to
// "any character here" instead of matching literally.
function ai_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

    $refConditions = [];
    $refParams = [];
    foreach ($terms as $term) {
        $refConditions[] = 'reference LIKE ?';
        $refParams[] = '%' . ai_escape_like($term) . '%';
    }
    $sqlRef = $pdo->prepare('SELECT id_produit FROM reference WHERE ' . implode(' AND ', $refConditions));
    $sqlRef->execute($refParams);
    $idProdsRef = $sqlRef->fetchAll(PDO::FETCH_COLUMN);

    $descConditions = [];
    $descParams = [];
    foreach ($terms as $term) {
        $descConditions[] = 'description LIKE ?';
        $descParams[] = '%' . ai_escape_like($term) . '%';
    }
    $sqlDesc = $pdo->prepare('SELECT DISTINCT pvd.id_produit FROM pvd WHERE ' . implode(' AND ', $descConditions));
    $sqlDesc->execute($descParams);
    $idProdsDesc = $sqlDesc->fetchAll(PDO::FETCH_COLUMN);

    $libConditions = [];
    $libParams = [];
    foreach ($terms as $term) {
        $libConditions[] = 'libelle LIKE ?';
        $libParams[] = '%' . ai_escape_like($term) . '%';
    }
    $sqlLib = $pdo->prepare('SELECT id_produit FROM produit WHERE ' . implode(' AND ', $libConditions));
    $sqlLib->execute($libParams);
    $idProdsLib = $sqlLib->fetchAll(PDO::FETCH_COLUMN);

    $idProds = array_values(array_unique(array_merge($idProdsRef, $idProdsDesc, $idProdsLib)));
    if (empty($idProds)) {
        return [];
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
    // than default stock-first ordering.
    $orderBy = ($min_price !== null || $max_price !== null)
        ? 'produit.prix ASC, produit.stock DESC'
        : 'produit.stock DESC';

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
    return array_slice($scored, 0, 5);
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
