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
    // Création d'un dossier depuis le formulaire de projet : admin ou droit « Peut créer des
    // dossiers », même règle que nouveau-dossier.php et api/app-create-project.php.
    $can_create_folders = ($user['role'] ?? '') === 'admin';
    if (!$can_create_folders && $user && ($user['role'] ?? '') !== 'follower') {
        if (array_key_exists('can_create_folders', $user)) {
            $can_create_folders = !empty($user['can_create_folders']);
        } else {
            try {
                $st = $pdo->prepare("SELECT can_create_folders FROM users WHERE id = ? LIMIT 1");
                $st->execute([(int) $user['id']]);
                $can_create_folders = !empty($st->fetchColumn());
            } catch (Throwable $e) {
                $can_create_folders = false;
            }
        }
    }
    // Budget prévu du formulaire de projet : seulement pour qui voit les finances
    // (api/app-create-project.php ignore sinon le montant reçu).
    require_once __DIR__ . '/../finance-permissions.php';
    $can_view_finances = $user ? user_can_view_finances($user) : false;
    echo json_encode(['ok' => true, 'folders' => $folders, 'can_create_folders' => $can_create_folders, 'can_view_finances' => $can_view_finances], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-folders] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
