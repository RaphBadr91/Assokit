<?php
/**
 * api/absence-get.php — Récupère une absence pour édition
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID manquant']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM assokit_absences WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, (int)$user['org_id']]);
    $absence = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$absence) {
        echo json_encode(['success' => false, 'error' => 'Absence introuvable']);
        exit;
    }
    
    echo json_encode(['success' => true, 'absence' => $absence]);
} catch (Throwable $e) {
    error_log('absence-get: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
