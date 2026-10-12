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
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();

require_login();
$user = current_user();
$org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée

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
    SELECT p.*, f.org_id, f.name AS folder_name, f.archived_at AS folder_archived_at
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

// Permissions : admin OU référent du projet ; les coordinateurs peuvent
// aussi MODIFIER (action update), mais ni archiver ni dupliquer.
// L'archivage est réservé à l'admin, comme /supprimer-projet et /archives.
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');
$is_referent = ((int)$project['referent_id'] === (int)$user['id']);
$can_edit = $is_admin || $is_referent || ($is_coord && $action === 'update');
if ($action === 'archive') $can_edit = $is_admin;

if (!$can_edit) {
    header('Location: /projet/' . $project_id . '?error=' . ($action === 'archive' ? 'archive_permission' : 'permission'));
    exit;
}

// Projet archivé (seul ou avec son dossier) : lecture seule jusqu'à sa
// restauration depuis /archives (l'archivage, lui, répond « déjà archivé »).
if ($action !== 'archive' && (!empty($project['archived_at']) || !empty($project['folder_archived_at']))) {
    header('Location: /projet/' . $project_id . '?error=archived');
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
    // Objectif et répartition femmes/hommes : champ absent du formulaire
    // (ancienne page encore ouverte) = valeur en base conservée.
    $objective = array_key_exists('objective', $_POST) ? trim((string)$_POST['objective']) : trim((string)($project['objective'] ?? ''));
    require_once __DIR__ . '/trial-helpers.php';
    $objective = (string)ak_db_fit(ak_db_columns($pdo, 'projects'), 'objective', mb_substr($objective, 0, 2000));
    $location = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? $project['status'];
    $referent_id = (int)($_POST['referent_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? $project['folder_id']);
    $progress = (int)($_POST['progress_percent'] ?? $project['progress_percent']);
    // Un projet avec des étapes a un avancement calculé (étapes validées /
    // total, comme la fiche projet et l'appli) : le curseur n'est proposé
    // qu'aux projets sans étape, et une valeur reçue malgré tout est ignorée.
    $steps_stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_completed), 0) AS done FROM project_steps WHERE project_id = ?");
    $steps_stmt->execute([$project_id]);
    $steps_count = $steps_stmt->fetch();
    $progress_from_steps = (int)$steps_count['total'] > 0;
    if ($progress_from_steps) {
        $progress = (int)round(((int)$steps_count['done'] / (int)$steps_count['total']) * 100);
    }
    $participants = (int)($_POST['participants_count'] ?? 0);
    $participants_female = max(0, (int)($_POST['participants_female'] ?? $project['participants_female'] ?? 0));
    $participants_male = max(0, (int)($_POST['participants_male'] ?? $project['participants_male'] ?? 0));
    // « 1 500,50 », espaces insécables compris (même lecture que la création)
    require_once __DIR__ . '/projet-email-helpers.php';
    $budget_planned = ak_parse_amount($_POST['budget_planned'] ?? $project['budget_planned']);
    $budget_used = ak_parse_amount($_POST['budget_used'] ?? $project['budget_used']);
    // Politique finances stricte (finance-permissions.php) : qui ne voit pas
    // l'argent ne peut pas non plus le modifier. Le formulaire ne lui montre
    // pas le budget ; on garde ici les valeurs en base quoi qu'on reçoive.
    require_once __DIR__ . '/finance-permissions.php';
    if (!user_can_view_finances($user)) {
        $budget_planned = (float)$project['budget_planned'];
        $budget_used = (float)$project['budget_used'];
    }
    // Date vide = pas de date (NULL) : '' est refusé par MariaDB en mode strict.
    $start_date = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $end_date = trim((string)($_POST['end_date'] ?? '')) ?: null;

    // Validations
    $errors = [];
    if ($name === '') $errors[] = 'Le nom du projet est obligatoire';
    if ($participants < 0) $participants = 0;
    // « Archivé » n'est plus un statut qu'on choisit ici : archiver passe par
    // /supprimer-projet (archived_at, /archives, lien public coupé). Un ancien
    // projet déjà en statut « archived » le garde tant qu'on n'en change pas.
    $allowed_status = ['draft', 'active', 'warning', 'done'];
    if ($project['status'] === 'archived') $allowed_status[] = 'archived';
    if (!in_array($status, $allowed_status, true)) {
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

    // Dates : format AAAA-MM-JJ et date réelle (le 31/02 ferait échouer l'UPDATE)
    $valid_date = function ($d) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
    };
    if ($start_date) $start_date = $valid_date($start_date);
    if ($end_date) $end_date = $valid_date($end_date);

    if ($errors) {
        $_SESSION['form_errors'] = $errors;
        header('Location: /modifier-projet/' . $project_id);
        exit;
    }

    // =========================================================
    // ENREGISTREMENT (avant l'historique : on ne journalise jamais une
    // modification qui n'a pas été enregistrée)
    // =========================================================
    $stmt = $pdo->prepare("
        UPDATE projects SET
            name = ?, description = ?, objective = ?, location = ?, status = ?,
            referent_id = ?, folder_id = ?, progress_percent = ?, participants_count = ?,
            participants_female = ?, participants_male = ?,
            budget_planned = ?, budget_used = ?, start_date = ?, end_date = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $description ?: null, $objective ?: null, $location ?: null, $status,
        $referent_id, $folder_id, $progress, $participants,
        $participants_female, $participants_male,
        $budget_planned, $budget_used, $start_date, $end_date,
        $project_id,
    ]);

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

    // 2 bis. Objectif (comme la description : signalé, pas recopié)
    if ($objective !== trim($old['objective'] ?? '')) {
        $old_len = mb_strlen(trim($old['objective'] ?? ''));
        log_project_activity($project_id, $user['id'], 'project_updated',
            $old_len === 0
                ? 'a défini l\'objectif du projet'
                : 'a modifié l\'objectif du projet (' . $old_len . ' → ' . mb_strlen($objective) . ' caractères)',
            ['field' => 'objective']);
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

    // 7. Progression (seulement quand elle est saisie : celle calculée par
    // les étapes n'est pas une modification de l'utilisateur)
    if (!$progress_from_steps && $progress !== (int)$old['progress_percent']) {
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
    if ($participants_female !== (int)($old['participants_female'] ?? 0) || $participants_male !== (int)($old['participants_male'] ?? 0)) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a mis à jour la répartition des participants : '
                . (int)($old['participants_female'] ?? 0) . ' F / ' . (int)($old['participants_male'] ?? 0) . ' H → '
                . $participants_female . ' F / ' . $participants_male . ' H',
            ['field' => 'participants_gender',
             'old' => ['female' => (int)($old['participants_female'] ?? 0), 'male' => (int)($old['participants_male'] ?? 0)],
             'new' => ['female' => $participants_female, 'male' => $participants_male]]);
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

    // 11. Dates (NULL et '' = pas de date : comparaison en chaîne)
    if ((string)$start_date !== (string)($old['start_date'] ?? '')) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié la date de début : ' . ($old['start_date'] ? date('d/m/Y', strtotime($old['start_date'])) : '—') . ' → ' . ($start_date ? date('d/m/Y', strtotime($start_date)) : '—'),
            ['field' => 'start_date', 'old' => $old['start_date'], 'new' => $start_date]);
    }
    if ((string)$end_date !== (string)($old['end_date'] ?? '')) {
        log_project_activity($project_id, $user['id'], 'project_updated',
            'a modifié la date de fin : ' . ($old['end_date'] ? date('d/m/Y', strtotime($old['end_date'])) : '—') . ' → ' . ($end_date ? date('d/m/Y', strtotime($end_date)) : '—'),
            ['field' => 'end_date', 'old' => $old['end_date'], 'new' => $end_date]);
    }

    header('Location: /projet/' . $project_id . '?updated=1');
    exit;
}

// =========================================================
// ACTION : ARCHIVER
// =========================================================
// Même archivage que /supprimer-projet : archived_at posé, le projet quitte
// les listes, passe dans /archives (restaurable ARCHIVE_RETENTION_DAYS jours)
// et son lien public est coupé. Le statut n'est pas modifié.
if ($action === 'archive') {
    require_once __DIR__ . '/archive-helper.php';
    if (!empty($project['archived_at'])) {
        $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'Ce projet est déjà archivé.'];
        header('Location: /projets');
        exit;
    }
    $fa = $pdo->prepare("SELECT archived_at FROM folders WHERE id = ?");
    $fa->execute([(int)$project['folder_id']]);
    if (!empty($fa->fetchColumn())) {
        $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'Le dossier parent est archivé. Impossible d\'archiver ce projet séparément.'];
        header('Location: /projets');
        exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            UPDATE projects
            SET archived_at = NOW(), archived_by_user_id = ?, archived_via_folder = 0
            WHERE id = ? AND archived_at IS NULL
        ")->execute([(int)$user['id'], $project_id]);
        // Même journal que /supprimer-projet : code 'archive_folder',
        // target_type 'project'
        archive_log($pdo, $org_id, 'archive_folder', 'project', $project_id, $project['name'], (int)$user['id'], [
            'status_at_archive' => $project['status'],
            'progress_at_archive' => (int)$project['progress_percent'],
            'folder_name' => $project['folder_name'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[action-projet] archive ' . $project_id . ' : ' . $e->getMessage());
        $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'L\'archivage a échoué. Réessayez dans un instant.'];
        header('Location: /projets');
        exit;
    }
    log_project_activity($project_id, $user['id'], 'status_changed', 'a archivé le projet',
        ['field' => 'archived_at', 'old' => null, 'new' => date('Y-m-d H:i:s')]);
    $_SESSION['flash_projets'] = [
        'type' => 'success',
        'message' => 'Projet "' . $project['name'] . '" archivé avec succès. Restauration possible pendant ' . ARCHIVE_RETENTION_DAYS . ' jours dans /archives.',
    ];
    header('Location: /projets');
    exit;
}

// =========================================================
// ACTION : DUPLIQUER (bonus utile pour créer un projet similaire)
// =========================================================
// La copie reprend la fiche (sans dates ni dépenses), les étapes (toutes à
// refaire, sans assignation) et l'équipe. Pas de color_theme : la couleur est
// celle du dossier, la table projects n'a pas cette colonne.
if ($action === 'duplicate') {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO projects (
                folder_id, name, description, objective, location, status,
                referent_id, progress_percent, participants_count,
                participants_female, participants_male,
                budget_planned, budget_used, start_date, end_date, created_at
            )
            SELECT
                folder_id,
                CONCAT(name, ' (copie)'), description, objective, location, 'draft',
                referent_id, 0, participants_count,
                participants_female, participants_male,
                budget_planned, 0, NULL, NULL, NOW()
            FROM projects WHERE id = ?
        ");
        $stmt->execute([$project_id]);
        $new_id = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO project_steps (project_id, position, title, description)
            SELECT ?, position, title, description
            FROM project_steps WHERE project_id = ?
            ORDER BY position ASC, id ASC
        ")->execute([$new_id, $project_id]);

        // Équipe interne seulement : un suiveur (financeur externe) ne voit que
        // les projets qu'on lui a explicitement ouverts, pas leurs copies.
        $pdo->prepare("
            INSERT IGNORE INTO project_members (project_id, user_id, role_in_project, joined_at)
            SELECT ?, pm.user_id, pm.role_in_project, NOW()
            FROM project_members pm
            JOIN users u ON u.id = pm.user_id
            WHERE pm.project_id = ? AND u.role <> 'follower'
        ")->execute([$new_id, $project_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[action-projet] duplicate ' . $project_id . ' : ' . $e->getMessage());
        header('Location: /projet/' . $project_id . '?error=duplicate');
        exit;
    }

    log_project_activity($new_id, $user['id'], 'project_created',
        'a créé ce projet comme copie de "' . $project['name'] . '"',
        ['source_project_id' => $project_id]);

    header('Location: /projet/' . $new_id . '?duplicated=1');
    exit;
}

header('Location: /projet/' . $project_id);
exit;
