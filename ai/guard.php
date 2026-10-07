<?php
// The assistant may only show products the catalog actually returned during
// the turn. Logged production case (01/10, "Plaquet revo" x3): Gemini
// Flash-Lite answered with a list of plaquettes, prices and links without
// calling any tool - the store owner saw invented results. A prompt rule
// already forbade this; this makes it a code guarantee.

// Every id_produit present anywhere in a tool result.
function ai_ids_produits_resultat($resultat, array &$ids): void
{
    if (!is_array($resultat)) {
        return;
    }
    foreach ($resultat as $cle => $valeur) {
        if ($cle === 'id_produit' && is_scalar($valeur)) {
            $ids[(int)$valeur] = true;
        } elseif (is_array($valeur)) {
            ai_ids_produits_resultat($valeur, $ids);
        }
    }
}

// id_produit => reference, for every "close reference" row (reference_proche)
// in a tool result.
function ai_refs_proches_resultat($resultat, array &$refs): void
{
    if (!is_array($resultat)) {
        return;
    }
    if (!empty($resultat['reference_proche']) && isset($resultat['id_produit'], $resultat['reference']) && !isset($refs[(int)$resultat['id_produit']])) {
        $refs[(int)$resultat['id_produit']] = trim((string)$resultat['reference']);
    }
    foreach ($resultat as $valeur) {
        if (is_array($valeur)) {
            ai_refs_proches_resultat($valeur, $refs);
        }
    }
}

// A close-reference suggestion is only useful if the customer can compare
// its reference with theirs, but the model drops it from the line even when
// told to keep it (store owner's 0C380-16400 case, 07/10): appended in code.
function ai_ajouter_refs_proches(string $texte, array $refs): string
{
    if (!$refs) {
        return $texte;
    }
    return implode("\n", array_map(function ($ligne) use ($refs) {
        foreach (ai_ids_produits_reponse($ligne) as $id) {
            // Letters and digits only: "16400-0C180" already on the line
            // covers the stored "16400-0C180/-".
            $compacter = fn($t) => preg_replace('/[^A-Z0-9]/', '', strtoupper($t));
            if (isset($refs[$id]) && strpos($compacter($ligne), $compacter($refs[$id])) === false) {
                $ajout = ' (réf. ' . $refs[$id] . ')';
                $pos = strpos($ligne, ' - [');
                return $pos !== false ? substr_replace($ligne, $ajout, $pos, 0) : $ligne . $ajout;
            }
        }
        return $ligne;
    }, preg_split('/\R/u', $texte)));
}

// Product ids linked in a reply ("produit.php?id=123...").
function ai_ids_produits_reponse(string $texte): array
{
    preg_match_all('/produit\.php\?id=(\d+)/', $texte, $m);
    return array_map('intval', $m[1]);
}

// Same contract as LlmProvider::converse(), plus the guarantees. One retry
// with an explicit instruction when the reply links a product no tool
// returned in this turn; if the retry still does, those lines are removed.
//
// $vehiculesConnus: id_voiture already used by earlier turns of this
// session. A tool call may only filter on a vehicle resolve_vehicle returned
// in this turn, or one of those - production 06/10: for a Vigo LAN15 the
// model skipped resolve_vehicle and searched id 333 (no such vehicle) then
// 76 (the Coaster, copied from a prompt example), showing Coaster culasses.
function ai_converse_verifie(LlmProvider $provider, string $systemPrompt, array $history, string $message, array $schemas, callable $dispatcher, array $vehiculesConnus = []): array
{
    $ids = [];
    $refsProches = [];
    $vehiculesAutorises = array_fill_keys(array_map('intval', $vehiculesConnus), true);
    $dispatcherSuivi = function (string $name, array $args) use ($dispatcher, &$ids, &$refsProches, &$vehiculesAutorises) {
        $idVoiture = (int)($args['id_voiture'] ?? 0);
        if ($name !== 'resolve_vehicle' && $idVoiture > 0 && !isset($vehiculesAutorises[$idVoiture])) {
            error_log("ai: id_voiture $idVoiture not returned by resolve_vehicle, call refused");
            return ['error' => "id_voiture $idVoiture was not returned by resolve_vehicle for this customer. Call resolve_vehicle with the customer's vehicle words first, then use an id_voiture from its matches. Never guess an id_voiture."];
        }
        $resultat = $dispatcher($name, $args);
        if ($name === 'resolve_vehicle') {
            foreach ($resultat['matches'] ?? [] as $match) {
                $vehiculesAutorises[(int)$match['id_voiture']] = true;
            }
        }
        ai_ids_produits_resultat($resultat, $ids);
        ai_refs_proches_resultat($resultat, $refsProches);
        return $resultat;
    };

    $result = $provider->converse($systemPrompt, $history, $message, $schemas, $dispatcherSuivi);
    $inventes = array_diff(ai_ids_produits_reponse($result['text']), array_keys($ids));
    if ($inventes === []) {
        $result['text'] = ai_ajouter_refs_proches($result['text'], $refsProches);
        return $result;
    }

    error_log('ai: reply listed products not returned by any tool (' . implode(',', $inventes) . '), retrying');
    $ids = [];
    $rappel = "\n\nIMPORTANT for this reply: your previous attempt listed products without getting them from a tool in this turn. Call search_products or lookup_by_reference now and list ONLY products those tools return - never copy products from earlier messages or write them from memory.";
    $retry = $provider->converse($systemPrompt . $rappel, $history, $message, $schemas, $dispatcherSuivi);
    $retry['tools_called'] = array_merge($result['tools_called'] ?? [], $retry['tools_called'] ?? []);

    $valides = array_keys($ids);
    // /u is required: without it \R also matches the byte 0x85, the second
    // byte of the Arabic letter م - production 06/10, "متوفر" was stored as
    // "?\nتوفر" after a retry.
    $lignes = preg_split('/\R/u', $retry['text']);
    $gardees = array_filter($lignes, function ($ligne) use ($valides) {
        foreach (ai_ids_produits_reponse($ligne) as $id) {
            if (!in_array($id, $valides, true)) {
                return false;
            }
        }
        return true;
    });
    if (count($gardees) < count($lignes)) {
        error_log('ai: retry still listed unreturned products, ' . (count($lignes) - count($gardees)) . ' line(s) removed');
    }
    $retry['text'] = trim(implode("\n", $gardees));
    if ($retry['text'] === '') {
        $retry['text'] = "عذراً، لم أتمكن من التحقق من هذه القطعة في الكتالوج. أعد صياغة الطلب أو اتصل بالمحل. / Désolé, je n'ai pas pu vérifier cette pièce dans le catalogue. Reformulez ou contactez le magasin.";
    }
    $retry['text'] = ai_ajouter_refs_proches($retry['text'], $refsProches);
    return $retry;
}
