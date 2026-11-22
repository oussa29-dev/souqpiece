<?php
// Products shown on the home page (index.php, "Découvrez nos produits"):
// for each category, the products with produit.trie > 0, ordered by that
// number, 8 at most. The number is set in the product form (add and edit):
// 1 to 8, empty = not on the home page. Store owner, 22/11: "قدام ارتكيل رقم
// من 1-8، اللي عندو ترقيم يخرج في الموقع" - the field had been taken out of
// the form on 27/08 (commit 19ac297), so the home page could no longer be
// changed.

const ACCUEIL_MAX = 8;

// Number typed in the form: 0 when empty (not on the home page), 1..8, or
// null when invalid.
function accueil_lire_numero($valeur): ?int
{
    $valeur = trim((string)$valeur);
    if ($valeur === '' || $valeur === '0') {
        return 0;
    }
    if (!ctype_digit($valeur) || (int)$valeur < 1 || (int)$valeur > ACCUEIL_MAX) {
        return null;
    }
    return (int)$valeur;
}

// The number belongs to one product per category: whoever had it in this
// category leaves the home page (trie = 0). Returns their names, for the
// message after saving.
function accueil_liberer_numero(PDO $pdo, int $idCategorie, int $numero, int $idProduitGarde): array
{
    if ($numero < 1) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT libelle FROM produit WHERE id_categorie = ? AND trie = ? AND id_produit <> ?');
    $stmt->execute([$idCategorie, $numero, $idProduitGarde]);
    $noms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($noms) {
        $pdo->prepare('UPDATE produit SET trie = 0 WHERE id_categorie = ? AND trie = ? AND id_produit <> ?')
            ->execute([$idCategorie, $numero, $idProduitGarde]);
    }
    return array_map('trim', $noms);
}

// What each category shows on the home page now: [id_categorie => [[trie,
// id_produit, libelle], ...]], in the home page's order.
function accueil_par_categorie(PDO $pdo): array
{
    $parCategorie = [];
    foreach ($pdo->query('SELECT id_produit, libelle, trie, id_categorie FROM produit WHERE trie > 0 ORDER BY id_categorie, trie, id_produit') as $p) {
        $parCategorie[(int)$p['id_categorie']][] = $p;
    }
    return $parCategorie;
}

// The form field, and under it what the chosen category already shows (it
// follows the category select, id "categorieSelect").
function accueil_champ(PDO $pdo, int $valeur, ?int $idProduit): void
{
    $parCategorie = accueil_par_categorie($pdo);
    ?>
    <label for="trie">Numéro sur la page d'accueil (1 à <?= ACCUEIL_MAX ?>)</label>
    <input type="number" name="trie" id="trie" min="1" max="<?= ACCUEIL_MAX ?>" placeholder="vide = pas sur l'accueil" value="<?= $valeur > 0 ? $valeur : '' ?>">
    <p style="font-size:13px;color:#555;margin:2px 0 6px">Le produit apparaît sur la page d'accueil, dans sa catégorie, à cette place. Si un autre produit de la même catégorie a déjà ce numéro, il est retiré de l'accueil.</p>
    <div id="accueil-actuel" style="font-size:13px;margin:0 0 14px"></div>
    <script>
    (function () {
        var parCategorie = <?= json_encode(array_map(fn($liste) => array_map(fn($p) => ['n' => (int)$p['trie'], 'id' => (int)$p['id_produit'], 'nom' => trim($p['libelle'])], $liste), $parCategorie), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        var idProduit = <?= (int)$idProduit ?>;
        var select = document.getElementById('categorieSelect');
        var zone = document.getElementById('accueil-actuel');
        function afficher() {
            var liste = (parCategorie[select ? select.value : ''] || []);
            zone.innerHTML = '';
            var titre = document.createElement('b');
            titre.textContent = liste.length ? 'Déjà sur l\'accueil dans cette catégorie :' : 'Aucun produit de cette catégorie sur l\'accueil.';
            zone.appendChild(titre);
            liste.forEach(function (p) {
                var ligne = document.createElement('div');
                ligne.textContent = p.n + '. ' + p.nom + (p.id === idProduit ? '  (ce produit)' : '');
                zone.appendChild(ligne);
            });
        }
        if (select) { select.addEventListener('change', afficher); }
        afficher();
    })();
    </script>
    <?php
}
