<?php
/**
 * api/app-attendance.php — Sessions d'emargement pour l'ecran natif. JSON, scope org, admin/coord.
 * NE MODIFIE PAS le site.
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

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'coordinator', 'founder', 'super_admin'], true) && empty($user['is_founder'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux encadrants.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT s.id, s.title, s.starts_at, s.location, s.is_open,
               (SELECT COUNT(*) FROM attendance_records WHERE session_id = s.id) AS nb_signed
        FROM attendance_sessions s
        WHERE s.org_id = ? AND s.archived_at IS NULL
        ORDER BY s.starts_at DESC LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $open = 0; $records = 0;
    foreach ($rows as $r) {
        $isopen = !empty($r['is_open']);
        if ($isopen) $open++;
        $records += (int) ($r['nb_signed'] ?? 0);
        $ts = strtotime((string) $r['starts_at']);
        $items[] = [
            'id'        => (int) $r['id'],
            'title'     => (string) $r['title'],
            'date'      => $ts ? date('d/m/Y H:i', $ts) : '',
            'location'  => (string) ($r['location'] ?? ''),
            'is_open'   => $isopen,
            'nb_signed' => (int) ($r['nb_signed'] ?? 0),
        ];
    }

    echo json_encode(['ok' => true, 'allowed' => true, 'stats' => ['total' => count($items), 'open' => $open, 'records' => $records], 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-attendance] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
