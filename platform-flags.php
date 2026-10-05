<?php
/**
 * platform-flags.php — droits plateforme (fondateur / super admin) fiables,
 * même quand current_user() ne charge pas ces colonnes.
 */
if (!function_exists('ak_platform_flags')) {
/**
 * Complète l'utilisateur avec is_founder / is_super_admin, relus en base :
 * selon les serveurs, current_user() ne charge pas ces colonnes, et les pages
 * fondateur refusaient alors l'accès au fondateur lui-même.
 */
function ak_platform_flags(?array $user): ?array {
    global $pdo;
    static $cache = [];
    if (!$user || empty($user['id'])) return $user;
    if (!array_key_exists('is_founder', $user) || !array_key_exists('is_super_admin', $user)) {
        try {
            $uid = (int)$user['id'];
            if (!isset($cache[$uid])) {
                $st = $pdo->prepare("SELECT is_founder, is_super_admin FROM users WHERE id = ?");
                $st->execute([$uid]);
                $cache[$uid] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $row = $cache[$uid];
            $user['is_founder'] = (int)($row['is_founder'] ?? 0);
            $user['is_super_admin'] = (int)($row['is_super_admin'] ?? 0);
        } catch (Throwable $e) {
            $user += ['is_founder' => 0, 'is_super_admin' => 0];
        }
    }
    if (($user['role'] ?? '') === 'founder') $user['is_founder'] = 1;
    if (($user['role'] ?? '') === 'super_admin') $user['is_super_admin'] = 1;
    return $user;
}
}
