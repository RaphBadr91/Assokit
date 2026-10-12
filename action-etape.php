<?php
/**
 * ============================================================
 * ASSOKIT — Action : gestion des étapes d'un projet
 * ============================================================
 * Actions supportées (POST) :
 *   - toggle  : valider/dévalider une étape (existant) ; target = état voulu
 *               (1/0), sans effet si l'étape y est déjà ; à la validation,
 *               un compte-rendu facultatif (completion_note)
 *   - note    : écrire ou corriger le compte-rendu d'une étape validée
 *   - add     : ajouter une nouvelle étape (assigned_to : e-mail à l'assigné)
 *   - edit    : modifier le titre/description/assignation d'une étape
 *   - delete  : supprimer une étape
 *   - reorder : réordonner les étapes
 * 
 * Permissions :
 *   - toggle, note : admin, coordinator, référent du projet
 *   - add/edit/delete/reorder : admin, coordinator, référent du projet
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();

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
    SELECT p.id, p.referent_id, p.archived_at, f.org_id, f.archived_at AS folder_archived_at
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

// Projet archivé (seul ou avec son dossier) : lecture seule jusqu'à sa
// restauration depuis /archives.
if (!empty($project['archived_at']) || !empty($project['folder_archived_at'])) {
    header('Location: /projet/' . $project_id . '?error=archived');
    exit;
}

// Permissions globales
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');
$is_referent = ((int)$project['referent_id'] === $user_id);
// add/edit/delete/reorder : comme la modification de la fiche (action-projet.php)
$can_modify_steps = $is_admin || $is_coord || $is_referent;

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

/**
 * La colonne completion_note arrive par migration
 * (migrations/2026-10-05-etapes-compte-rendu.sql) : tant qu'elle manque,
 * on valide l'étape comme avant, sans compte-rendu.
 */
function step_note_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $q = $pdo->query("SHOW COLUMNS FROM project_steps LIKE 'completion_note'");
            $ok = (bool)$q->fetch();
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Compte-rendu saisi : texte libre, borné, vide => NULL. */
function step_note_input(): ?string {
    $n = trim(str_replace("\r\n", "\n", (string)($_POST['completion_note'] ?? '')));
    if ($n === '') return null;
    return mb_substr($n, 0, 2000);
}

/**
 * Personne choisie dans « Assigner à… » : un membre actif de l'association,
 * sinon personne (NULL). Valeur vide = étape non assignée.
 * Un suiveur (financeur) ne voit que les projets qu'il suit : il n'est
 * assignable que s'il fait partie de l'équipe du projet, comme pour les
 * @mentions (ak_mention_candidates).
 */
function step_assignee_input(PDO $pdo, int $org_id, int $project_id): ?int {
    $uid = (int)($_POST['assigned_to'] ?? 0);
    if ($uid <= 0) return null;
    $check = $pdo->prepare("
        SELECT id FROM users
        WHERE id = ? AND org_id = ? AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
          AND (COALESCE(role, '') <> 'follower'
               OR id IN (SELECT user_id FROM project_members WHERE project_id = ?))
    ");
    $check->execute([$uid, $org_id, $project_id]);
    return $check->fetchColumn() ? $uid : null;
}

/** E-mail + notification à la personne assignée (jamais à soi-même). */
function step_notify_assignee(PDO $pdo, int $step_id, ?int $assignee, int $user_id): string {
    if (!$assignee || $assignee === $user_id) return '';
    try {
        require_once __DIR__ . '/projet-email-helpers.php';
        $sent = ak_email_step_assigned($pdo, $step_id, $assignee, $user_id);
    } catch (Throwable $e) {
        error_log('[action-etape] e-mail assignation étape ' . $step_id . ' : ' . $e->getMessage());
        $sent = false;
    }
    $n = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $n->execute([$assignee]);
    $u = $n->fetch();
    $name = $u ? trim($u['first_name'] . ' ' . $u['last_name']) : '';
    return $sent
        ? ' · 📧 ' . $name . ' a été prévenu(e) par e-mail.'
        : ' · ⚠️ L\'e-mail à ' . $name . ' n\'a pas pu être envoyé.';
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
        
        // État voulu envoyé par le bouton (target=1 « Valider », 0 « Dévalider ») :
        // un double clic ou une page pas à jour ne défait pas ce qui vient
        // d'être fait. Sans target (ancien formulaire), on inverse l'état.
        $is_done = !empty($step['is_completed']);
        $target_raw = $_POST['target'] ?? '';
        $target = ($target_raw === '0' || $target_raw === '1') ? ($target_raw === '1') : !$is_done;
        if ($target === $is_done) {
            header('Location: /projet/' . $project_id . '#etape-' . $step_id);
            exit;
        }

        // UPDATE conditionné à l'état lu : de deux requêtes simultanées, une
        // seule change l'étape et écrit l'historique.
        if (!$target) {
            $upd = $pdo->prepare("UPDATE project_steps SET is_completed = 0, completed_at = NULL, completed_by = NULL WHERE id = ? AND is_completed = 1");
            $upd->execute([$step_id]);
            if ($upd->rowCount() > 0) {
                log_step_activity($pdo, $project_id, $user_id, 'a dévalidé l\'étape « ' . mb_substr($step['title'], 0, 80) . ' »');
            }
        } else {
            $note = step_note_input();
            if (step_note_available($pdo)) {
                $upd = $pdo->prepare("UPDATE project_steps SET is_completed = 1, completed_at = NOW(), completed_by = ?, completion_note = ? WHERE id = ? AND (is_completed = 0 OR is_completed IS NULL)");
                $upd->execute([$user_id, $note, $step_id]);
            } else {
                $upd = $pdo->prepare("UPDATE project_steps SET is_completed = 1, completed_at = NOW(), completed_by = ? WHERE id = ? AND (is_completed = 0 OR is_completed IS NULL)");
                $upd->execute([$user_id, $step_id]);
            }
            if ($upd->rowCount() > 0) {
                log_step_activity($pdo, $project_id, $user_id, 'a validé l\'étape « ' . mb_substr($step['title'], 0, 80) . ' »'
                    . ($note !== null ? ' — ' . mb_substr($note, 0, 120) . (mb_strlen($note) > 120 ? '…' : '') : ''));
            }
        }

        recalc_project_progress($pdo, $project_id);
        header('Location: /projet/' . $project_id . '#etape-' . $step_id);
        exit;

    // ============= NOTE (compte-rendu d'une étape validée) =============
    case 'note':
        $step_id = (int)($_POST['step_id'] ?? 0);
        if (!$is_admin && !$is_coord && !$is_referent) {
            http_response_code(403);
            die('Vous n\'avez pas le droit de modifier cette étape.');
        }
        if ($step_id > 0 && step_note_available($pdo)) {
            $stmt = $pdo->prepare("SELECT title FROM project_steps WHERE id = ? AND project_id = ? AND is_completed = 1");
            $stmt->execute([$step_id, $project_id]);
            $title = $stmt->fetchColumn();
            if ($title !== false) {
                $note = step_note_input();
                $pdo->prepare("UPDATE project_steps SET completion_note = ? WHERE id = ? AND project_id = ?")
                    ->execute([$note, $step_id, $project_id]);
                log_step_activity($pdo, $project_id, $user_id, 'a ' . ($note === null ? 'retiré' : 'complété') . ' le compte-rendu de l\'étape « ' . mb_substr($title, 0, 80) . ' »');
            }
        }
        header('Location: /projet/' . $project_id . '#etape-' . $step_id);
        exit;

    // ============= ADD (ajouter une étape) =============
    case 'add':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seuls l\'administrateur, les coordinateurs et le référent du projet peuvent ajouter des étapes.');
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
        
        $assigned = step_assignee_input($pdo, $org_id, $project_id);

        $stmt = $pdo->prepare("INSERT INTO project_steps (project_id, position, title, description, assigned_to_user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $new_pos, $title, $description ?: null, $assigned]);
        $new_step_id = (int)$pdo->lastInsertId();

        log_step_activity($pdo, $project_id, $user_id, 'a ajouté l\'étape « ' . mb_substr($title, 0, 80) . ' »');
        recalc_project_progress($pdo, $project_id);

        $notice = step_notify_assignee($pdo, $new_step_id, $assigned, $user_id);
        $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Étape ajoutée.' . $notice];
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    // ============= EDIT (modifier titre/description) =============
    case 'edit':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seuls l\'administrateur, les coordinateurs et le référent du projet peuvent modifier les étapes.');
        }
        
        $step_id = (int)($_POST['step_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($step_id <= 0 || $title === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Données invalides.'];
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT assigned_to_user_id FROM project_steps WHERE id = ? AND project_id = ?");
        $stmt->execute([$step_id, $project_id]);
        $old_step = $stmt->fetch();
        if (!$old_step) {
            header('Location: /modifier-etapes?id=' . $project_id);
            exit;
        }
        $old_assigned = $old_step['assigned_to_user_id'] !== null ? (int)$old_step['assigned_to_user_id'] : null;
        // Champ absent du formulaire, ou même personne qu'avant = assignation
        // inchangée (aucun nouvel e-mail) ; sinon la nouvelle est vérifiée
        $posted_assigned = (int)($_POST['assigned_to'] ?? 0);
        if (!array_key_exists('assigned_to', $_POST) || ($old_assigned !== null && $posted_assigned === $old_assigned)) {
            $assigned = $old_assigned;
        } else {
            $assigned = step_assignee_input($pdo, $org_id, $project_id);
        }

        $stmt = $pdo->prepare("UPDATE project_steps SET title = ?, description = ?, assigned_to_user_id = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$title, $description ?: null, $assigned, $step_id, $project_id]);

        log_step_activity($pdo, $project_id, $user_id, 'a modifié l\'étape « ' . mb_substr($title, 0, 80) . ' »');

        $notice = ($assigned !== $old_assigned) ? step_notify_assignee($pdo, $step_id, $assigned, $user_id) : '';
        $_SESSION['flash'] = ['type' => 'success', 'message' => '✅ Étape modifiée.' . $notice];
        header('Location: /modifier-etapes?id=' . $project_id);
        exit;

    // ============= DELETE (supprimer) =============
    case 'delete':
        if (!$can_modify_steps) {
            http_response_code(403);
            die('Seuls l\'administrateur, les coordinateurs et le référent du projet peuvent supprimer des étapes.');
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
            die('Seuls l\'administrateur, les coordinateurs et le référent du projet peuvent réordonner les étapes.');
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
