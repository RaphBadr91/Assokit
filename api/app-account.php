<?php
/**
 * api/app-account.php — Infos du compte + org pour l'ecran Parametres natif. JSON.
 * NE MODIFIE PAS le site.
 */
// Compte relu en base : désactivé, supprimé ou introuvable → session fermée, 401 'auth'
// (plus de repli sur $_SESSION['user_id'] / $_SESSION['org_id']). Expose $user, $uid, $org_id.
require_once __DIR__ . '/_app-read-boot.php';

try {
    $st = $pdo->prepare("SELECT first_name, last_name, email, phone, city, role, is_founder, is_platform_admin FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $org_name = ''; $org_logo = null;
    try {
        $st = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ? LIMIT 1");
        $st->execute([$org_id]);
        $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $org_name = (string) ($o['name'] ?? '');
        $lp = trim((string) ($o['logo_path'] ?? ''));
        if ($lp !== '') $org_logo = (strpos($lp, 'http') === 0) ? $lp : 'https://assokit.fr/' . ltrim($lp, '/');
    } catch (Throwable $e) {}

    // Mêmes règles que le site (ak_account_delete_blocker) : Fondateur, seul administrateur de l'association…
    $delete_blocked = function_exists('ak_account_delete_blocker') ? ak_account_delete_blocker($pdo, $uid)
        : ((!empty($u['is_founder']) || !empty($u['is_platform_admin'])) ? 'Contactez le support.' : '');
    if ($delete_blocked === '' && !empty($_SESSION['impersonation_target_user_id'])) {
        $delete_blocked = 'Suppression impossible pendant une incarnation.';
    }
    $can_delete = ($delete_blocked === '');

    echo json_encode([
        'ok' => true,
        'account' => [
            'first_name' => (string) ($u['first_name'] ?? ''),
            'last_name'  => (string) ($u['last_name'] ?? ''),
            'email'      => (string) ($u['email'] ?? ''),
            'phone'      => (string) ($u['phone'] ?? ''),
            'city'       => (string) ($u['city'] ?? ''),
            'role'       => (string) ($u['role'] ?? 'member'),
            'can_delete' => $can_delete,
            // Raison du refus, à afficher sous le bouton grisé (null si la suppression est possible)
            'delete_blocked_reason' => $can_delete ? null : $delete_blocked,
        ],
        'org' => ['name' => $org_name, 'logo' => $org_logo, 'is_admin' => (($u['role'] ?? '') === 'admin')],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
