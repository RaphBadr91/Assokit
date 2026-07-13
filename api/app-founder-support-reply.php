<?php
/**
 * api/app-founder-support-reply.php — Répondre à un ticket support (Fondateur, natif).
 * POST { ticket_id:int, body, csrf } · réponse côté support + notif à l'org.
 * NE MODIFIE PAS le site : dédié à l'application.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';
@require_once __DIR__ . '/../support-helper.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$ticket_id = (int) ($input['ticket_id'] ?? 0);
$body = trim((string) ($input['body'] ?? ''));
if ($ticket_id <= 0) app_fail(400, 'ticket_id');
if (mb_strlen($body) < 2) app_fail(400, 'body', 'Message trop court.');

try {
    $st = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id = ? LIMIT 1");
    $st->execute([$ticket_id]);
    $ticket = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) app_fail(404, 'not_found');

    $pdo->prepare("INSERT INTO support_messages (ticket_id, author_user_id, author_side, body, is_internal_note, read_by_support, created_at)
                   VALUES (?, ?, 'support', ?, 0, 1, NOW())")
        ->execute([$ticket_id, $uid, $body]);

    $new_status = $ticket['status'] === 'open' ? 'in_progress' : $ticket['status'];
    if (in_array($ticket['status'], ['resolved', 'closed'], true)) $new_status = 'waiting_user';
    try { $pdo->prepare("UPDATE support_tickets SET last_message_at = NOW(), last_message_by = 'support', status = ? WHERE id = ?")->execute([$new_status, $ticket_id]); }
    catch (Throwable $e) { try { $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?")->execute([$new_status, $ticket_id]); } catch (Throwable $e2) {} }

    if (function_exists('support_notify_new_message')) {
        try { support_notify_new_message($ticket_id, $uid, 'support', $body); } catch (Throwable $e) {}
    }

    echo json_encode(['ok' => true, 'ticket_id' => $ticket_id, 'status' => $new_status], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-support-reply] ' . $e->getMessage());
    app_fail(500, 'server');
}
