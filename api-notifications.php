<?php
/**
 * ============================================================
 * ASSOKIT — API : compteur + liste notifications
 * ============================================================
 * GET /api-notifications.php?action=count   → compteur uniquement
 * GET /api-notifications.php?action=list&limit=10 → liste détaillée
 * 
 * Pour le polling toutes les 30s du badge sidebar
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('api_notif', 60, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/notification-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_login();

$current = current_user();
$user_id = (int)$current['id'];
$org_id = (int)$current['org_id'];

$action = $_GET['action'] ?? 'count';

try {
    if ($action === 'count') {
        // Compteur global notifications + map des projets non-lus
        $unread_notifs = ak_notif_count_unread($pdo, $user_id);
        $unread_by_project = ak_user_unread_by_project($pdo, $user_id, $org_id);
        
        $total_messages_unread = array_sum($unread_by_project);
        
        echo json_encode([
            'ok' => true,
            'unread_notifs' => $unread_notifs,
            'unread_messages_total' => $total_messages_unread,
            'unread_by_project' => $unread_by_project,
            'badge_total' => $unread_notifs + $total_messages_unread,
        ]);
        
    } elseif ($action === 'list') {
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
        $only_unread = !empty($_GET['unread']);
        
        $notifs = ak_notif_get_recent($pdo, $user_id, $limit, $only_unread);
        
        // Format pour le JSON
        $out = [];
        foreach ($notifs as $n) {
            $icon_data = ak_notif_icon($n['notification_type']);
            $out[] = [
                'id' => (int)$n['id'],
                'type' => $n['notification_type'],
                'icon' => $icon_data['icon'],
                'color' => $icon_data['color'],
                'title' => $n['title'],
                'body' => $n['body'] ?? '',
                'link_url' => $n['link_url'] ?? '#',
                'is_read' => (bool)$n['is_read'],
                'time_ago' => ak_notif_time_ago($n['created_at']),
                'created_at' => $n['created_at'],
                'actor' => $n['actor_first'] ? [
                    'name' => trim($n['actor_first'] . ' ' . $n['actor_last']),
                    'initials' => mb_strtoupper(mb_substr($n['actor_first'], 0, 1) . mb_substr($n['actor_last'], 0, 1)),
                    'color' => $n['actor_color'] ?? 'blue',
                ] : null,
            ];
        }
        
        echo json_encode([
            'ok' => true,
            'notifications' => $out,
            'total_unread' => ak_notif_count_unread($pdo, $user_id),
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_action']);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
