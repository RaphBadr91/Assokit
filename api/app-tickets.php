<?php
/**
 * api/app-tickets.php — Tickets de support pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
@require_once __DIR__ . '/../support-helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$ST_KIND = ['open' => 'wait', 'in_progress' => 'wait', 'waiting_user' => 'draft', 'resolved' => 'done', 'closed' => 'off'];
$ST_LABEL = ['open' => 'Ouvert', 'in_progress' => 'En cours', 'waiting_user' => 'En attente', 'resolved' => 'Résolu', 'closed' => 'Fermé'];

function tfd($v) {
    if (empty($v) || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime((string) $v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, t.status, t.priority, t.category, t.created_at, t.last_message_at,
                   (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0 AND m.author_side = 'support' AND m.read_by_org = 0) AS nb_unread
            FROM support_tickets t
            WHERE t.org_id = ?
            ORDER BY CASE WHEN t.status IN ('open','in_progress','waiting_user') THEN 0 ELSE 1 END,
                     t.last_message_at DESC, t.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$org_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $items = [];
    $open = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'open');
        if (in_array($status, ['open', 'in_progress', 'waiting_user'], true)) $open++;
        $label = function_exists('support_status_label') ? support_status_label($status) : ($ST_LABEL[$status] ?? ucfirst($status));
        $items[] = [
            'id'           => (int) $r['id'],
            'title'        => (string) ($r['title'] ?? ''),
            'status_label' => (string) $label,
            'status_kind'  => $ST_KIND[$status] ?? 'wait',
            'unread'       => (int) ($r['nb_unread'] ?? 0) > 0,
            'date'         => tfd($r['last_message_at'] ?? ($r['created_at'] ?? null)),
        ];
    }

    echo json_encode(['ok' => true, 'stats' => ['nb' => count($items), 'open' => $open], 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-tickets] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
