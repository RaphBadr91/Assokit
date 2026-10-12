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

// Suiveurs (financeurs externes) : pas d'accès à l'emploi du temps ni aux absences
// de l'équipe (même règle que la page emploi-du-temps.php).
if (is_follower()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
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

    // Motif libre (ex. « Arrêt maladie ») : visible de l'intéressé et des administrateurs
    // du planning seulement ; les autres membres ne voient que le type et les dates.
    $role = (string)($user['role'] ?? '');
    $is_planning_admin = in_array($role, ['admin', 'founder', 'super_admin'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
    if (!$is_planning_admin && (int)$absence['user_id'] !== (int)$user['id']) {
        $absence['reason'] = null;
    }

    echo json_encode(['success' => true, 'absence' => $absence]);
} catch (Throwable $e) {
    error_log('absence-get: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
