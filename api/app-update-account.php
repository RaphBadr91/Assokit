<?php
/**
 * api/app-update-account.php — Mise a jour du compte perso depuis l'app (natif).
 * Reproduit action-parametres.php (update_account). Retour JSON. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

$first = trim((string) ($input['first_name'] ?? ''));
$last  = trim((string) ($input['last_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$city  = trim((string) ($input['city'] ?? ''));

if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    app_fail(422, 'invalid', 'Champs obligatoires manquants ou email invalide.');
}

try {
    // Anti-escalade : une adresse réservée au Fondateur ne peut pas être adoptée par un autre compte.
    @require_once __DIR__ . '/_app-founder.php';
    if (function_exists('app_founder_emails') && in_array(strtolower($email), app_founder_emails(), true)
        && (!function_exists('app_is_founder') || !app_is_founder($pdo, $user))) {
        app_fail(403, 'reserved', 'Cette adresse email est réservée.');
    }

    $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $st->execute([$email, $uid]);
    if ($st->fetch()) app_fail(409, 'duplicate', 'Cet email est déjà utilisé.');

    $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=?, city=? WHERE id=?")
        ->execute([$first, $last, $email, $phone ?: null, $city ?: null, $uid]);

    echo json_encode(['ok' => true, 'message' => 'Profil mis à jour.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-update-account] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de mettre à jour le profil.');
}
