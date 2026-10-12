<?php
/**
 * ASSOKIT — Export RGPD des données personnelles (art. 20 portabilité)
 * URL : /rgpd-export — télécharge un JSON des données du user connecté
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();

$current = current_user();
$uid   = (int)$current['id'];
$email = $current['email'] ?? '';

// Incarnation (support Fondateur) : on ne télécharge pas les données personnelles de la personne incarnée
if (!empty($_SESSION['impersonation_target_user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Export indisponible pendant une incarnation : seule la personne elle-même peut télécharger ses données.');
}

$export = [
    'export_genere_le' => date('c'),
    'plateforme'       => 'Assokit',
    'mention'          => "Export de vos données personnelles conformément au RGPD (art. 15 & 20).",
];

// Helper : requête sûre (table absente → rubrique vide). L'erreur est journalisée : une
// colonne mal nommée vidait la rubrique des messages sans que personne ne le voie.
$grab = function(string $sql, array $params) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[rgpd-export] ' . $e->getMessage());
        return [];
    }
};

// 1) Profil (sans secrets)
try {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['password_hash','ics_token','must_change_password','totp_secret','totp_backup_codes'] as $secret) unset($u[$secret]);
    $export['profil'] = $u;
} catch (Throwable $e) { $export['profil'] = []; }

// 2) Ce que la personne a écrit ou déposé, et ce qui la concerne directement (pas de secrets).
//    Colonnes de la base de production (snapshot demo-sql, migrations, requêtes d'écriture du site).
//    Les messages supprimés (suppression douce) sont encore conservés : ils figurent dans l'export
//    avec leur date de suppression.
$org_id = (int)($u['org_id'] ?? ($current['org_id'] ?? 0));
$export['mes_messages']          = $grab("SELECT id, project_id, content, created_at FROM project_messages WHERE author_id = ? ORDER BY created_at DESC LIMIT 1000", [$uid]);
$export['mes_messages_canaux']   = $grab("SELECT id, channel_id, content, created_at, updated_at, deleted_at FROM channel_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 2000", [$uid]);
$export['mes_points_avancement'] = $grab("SELECT id, project_id, content, progress_percent, created_at FROM project_updates WHERE author_id = ? ORDER BY created_at DESC LIMIT 1000", [$uid]);
$export['mes_projets']           = $grab("SELECT project_id, role_in_project, joined_at FROM project_members WHERE user_id = ? LIMIT 1000", [$uid]);
$export['mes_fichiers']          = $grab("SELECT id, project_id, filename, filesize_bytes, created_at FROM project_files WHERE uploaded_by = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_documents_ia']      = $grab("SELECT id, project_id, doc_type, title, created_at FROM ai_generated_docs WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_reponses_agenda']   = $grab("SELECT event_id, response, responded_at, created_at FROM event_participants WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_reponses_evenements'] = $grab("SELECT event_id, response, nb_accompanying, comment, responded_at FROM communication_event_rsvps WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_absences']          = $grab("SELECT id, type, start_date, end_date, reason, is_approved, approved_at, created_at FROM assokit_absences WHERE user_id = ? ORDER BY start_date DESC LIMIT 1000", [$uid]);
$export['mes_plannings']         = $grab("SELECT id, title, type, recurrence, day_of_week, specific_date, start_time, end_time, location, notes, created_at FROM assokit_schedules WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_notes_de_frais']    = $grab("SELECT id, title, status, total_cents, note, decided_at, created_at FROM expense_reports WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
// Cotisations : rattachées au compte, ou saisies à son adresse par SON association (jamais celles d'une autre structure)
$export['mes_cotisations']       = $grab("SELECT id, campaign_id, payer_name, payer_email, amount, currency, payment_method, status, reference, paid_at FROM cotisation_payments WHERE adherent_id = ? OR (org_id = ? AND ? <> '' AND payer_email = ?) ORDER BY id DESC LIMIT 1000", [$uid, $org_id, $email, $email]);
// Support : ses tickets et ses messages (les notes internes de l'équipe support ne sont pas des échanges avec la personne)
$export['mes_tickets_support']   = $grab("SELECT id, title, category, status, created_at, closed_at FROM support_tickets WHERE created_by_user_id = ? ORDER BY id DESC LIMIT 500", [$uid]);
$export['mes_messages_support']  = $grab("SELECT id, ticket_id, body, created_at FROM support_messages WHERE author_user_id = ? AND is_internal_note = 0 ORDER BY id DESC LIMIT 2000", [$uid]);
// Historique : les lignes du compte, plus les essais de connexion ratés à son adresse (journalisés sans
// compte), seulement depuis que l'adresse est la sienne (création du compte ou dernier changement
// d'adresse) : avant, elle a pu appartenir à un autre compte, d'une autre association.
$addr_since = (string)($u['created_at'] ?? '');
try {
    $st = $pdo->prepare("SELECT MAX(created_at) FROM assokit_activity_log WHERE user_id = ? AND event_type = 'action' AND event_action = 'email_changed'");
    $st->execute([$uid]);
    $addr_since = max($addr_since, (string)$st->fetchColumn());
} catch (Throwable $e) {
    // journal absent : la date de création du compte suffit
}
$export['historique_activite'] = $grab("SELECT event_type, event_action, ip, created_at FROM assokit_activity_log WHERE user_id = ? OR (user_id IS NULL AND user_email = ? AND created_at >= ?) ORDER BY created_at DESC LIMIT 500", [$uid, $email, $addr_since !== '' ? $addr_since : '1970-01-01 00:00:00']);

$filename = 'assokit-mes-donnees-' . date('Ymd') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
