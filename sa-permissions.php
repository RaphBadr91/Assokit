<?php
/**
 * sa-permissions.php — Couche de permissions FONDATEUR / SUPER ADMIN
 * ===================================================================
 *
 * Ce fichier centralise TOUTES les regles de permissions du cockpit.
 * L'objectif : plus jamais avoir de logique dispersee dans 15 fichiers.
 *
 * HIERARCHIE :
 *   - FONDATEUR (is_founder=1) : pouvoir absolu sur tout
 *   - SUPER ADMIN (role='super_admin' OU is_super_admin=1) : large pouvoir
 *     MAIS ses creations sensibles passent en "pending validation"
 *   - ADMIN d'asso : limite a son asso uniquement
 *
 * USAGE type dans une page :
 *
 *   require_once __DIR__ . '/sa-permissions.php';
 *   $ctx = sa_get_permissions_context();  // info sur le user courant
 *
 *   if ($ctx['can_validate_orgs']) { ... }
 *   if ($ctx['is_founder']) { ... }
 *
 *   sa_require_capability('manage_super_admins');  // arret si pas droit
 */

if (!defined('SA_PERMISSIONS_LOADED')) {
    define('SA_PERMISSIONS_LOADED', true);
}

/**
 * Renvoie le contexte de permissions complet pour l'user courant.
 * Cache statique pour eviter de tout recalculer plusieurs fois par page.
 */
function sa_get_permissions_context(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;

    $user = function_exists('current_user') ? current_user() : null;
    if (!$user) {
        return ['user' => null, 'is_founder' => false, 'is_super_admin' => false];
    }

    // Recharge is_founder et is_super_admin si absents de current_user()
    $is_founder = false;
    $is_sa = ($user['role'] ?? '') === 'super_admin';

    if (array_key_exists('is_founder', $user)) {
        $is_founder = !empty($user['is_founder']);
    } else {
        try {
            $stmt = $pdo->prepare("SELECT is_founder, is_super_admin FROM users WHERE id = ?");
            $stmt->execute([(int) $user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $is_founder = (int) $row['is_founder'] === 1;
                if (!$is_sa) $is_sa = (int) $row['is_super_admin'] === 1;
            }
        } catch (Throwable $e) {}
    }
    if (!$is_sa && array_key_exists('is_super_admin', $user)) {
        $is_sa = !empty($user['is_super_admin']);
    }

    // Un fondateur est forcement super admin
    if ($is_founder) $is_sa = true;

    // Tableau de permissions explicites
    $perms = [
        'user' => $user,
        'user_id' => (int) $user['id'],
        'is_founder' => $is_founder,
        'is_super_admin' => $is_sa,
        'is_super_admin_non_founder' => $is_sa && !$is_founder,

        // ===== Droits absolus (Fondateur uniquement) =====
        'can_manage_super_admins'     => $is_founder,  // Creer/revoquer des SA
        'can_manage_founders'         => $is_founder,  // Nommer d'autres fondateurs
        'can_validate_orgs'           => $is_founder,  // Valider/refuser assos
        'can_view_platform_stats'     => $is_founder,  // Stats approfondies
        'can_view_emails_logs'        => $is_founder,  // Journal emails/SMS detaille
        'can_delete_anything'         => $is_founder,
        'can_impersonate'             => $is_founder,  // Incarnation (futur)

        // ===== Droits partages (SA + Fondateur) =====
        'can_view_orgs'               => $is_sa,
        'can_edit_orgs'               => $is_sa,
        'can_create_org'              => $is_sa,  // mais SA non-fondateur = pending validation
        'can_suspend_org'             => $is_sa,
        'can_view_users'              => $is_sa,
        'can_manage_subscriptions'    => $is_sa,
        'can_create_invoices'         => $is_sa,
        'can_mark_paid'               => $is_sa,
        'can_view_logs'               => $is_sa,
        'can_use_support'             => $is_sa,  // Pack 2

        // ===== Droits sur les projets =====
        'can_view_all_projects'       => $is_founder,
        'can_view_org_projects'       => $is_sa,  // dans le cadre du support
        'can_edit_projects'           => $is_founder,
    ];

    return $cache = $perms;
}

/**
 * Garde-fou : arrete l'execution si l'user n'a pas la capacite demandee.
 * A appeler au debut des pages sensibles.
 *
 * Exemples :
 *   sa_require_capability('can_manage_super_admins');
 *   sa_require_capability('can_validate_orgs');
 */
function sa_require_capability(string $capability): void {
    $ctx = sa_get_permissions_context();
    if (empty($ctx[$capability])) {
        http_response_code(403);
        $label = function_exists('h') ? h($capability) : htmlspecialchars($capability);
        die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#0A0A0B;color:#fff;text-align:center">
            <h1>403 — Permission insuffisante</h1>
            <p>Cette fonctionnalité est réservée aux Fondateurs de la plateforme.</p>
            <p style="color:#71717A;font-size:12px;margin-top:20px;">Capacité requise : ' . $label . '</p>
            <p><a href="/super-admin" style="color:#C4B5FD">← Retour au cockpit</a></p>
        </body></html>');
    }
}

/**
 * Raccourcis pratiques (alias)
 */
function sa_is_founder(): bool {
    $ctx = sa_get_permissions_context();
    return !empty($ctx['is_founder']);
}

function sa_can(string $capability): bool {
    $ctx = sa_get_permissions_context();
    return !empty($ctx[$capability]);
}

/**
 * Notification in-app : creer une notification (surtout pour le Fondateur)
 */
function sa_notify(int $recipient_user_id, string $type, string $title, ?string $message = null, ?string $target_url = null, ?int $target_org_id = null, ?int $target_user_id = null): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_notifications
                (recipient_user_id, type, title, message, target_url, target_org_id, target_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $recipient_user_id, $type, $title,
            $message, $target_url, $target_org_id, $target_user_id,
        ]);
    } catch (Throwable $e) {
        // silencieux si table pas encore creee
    }
}

/**
 * Notifie TOUS les fondateurs actifs (utile pour les events globaux)
 */
function sa_notify_all_founders(string $type, string $title, ?string $message = null, ?string $target_url = null, ?int $target_org_id = null): void {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE is_founder = 1 AND is_active = 1");
        $founders = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($founders as $fid) {
            sa_notify((int) $fid, $type, $title, $message, $target_url, $target_org_id);
        }
    } catch (Throwable $e) {}
}

/**
 * Compte les notifs non lues pour un user (pour badge)
 */
function sa_count_unread_notifications(int $user_id): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM platform_notifications WHERE recipient_user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Marque comme lues toutes les notifs d'un user
 */
function sa_mark_all_notifications_read(int $user_id): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE platform_notifications SET is_read = 1, read_at = NOW() WHERE recipient_user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
    } catch (Throwable $e) {}
}
