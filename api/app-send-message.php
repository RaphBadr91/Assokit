<?php
/**
 * api/app-send-message.php — Envoi d'un message dans un canal depuis l'app (natif).
 * Reproduit les regles de action-message-canal.php (permission d'ecriture), retour JSON.
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../rate-limit-helper.php';
if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_msg', 30, 60, (string) $uid);

$channel_id = (int) ($input['channel_id'] ?? 0);
$content = trim((string) ($input['content'] ?? ''));
$reply_to = (int) ($input['reply_to_message_id'] ?? 0);

// Suiveurs (financeurs externes) : pas d'accès à la messagerie interne, comme sur le site
if (function_exists('is_follower') && is_follower()) app_fail(403, 'forbidden', 'La messagerie interne est réservée aux membres de l\'association.');
if ($channel_id <= 0) app_fail(422, 'invalid', 'Canal manquant.');
if ($content === '') app_fail(422, 'invalid', 'Message vide.');
if (mb_strlen($content) > 8000) app_fail(422, 'invalid', 'Message trop long.');

try {
    // Canal de l'org
    $st = $pdo->prepare("SELECT * FROM channels WHERE id = ? AND org_id = ? AND is_archived = 0 LIMIT 1");
    $st->execute([$channel_id, $org_id]);
    $ch = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ch) app_fail(404, 'not_found', 'Canal introuvable.');

    // Permission d'ecriture (comme le site)
    $role = (string) ($user['role'] ?? '');
    $can_write = true;
    if ($ch['type'] === 'announce') {
        $can_write = in_array($role, ['admin', 'coordinator'], true);
    } elseif ($ch['type'] === 'private') {
        if ($role !== 'admin') {
            $m = $pdo->prepare("SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1");
            $m->execute([$channel_id, $uid]);
            $can_write = (bool) $m->fetchColumn();
        }
    }
    if (!$can_write) app_fail(403, 'forbidden', 'Vous ne pouvez pas écrire dans ce canal.');

    // Verifier le message parent (reply) dans le meme canal
    $reply_val = null;
    if ($reply_to > 0) {
        $r = $pdo->prepare("SELECT id FROM channel_messages WHERE id = ? AND channel_id = ? AND deleted_at IS NULL LIMIT 1");
        $r->execute([$reply_to, $channel_id]);
        if ($r->fetchColumn()) $reply_val = $reply_to;
    }

    $ins = $pdo->prepare("INSERT INTO channel_messages (channel_id, user_id, content, reply_to_message_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    $ins->execute([$channel_id, $uid, $content, $reply_val]);
    $new_id = (int) $pdo->lastInsertId();

    try {
        $pdo->prepare("INSERT INTO channel_reads (channel_id, user_id, last_read_message_id) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id))")
            ->execute([$channel_id, $uid, $new_id]);
    } catch (Throwable $e) {}

    // @mentions (notif + e-mail) puis notifs in-app (+ push via cron) pour les autres membres du canal :
    // même helper que le site (action-message-canal.php), agents de la mairie compris.
    try {
        @require_once __DIR__ . '/../notification-helpers.php';
        if (function_exists('ak_channel_message_notify')) {
            ak_channel_message_notify($pdo, $ch, (array) $user, $content);
        }
    } catch (Throwable $e) {
        error_log('[app-send-message] notifs: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $new_id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-send-message] ' . $e->getMessage());
    app_fail(500, 'server', 'Message non envoyé.');
}
