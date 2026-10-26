<?php
// Retail price <-> wholesale price ("PV Gros" column of the stock file).
//
// The site's price is the PV Gros with a margin that depends on the PV Gros
// itself. The store owner's decision (13/10): the wholesale price given to
// registered wholesale customers is exactly the PV Gros, no margin - stored
// in produit.prix_gros at every stock import. Products imported before that
// column existed get an estimate from their retail price (prix_gros_estimer).

// Same scale as the previous version of the code (moved here unchanged from
// dashboard/stock.php). Known quirk, left as is until the store decides: the
// last step tests the price AFTER the previous margin, so a PV Gros between
// about 53,600 and 60,000 gets x1.12 then x1.11.
function stock_appliquer_marge(float $prixInitial): float
{
    $prix = $prixInitial;
    if ($prixInitial > 0 && $prixInitial <= 2000) {
        $prix *= 1.5;
    }
    if ($prixInitial > 2000 && $prixInitial <= 4000) {
        $prix *= 1.4;
    }
    if ($prixInitial > 4000 && $prixInitial <= 6000) {
        $prix *= 1.35;
    }
    if ($prixInitial > 6000 && $prixInitial <= 8000) {
        $prix *= 1.3;
    }
    if ($prixInitial > 8000 && $prixInitial <= 15000) {
        $prix *= 1.25;
    }
    if ($prixInitial > 15000 && $prixInitial <= 30000) {
        $prix *= 1.2;
    }
    if ($prixInitial > 30000 && $prixInitial <= 50000) {
        $prix *= 1.15;
    }
    if ($prixInitial > 50000 && $prixInitial <= 60000) {
        $prix *= 1.12;
    }
    if ($prix > 60000) {
        $prix *= 1.11;
    }
    return $prix;
}

// PV Gros that gives this retail price (produit.prix is an INT, so MySQL
// rounded the margin result). Only whole PV Gros values are tried. Near the
// scale's steps two can give the same retail price (3,000 DA = 2,000 x1.5 or
// 2,143 x1.4): 97% of the PV Gros found unambiguously are multiples of 5
// (measured 26/10 on 20,552 products), so a tie goes to the only multiple of
// 5. Otherwise null - a wrong wholesale price is worse than none.
function prix_gros_estimer(int $prix): ?int
{
    if ($prix <= 0) {
        return null;
    }
    $trouves = [];
    foreach ([1.5, 1.4, 1.35, 1.3, 1.25, 1.2, 1.15, 1.12, 1.12 * 1.11, 1.11] as $facteur) {
        $base = (int)round($prix / $facteur);
        for ($pvGros = $base - 1; $pvGros <= $base + 1; $pvGros++) {
            if ($pvGros > 0 && (int)round(stock_appliquer_marge($pvGros)) === $prix) {
                $trouves[$pvGros] = true;
            }
        }
    }
    if (count($trouves) > 1) {
        $trouves = array_filter($trouves, fn($v, $pvGros) => $pvGros % 5 === 0, ARRAY_FILTER_USE_BOTH);
    }
    return count($trouves) === 1 ? (int)array_key_first($trouves) : null;
}
