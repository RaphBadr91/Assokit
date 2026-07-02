<?php
/**
 * ============================================================
 * ASSOKIT — Action : supprimer un fichier d'un projet
 * ============================================================
 * Permission : admin OU référent du projet OU uploader du fichier
 * 
 * POST :
 *   - file_id (int)
 *   - project_id (int)
 *   - csrf_token
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Session expirée.');
}

$file_id = (int)($_POST['file_id'] ?? 0);
$project_id = (int)($_POST['project_id'] ?? 0);

if ($file_id <= 0 || $project_id <= 0) {
    header('Location: /projets');
    exit;
}

$user = current_user();

// Charger le fichier + projet pour vérifs
$stmt = $pdo->prepare("
    SELECT pf.id, pf.uploaded_by, pf.filepath, pf.filename,
           p.referent_id, f.org_id
    FROM project_files pf
    JOIN projects p ON pf.project_id = p.id
    JOIN folders f ON p.folder_id = f.id
    WHERE pf.id = ? AND p.id = ?
");
$stmt->execute([$file_id, $project_id]);
$file = $stmt->fetch();

if (!$file || (int)$file['org_id'] !== (int)$user['org_id']) {
    http_response_code(403);
    die('Fichier introuvable ou accès refusé.');
}

// Permissions : admin OU référent du projet OU uploader
$is_admin = ($user['role'] === 'admin');
$is_referent = ((int)$file['referent_id'] === (int)$user['id']);
$is_uploader = ((int)$file['uploaded_by'] === (int)$user['id']);

if (!$is_admin && !$is_referent && !$is_uploader) {
    http_response_code(403);
    die('Vous n\'avez pas le droit de supprimer ce fichier.');
}

// Supprimer le fichier physique
$full_path = __DIR__ . '/' . $file['filepath'];
if (is_file($full_path)) {
    @unlink($full_path);
}

// Supprimer en base
$pdo->prepare("DELETE FROM project_files WHERE id = ?")->execute([$file_id]);

// Log
try {
    $pdo->prepare("INSERT INTO project_activity_log (project_id, user_id, action_type, action_label, ip_address) VALUES (?, ?, 'file_deleted', ?, ?)")
        ->execute([$project_id, $user['id'], 'a supprimé le fichier « ' . mb_substr($file['filename'], 0, 80) . ' »', $_SERVER['REMOTE_ADDR'] ?? null]);
} catch (Exception $e) {}

$_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Fichier supprimé.'];

header('Location: /projet/' . $project_id . '/fichiers');
exit;
