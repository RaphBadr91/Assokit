<?php
/**
 * api/app-change-password.php — Changement de mot de passe depuis l'app.
 *
 * Parité avec action-parametres.php (action `change_password`) : 8 caractères
 * minimum, confirmation identique, vérification du mot de passe actuel, et
 * remise à zéro de `must_change_password`.
 *
 * Le point d'entrée est limité en fréquence : sans cela il permettrait de
 * tester le mot de passe actuel en boucle depuis une session volée.
 */
require_once __DIR__ . '/_app-write-boot.php';

if (!function_exists('ak_rate_limit_or_die')) {
    @require_once __DIR__ . '/../rate-limit-helper.php';
}
if (function_exists('ak_rate_limit_or_die')) {
    ak_rate_limit_or_die('app_change_password', 8, 600, (string) $uid);
}

$current = (string) ($input['current_password'] ?? '');
$new     = (string) ($input['new_password'] ?? '');
$confirm = (string) ($input['confirm_password'] ?? '');

if (strlen($new) < 8) app_fail(422, 'invalid', 'Nouveau mot de passe trop court (8 caractères minimum).');
if ($new !== $confirm) app_fail(422, 'invalid', 'Les deux nouveaux mots de passe ne correspondent pas.');
if ($new === $current) app_fail(422, 'invalid', 'Le nouveau mot de passe est identique à l’actuel.');

try {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, (string) $row['password_hash'])) {
        app_fail(422, 'invalid', 'Mot de passe actuel incorrect.');
    }

    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
} catch (Throwable $e) {
    error_log('[app-change-password] ' . $e->getMessage());
    app_fail(500, 'server', 'Changement impossible.');
}

echo json_encode(['ok' => true, 'message' => 'Mot de passe mis à jour.'], JSON_UNESCAPED_UNICODE);
