<?php
/**
 * api/app-notifications.php — Notifications de l'utilisateur pour l'ecran natif.
 * JSON, scope user. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$ICON = ['message' => 'chatbubble', 'mention' => 'at', 'team_added' => 'people', 'step_assigned' => 'checkmark-circle'];

function nago($v) {
    $ts = strtotime((string) $v);
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return 'à l\'instant';
    if ($d < 3600) return floor($d / 60) . ' min';
    if ($d < 86400) return floor($d / 3600) . ' h';
    if ($d < 604800) return floor($d / 86400) . ' j';
    return date('d/m/Y', $ts);
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $uid = (int) ($user['id'] ?? ($_SESSION['user_id'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT n.id, n.notification_type, n.title, n.body, n.link_url, n.is_read, n.created_at,
               u.first_name AS actor_first, u.last_name AS actor_last, u.avatar_color AS actor_color
        FROM user_notifications n
        LEFT JOIN users u ON n.actor_id = u.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $unread = 0;
    foreach ($rows as $r) {
        $isr = !empty($r['is_read']);
        if (!$isr) $unread++;
        $items[] = [
            'id'      => (int) $r['id'],
            'title'   => (string) ($r['title'] ?? ''),
            'body'    => (string) ($r['body'] ?? ''),
            'link'    => (string) ($r['link_url'] ?? ''),
            'icon'    => $ICON[$r['notification_type'] ?? ''] ?? 'notifications',
            'read'    => $isr,
            'ago'     => nago($r['created_at'] ?? null),
        ];
    }

    echo json_encode(['ok' => true, 'unread' => $unread, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-notifications] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
