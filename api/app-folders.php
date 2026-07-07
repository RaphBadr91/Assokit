<?php
/**
 * api/app-folders.php — Liste des dossiers pour le formulaire natif de projet.
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

    $stmt = $pdo->prepare("SELECT id, name FROM folders WHERE org_id = ? AND archived_at IS NULL ORDER BY name ASC");
    $stmt->execute([$org_id]);
    $folders = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $f) {
        $folders[] = ['id' => (int) $f['id'], 'name' => (string) $f['name']];
    }
    echo json_encode(['ok' => true, 'folders' => $folders], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-folders] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
