<?php
/**
 * ============================================================
 * ASSOKIT — action-equipe.php (v3 — diagnostic amélioré)
 * ============================================================
 * v3 nouveautés :
 *   - Détection précise des nouveaux membres (avant DELETE)
 *   - Message de feedback détaillé (qui a reçu l'email, qui pas)
 *   - Log d'erreur explicite si email échoue
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/projet-email-helpers.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Session expirée, rechargez la page.'];
    header('Location: /projets');
    exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger le projet
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.referent_id, f.org_id 
    FROM projects p 
    JOIN folders f ON p.folder_id = f.id 
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || (int)$project['org_id'] !== $org_id) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Projet introuvable.'];
    header('Location: /projets');
    exit;
}

// Permissions
$is_admin = ($current['role'] === 'admin');
$is_referent = ((int)$project['referent_id'] === (int)$current['id']);

if (!$is_admin && !$is_referent) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Permission refusée.'];
    header('Location: /projet/' . $project_id);
    exit;
}

// =============================================================
// MEMBRES SÉLECTIONNÉS
// =============================================================
$selected = array_map('intval', $_POST['team_members'] ?? []);
$selected = array_unique(array_filter($selected, fn($id) => $id > 0));

if ((int)$project['referent_id'] > 0) {
    $selected[] = (int)$project['referent_id'];
    $selected = array_unique($selected);
}

// Validation org
if (!empty($selected)) {
    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $params = array_merge([$org_id], $selected);
    $stmt = $pdo->prepare("
        SELECT id FROM users 
        WHERE org_id = ? AND id IN ($placeholders)
          AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
    ");
    $stmt->execute($params);
    $valid_ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
} else {
    $valid_ids = [];
}

// =============================================================
// DÉTECTER LES NOUVEAUX MEMBRES (avant le DELETE)
// =============================================================
$stmt = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
$stmt->execute([$project_id]);
$current_member_ids = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));

// Les "nouveaux" sont ceux dans $valid_ids mais pas dans $current_member_ids
$new_member_ids = array_values(array_diff($valid_ids, $current_member_ids));
// Les "retirés" sont ceux dans $current_member_ids mais pas dans $valid_ids  
$removed_member_ids = array_values(array_diff($current_member_ids, $valid_ids));

// =============================================================
// SAUVEGARDE
// =============================================================
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ?");
    $stmt->execute([$project_id]);
    
    if (!empty($valid_ids)) {
        $stmt = $pdo->prepare("
            INSERT INTO project_members (project_id, user_id, role_in_project, joined_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($valid_ids as $uid) {
            $role_proj = ($uid === (int)$project['referent_id']) ? 'referent' : 'member';
            $stmt->execute([$project_id, $uid, $role_proj]);
        }
    }
    
    $pdo->commit();
    
    // =========================================================
    // ENVOYER LES EMAILS AUX NOUVEAUX MEMBRES
    // =========================================================
    $email_sent_to = [];
    $email_failed = [];
    $email_skipped_self = [];
    
    foreach ($new_member_ids as $new_uid) {
        // Ne pas envoyer email à soi-même
        if ($new_uid === (int)$current['id']) {
            $email_skipped_self[] = $new_uid;
            continue;
        }
        
        try {
            $sent = ak_email_project_team_added($pdo, $project_id, $new_uid, (int)$current['id']);
            if ($sent === true) {
                // Récupérer le prénom pour le message
                $name_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $name_stmt->execute([$new_uid]);
                $u = $name_stmt->fetch();
                $email_sent_to[] = $u ? trim($u['first_name'] . ' ' . $u['last_name']) : "user#$new_uid";
            } else {
                $email_failed[] = "user#$new_uid";
            }
        } catch (Throwable $e) {
            error_log("[action-equipe] Erreur envoi email pour user $new_uid : " . $e->getMessage());
            $email_failed[] = "user#$new_uid (erreur)";
        }
    }
    
    // =========================================================
    // CONSTRUIRE LE MESSAGE DE FEEDBACK DÉTAILLÉ
    // =========================================================
    $count = count($valid_ids);
    $parts = ["✅ Équipe mise à jour : $count membre" . ($count > 1 ? 's' : '')];
    
    if (!empty($email_sent_to)) {
        $parts[] = "📧 Email envoyé à : " . implode(', ', $email_sent_to);
    }
    
    if (!empty($email_failed)) {
        $parts[] = "⚠️ Échec email : " . implode(', ', $email_failed);
    }
    
    if (empty($new_member_ids) && !empty($valid_ids)) {
        $parts[] = "ℹ️ Aucun NOUVEAU membre (aucun email à envoyer)";
    }
    
    if (!empty($removed_member_ids)) {
        $parts[] = "🚪 " . count($removed_member_ids) . " membre" . (count($removed_member_ids) > 1 ? 's' : '') . " retiré" . (count($removed_member_ids) > 1 ? 's' : '');
    }
    
    $_SESSION['flash'] = [
        'type' => empty($email_failed) ? 'success' : 'error',
        'message' => implode(' · ', $parts)
    ];
    
} catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Erreur technique : ' . $e->getMessage()
    ];
}

header('Location: /modifier-projet?id=' . $project_id);
exit;
