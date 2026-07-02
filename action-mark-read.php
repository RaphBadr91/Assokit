<?php
/**
 * ============================================================
 * ASSOKIT — Action : marquer notifications comme lues
 * ============================================================
 * POST /action-mark-read.php
 *   - action='mark_one'      + notif_id
 *   - action='mark_all'      (toutes les notifs du user)
 *   - action='mark_project'  + project_id (marque tous les messages comme lus)
 * 
 * Réponse JSON
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$user = current_user();
$user_id = (int)$user['id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'mark_one':
            $notif_id = (int)($_POST['notif_id'] ?? 0);
            if ($notif_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'invalid_id']);
                exit;
            }
            $result = ak_notif_mark_read($pdo, $notif_id, $user_id);
            echo json_encode([
                'ok' => $result,
                'unread_count' => ak_notif_count_unread($pdo, $user_id)
            ]);
            break;
            
        case 'mark_all':
            $result = ak_notif_mark_all_read($pdo, $user_id);
            echo json_encode([
                'ok' => $result,
                'unread_count' => 0
            ]);
            break;
            
        case 'mark_project':
            $project_id = (int)($_POST['project_id'] ?? 0);
            if ($project_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'invalid_project']);
                exit;
            }
            // Vérifier appartenance org
            $stmt = $pdo->prepare("
                SELECT p.id FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE p.id = ? AND f.org_id = ?
            ");
            $stmt->execute([$project_id, $user['org_id']]);
            if (!$stmt->fetch()) {
                echo json_encode(['ok' => false, 'error' => 'forbidden']);
                exit;
            }
            
            $result = ak_project_mark_read($pdo, $user_id, $project_id);
            echo json_encode([
                'ok' => $result,
                'project_id' => $project_id
            ]);
            break;
            
        default:
            echo json_encode(['ok' => false, 'error' => 'invalid_action']);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
