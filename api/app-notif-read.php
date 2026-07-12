<?php
/**
 * api/app-notif-read.php — Marque des notifications comme lues (app mobile).
 * Corps JSON : { id?: int, type?: 'message'|'support', all?: bool, csrf }
 * Renvoie les compteurs recalculés. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../notification-helpers.php';

$all  = !empty($input['all']);
$id   = (int) ($input['id'] ?? 0);
$type = (string) ($input['type'] ?? '');

try {
    if ($all) {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0")
            ->execute([$uid]);
    } elseif ($type === 'message') {
        // Tous les messages internes (message + mention)
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW()
                       WHERE user_id = ? AND is_read = 0 AND notification_type IN ('message','mention')")
            ->execute([$uid]);
    } elseif ($type === 'support') {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW()
                       WHERE user_id = ? AND is_read = 0 AND notification_type = 'support'")
            ->execute([$uid]);
    } elseif ($id > 0) {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([$id, $uid]);
    }

    // Recalcul des compteurs
    $notif_unread = 0; $msg_unread = 0; $support_unread = 0;
    $st = $pdo->prepare("SELECT notification_type AS t, COUNT(*) AS c FROM user_notifications WHERE user_id = ? AND is_read = 0 GROUP BY notification_type");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = (int) $row['c']; $notif_unread += $c;
        if ($row['t'] === 'message' || $row['t'] === 'mention') $msg_unread += $c;
        elseif ($row['t'] === 'support') $support_unread += $c;
    }

    echo json_encode([
        'ok' => true,
        'notif_unread' => $notif_unread,
        'msg_unread' => $msg_unread,
        'support_unread' => $support_unread,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-notif-read] ' . $e->getMessage());
    app_fail(500, 'server');
}
