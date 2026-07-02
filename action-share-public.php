<?php
/**
 * ============================================================
 * ASSOKIT — Action : créer ou révoquer un lien public projet
 * ============================================================
 * POST /action-share-public.php
 *   action=create | revoke
 *   project_id=NN
 *   csrf_token=...
 *   [token_id=NN]  (pour revoke)
 * Réponse JSON.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Session expirée']);
    exit;
}

$user = current_user();
$project_id = (int)($_POST['project_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Vérifier accès projet (org match) + permission admin/édition
$stmt = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON f.id = p.folder_id WHERE p.id = ? AND f.org_id = ?");
$stmt->execute([$project_id, $user['org_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Projet introuvable']);
    exit;
}

$is_admin = ($user['role'] === 'admin');
// Charge le projet pour vérifier référent
$stmt = $pdo->prepare("SELECT referent_id FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$proj = $stmt->fetch();
$is_referent = $proj && (int)$proj['referent_id'] === (int)$user['id'];
$can_edit = function_exists('can') && (can('edit_projects') || can('manage_projects'));
if (!$is_admin && !$is_referent && !$can_edit) {
    echo json_encode(['ok' => false, 'error' => 'Permission refusée — admin, référent ou coordinateur uniquement']);
    exit;
}

if ($action === 'create') {
    // Révoquer les anciens tokens actifs (un seul lien actif par projet)
    $pdo->prepare("UPDATE project_share_tokens SET revoked_at = NOW() WHERE project_id = ? AND revoked_at IS NULL")
        ->execute([$project_id]);

    // Génère un nouveau token sécurisé
    $token = bin2hex(random_bytes(20)); // 40 chars hex
    $stmt = $pdo->prepare("INSERT INTO project_share_tokens (project_id, token, created_by) VALUES (?, ?, ?)");
    $stmt->execute([$project_id, $token, (int)$user['id']]);
    $tid = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'token' => $token, 'token_id' => $tid, 'url' => '/projet-public.php?t=' . $token]);
    exit;
}

if ($action === 'revoke') {
    $pdo->prepare("UPDATE project_share_tokens SET revoked_at = NOW() WHERE project_id = ? AND revoked_at IS NULL")
        ->execute([$project_id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Action invalide']);
