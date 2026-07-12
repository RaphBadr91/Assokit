<?php
/**
 * api/app-founder-support.php — Support plateforme : tous les tickets (Fondateur, natif).
 * GET ?filter=open|all  · réservé Fondateur/Super Admin. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

$filter = (string) ($_GET['filter'] ?? 'open');
$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

$ST_META = [
    'open'         => ['label' => 'Ouvert',      'kind' => 'wait'],
    'in_progress'  => ['label' => 'En cours',    'kind' => 'wait'],
    'waiting_user' => ['label' => 'Attente',     'kind' => 'draft'],
    'resolved'     => ['label' => 'Résolu',      'kind' => 'done'],
    'closed'       => ['label' => 'Fermé',        'kind' => 'off'],
];

try {
    $open_nb    = (int) $scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')");
    $unread_nb  = (int) $scalar("SELECT COUNT(DISTINCT t.id) FROM support_tickets t JOIN support_messages m ON m.ticket_id=t.id WHERE m.author_side='org' AND m.read_by_support=0 AND m.is_internal_note=0");

    $where = "1=1";
    if ($filter === 'open') $where = "t.status IN ('open','in_progress','waiting_user')";

    $rows = [];
    try {
        $st = $pdo->query("
            SELECT t.id, t.title, t.category, t.priority, t.status, t.created_at, t.org_id,
                   o.name AS org_name,
                   (SELECT MAX(created_at) FROM support_messages WHERE ticket_id=t.id AND is_internal_note=0) AS last_msg,
                   (SELECT COUNT(*) FROM support_messages WHERE ticket_id=t.id AND author_side='org' AND read_by_support=0 AND is_internal_note=0) AS unread
            FROM support_tickets t
            LEFT JOIN organizations o ON o.id = t.org_id
            WHERE $where
            ORDER BY (t.status IN ('open','in_progress')) DESC, t.updated_at DESC, t.created_at DESC
            LIMIT 100");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $st = $pdo->query("
                SELECT t.id, t.title, t.category, t.priority, t.status, t.created_at, t.org_id, o.name AS org_name
                FROM support_tickets t LEFT JOIN organizations o ON o.id=t.org_id
                WHERE $where ORDER BY t.created_at DESC LIMIT 100");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {}
    }

    $tickets = [];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'open');
        $meta = $ST_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $tickets[] = [
            'id'           => (int) $r['id'],
            'title'        => (string) ($r['title'] ?? 'Ticket'),
            'org'          => (string) ($r['org_name'] ?? '—'),
            'category'     => (string) ($r['category'] ?? ''),
            'priority'     => (string) ($r['priority'] ?? 'normal'),
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'unread'       => (int) ($r['unread'] ?? 0),
            'date'         => !empty($r['last_msg'] ?? $r['created_at']) ? date('d/m/Y', strtotime($r['last_msg'] ?? $r['created_at'])) : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'filter' => $filter,
        'summary' => ['open' => $open_nb, 'unread' => $unread_nb],
        'tickets' => $tickets,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-support] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
