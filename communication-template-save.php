<?php
/**
 * communication-template-save.php — Sauvegarde template (V1 FIX)
 * ===============================================================
 * Corrections : `access_marketing` au lieu de `can_access_marketing`
 */

require_once __DIR__ . '/config.php';
require_login();
require_capability('access_marketing');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

if (function_exists('check_csrf') && !check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
    exit;
}

$title    = trim($_POST['title'] ?? '');
$content  = trim($_POST['content'] ?? '');
$category = trim($_POST['category'] ?? 'autre');
$type     = trim($_POST['type'] ?? 'autre');

if ($title === '' || $content === '') {
    echo json_encode(['success' => false, 'error' => 'Titre et contenu requis']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO communication_saved_templates
            (org_id, created_by, category, type, title, content, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        (int) $_SESSION['org_id'],
        (int) $_SESSION['user_id'],
        $category,
        $type,
        $title,
        $content,
    ]);

    echo json_encode([
        'success'     => true,
        'template_id' => (int) $pdo->lastInsertId(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur BDD : ' . $e->getMessage(),
    ]);
}
