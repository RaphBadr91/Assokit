<?php
/**
 * ============================================================
 * ASSOKIT — Action : épingler / désépingler un dossier
 * ============================================================
 * Personnel à chaque utilisateur — chaque utilisateur a ses
 * propres épingles.
 * 
 * POST :
 *   - folder_id (int)
 *   - action ('pin' | 'unpin')
 *   - csrf_token
 * 
 * Si appel via fetch (AJAX), retourne JSON.
 * Sinon redirige vers /projets.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    http_response_code(400);
    die('Session expirée.');
}

$user = current_user();
$user_id = (int)$user['id'];
$org_id = (int)$user['org_id'];

$folder_id = (int)($_POST['folder_id'] ?? 0);
$action = $_POST['action'] ?? 'pin';

if ($folder_id <= 0 || !in_array($action, ['pin', 'unpin'], true)) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'invalid']);
        exit;
    }
    header('Location: /projets');
    exit;
}

// Vérifier que le dossier appartient à l'org
$stmt = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND org_id = ?");
$stmt->execute([$folder_id, $org_id]);
if (!$stmt->fetch()) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    http_response_code(403);
    die('Accès refusé.');
}

try {
    if ($action === 'pin') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO user_pinned_folders (user_id, folder_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $folder_id]);
        $new_state = 'pinned';
    } else {
        $stmt = $pdo->prepare("DELETE FROM user_pinned_folders WHERE user_id = ? AND folder_id = ?");
        $stmt->execute([$user_id, $folder_id]);
        $new_state = 'unpinned';
    }
    
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'state' => $new_state, 'folder_id' => $folder_id]);
        exit;
    }
    
    header('Location: /projets#f' . $folder_id);
    exit;
    
} catch (Throwable $e) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    die('Erreur : ' . $e->getMessage());
}
