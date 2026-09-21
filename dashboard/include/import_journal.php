<?php
// ---------------------------------------------------------------
// Journal des imports (voir db/import_journal.sql). Toutes ces
// fonctions sont volontairement tolerantes : si la table n'existe pas
// encore (code deploye avant la migration) ou si une ecriture echoue,
// l'import lui-meme continue normalement - le journal n'a jamais le
// droit de bloquer un import.
// ---------------------------------------------------------------
function stock_journal_debut(PDO $pdo, string $type, string $nomFichier, int $taille): ?int
{
    try {
        $utilisateur = isset($_SESSION['utilisateur']['login']) ? (string)$_SESSION['utilisateur']['login'] : null;
        $stmt = $pdo->prepare("INSERT INTO import_journal (type_import, nom_fichier, taille_octets, statut, utilisateur) VALUES (?, ?, ?, 'en_cours', ?)");
        if (!$stmt->execute([$type, mb_substr($nomFichier, 0, 255), $taille, $utilisateur])) {
            return null;
        }
        return (int)$pdo->lastInsertId() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// Marque une ligne du journal comme terminee (et memorise qu'elle
// l'est, pour que le garde-fou d'arret ci-dessous ne la re-touche pas).
function stock_journal_fin(PDO $pdo, ?int $id, string $statut, array $stats = [], ?string $message = null): void
{
    if ($id === null) {
        return;
    }
    stock_journal_est_termine($id, true);
    try {
        $stmt = $pdo->prepare('UPDATE import_journal SET statut = ?, lignes_lues = ?, crees = ?, mis_a_jour = ?, anomalies = ?, message = ?, fin = NOW() WHERE id_import_journal = ?');
        $stmt->execute([
            $statut,
            $stats['lignes_lues'] ?? null,
            $stats['crees'] ?? null,
            $stats['maj'] ?? null,
            $stats['anomalies'] ?? null,
            $message !== null ? mb_substr($message, 0, 500) : (isset($stats['detail']) ? mb_substr((string)$stats['detail'], 0, 500) : null),
            $id,
        ]);
    } catch (Throwable $e) {
        // journal indisponible : on ne casse rien
    }
}

function stock_journal_est_termine(int $id, bool $marquer = false): bool
{
    static $termines = [];
    if ($marquer) {
        $termines[$id] = true;
    }
    return isset($termines[$id]);
}

// Si la requete meurt avant la fin (erreur fatale PHP, delai depasse,
// connexion coupee), aucun code de l'import ne s'execute plus : ce
// filet, lui, tourne quand meme a l'arret du script et note la cause
// reelle (ex. "Call to undefined function str_starts_with()") au lieu
// de laisser une page figee sans explication. La transaction de
// l'import est encore ouverte a ce stade et sera de toute facon
// annulee : on la ferme d'abord, sinon la mise a jour du journal
// partirait avec elle.
// Ne retient comme cause qu'une VRAIE erreur fatale. error_get_last()
// renvoie aussi les simples avertissements passes plus tot dans la requete,
// meme ceux masques par un "@" (mesure : un import interrompu par la
// fermeture de l'onglet a d'abord ete journalise avec le texte d'un
// ancien avertissement ini_set() sans rapport - pire qu'aucune cause).
// $err = resultat de error_get_last().
function stock_journal_cause_arret(?array $err): string
{
    $fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if ($err && in_array($err['type'], $fatales, true)) {
        return $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')';
    }
    return "Le script s'est arrêté avant la fin (connexion coupée ou délai dépassé).";
}

function stock_journal_surveiller_arret(PDO $pdo, ?int $id): void
{
    if ($id === null) {
        return;
    }
    register_shutdown_function(function () use ($pdo, $id) {
        if (stock_journal_est_termine($id)) {
            return;
        }
        $cause = stock_journal_cause_arret(error_get_last());
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $stmt = $pdo->prepare("UPDATE import_journal SET statut = 'echec', message = ?, fin = NOW() WHERE id_import_journal = ? AND statut = 'en_cours'");
            $stmt->execute([mb_substr($cause, 0, 500), $id]);
        } catch (Throwable $e) {
        }
    });
}

// Derniers imports, affiches sur la page : permet de voir d'un coup
// d'oeil si un import a vraiment tourne, sur quel fichier, et comment
// il s'est termine.
function stock_journal_afficher(PDO $pdo): void
{
    try {
        $lignes = $pdo->query('SELECT *, (debut < NOW() - INTERVAL 30 MINUTE) AS ancien FROM import_journal ORDER BY id_import_journal DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    if (!$lignes) {
        return;
    }
    $noms = ['stock' => 'Stock complet', 'ventes' => 'Ventes du jour', 'achats' => 'Achats du jour', 'photos' => 'Photos'];
    echo '<div class="page-voiture"><h1>Derniers imports</h1>';
    echo '<table style="border-collapse:collapse;font-size:13px;width:100%;max-width:980px;">';
    echo '<tr style="text-align:left;background:#eee;"><th style="padding:5px 8px;">Date</th><th style="padding:5px 8px;">Type</th><th style="padding:5px 8px;">Fichier</th><th style="padding:5px 8px;">Résultat</th><th style="padding:5px 8px;">Détail</th></tr>';
    foreach ($lignes as $l) {
        switch ($l['statut']) {
            case 'termine':
                $etat = ['Terminé', 'green'];
                break;
            case 'vide':
                $etat = ['Fichier vide : rien importé', '#d35400'];
                break;
            case 'annule':
                $etat = ['Annulé', '#c0392b'];
                break;
            case 'echec':
                $etat = ['Échec', '#c0392b'];
                break;
            default:
                $etat = $l['ancien'] ? ['Interrompu (aucune fin enregistrée)', '#c0392b'] : ['En cours…', '#555'];
        }
        $detail = [];
        if ($l['lignes_lues'] !== null) {
            $detail[] = $l['lignes_lues'] . ' ligne(s) lue(s)';
        }
        if ($l['crees']) {
            $detail[] = $l['crees'] . ' créé(s)';
        }
        if ($l['mis_a_jour'] !== null) {
            $detail[] = $l['mis_a_jour'] . ' mis à jour';
        }
        if ($l['anomalies']) {
            $detail[] = $l['anomalies'] . ' anomalie(s)';
        }
        if ($l['message']) {
            $detail[] = preg_replace('/\s+/', ' ', $l['message']);
        }
        echo '<tr style="border-top:1px solid #ddd;">'
            . '<td style="padding:5px 8px;white-space:nowrap;">' . htmlspecialchars(date('d/m H:i', strtotime($l['debut']))) . '</td>'
            . '<td style="padding:5px 8px;">' . htmlspecialchars($noms[$l['type_import']] ?? $l['type_import']) . '</td>'
            . '<td style="padding:5px 8px;">' . htmlspecialchars($l['nom_fichier']) . ' <span style="color:#888;">(' . round($l['taille_octets'] / 1024, 1) . ' Ko)</span></td>'
            . '<td style="padding:5px 8px;color:' . $etat[1] . ';font-weight:bold;">' . htmlspecialchars($etat[0]) . '</td>'
            . '<td style="padding:5px 8px;color:#555;">' . htmlspecialchars(implode(' · ', $detail)) . '</td>'
            . '</tr>';
    }
    echo '</table></div>';
}
