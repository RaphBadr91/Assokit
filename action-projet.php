<?php
/**
 * ============================================================
 * ASSOKIT — Actions sur un projet (update, archive, delete)
 * ============================================================
 * Chaque modification est automatiquement tracée dans
 * project_activity_log pour permettre l'historique visible
 * par l'admin (et le référent dans l'envoi 2).
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();
$user = current_user();
$org_id = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /projets?error=csrf');
    exit;
}

$action = $_POST['action'] ?? '';
$project_id = (int)($_POST['project_id'] ?? 0);

if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger le projet et vérifier l'organisation
$stmt = $pdo->prepare("
    SELECT p.*, f.org_id, f.name AS folder_name
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || $project['org_id'] != $org_id) {
    header('Location: /projets?error=notfound');
    exit;
}

// Permissions : admin OU référent du projet
$is_admin = ($user['role'] === 'admin');
$is_referent = ((int)$project['referent_id'] === (int)$user['id']);
$can_edit = $is_admin || $is_referent;

if (!$can_edit) {
    header('Location: /projet/' . $project_id . '?error=permission');
    exit;
}

// =========================================================
// Helper : logger une action sur le projet
// =========================================================
function log_project_activity($project_id, $user_id, $action_type, $action_label, $changes = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_activity_log
                (project_id, user_id, action_type, action_label, changes, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $project_id, $user_id, $action_type, $action_label,
            is_array($changes) ? json_encode($changes, JSON_UNESCAPED_UNICODE) : $changes,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Log silencieux en cas d'erreur
    }
}

// =========================================================
// Helper : formater un changement pour affichage lisible
// =========================================================
function format_status_label($status) {
    return [
        'draft' => 'Brouillon',
        'active' => 'En cours',
        'warning' => 'À surveiller',
        'done' => 'Terminé',
        'archived' => 'Archivé',
    ][$status] ?? $status;
}

// =========================================================
// ACTION : UPDATE
// =========================================================
if ($action === 'update') {
    // Récupération des champs
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? $project['status'];
    $referent_id = (int)($_POST['referent_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? $project['folder_id']);
    $progress = (int)($_POST['progress_percent'] ?? $project['progress_percent']);
    $participants = (int)($_POST['participants_count'] ?? 0);
    $budget_planned = (float)str_replace([' ', ','], ['', '.'], $_POST['budget_planned'] ?? $project['budget_planned']);
    $budget_used = (float)str_replace([' ', ','], ['', '.'], $_POST['budget_used'] ?? $project['budget_used']);
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;

    // Validations
    $errors = [];
    if ($name === '') $errors[] = 'Le nom du projet est obligatoire';
    if (!in_array($status, ['draft', 'active', 'warning', 'done', 'archived'], true)) {
        $status = $project['status'];
    }
    if ($progress < 0) $progress = 0;
    if ($progress > 100) $progress = 100;

    // Vérifier que le folder appartient à l'org
    if ($folder_id !== (int)$project['folder_id']) {
        $check = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND org_id = ?");
        $check->execute([$folder_id, $org_id]);
        if (!$check->fetch()) $folder_id = (int)$project['folder_id'];
    }

    // Vérifier que le référent appartient à l'org
    if ($referent_id > 0) {
        $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND is_active = 1");
        $check->execute([$referent_id, $org_id]);
        if (!$check->fetch()) $referent_id = (int)$project['referent_id'];
    } else {
        $referent_id = null;
    }

    // Dates
    if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = null;
    if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = null;

    if ($errors) {
        $_SESSION['form_errors'] = $errors;
        header('Location: /modifier-projet/' . $project_id);
        exit;
    }

    // =========================================================
    // DÉTECTION DES CHANGEMENTS (pour logger chaque diff séparément)
    // =========================================================
    $old = $project;

    // 1. Nom
    if ($name !== $old['name']) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a renommé le projet : "' . mb_substr($old['name'], 0, 60) . '" → "' . mb_substr($name, 0, 60) . '"',
            ['field' => 'name', 'old' => $old['name'], 'new' => $name]);
    }

    // 2. Description (juste signaler si changée, pas le contenu)
    if (trim($description) !== trim($old['description'] ?? '')) {
        $old_len = mb_strlen(trim($old['description'] ?? ''));
        $new_len = mb_strlen(trim($description));
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié la description du projet (' . $old_len . ' → ' . $new_len . ' caractères)',
            ['field' => 'description']);
    }

    // 3. Lieu
    if (trim($location) !== trim($old['location'] ?? '')) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié le lieu : "' . ($old['location'] ?: '—') . '" → "' . ($location ?: '—') . '"',
            ['field' => 'location', 'old' => $old['location'], 'new' => $location]);
    }

    // 4. Statut
    if ($status !== $old['status']) {
        log_project_activity($project_id, $user['id'], 'status_changed',
            'a changé le statut : ' . format_status_label($old['status']) . ' → ' . format_status_label($status),
            ['field' => 'status', 'old' => $old['status'], 'new' => $status]);
    }

    // 5. Référent
    if ((int)$referent_id !== (int)$old['referent_id']) {
        // Récupérer les noms
        $old_ref_name = '—';
        if ($old['referent_id']) {
            $ref_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $ref_stmt->execute([$old['referent_id']]);
            if ($r = $ref_stmt->fetch()) $old_ref_name = $r['first_name'] . ' ' . $r['last_name'];
        }
        $new_ref_name = '—';
        if ($referent_id) {
            $ref_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $ref_stmt->execute([$referent_id]);
            if ($r = $ref_stmt->fetch()) $new_ref_name = $r['first_name'] . ' ' . $r['last_name'];
        }
        log_project_activity($project_id, $user['id'], 'referent_changed',
            'a changé le référent : ' . $old_ref_name . ' → ' . $new_ref_name,
            ['field' => 'referent_id', 'old' => $old['referent_id'], 'new' => $referent_id]);
    }

    // 6. Dossier
    if ($folder_id !== (int)$old['folder_id']) {
        $fstmt = $pdo->prepare("SELECT name FROM folders WHERE id = ?");
        $fstmt->execute([$folder_id]);
        $new_folder_name = $fstmt->fetchColumn() ?: '—';
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a déplacé le projet : "' . $old['folder_name'] . '" → "' . $new_folder_name . '"',
            ['field' => 'folder_id', 'old' => $old['folder_id'], 'new' => $folder_id]);
    }

    // 7. Progression
    if ($progress !== (int)$old['progress_percent']) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a mis à jour la progression : ' . $old['progress_percent'] . '% → ' . $progress . '%',
            ['field' => 'progress_percent', 'old' => $old['progress_percent'], 'new' => $progress]);
    }

    // 8. Participants
    if ($participants !== (int)$old['participants_count']) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a mis à jour le nombre de participants : ' . $old['participants_count'] . ' → ' . $participants,
            ['field' => 'participants_count', 'old' => $old['participants_count'], 'new' => $participants]);
    }

    // 9. Budget prévu
    if (abs($budget_planned - (float)$old['budget_planned']) > 0.01) {
        log_project_activity($project_id, $user['id'], 'budget_changed',
            'a modifié le budget prévu : ' . number_format((float)$old['budget_planned'], 0, ',', ' ') . ' € → ' . number_format($budget_planned, 0, ',', ' ') . ' €',
            ['field' => 'budget_planned', 'old' => $old['budget_planned'], 'new' => $budget_planned]);
    }

    // 10. Budget utilisé
    if (abs($budget_used - (float)$old['budget_used']) > 0.01) {
        log_project_activity($project_id, $user['id'], 'budget_changed',
            'a modifié le budget utilisé : ' . number_format((float)$old['budget_used'], 0, ',', ' ') . ' € → ' . number_format($budget_used, 0, ',', ' ') . ' €',
            ['field' => 'budget_used', 'old' => $old['budget_used'], 'new' => $budget_used]);
    }

    // 11. Dates
    if ($start_date !== $old['start_date']) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié la date de début : ' . ($old['start_date'] ? date('d/m/Y', strtotime($old['start_date'])) : '—') . ' → ' . ($start_date ? date('d/m/Y', strtotime($start_date)) : '—'),
            ['field' => 'start_date', 'old' => $old['start_date'], 'new' => $start_date]);
    }
    if ($end_date !== $old['end_date']) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié la date de fin : ' . ($old['end_date'] ? date('d/m/Y', strtotime($old['end_date'])) : '—') . ' → ' . ($end_date ? date('d/m/Y', strtotime($end_date)) : '—'),
            ['field' => 'end_date', 'old' => $old['end_date'], 'new' => $end_date]);
    }

    // =========================================================
    // ENREGISTREMENT
    // =========================================================
    $stmt = $pdo->prepare("
        UPDATE projects SET
            name = ?, description = ?, location = ?, status = ?,
            referent_id = ?, folder_id = ?, progress_percent = ?, participants_count = ?,
            budget_planned = ?, budget_used = ?, start_date = ?, end_date = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $description ?: null, $location ?: null, $status,
        $referent_id, $folder_id, $progress, $participants,
        $budget_planned, $budget_used, $start_date, $end_date,
        $project_id,
    ]);

    header('Location: /projet/' . $project_id . '?updated=1');
    exit;
}

// =========================================================
// ACTION : ARCHIVER
// =========================================================
if ($action === 'archive') {
    $pdo->prepare("UPDATE projects SET status = 'archived' WHERE id = ?")->execute([$project_id]);
    log_project_activity($project_id, $user['id'], 'status_changed',
        'a archivé le projet',
        ['field' => 'status', 'old' => $project['status'], 'new' => 'archived']);
    header('Location: /projets?archived=1');
    exit;
}

// =========================================================
// ACTION : DUPLIQUER (bonus utile pour créer un projet similaire)
// =========================================================
if ($action === 'duplicate') {
    $stmt = $pdo->prepare("
        INSERT INTO projects (
            folder_id, name, description, location, status,
            referent_id, progress_percent, participants_count,
            budget_planned, budget_used, start_date, end_date, color_theme
        )
        SELECT
            folder_id,
            CONCAT(name, ' (copie)'), description, location, 'draft',
            referent_id, 0, participants_count,
            budget_planned, 0, NULL, NULL, color_theme
        FROM projects WHERE id = ?
    ");
    $stmt->execute([$project_id]);
    $new_id = (int)$pdo->lastInsertId();

    log_project_activity($new_id, $user['id'], 'project_created',
        'a créé ce projet comme copie de "' . $project['name'] . '"',
        ['source_project_id' => $project_id]);

    header('Location: /projet/' . $new_id . '?duplicated=1');
    exit;
}

header('Location: /projet/' . $project_id);
exit;
