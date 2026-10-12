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
 *   - reset_2fa : réinitialiser la double authentification (téléphone perdu)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/demo-guard.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
@require_once __DIR__ . '/plan-helpers.php';     // limite d'utilisateurs du plan (ak_can_add_user)
activity_log_request();

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

if ($current['role'] !== 'admin') {
    header('Location: /dashboard?error=not_admin');
    exit;
}
// Sans association (agent de collectivité, rôle admin, org_id vide) : aucun compte à gérer. Un
// compte créé ici tombait dans l'espace « org 0 », commun à toutes les collectivités.
if ($org_id <= 0) {
    header('Location: /dashboard');
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

// Démo : les comptes de connexion partagés (admin@, salarie@…) ne se
// modifient pas, ne se désactivent pas et ne voient pas leur mot de passe réinitialisé.
if ($action !== 'create' && ak_demo_protected_account((int)($_POST['user_id'] ?? 0))) {
    header('Location: /admin-modifier-utilisateur/' . (int)$_POST['user_id'] . '?error=demo');
    exit;
}

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

// Helper : récupérer et valider un user cible. Un compte supprimé (corbeille de /adherents)
// est introuvable ici : le réactiver créerait un compte connecté mais absent des listes.
function get_target_user($pdo, $user_id, $org_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id, $org_id]);
    $t = $stmt->fetch() ?: null;
    // Un compte plateforme (fondateur / super admin) ne se gère jamais depuis l'admin d'une asso :
    // sinon un admin d'asso réinitialise son mot de passe ou change son email et prend le compte.
    if ($t && (!empty($t['is_super_admin']) || !empty($t['is_founder'])
               || in_array($t['role'] ?? '', ['super_admin', 'founder'], true))) return null;
    return $t;
}

// Helper : limite d'utilisateurs du plan, vérifiée à l'enregistrement (la page de création
// ne fait que l'afficher). Renvoie le message de refus, ou null si l'ajout est permis.
function plan_user_limit_error($pdo, $org_id) {
    if (!function_exists('ak_can_add_user')) return null;
    $chk = ak_can_add_user($pdo, (int)$org_id);
    return empty($chk['ok']) ? ($chk['reason'] ?: 'Limite d\'utilisateurs de votre plan atteinte.') : null;
}

// Helper : limite d'adhérents du plan (un compte actif compte comme adhérent), même règle que
// nouveau-adherent.php, l'import, la corbeille et modifier-adherent.php.
function plan_adherent_limit_error($pdo, $org_id) {
    if (!function_exists('ak_can_add_adherent')) return null;
    $chk = ak_can_add_adherent($pdo, (int)$org_id);
    return empty($chk['ok']) ? ($chk['reason'] ?: 'Limite d’adhérents de votre plan atteinte.') : null;
}

// Helper : rendre inutilisables les liens d'invitation et « mot de passe oublié » déjà envoyés
function revoke_password_tokens($pdo, $user_id) {
    try {
        $pdo->prepare("UPDATE user_password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([(int)$user_id]);
    } catch (Throwable $e) {
        error_log('[action-admin-utilisateur] jetons : ' . $e->getMessage());
    }
}

// Helper : générer un mot de passe temporaire lisible
function generate_temp_password() {
    // Lisible mais imprévisible (CSPRNG) : mot + 2 groupes de 4 caractères sans ambiguïté
    $words = ['assos', 'projet', 'bureau', 'cafe', 'paper', 'livre', 'metro', 'radio'];
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    $g = function () use ($abc) { $o = ''; for ($i = 0; $i < 4; $i++) $o .= $abc[random_int(0, strlen($abc) - 1)]; return $o; };
    return $words[random_int(0, count($words) - 1)] . '-' . $g() . '-' . $g();
}

// Helper : parser les capacités depuis $_POST.
// Suiveur (financeur externe) : aucune capacité. Le formulaire masque la section pour ce rôle,
// mais les cases cochées avant le changement de rôle partaient quand même et ouvraient
// Comptabilité, Export FEC, Prévisions… (can('manage_finances')) au financeur.
function parse_capabilities($post, $role = '') {
    if ($role === 'follower') {
        return array_fill_keys(['can_create_projects', 'can_manage_members', 'can_manage_finances',
            'can_access_marketing', 'can_manage_events', 'can_moderate_messages'], 0);
    }
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
    if ($limit_err = plan_user_limit_error($pdo, $org_id)) {
        $_SESSION['form_errors'] = [$limit_err];
        $_SESSION['form_data'] = $_POST;
        header('Location: /admin-nouveau-utilisateur');
        exit;
    }

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
    $caps = parse_capabilities($_POST, $role);

    // Mot de passe temporaire
    $temp_password = $_POST['initial_password'] ?? '';
    if ($temp_password === '') {
        $temp_password = generate_temp_password();
    }
    $password_hash = password_hash($temp_password, PASSWORD_BCRYPT, ['cost' => 10]);
    $must_change = isset($_POST['must_change_password']) ? 1 : 0;

    // Token ICS (lien d'abonnement à l'agenda) : aléatoire, imprévisible, 40 caractères hexadécimaux
    $ics_token = bin2hex(random_bytes(20));

    // Insertion
    $stmt = $pdo->prepare("
        INSERT INTO users (
            org_id, first_name, last_name, email, phone, city,
            password_hash, role, contract_type, contract_start_date, contract_end_date,
            organization_name, avatar_color, adhesion_date,
            can_create_projects, can_manage_members, can_manage_finances,
            can_access_marketing, can_manage_events, can_moderate_messages,
            is_active, must_change_password, created_by_user_id, notes_admin, ics_token, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            1, ?, ?, ?, ?, NOW()
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
    $_SESSION['admin_temp_password'] = $temp_password;
    header('Location: /admin?created=1&password_reset=1');
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

    // Sa propre adresse de connexion se change dans Paramètres › Compte, mot de passe demandé :
    // sinon une session restée ouverte change l'adresse ici, puis « Mot de passe oublié » sur la
    // nouvelle adresse prend le compte.
    if ($is_self && strcasecmp($email, (string)$target['email']) !== 0) {
        $_SESSION['form_errors'] = ['Pour changer votre propre adresse de connexion, passez par Paramètres › Compte (mot de passe demandé).'];
        header('Location: /admin-modifier-utilisateur/' . $target_id);
        exit;
    }

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

    $caps = parse_capabilities($_POST, $role);

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

    // Adresse de connexion d'un autre compte modifiée : l'ancienne adresse est prévenue, la nouvelle
    // doit être confirmée, et les liens d'invitation ou de mot de passe déjà envoyés à l'ancienne
    // adresse ne servent plus (ils donneraient la main sur le compte à qui lit cette boîte).
    if (!$is_self && strcasecmp($email, (string)$target['email']) !== 0) {
        revoke_password_tokens($pdo, $target_id);
        require_once __DIR__ . '/password-token-helper.php';
        ak_account_email_changed($pdo, $target_id, (string)$target['email'], $email, $first_name, true);
    }

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
    // Liens d'invitation ou « mot de passe oublié » déjà envoyés : consommés. Deuxième barrière
    // (validate_password_token refuse déjà un compte désactivé), qui tient après une réactivation.
    revoke_password_tokens($pdo, $target_id);

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
    // Un compte réactivé compte à nouveau dans les limites d'utilisateurs et d'adhérents du plan
    if (empty($target['is_active'])
        && ($limit_err = plan_user_limit_error($pdo, $org_id) ?? plan_adherent_limit_error($pdo, $org_id))) {
        $_SESSION['form_errors'] = [rtrim($limit_err, '. ') . '. Passez à un plan supérieur pour réactiver ce compte.'];
        header('Location: /admin-modifier-utilisateur/' . $target_id);
        exit;
    }

    $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND org_id = ? AND deleted_at IS NULL")
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
    // Son propre mot de passe se change dans Paramètres › Sécurité, avec le mot de passe actuel :
    // une session restée ouverte ne doit pas pouvoir en poser un nouveau d'ici.
    if ($target_id === (int)$current['id']) {
        header('Location: /parametres?tab=securite');
        exit;
    }

    // Nouveau mot de passe temporaire
    $temp_password = generate_temp_password();
    $password_hash = password_hash($temp_password, PASSWORD_BCRYPT, ['cost' => 10]);

    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?")
        ->execute([$password_hash, $target_id]);

    log_admin_action($current['id'], $target_id, 'reset_password', ['email' => $target['email']]);

    $_SESSION['admin_temp_password'] = $temp_password;
    header('Location: /admin?password_reset=1');
    exit;
}

// =========================================================
// ACTION : RÉINITIALISER LA DOUBLE AUTHENTIFICATION
// =========================================================
// Téléphone perdu sans code de secours : l'administrateur de l'association retire la 2FA du
// compte ; à la prochaine connexion, le mot de passe suffit et l'utilisateur peut la réactiver.
if ($action === 'reset_2fa') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $target = get_target_user($pdo, $target_id, $org_id);
    if (!$target) {
        header('Location: /admin?error=notfound');
        exit;
    }
    // Sa propre 2FA se gère dans Paramètres › Sécurité (mot de passe demandé)
    if ($target_id === (int)$current['id']) {
        header('Location: /parametres?tab=securite');
        exit;
    }

    require_once __DIR__ . '/totp-helper.php';
    if (!ak_totp_reset($pdo, $target_id, $org_id)) {
        header('Location: /admin?error=totp_off');
        exit;
    }

    log_admin_action($current['id'], $target_id, 'reset_2fa', ['email' => $target['email']]);

    header('Location: /admin?totp_reset=1');
    exit;
}

header('Location: /admin');
exit;
