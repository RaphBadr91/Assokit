<?php
/**
 * api/app-founder-projects.php — Vue globale de TOUS les projets de toutes les orgs (Fondateur).
 * GET ?q=&status= → liste (lecture seule). Réservé Fondateur/Super Admin. NE MODIFIE PAS le site.
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
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');

try {
    $where = ['o.deleted_at IS NULL'];
    $params = [];
    if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }
    if ($q !== '') { $where[] = '(p.name LIKE ? OR o.name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
    $sql = "SELECT p.id, p.name, p.status, p.progress_percent, p.budget_planned, p.budget_used,
                   p.created_at, o.name AS org_name
            FROM projects p
            JOIN folders f ON p.folder_id = f.id
            JOIN organizations o ON f.org_id = o.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $rows[] = [
            'id'       => (int) $p['id'],
            'name'     => (string) $p['name'],
            'org'      => (string) $p['org_name'],
            'status'   => (string) ($p['status'] ?? ''),
            'progress' => (int) ($p['progress_percent'] ?? 0),
            'budget'   => round((float) ($p['budget_planned'] ?? 0), 2),
            'used'     => round((float) ($p['budget_used'] ?? 0), 2),
            'created'  => !empty($p['created_at']) ? date('d/m/Y', strtotime($p['created_at'])) : '',
        ];
    }
    echo json_encode(['ok' => true, 'projects' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-projects] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
