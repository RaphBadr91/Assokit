<?php
/**
 * api/app-message-action.php — Modération d'un message de canal depuis l'app (appui long).
 *
 * Règle 1.2 d'Apple (contenus des utilisateurs) et règle équivalente de Google Play : chaque
 * utilisateur doit pouvoir signaler un message, masquer les messages d'une personne, et un
 * administrateur doit pouvoir retirer un message.
 *
 *   action = report : ouvre un ticket de support pré-rempli (canal, message, auteur, extrait),
 *                     dans les mêmes tables et avec la même alerte au support que
 *                     support-nouveau.php (Plus › Support). Le support d'Assokit le reçoit
 *                     comme tout ticket, et l'association le voit dans sa liste de tickets.
 *   action = delete : suppression douce (deleted_at), MÊMES RÈGLES que l'action « delete »
 *                     d'action-message-canal.php : l'auteur, ou un administrateur (sauf les
 *                     messages des agents de la mairie sur le canal « Suivi · Mairie »).
 *
 * « Masquer les messages de cette personne » est local à l'appareil (expo-secure-store) : rien
 * n'est envoyé au serveur.
 *
 * Entrée JSON : { action, message_id, reason?, csrf }. Sortie : { ok, message }.
 */
require __DIR__ . '/_app-write-boot.php';   // session, compte actif, CSRF, $pdo, $user, $uid, $org_id, $input
@require_once __DIR__ . '/../rate-limit-helper.php';

$action     = (string) ($input['action'] ?? '');
$message_id = (int) ($input['message_id'] ?? 0);

if (!in_array($action, ['report', 'delete'], true)) app_fail(422, 'invalid', 'Action inconnue.');
if ($message_id <= 0) app_fail(422, 'invalid', 'Message manquant.');
// Suiveurs (financeurs externes) : pas d'accès à la messagerie interne, comme sur le site
if (function_exists('is_follower') && is_follower()) app_fail(403, 'forbidden', 'La messagerie interne est réservée aux membres de l\'association.');

if (function_exists('ak_rate_limit_or_die')) {
    ak_rate_limit_or_die($action === 'report' ? 'app_msg_report' : 'app_msg_delete', $action === 'report' ? 10 : 60, 3600, (string) $uid);
}

try {
    // Le message, son canal (de l'association de la session) et son auteur
    $st = $pdo->prepare("
        SELECT cm.id, cm.user_id, cm.content, cm.created_at, cm.channel_id,
               c.name AS channel_name, c.type AS channel_type, c.org_id, c.is_mairie_channel,
               u.first_name, u.last_name, u.org_id AS author_org_id
        FROM channel_messages cm
        JOIN channels c ON c.id = cm.channel_id
        LEFT JOIN users u ON u.id = cm.user_id
        WHERE cm.id = ? AND cm.deleted_at IS NULL AND c.org_id = ? AND c.is_archived = 0
        LIMIT 1
    ");
    $st->execute([$message_id, $org_id]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);
    if (!$msg) app_fail(404, 'not_found', 'Ce message n’existe plus.');

    // Canal privé : réservé à ses membres, comme la lecture (api/app-channel-messages.php)
    if ($msg['channel_type'] === 'private') {
        $m = $pdo->prepare("SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1");
        $m->execute([(int) $msg['channel_id'], $uid]);
        if (!$m->fetchColumn() && ($user['role'] ?? '') !== 'admin') app_fail(403, 'forbidden', 'Vous n’avez pas accès à ce canal.');
    }

    if ($action === 'delete') {
        // Mêmes règles qu'action-message-canal.php (action delete)
        $is_admin = (($user['role'] ?? '') === 'admin');
        $is_mairie_agent_msg = !empty($msg['is_mairie_channel']) && (int) ($msg['author_org_id'] ?? 0) !== $org_id;
        $can_delete = ((int) $msg['user_id'] === $uid) || ($is_admin && !$is_mairie_agent_msg);
        if (!$can_delete) app_fail(403, 'permission', 'Seuls l’auteur du message et un administrateur peuvent le supprimer.');
        // Suppression douce : on garde l'historique pour les comptes rendus IA passés, comme le site
        $pdo->prepare("UPDATE channel_messages SET deleted_at = NOW() WHERE id = ?")->execute([$message_id]);
        echo json_encode(['ok' => true, 'message' => 'Message supprimé.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- report : ticket de support pré-rempli ----
    if ((int) $msg['user_id'] === $uid) app_fail(422, 'invalid', 'Vous ne pouvez pas signaler votre propre message.');
    ob_start();
    require_once __DIR__ . '/../support-helper.php';
    @require_once __DIR__ . '/../sa-permissions.php';
    @require_once __DIR__ . '/../resend-helper.php';
    ob_end_clean();

    $author  = trim(trim((string) ($msg['first_name'] ?? '')) . ' ' . trim((string) ($msg['last_name'] ?? '')));
    $excerpt = trim(preg_replace('/\s+/u', ' ', (string) $msg['content']));
    if (mb_strlen($excerpt) > 500) $excerpt = mb_substr($excerpt, 0, 497) . '…';
    $reason  = trim((string) ($input['reason'] ?? ''));
    if (mb_strlen($reason) > 1000) $reason = mb_substr($reason, 0, 1000);
    $chan    = (string) ($msg['channel_name'] ?? '');

    $title = mb_substr('Signalement d’un message — #' . $chan, 0, 190);
    $body  = "Message signalé depuis l'application.\n\n"
           . 'Canal : ' . $chan . ' (identifiant ' . (int) $msg['channel_id'] . ")\n"
           . 'Message : identifiant ' . (int) $msg['id'] . ', écrit le ' . date('d/m/Y à H:i', strtotime((string) $msg['created_at']) ?: time()) . "\n"
           . 'Auteur : ' . ($author !== '' ? $author : 'inconnu') . ' (compte ' . (int) $msg['user_id'] . ")\n\n"
           . "Extrait :\n« " . $excerpt . " »"
           . ($reason !== '' ? "\n\nMotif indiqué : " . $reason : '');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO support_tickets
                (org_id, created_by_user_id, title, category, priority, status,
                 last_message_at, last_message_by, created_at)
            VALUES (?, ?, ?, 'other', 'high', 'open', NOW(), 'org', NOW())
        ")->execute([$org_id, $uid, $title]);
        $ticket_id = (int) $pdo->lastInsertId();
        $pdo->prepare("
            INSERT INTO support_messages
                (ticket_id, author_user_id, author_side, body, read_by_org, created_at)
            VALUES (?, ?, 'org', ?, 1, NOW())
        ")->execute([$ticket_id, $uid, $body]);
        if (function_exists('support_log_event')) support_log_event($ticket_id, $uid, 'created');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // Alerte au support d'Assokit (e-mail + cloche), comme un ticket écrit dans Plus › Support
    try {
        if (function_exists('support_notify_new_message')) support_notify_new_message($ticket_id, $uid, 'org', $body);
    } catch (Throwable $e) {
        error_log('[api/app-message-action] notify: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'ticket_id' => $ticket_id,
        'message' => 'Merci. Le message est signalé à l’équipe Assokit, qui l’examine. Vous suivez ce signalement dans Plus › Support.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-message-action] ' . $e->getMessage());
    app_fail(500, 'server', 'Action impossible pour le moment. Réessayez dans un instant.');
}
