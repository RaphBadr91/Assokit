<?php
/**
 * finance-permissions.php — VERSION STRICTE
 * --------------------------------------------------------------
 * [PACK 6.5 - SECURITY] Helper centralisé de contrôle d'accès aux finances.
 *
 * ⚠️ POLITIQUE STRICTE : SEULS les rôles suivants voient l'argent :
 *   - Admin de l'asso (role='admin')
 *   - Fondateur (role='founder' OU is_founder=1)
 *   - Super Admin (role='super_admin' OU is_super_admin=1)
 *
 * AUCUN autre rôle ne voit les budgets / factures :
 *   ❌ Responsable
 *   ❌ Référent (Projet)
 *   ❌ Coordinateur
 *   ❌ Suiveur (Follower)
 *   ❌ Membre
 *
 * Usage dans une page :
 *   require_once __DIR__ . '/finance-permissions.php';
 *   require_finance_access();   // bloque + page de refus
 *
 * Usage pour test seulement (sans bloquer) :
 *   if (user_can_view_finances()) { ... }
 * --------------------------------------------------------------
 */

if (!function_exists('user_can_view_finances')) {
    /**
     * Retourne TRUE UNIQUEMENT si Admin / Founder / Super Admin.
     * Tous les autres rôles → FALSE.
     */
    function user_can_view_finances(?array $user = null): bool {
        if ($user === null) {
            if (!function_exists('current_user')) return false;
            $user = current_user();
        }
        if (!$user || !is_array($user)) return false;

        // ⚠️ STRICT : seulement role admin/founder/super_admin OU flags is_founder/is_super_admin
        $role = $user['role'] ?? '';
        $is_authorized_role = in_array($role, ['admin', 'founder', 'super_admin'], true);
        $is_founder = !empty($user['is_founder']);
        $is_super_admin = !empty($user['is_super_admin']);

        return $is_authorized_role || $is_founder || $is_super_admin;
    }
}

if (!function_exists('require_finance_access')) {
    /**
     * Bloque l'accès si l'utilisateur n'est pas Admin/Founder/Super Admin.
     */
    function require_finance_access(string $sidebar_active = 'dashboard', string $context = 'cette page'): void {
        if (user_can_view_finances()) return;

        http_response_code(403);

        // Si layout disponible → page de refus stylée
        if (function_exists('render_head') && function_exists('render_sidebar') && function_exists('render_foot')) {
            render_head('Accès refusé');
            render_sidebar($sidebar_active);
            echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
            echo '<div style="font-size:54px;margin-bottom:14px;">🔒</div>';
            echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
            echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">';
            echo 'L\'accès à ' . htmlspecialchars($context) . ' est strictement réservé aux <strong>Administrateurs</strong> de l\'association.';
            echo '</p>';
            echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
            echo '</div></main>';
            render_foot();
        } else {
            // Fallback simple pour endpoints AJAX / POST handlers
            die('Accès refusé : fonction strictement réservée aux administrateurs de l\'association.');
        }
        exit;
    }
}

if (!function_exists('require_finance_access_json')) {
    /**
     * Variante JSON pour les endpoints AJAX.
     */
    function require_finance_access_json(): void {
        if (user_can_view_finances()) return;

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Accès refusé',
            'message' => 'Strictement réservé aux administrateurs de l\'association.',
        ]);
        exit;
    }
}
