<?php
/**
 * api/app-delete-account.php — Suppression RGPD du compte depuis l'app (natif).
 * Reproduit rgpd-supprimer-compte.php (anonymisation + soft-delete + deconnexion). JSON.
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

if (($input['confirm'] ?? '') !== 'SUPPRIMER') {
    app_fail(422, 'confirm', 'Confirmation manquante.');
}

try {
    $st = $pdo->prepare("SELECT is_founder, is_platform_admin FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($u['is_founder']) || !empty($u['is_platform_admin'])) {
        app_fail(403, 'critical', 'Votre compte gère la structure : contactez le support pour transférer la propriété avant suppression.');
    }

    $pdo->prepare("
        UPDATE users SET
            email = CONCAT('deleted_', id, '@anonyme.assokit.local'),
            first_name = 'Compte', last_name = 'supprimé',
            phone = NULL, city = NULL, is_active = 0,
            deleted_at = NOW(), deleted_by_user_id = id
        WHERE id = ? AND deleted_at IS NULL
    ")->execute([$uid]);

    try {
        $pdo->prepare("INSERT INTO assokit_activity_log (event_type, event_action, ip, created_at) VALUES ('account','self_delete', ?, NOW())")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}

    // Deconnexion complete
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();

    echo json_encode(['ok' => true, 'message' => 'Votre compte a été supprimé.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-delete-account] ' . $e->getMessage());
    app_fail(500, 'server', 'Une erreur est survenue.');
}
