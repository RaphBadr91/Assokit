<?php
/**
 * api/schedule-get.php — Récupère un créneau pour édition
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Suiveurs (financeurs externes) : pas d'accès à l'emploi du temps ni aux absences
// de l'équipe (même règle que la page emploi-du-temps.php).
if (is_follower()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

$user = current_user();
if ((int)($user['org_id'] ?? 0) <= 0) {  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID manquant']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM assokit_schedules WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, (int)$user['org_id']]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$slot) {
        echo json_encode(['success' => false, 'error' => 'Créneau introuvable']);
        exit;
    }
    
    echo json_encode(['success' => true, 'slot' => $slot]);
} catch (Throwable $e) {
    error_log('schedule-get: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
