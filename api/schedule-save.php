<?php
/**
 * api/schedule-save.php — Enregistre/modifie/supprime un créneau
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

// Tracker l'action si dispo
if (file_exists(__DIR__ . '/../activity-tracker.php')) {
    require_once __DIR__ . '/../activity-tracker.php';
}

$action = $_POST['action'] ?? 'save';
$id = (int)($_POST['id'] ?? 0);

try {
    // ============================================================
    // SUPPRESSION
    // ============================================================
    if ($action === 'delete') {
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID manquant']);
            exit;
        }
        
        // Vérifier que le créneau appartient à l'org
        $stmt = $pdo->prepare("SELECT user_id FROM assokit_schedules WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $org_id]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Créneau introuvable']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM assokit_schedules WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $org_id]);
        
        if (function_exists('activity_log_action')) {
            activity_log_action('schedule_deleted', ['target_user' => (int)$row['user_id']], (string)$id);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============================================================
    // CRÉATION OU MODIFICATION
    // ============================================================
    $target_user_id = (int)($_POST['user_id'] ?? $user_id);
    $title = trim((string)($_POST['title'] ?? ''));
    $type = $_POST['type'] ?? 'present';
    $recurrence = $_POST['recurrence'] ?? 'weekly';
    $day_of_week = (int)($_POST['day_of_week'] ?? 1);
    $specific_date = $_POST['specific_date'] ?? null;
    $start_time = $_POST['start_time'] ?? '09:00';
    $end_time = $_POST['end_time'] ?? '12:00';
    $location = trim((string)($_POST['location'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    // Validation
    if (!in_array($type, ['present', 'remote', 'meeting', 'other'], true)) {
        echo json_encode(['success' => false, 'error' => 'Type invalide']);
        exit;
    }
    if (!in_array($recurrence, ['weekly', 'once'], true)) {
        echo json_encode(['success' => false, 'error' => 'Récurrence invalide']);
        exit;
    }
    if ($recurrence === 'weekly' && ($day_of_week < 1 || $day_of_week > 7)) {
        echo json_encode(['success' => false, 'error' => 'Jour invalide']);
        exit;
    }
    if ($recurrence === 'once' && (!$specific_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $specific_date))) {
        echo json_encode(['success' => false, 'error' => 'Date invalide']);
        exit;
    }
    if (!preg_match('/^\d{2}:\d{2}/', $start_time) || !preg_match('/^\d{2}:\d{2}/', $end_time)) {
        echo json_encode(['success' => false, 'error' => 'Heures invalides']);
        exit;
    }
    if ($start_time >= $end_time) {
        echo json_encode(['success' => false, 'error' => 'L\'heure de fin doit être après l\'heure de début']);
        exit;
    }
    
    // Vérifier que le membre cible appartient bien à l'org
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND is_active = 1");
    $stmt->execute([$target_user_id, $org_id]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Membre invalide']);
        exit;
    }
    
    $color = '#10b981';
    if ($type === 'remote')  $color = '#f59e0b';
    elseif ($type === 'meeting') $color = '#3b82f6';
    elseif ($type === 'other')   $color = '#94a3b8';
    
    if ($id > 0) {
        // UPDATE
        $stmt = $pdo->prepare("SELECT id FROM assokit_schedules WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $org_id]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Créneau introuvable']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE assokit_schedules SET
            user_id = ?, title = ?, type = ?, recurrence = ?,
            day_of_week = ?, specific_date = ?, start_time = ?, end_time = ?,
            location = ?, notes = ?, color = ?
            WHERE id = ? AND org_id = ?");
        $stmt->execute([
            $target_user_id,
            $title ?: null,
            $type,
            $recurrence,
            $recurrence === 'weekly' ? $day_of_week : null,
            $recurrence === 'once' ? $specific_date : null,
            $start_time,
            $end_time,
            $location ?: null,
            $notes ?: null,
            $color,
            $id,
            $org_id,
        ]);
        
        if (function_exists('activity_log_action')) {
            activity_log_action('schedule_updated', ['type' => $type, 'recurrence' => $recurrence], (string)$id);
        }
        
        echo json_encode(['success' => true, 'id' => $id, 'updated' => true]);
    } else {
        // INSERT
        $stmt = $pdo->prepare("INSERT INTO assokit_schedules
            (org_id, user_id, title, type, recurrence, day_of_week, specific_date, start_time, end_time, location, notes, color, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $org_id,
            $target_user_id,
            $title ?: null,
            $type,
            $recurrence,
            $recurrence === 'weekly' ? $day_of_week : null,
            $recurrence === 'once' ? $specific_date : null,
            $start_time,
            $end_time,
            $location ?: null,
            $notes ?: null,
            $color,
            $user_id,
        ]);
        
        $new_id = (int)$pdo->lastInsertId();
        
        if (function_exists('activity_log_action')) {
            activity_log_action('schedule_created', ['type' => $type, 'recurrence' => $recurrence, 'target_user' => $target_user_id], (string)$new_id);
        }
        
        echo json_encode(['success' => true, 'id' => $new_id, 'created' => true]);
    }
} catch (Throwable $e) {
    error_log('schedule-save: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
