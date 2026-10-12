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
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
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
$stmt = $pdo->prepare("SELECT p.id, p.archived_at, f.archived_at AS folder_archived_at FROM projects p JOIN folders f ON f.id = p.folder_id WHERE p.id = ? AND f.org_id = ?");
$stmt->execute([$project_id, $user['org_id']]);
$share_project = $stmt->fetch();
if (!$share_project) {
    echo json_encode(['ok' => false, 'error' => 'Projet introuvable']);
    exit;
}
// Projet archivé : pas de nouveau lien public (l'archivage a coupé l'ancien) ;
// révoquer reste possible.
if ($action === 'create' && (!empty($share_project['archived_at']) || !empty($share_project['folder_archived_at']))) {
    echo json_encode(['ok' => false, 'error' => 'Ce projet est archivé : il ne peut pas être partagé.']);
    exit;
}

// Même règle que le bouton « Partager publiquement » de la fiche projet ($can_share_public,
// projet.php) : admin, référent DU projet ou coordinateur — rien d'autre.
$is_admin = (($user['role'] ?? '') === 'admin');
$is_coord = (($user['role'] ?? '') === 'coordinator');
// Charge le projet pour vérifier référent
$stmt = $pdo->prepare("SELECT referent_id FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$proj = $stmt->fetch();
$is_referent = $proj && (int)$proj['referent_id'] === (int)$user['id'];
if (!$is_admin && !$is_referent && !$is_coord) {
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
