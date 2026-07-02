<?php
/**
 * asso-ai-quotas.php
 * --------------------------------------------------------------
 * Module Quotas IA — Pack PHASE 4.6 v3 (IMMUTABLE)
 *
 * Système immuable : les quotas sont codés en dur, non modifiables.
 * Reset automatique : le compteur regarde la date du jour, donc se
 * remet à zéro à minuit naturellement (pas besoin de CRON).
 *
 * Permissions :
 *   - Fondateur / super-admin : illimité
 *   - Admin : 10 générations/jour/outil
 *   - Adhérent / member : 4 générations/jour/outil, pas d'images
 *   - Follower : 2 générations/jour/outil, pas d'images
 *
 * Convention image : tout outil dont tool_type commence par "image-"
 * est traité comme outil image (bloqué pour les rôles non-admin).
 * --------------------------------------------------------------
 */

if (!defined('AK_AI_QUOTA_LOADED')) define('AK_AI_QUOTA_LOADED', 1);

/**
 * Quotas FIXES par rôle. Cette config est la SEULE source de vérité.
 * Aucune lecture/écriture BDD pour ces valeurs.
 */
if (!function_exists('ak_ai_quota_defaults')) {
    function ak_ai_quota_defaults(): array {
        return [
            // Admins de l'asso
            'admin'       => ['daily_limit' => 10, 'allow_images' => true,  'label' => 'Admin'],
            'manager'     => ['daily_limit' => 10, 'allow_images' => true,  'label' => 'Manager'],
            'coordinator' => ['daily_limit' => 8,  'allow_images' => true,  'label' => 'Coordinateur'],
            'editor'      => ['daily_limit' => 8,  'allow_images' => true,  'label' => 'Éditeur'],
            // Adhérents
            'member'      => ['daily_limit' => 4,  'allow_images' => false, 'label' => 'Adhérent'],
            'adherent'    => ['daily_limit' => 4,  'allow_images' => false, 'label' => 'Adhérent'],
            // Followers
            'follower'    => ['daily_limit' => 2,  'allow_images' => false, 'label' => 'Follower'],
            'viewer'      => ['daily_limit' => 2,  'allow_images' => false, 'label' => 'Lecteur'],
            // Fallback inconnu : valeurs prudentes
            '*'           => ['daily_limit' => 4,  'allow_images' => false, 'label' => 'Autre'],
        ];
    }
}

/**
 * Détecte si un outil est de type "génération d'image"
 */
if (!function_exists('ak_ai_quota_is_image_tool')) {
    function ak_ai_quota_is_image_tool(string $tool_type): bool {
        return (strpos($tool_type, 'image-') === 0) || (strpos($tool_type, 'image_') === 0);
    }
}

/**
 * Vérifie si l'utilisateur est fondateur ou super-admin (= illimité)
 */
if (!function_exists('ak_ai_quota_is_unlimited')) {
    function ak_ai_quota_is_unlimited(PDO $pdo, int $user_id): bool {
        try {
            $st = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $user_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            return ((int)($row['is_super_admin'] ?? 0) === 1)
                || ((int)($row['is_founder']     ?? 0) === 1);
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Récupère la config quota d'un rôle (HARDCODED, pas de BDD)
 */
if (!function_exists('ak_ai_quota_get_role')) {
    function ak_ai_quota_get_role(string $role): array {
        $defaults = ak_ai_quota_defaults();
        $role_norm = strtolower(trim($role));
        return $defaults[$role_norm] ?? $defaults['*'];
    }
}

/**
 * Compte les générations réussies aujourd'hui pour un user × outil.
 * Reset automatique à minuit (la date change, le COUNT redevient 0).
 */
if (!function_exists('ak_ai_quota_count_today')) {
    function ak_ai_quota_count_today(PDO $pdo, int $user_id, string $tool_type): int {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM asso_ai_generations
                WHERE user_id = :u AND tool_type = :t
                  AND status = 'success'
                  AND DATE(created_at) = CURRENT_DATE()
            ");
            $st->execute([':u' => $user_id, ':t' => $tool_type]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}

/**
 * Vérifie si un user peut générer avec un outil donné.
 *
 * @return array [
 *   'allowed' => bool,
 *   'limit' => int,         // -1 si illimité
 *   'used' => int,
 *   'remaining' => int,     // -1 si illimité
 *   'reason' => ?string,
 *   'unlimited' => bool,
 * ]
 */
if (!function_exists('ak_ai_quota_check')) {
    function ak_ai_quota_check(PDO $pdo, array $user, string $tool_type): array {
        $user_id = (int)($user['id']     ?? 0);
        $role    = strtolower(trim((string)($user['role'] ?? 'member')));

        // Fondateur / super-admin → illimité
        if (ak_ai_quota_is_unlimited($pdo, $user_id)) {
            return ['allowed' => true, 'limit' => -1, 'used' => 0, 'remaining' => -1, 'reason' => null, 'unlimited' => true];
        }

        // Config rôle (hardcoded)
        $cfg = ak_ai_quota_get_role($role);

        // Vérifie outil image
        if (ak_ai_quota_is_image_tool($tool_type) && empty($cfg['allow_images'])) {
            return ['allowed' => false, 'limit' => 0, 'used' => 0, 'remaining' => 0,
                    'reason' => 'La génération d\'images n\'est pas autorisée pour votre rôle.', 'unlimited' => false];
        }

        // Vérifie quota quotidien
        $used  = ak_ai_quota_count_today($pdo, $user_id, $tool_type);
        $limit = max(0, (int)$cfg['daily_limit']);
        $remaining = max(0, $limit - $used);
        $allowed = ($limit === 0) ? false : ($remaining > 0);

        $reason = null;
        if (!$allowed) {
            if ($limit === 0) $reason = 'Aucun quota disponible pour votre rôle.';
            else $reason = "Quota quotidien atteint pour cet outil ({$used}/{$limit}). Vos crédits seront restaurés demain à minuit.";
        }

        return [
            'allowed'   => $allowed,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $remaining,
            'reason'    => $reason,
            'unlimited' => false,
        ];
    }
}

/**
 * Permission de CONSULTER la page de quotas (lecture seule).
 * Réservée aux fondateurs et super-admins.
 */
if (!function_exists('ak_ai_quota_can_view_admin')) {
    function ak_ai_quota_can_view_admin(PDO $pdo, array $user): bool {
        return ak_ai_quota_is_unlimited($pdo, (int)($user['id'] ?? 0));
    }
}

/**
 * Alias rétro-compat : utilisé par certaines pages
 */
if (!function_exists('ak_ai_quota_can_administer')) {
    function ak_ai_quota_can_administer(PDO $pdo, array $user): bool {
        return ak_ai_quota_can_view_admin($pdo, $user);
    }
}
