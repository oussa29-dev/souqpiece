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

// Product ids linked in a reply ("produit.php?id=123...").
function ai_ids_produits_reponse(string $texte): array
{
    preg_match_all('/produit\.php\?id=(\d+)/', $texte, $m);
    return array_map('intval', $m[1]);
}

// Same contract as LlmProvider::converse(), plus the guarantee. One retry
// with an explicit instruction when the reply links a product no tool
// returned in this turn; if the retry still does, those lines are removed.
function ai_converse_verifie(LlmProvider $provider, string $systemPrompt, array $history, string $message, array $schemas, callable $dispatcher): array
{
    $ids = [];
    $dispatcherSuivi = function (string $name, array $args) use ($dispatcher, &$ids) {
        $resultat = $dispatcher($name, $args);
        ai_ids_produits_resultat($resultat, $ids);
        return $resultat;
    };

    $result = $provider->converse($systemPrompt, $history, $message, $schemas, $dispatcherSuivi);
    $inventes = array_diff(ai_ids_produits_reponse($result['text']), array_keys($ids));
    if ($inventes === []) {
        return $result;
    }

    error_log('ai: reply listed products not returned by any tool (' . implode(',', $inventes) . '), retrying');
    $ids = [];
    $rappel = "\n\nIMPORTANT for this reply: your previous attempt listed products without getting them from a tool in this turn. Call search_products or lookup_by_reference now and list ONLY products those tools return - never copy products from earlier messages or write them from memory.";
    $retry = $provider->converse($systemPrompt . $rappel, $history, $message, $schemas, $dispatcherSuivi);
    $retry['tools_called'] = array_merge($result['tools_called'] ?? [], $retry['tools_called'] ?? []);

    $valides = array_keys($ids);
    $lignes = preg_split('/\R/', $retry['text']);
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
    return $retry;
}
