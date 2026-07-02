<?php
/**
 * ============================================================
 * ASSOKIT — Actions admin sur utilisateurs
 * ============================================================
 * Gère :
 *   - create : créer un nouveau compte
 *   - update : modifier un compte existant
 *   - deactivate : désactiver (l'utilisateur ne peut plus se connecter)
 *   - reactivate : réactiver un compte désactivé
 *   - reset_password : générer un nouveau mot de passe temporaire
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

if ($current['role'] !== 'admin') {
    header('Location: /dashboard?error=not_admin');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /admin?error=csrf');
    exit;
}

$action = $_POST['action'] ?? '';

// Helper : logger une action admin
function log_admin_action($admin_id, $target_id, $action_name, $details = null) {
    global $pdo, $org_id;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (org_id, admin_user_id, target_user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $org_id,
            $admin_id,
            $target_id,
            $action_name,
            is_array($details) ? json_encode($details) : $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Exception $e) {
        // Log silencieux si erreur
    }
}

// Helper : récupérer et valider un user cible
function get_target_user($pdo, $user_id, $org_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND org_id = ?");
    $stmt->execute([$user_id, $org_id]);
    return $stmt->fetch() ?: null;
}

// Helper : générer un mot de passe temporaire lisible
function generate_temp_password() {
    $words = ['assos', 'projet', 'bureau', 'cafe', 'paper', 'livre', 'metro', 'radio'];
    $word = $words[array_rand($words)];
    $number = mt_rand(1000, 9999);
    return $word . '-' . $number;
}

// Helper : parser les capacités depuis $_POST
function parse_capabilities($post) {
    return [
        'can_create_projects' => isset($post['can_create_projects']) ? 1 : 0,
        'can_manage_members' => isset($post['can_manage_members']) ? 1 : 0,
        'can_manage_finances' => isset($post['can_manage_finances']) ? 1 : 0,
        'can_access_marketing' => isset($post['can_access_marketing']) ? 1 : 0,
        'can_manage_events' => isset($post['can_manage_events']) ? 1 : 0,
        'can_moderate_messages' => isset($post['can_moderate_messages']) ? 1 : 0,
    ];
}

// Helper : valider valeurs enum
function validate_role($role) {
    $valid = ['admin', 'coordinator', 'referent', 'member', 'follower'];
    return in_array($role, $valid, true) ? $role : 'member';
}
function validate_contract($ct) {
    $valid = ['volunteer', 'employee', 'intern', 'civic_service', 'contractor', 'external'];
    return in_array($ct, $valid, true) ? $ct : 'volunteer';
}
function validate_avatar_color($color) {
    $valid = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red', 'gray'];
    return in_array($color, $valid, true) ? $color : 'gray';
}

// =========================================================
// ACTION : CRÉER UN UTILISATEUR
// =========================================================
if ($action === 'create') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $role = validate_role($_POST['role'] ?? 'member');
    $contract_type = validate_contract($_POST['contract_type'] ?? 'volunteer');
    $contract_start = $_POST['contract_start_date'] ?? '';
    $contract_end = $_POST['contract_end_date'] ?? '';
    $organization_name = trim($_POST['organization_name'] ?? '');
    $avatar_color = validate_avatar_color($_POST['avatar_color'] ?? 'gray');
    $adhesion_date = $_POST['adhesion_date'] ?? '';
    $notes_admin = trim($_POST['notes_admin'] ?? '');

    // Validation
    $errors = [];
    if ($first_name === '') $errors[] = 'Prénom obligatoire';
    if ($last_name === '') $errors[] = 'Nom obligatoire';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide';

    // Vérifier unicité de l'email
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetch()) $errors[] = 'Cet email est déjà utilisé';

    // Dates valides ?
    if ($contract_start && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contract_start)) $contract_start = null;
    if ($contract_end && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contract_end)) $contract_end = null;
    if ($adhesion_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $adhesion_date)) $adhesion_date = null;

    if ($errors) {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /admin-nouveau-utilisateur');
        exit;
    }

    // Capacités
    $caps = parse_capabilities($_POST);

    // Mot de passe temporaire
    $temp_password = $_POST['initial_password'] ?? '';
    if ($temp_password === '') {
        $temp_password = generate_temp_password();
    }
    $password_hash = password_hash($temp_password, PASSWORD_BCRYPT, ['cost' => 10]);
    $must_change = isset($_POST['must_change_password']) ? 1 : 0;

    // Token ICS
    $ics_token = sha1($email . '-' . mt_rand() . '-' . time());

    // Insertion
    $stmt = $pdo->prepare("
        INSERT INTO users (
            org_id, first_name, last_name, email, phone, city,
            password_hash, role, contract_type, contract_start_date, contract_end_date,
            organization_name, avatar_color, adhesion_date,
            can_create_projects, can_manage_members, can_manage_finances,
            can_access_marketing, can_manage_events, can_moderate_messages,
            is_active, must_change_password, created_by_user_id, notes_admin, ics_token
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            1, ?, ?, ?, ?
        )
    ");
    $stmt->execute([
        $org_id, $first_name, $last_name, $email, $phone ?: null, $city ?: null,
        $password_hash, $role, $contract_type, $contract_start ?: null, $contract_end ?: null,
        $organization_name ?: null, $avatar_color, $adhesion_date ?: null,
        $caps['can_create_projects'], $caps['can_manage_members'], $caps['can_manage_finances'],
        $caps['can_access_marketing'], $caps['can_manage_events'], $caps['can_moderate_messages'],
        $must_change, $current['id'], $notes_admin ?: null, $ics_token,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    log_admin_action($current['id'], $new_id, 'create_user', [
        'email' => $email, 'role' => $role, 'contract_type' => $contract_type,
    ]);

    // Rediriger avec mot de passe affiché (seule fois où il est visible en clair)
    header('Location: /admin?created=1&password_reset=' . urlencode($temp_password));
    exit;
}

// =========================================================
// ACTION : MODIFIER UN UTILISATEUR
// =========================================================
if ($action === 'update') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $target = get_target_user($pdo, $target_id, $org_id);
    if (!$target) {
        header('Location: /admin?error=notfound');
        exit;
    }

    // Protection : impossible de se rétrograder soi-même d'admin
    $is_self = ($target_id === (int)$current['id']);

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $role = validate_role($_POST['role'] ?? $target['role']);
    $contract_type = validate_contract($_POST['contract_type'] ?? 'volunteer');
    $contract_start = $_POST['contract_start_date'] ?? '';
    $contract_end = $_POST['contract_end_date'] ?? '';
    $organization_name = trim($_POST['organization_name'] ?? '');
    $avatar_color = validate_avatar_color($_POST['avatar_color'] ?? 'gray');
    $adhesion_date = $_POST['adhesion_date'] ?? '';
    $notes_admin = trim($_POST['notes_admin'] ?? '');

    // Si l'admin se modifie lui-même, on force role = admin (sécurité)
    if ($is_self) $role = 'admin';

    // Validation
    $errors = [];
    if ($first_name === '') $errors[] = 'Prénom obligatoire';
    if ($last_name === '') $errors[] = 'Nom obligatoire';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide';

    // Vérifier unicité si email changé
    if ($email !== $target['email']) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $check->execute([$email, $target_id]);
        if ($check->fetch()) $errors[] = 'Cet email est déjà utilisé par un autre compte';
    }

    if ($contract_start && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contract_start)) $contract_start = null;
    if ($contract_end && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contract_end)) $contract_end = null;
    if ($adhesion_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $adhesion_date)) $adhesion_date = null;

    if ($errors) {
        $_SESSION['form_errors'] = $errors;
        header('Location: /admin-modifier-utilisateur/' . $target_id);
        exit;
    }

    $caps = parse_capabilities($_POST);

    $stmt = $pdo->prepare("
        UPDATE users SET
            first_name = ?, last_name = ?, email = ?, phone = ?, city = ?,
            role = ?, contract_type = ?, contract_start_date = ?, contract_end_date = ?,
            organization_name = ?, avatar_color = ?, adhesion_date = ?,
            can_create_projects = ?, can_manage_members = ?, can_manage_finances = ?,
            can_access_marketing = ?, can_manage_events = ?, can_moderate_messages = ?,
            notes_admin = ?
        WHERE id = ? AND org_id = ?
    ");
    $stmt->execute([
        $first_name, $last_name, $email, $phone ?: null, $city ?: null,
        $role, $contract_type, $contract_start ?: null, $contract_end ?: null,
        $organization_name ?: null, $avatar_color, $adhesion_date ?: null,
        $caps['can_create_projects'], $caps['can_manage_members'], $caps['can_manage_finances'],
        $caps['can_access_marketing'], $caps['can_manage_events'], $caps['can_moderate_messages'],
        $notes_admin ?: null,
        $target_id, $org_id,
    ]);

    log_admin_action($current['id'], $target_id, 'update_user', [
        'changed_fields' => 'multiple',
    ]);

    header('Location: /admin-modifier-utilisateur/' . $target_id . '?updated=1');
    exit;
}

// =========================================================
// ACTION : DÉSACTIVER UN COMPTE
// =========================================================
if ($action === 'deactivate') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $target = get_target_user($pdo, $target_id, $org_id);
    if (!$target) {
        header('Location: /admin?error=notfound');
        exit;
    }
    // On ne peut pas se désactiver soi-même
    if ($target_id === (int)$current['id']) {
        header('Location: /admin-modifier-utilisateur/' . $target_id . '?error=cannot_deactivate_self');
        exit;
    }

    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND org_id = ?")
        ->execute([$target_id, $org_id]);

    log_admin_action($current['id'], $target_id, 'deactivate_user', ['email' => $target['email']]);

    header('Location: /admin?deactivated=1');
    exit;
}

// =========================================================
// ACTION : RÉACTIVER UN COMPTE
// =========================================================
if ($action === 'reactivate') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $target = get_target_user($pdo, $target_id, $org_id);
    if (!$target) {
        header('Location: /admin?error=notfound');
        exit;
    }

    $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND org_id = ?")
        ->execute([$target_id, $org_id]);

    log_admin_action($current['id'], $target_id, 'reactivate_user', ['email' => $target['email']]);

    header('Location: /admin?reactivated=1');
    exit;
}

// =========================================================
// ACTION : RESET MOT DE PASSE
// =========================================================
if ($action === 'reset_password') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $target = get_target_user($pdo, $target_id, $org_id);
    if (!$target) {
        header('Location: /admin?error=notfound');
        exit;
    }

    // Nouveau mot de passe temporaire
    $temp_password = generate_temp_password();
    $password_hash = password_hash($temp_password, PASSWORD_BCRYPT, ['cost' => 10]);

    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?")
        ->execute([$password_hash, $target_id]);

    log_admin_action($current['id'], $target_id, 'reset_password', ['email' => $target['email']]);

    header('Location: /admin?password_reset=' . urlencode($temp_password));
    exit;
}

header('Location: /admin');
exit;
