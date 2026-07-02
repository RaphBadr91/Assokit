<?php
/**
 * ============================================================
 * ASSOKIT — Endpoint guide onboarding
 * ============================================================
 * URL : /guide-onboarding-done (POST, AJAX)
 * Marque le guide comme vu pour l'utilisateur connecte.
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("UPDATE users SET onboarding_completed_at = NOW() WHERE id = ? AND onboarding_completed_at IS NULL");
    $stmt->execute([$user_id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
