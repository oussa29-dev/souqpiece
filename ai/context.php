<?php
// Per-turn context appended to the system prompt by chat.php: store contact
// details, and what the conversation has already established (vehicle,
// part) - history only carries message text, so without this the model
// re-asked for the vehicle or dropped it on follow-ups (logged cases:
// "Nsp130" -> asked the part again; "Vilbroqeun" after "Vilb coaster"
// searched every vehicle).

// $historyRows: ai_conversation rows (role, message, tools_called), oldest
// first - the same window chat.php already sends as history.
function ai_session_context(PDO $pdo, array $historyRows): string
{
    $idVoiture = null;
    $partie = null;
    $vehiculeTexte = null;
    $vehiculeFixe = false;
    $partieFixee = false;

    // Newest call first. The FIRST vehicle signal met decides the current
    // vehicle: a resolve_vehicle that was not yet followed by a search means
    // the customer moved on to another car, and any older id_voiture belongs
    // to the previous request. Logged production bug (01/10): "Compresseur
    // corolla" -> Nde180 -> "Dem yaris" -> "Yaris 2" -> "Ncp90" kept
    // reporting the Corolla (id 54) as identified, and the model answered
    // "Ncp90" with an invented id 57 (a Revo).
    foreach (array_reverse($historyRows) as $row) {
        if ($row['role'] !== 'assistant' || empty($row['tools_called'])) {
            continue;
        }
        $appels = json_decode($row['tools_called'], true);
        if (!is_array($appels)) {
            continue;
        }
        foreach (array_reverse($appels) as $appel) {
            $nom = $appel['name'] ?? '';
            $args = $appel['args'] ?? [];
            $idAppel = (int)($args['id_voiture'] ?? 0);

            if (!$vehiculeFixe) {
                if ($idAppel > 0) {
                    $idVoiture = $idAppel;
                    $vehiculeFixe = true;
                } elseif ($nom === 'resolve_vehicle' && !empty($args['free_text'])) {
                    $vehiculeTexte = (string)$args['free_text'];
                    $vehiculeFixe = true;
                }
            }

            if (!$partieFixee && $nom === 'search_products' && !empty($args['query'])) {
                $partieFixee = true;
                // A part searched for a vehicle other than the current one
                // belongs to an earlier request - the customer named a new
                // part since, which the model reads from the messages. A part
                // searched before any vehicle was known stays ("Plateau" ->
                // "Yaris" -> "Nsp130").
                if ($idAppel === 0 || $idAppel === $idVoiture) {
                    $partie = (string)$args['query'];
                }
            }
        }
        if ($vehiculeFixe && $partieFixee) {
            break;
        }
    }

    if ($idVoiture === null && $partie === null && $vehiculeTexte === null) {
        return '';
    }

    $lignes = [];
    if ($idVoiture === null && $vehiculeTexte !== null) {
        $lignes[] = '- Vehicle words given so far, not yet narrowed to one model: "' . $vehiculeTexte . '" - combine them with the customer\'s next answer (e.g. "' . $vehiculeTexte . ' 4").';
    }
    if ($idVoiture !== null) {
        $stmt = $pdo->prepare('SELECT m.libelle AS marque, v.modele, v.annee_debut, v.annee_fin FROM voiture v JOIN marque m ON m.id_marque = v.id_marque WHERE v.id_voiture = ?');
        $stmt->execute([$idVoiture]);
        if ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $label = trim($v['marque'] . ' ' . preg_replace('/\s+/', ' ', $v['modele']))
                . ($v['annee_debut'] !== null ? ' (' . $v['annee_debut'] . '-' . $v['annee_fin'] . ')' : '');
            $lignes[] = "- Vehicle already identified: $label, id_voiture=$idVoiture.";
        }
    }
    if ($partie !== null) {
        $lignes[] = '- Last part searched: "' . $partie . '".';
    }

    return "\n\nConversation so far (from earlier turns of this session):\n" . implode("\n", $lignes) . "\n"
        . "Use this for follow-up messages: a bare part name (\"Vilbroqeun\", \"Demareur\") means that part for this same vehicle - search with this id_voiture, do not ask for the vehicle again. A bare vehicle answer (\"Nsp130\") means the last part searched, for that vehicle - search it directly, do not ask which part. Only drop this context when the customer names a different vehicle or a clearly unrelated request.";
}

// Every id_voiture an earlier turn of this session actually filtered on -
// vehicles already validated, that a follow-up may reuse without calling
// resolve_vehicle again (see ai_converse_verifie in guard.php).
function ai_vehicules_connus(array $historyRows): array
{
    $ids = [];
    foreach ($historyRows as $row) {
        foreach ((array)json_decode((string)($row['tools_called'] ?? ''), true) as $appel) {
            $id = (int)($appel['args']['id_voiture'] ?? 0);
            if ($id > 0 && ($appel['name'] ?? '') !== '_error') {
                $ids[$id] = true;
            }
        }
    }
    return array_keys($ids);
}

// Store contact details for questions about the shop itself. Phone, name
// and Facebook come from the same `setting` row the site footer shows (the
// boss edits it in Dashboard > Parametre), so they never drift from the
// site. Hours, address and wholesale policy are not in that table: they
// come from $config['store_info'] once the store has provided them.
function ai_store_info_block(PDO $pdo, array $config): string
{
    $lignes = [];
    $setting = $pdo->query('SELECT nom, tel, facebook FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($setting) {
        if (trim((string)$setting['nom']) !== '') {
            $lignes[] = 'Store name: ' . trim($setting['nom']);
        }
        if (trim((string)$setting['tel']) !== '') {
            $lignes[] = 'Phone: 0' . ltrim(trim($setting['tel']), '0');
        }
        if (trim((string)$setting['facebook']) !== '') {
            $lignes[] = 'Facebook: ' . trim($setting['facebook']);
        }
    }
    $extra = trim((string)($config['store_info'] ?? ''));
    if ($extra !== '') {
        $lignes[] = $extra;
    }

    return "\n\nStore information (the only facts you may give about the store itself):\n" . implode("\n", $lignes) . "\n"
        . "For any store question not answered above (opening hours, address, wholesale conditions, payment...), say you don't have that detail and give the phone number above. Never invent store details.";
}
