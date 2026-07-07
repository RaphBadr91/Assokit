<?php
/**
 * api/app-projects.php — Liste des projets pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
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

    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.progress_percent, p.status,
               COALESCE(p.participants_count, 0) AS participants,
               f.name AS folder_name
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
          AND p.status IN ('active','warning','done')
        ORDER BY FIELD(p.status,'warning','active','done'), p.progress_percent ASC
        LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $projects = [];
    foreach ($rows as $r) {
        $projects[] = [
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'folder'       => (string) $r['folder_name'],
            'progress'     => (int) round((float) $r['progress_percent']),
            'status'       => (string) $r['status'],
            'participants' => (int) $r['participants'],
        ];
    }

    echo json_encode(['ok' => true, 'projects' => $projects], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-projects] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
