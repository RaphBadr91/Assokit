<?php
/**
 * api/app-create-member.php — Ajout d'un adherent depuis l'app (natif).
 * Reproduit fidelement la logique de nouveau-adherent.php (magic link).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../password-token-helper.php';
@require_once __DIR__ . '/../plan-helpers.php';

// Meme controle de role que la page web
if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Rôle insuffisant pour ajouter un adhérent.');
}

// Meme blocage de limite de plan
if (function_exists('ak_can_add_adherent')) {
    $check = ak_can_add_adherent($pdo, $org_id);
    if (empty($check['ok'])) {
        app_fail(403, 'plan_limit', ($check['reason'] ?? 'Limite d\'adhérents atteinte') . '. Passez au plan supérieur.');
    }
}

$first = trim((string) ($input['first_name'] ?? ''));
$last  = trim((string) ($input['last_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$city  = trim((string) ($input['city'] ?? ''));
$role  = (string) ($input['role'] ?? 'member');
$send  = !empty($input['send_email']);

$valid_roles = ['admin', 'coordinator', 'referent', 'member', 'follower'];
if (!in_array($role, $valid_roles, true)) $role = 'member';
// Un coordinateur ne peut pas creer d'admin
if ($role === 'admin' && ($user['role'] ?? '') !== 'admin') $role = 'coordinator';

if ($first === '' || $last === '') app_fail(422, 'invalid', 'Le prénom et le nom sont obligatoires.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email invalide.');

$stmt = $pdo->prepare("SELECT id, deleted_at FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if ($existing && $existing['deleted_at'] === null) {
    app_fail(409, 'duplicate', 'Un utilisateur avec cet email existe déjà.');
}

try {
    $placeholder_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    $colors = ['blue', 'purple', 'amber', 'pink', 'teal'];
    $avatar_color = $colors[array_rand($colors)];
    $perms = match ($role) {
        'admin'       => [1, 1, 1, 1, 1, 1],
        'coordinator' => [1, 1, 0, 1, 1, 1],
        'referent'    => [1, 0, 0, 0, 1, 0],
        default       => [0, 0, 0, 0, 0, 0],
    };

    $stmt = $pdo->prepare("
        INSERT INTO users
            (org_id, role, email, password_hash, first_name, last_name,
             phone, city, avatar_color, adhesion_date,
             must_change_password, is_active,
             can_create_projects, can_manage_members, can_manage_finances,
             can_access_marketing, can_manage_events, can_moderate_messages,
             created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 1, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $org_id, $role, $email, $placeholder_hash, $first, $last,
        $phone ?: null, $city ?: null, $avatar_color,
        ...$perms,
    ]);
    $new_id = (int) $pdo->lastInsertId();

    $email_ok = false;
    if ($send && function_exists('create_password_token') && function_exists('send_welcome_email')) {
        try {
            $token = create_password_token($pdo, $new_id, 'set_password');
            $org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $org->execute([$org_id]);
            $org_name = ($org->fetch(PDO::FETCH_ASSOC)['name'] ?? 'votre association');
            $email_ok = send_welcome_email($email, $first, $org_name, $token);
        } catch (Throwable $e) {
            error_log('[app-create-member email] ' . $e->getMessage());
        }
    }

    echo json_encode([
        'ok'       => true,
        'id'       => $new_id,
        'email_ok' => $email_ok,
        'message'  => $first . ' ' . $last . ' a été ajouté(e).'
            . ($send ? ($email_ok ? ' Un email de création de mot de passe a été envoyé.' : ' (Email non envoyé.)') : ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-member] ' . $e->getMessage());
    app_fail(500, 'server', 'Erreur technique lors de la création.');
}
