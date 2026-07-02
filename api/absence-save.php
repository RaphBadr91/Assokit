<?php
/**
 * api/absence-save.php — Enregistre/modifie/supprime une absence
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
$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];

if (file_exists(__DIR__ . '/../activity-tracker.php')) {
    require_once __DIR__ . '/../activity-tracker.php';
}

$action = $_POST['action'] ?? 'save';
$id = (int)($_POST['id'] ?? 0);

try {
    if ($action === 'delete') {
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID manquant']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM assokit_absences WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $org_id]);
        
        if (function_exists('activity_log_action')) {
            activity_log_action('absence_deleted', [], (string)$id);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    $target_user_id = (int)($_POST['user_id'] ?? $user_id);
    $type = $_POST['type'] ?? 'vacation';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = trim((string)($_POST['reason'] ?? ''));
    
    // Validation
    if (!in_array($type, ['vacation', 'sick', 'personal', 'other'], true)) {
        echo json_encode(['success' => false, 'error' => 'Type invalide']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        echo json_encode(['success' => false, 'error' => 'Dates invalides']);
        exit;
    }
    if ($start_date > $end_date) {
        echo json_encode(['success' => false, 'error' => 'La date de fin doit être après la date de début']);
        exit;
    }
    
    // Vérifier le membre
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND is_active = 1");
    $stmt->execute([$target_user_id, $org_id]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Membre invalide']);
        exit;
    }
    
    if ($id > 0) {
        // UPDATE
        $stmt = $pdo->prepare("SELECT id FROM assokit_absences WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $org_id]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Absence introuvable']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE assokit_absences SET
            user_id = ?, type = ?, start_date = ?, end_date = ?, reason = ?
            WHERE id = ? AND org_id = ?");
        $stmt->execute([$target_user_id, $type, $start_date, $end_date, $reason ?: null, $id, $org_id]);
        
        if (function_exists('activity_log_action')) {
            activity_log_action('absence_updated', ['type' => $type], (string)$id);
        }
        echo json_encode(['success' => true, 'id' => $id, 'updated' => true]);
    } else {
        // INSERT
        $stmt = $pdo->prepare("INSERT INTO assokit_absences
            (org_id, user_id, type, start_date, end_date, reason, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$org_id, $target_user_id, $type, $start_date, $end_date, $reason ?: null]);
        
        $new_id = (int)$pdo->lastInsertId();
        if (function_exists('activity_log_action')) {
            activity_log_action('absence_created', ['type' => $type, 'days' => (strtotime($end_date) - strtotime($start_date)) / 86400 + 1], (string)$new_id);
        }
        echo json_encode(['success' => true, 'id' => $new_id, 'created' => true]);
    }
} catch (Throwable $e) {
    error_log('absence-save: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
