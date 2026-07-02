<?php
/**
 * ============================================================
 * ASSOKIT — Action : gestion des étapes d'un projet
 * ============================================================
 * Actions supportées (POST) :
 *   - toggle  : valider/dévalider une étape (existant)
 *   - add     : ajouter une nouvelle étape
 *   - edit    : modifier le titre/description d'une étape
 *   - delete  : supprimer une étape
 *   - reorder : réordonner les étapes
 * 
 * Permissions :
 *   - toggle : admin, coordinator, référent du projet
 *   - add/edit/delete/reorder : admin OU référent du projet uniquement
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

// Vérif CSRF
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Session expirée, rechargez la page.');
}

$user = current_user();
$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];

$action = $_POST['action'] ?? 'toggle';
$project_id = (int)($_POST['project_id'] ?? 0);

if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Vérifier que le projet appartient bien à l'org
$stmt = $pdo->prepare("
    SELECT p.id, p.referent_id, f.org_id
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
    LIMIT 1
");
$stmt->execute([$project_id, $org_id]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(403);
    die('Projet introuvable ou accès refusé.');
}

// Permissions globales
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');
$is_referent = ((int)$project['referent_id'] === $user_id);
$can_modify_steps = $is_admin || $is_referent;  // add/edit/delete/reorder

// =============================================================
// HELPERS
// =============================================================
function recalc_project_progress(PDO $pdo, int $project_id): void {
    $row = $pdo->prepare("
        SELECT COUNT(*) AS total, SUM(is_completed) AS done
        FROM project_steps WHERE project_id = ?
    ");
    $row->execute([$project_id]);
    $counts = $row->fetch();
    $new_progress = $counts['total'] > 0
        ? (int)round(($counts['done'] / $counts['total']) * 100)
        : 0;
    
    $cur_status_stmt = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
    $cur_status_stmt->execute([$project_id]);
    $cs = $cur_status_stmt->fetchColumn();
    
    $new_status = null;
    if ($new_progress >= 100 && $cs !== 'archived') {
        $new_status = 'done';
    } elseif ($new_progress < 100 && $cs === 'done') {
        $new_status = 'active';
    }
    
    if ($new_status) {
        $pdo->prepare("UPDATE projects SET progress_percent = ?, status = ? WHERE id = ?")
            ->execute([$new_progress, $new_status, $project_id]);
    } else {
        $pdo->prepare("UPDATE projects SET progress_percent = ? WHERE id = ?")
            ->execute([$new_progress, $project_id]);
    }
}

function log_step_activity(PDO $pdo, int $project_id, int $user_id, string $msg): void {
    try {
        $pdo->prepare("INSERT INTO project_activity_log (project_id, user_id, action_type, action_label, ip_address) VALUES (?, ?, 'step_updated', ?, ?)")
            ->execute([$project_id, $user_id, $msg, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}

// =============================================================
// DISPATCH
// =============================================================
switch ($action) {

    // ============= TOGGLE (existant - valider/dévalider) =============
    case 'toggle':
        $step_id = (int)($_POST['step_id'] ?? 0);
        if ($step_id <= 0) {
            header('Location: /projet/' . $project_id);
            exit;
        }
        
        if (!$is_admin && !$is_coord && !$is_referent) {
            http_response_code(403);
            die('Vous n\'avez pas le droit de modifier cette étape.');
        }
        
        $stmt = $pdo->prepare("SELECT id, is_completed, title FROM project_steps WHERE id = ? AND project_id = ?");
        $stmt->execute([$step_id, $project_id]);
        $step = $stmt->fetch();
        
        if (!$step) {
            header('Location: /projet/' . $project_id);
            exit;
        }
        
        if ($step['is_completed']) {
            $pdo->prepare("UPDATE project_steps SET is_completed = 0, completed_at = NULL, completed_by = NULL WHERE id = ?")->execute([$step_id]);
            log_step_activity($pdo, $project_id, $user_id, 'a dévalidé l\'étape « ' . mb_substr($step['title'], 0, 80) . ' »');
        } else {
            $pdo->prepare("UPDATE project_steps SET is_completed = 1, completed_at = NOW(), completed_by = ? WHERE id = ?")->execute([$user_id, $step_id]);
            log_step_activity($pdo, $project_id, $user_id, 'a validé l\'étape « ' . mb_substr($step['title'], 0, 80) . ' »');
        }
        
        recalc_project_progress($pdo, $project_id);
        header('Location: /projet/' . $project_id);
        exit;

    // ============= ADD (ajouter une étape) =============
    case 'add':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seul le référent ou un admin peut ajouter des étapes.');
        }
        
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($title === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Le titre de l\'étape est obligatoire.'];
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        
        // Position = max + 1
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM project_steps WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $new_pos = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("INSERT INTO project_steps (project_id, position, title, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$project_id, $new_pos, $title, $description ?: null]);
        
        log_step_activity($pdo, $project_id, $user_id, 'a ajouté l\'étape « ' . mb_substr($title, 0, 80) . ' »');
        recalc_project_progress($pdo, $project_id);
        
        $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Étape ajoutée.'];
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    // ============= EDIT (modifier titre/description) =============
    case 'edit':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seul le référent ou un admin peut modifier les étapes.');
        }
        
        $step_id = (int)($_POST['step_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($step_id <= 0 || $title === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Données invalides.'];
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE project_steps SET title = ?, description = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$title, $description ?: null, $step_id, $project_id]);
        
        log_step_activity($pdo, $project_id, $user_id, 'a modifié l\'étape « ' . mb_substr($title, 0, 80) . ' »');
        
        $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Étape modifiée.'];
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    // ============= DELETE (supprimer) =============
    case 'delete':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seul le référent ou un admin peut supprimer des étapes.');
        }
        
        $step_id = (int)($_POST['step_id'] ?? 0);
        if ($step_id <= 0) {
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        
        // Récupérer le titre pour le log
        $stmt = $pdo->prepare("SELECT title FROM project_steps WHERE id = ? AND project_id = ?");
        $stmt->execute([$step_id, $project_id]);
        $step_title = $stmt->fetchColumn();
        
        if ($step_title) {
            $pdo->prepare("DELETE FROM project_steps WHERE id = ? AND project_id = ?")->execute([$step_id, $project_id]);
            log_step_activity($pdo, $project_id, $user_id, 'a supprimé l\'étape « ' . mb_substr($step_title, 0, 80) . ' »');
            recalc_project_progress($pdo, $project_id);
            $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Étape supprimée.'];
        }
        
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    // ============= REORDER (réordonner) =============
    case 'reorder':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seul le référent ou un admin peut réordonner les étapes.');
        }
        
        $order = $_POST['order'] ?? [];
        if (!is_array($order) || empty($order)) {
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE project_steps SET position = ? WHERE id = ? AND project_id = ?");
            foreach ($order as $i => $step_id) {
                $stmt->execute([$i + 1, (int)$step_id, $project_id]);
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Ordre des étapes mis à jour.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
        }
        
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    default:
        header('Location: /projet/' . $project_id);
        exit;
}
