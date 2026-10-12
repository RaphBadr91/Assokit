<?php
/**
 * api/app-update-account.php — Mise a jour du compte perso depuis l'app (natif).
 * Reproduit action-parametres.php (update_account). Retour JSON. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/../demo-guard.php';
// Démo : le nom peut changer, pas l'e-mail de connexion d'un compte partagé
if (ak_demo_protected_account($uid)) {
    $__cur = $pdo->prepare('SELECT email FROM users WHERE id = ?'); $__cur->execute([$uid]);
    if (strtolower(trim((string)($input['email'] ?? ''))) !== strtolower((string)$__cur->fetchColumn())) app_fail(403, 'demo', ak_demo_message());
}


$first = trim((string) ($input['first_name'] ?? ''));
$last  = trim((string) ($input['last_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$city  = trim((string) ($input['city'] ?? ''));

if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    app_fail(422, 'invalid', 'Champs obligatoires manquants ou email invalide.');
}

try {
    // Anti-escalade : une adresse réservée (Fondateur, boîte des alertes d'inscription) ne peut pas
    // être adoptée par un autre compte. Celui qui l'a déjà la garde (simple mise à jour du nom).
    @require_once __DIR__ . '/_app-founder.php';
    require_once __DIR__ . '/../trial-helpers.php';
    if (in_array(strtolower($email), ak_founder_emails(), true)) {
        $cur = $pdo->prepare("SELECT email FROM users WHERE id = ?"); $cur->execute([$uid]);
        if (strtolower(trim((string)$cur->fetchColumn())) !== strtolower($email)
            && (!function_exists('app_is_founder') || !app_is_founder($pdo, $user))) {
            app_fail(403, 'reserved', 'Cette adresse email est réservée.');
        }
    }

    $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $st->execute([$email, $uid]);
    if ($st->fetch()) app_fail(409, 'duplicate', 'Cet email est déjà utilisé.');

    // Même règle que le site (action-parametres.php) : changer l'adresse de connexion
    // demande le mot de passe actuel (champ « current_password »).
    $st = $pdo->prepare("SELECT email, password_hash FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $old_email = (string) ($me['email'] ?? '');
    $email_changed = strtolower($old_email) !== strtolower($email);
    if ($email_changed) {
        $pw = (string) ($input['current_password'] ?? '');
        if ($pw === '') app_fail(403, 'password_required', 'Pour changer d’adresse e-mail, saisissez votre mot de passe actuel.');
        @require_once __DIR__ . '/../rate-limit-helper.php';
        if (function_exists('ak_rate_limit') && !ak_rate_limit('email_change_pw', 8, 600, (string) $uid)) {
            app_fail(429, 'too_many_requests', 'Trop de tentatives. Merci de patienter quelques minutes.');
        }
        if (empty($me['password_hash']) || !password_verify($pw, (string) $me['password_hash'])) {
            app_fail(422, 'invalid_password', 'Mot de passe actuel incorrect : adresse e-mail non modifiée.');
        }
    }

    $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=?, city=? WHERE id=?")
        ->execute([$first, $last, $email, $phone ?: null, $city ?: null, $uid]);

    $message = 'Profil mis à jour.';
    if ($email_changed) {
        $_SESSION['user_email'] = $email;
        require_once __DIR__ . '/../password-token-helper.php';
        ak_account_email_changed($pdo, $uid, $old_email, $email, $first);
        $message = 'Profil mis à jour. Un e-mail de confirmation a été envoyé à votre nouvelle adresse, et l’ancienne a été prévenue.';
    }

    echo json_encode(['ok' => true, 'message' => $message, 'email_changed' => $email_changed], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-update-account] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de mettre à jour le profil.');
}
