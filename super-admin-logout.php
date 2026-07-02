<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-logout.php
 * Déconnexion du cockpit SA (Niveau 2 sécurité)
 * ============================================================
 * URL : /super-admin-logout
 *
 * Efface UNIQUEMENT le cookie SA, pas la session Assokit principale.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sa-auth-helpers.php';

// Log la déconnexion si on savait qui était connecté
$userId = sa_auth_verify_cookie();
if ($userId) {
    try {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $email = (string)$stmt->fetchColumn();
        sa_auth_log_attempt($pdo, $email, $userId, 'logout', 'Déconnexion volontaire');
    } catch (Throwable $e) {}
}

sa_auth_clear_cookie();
header('Location: /super-admin-login?logged_out=1');
exit;
