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
 *   ❌ Coordinateur (sauf Facturation, Relances et Radar subventions :
 *      voir user_can_access_billing())
 *   ❌ Trésorier non administrateur (droit « Gérer les finances ») : sauf
 *      Facturation et Relances, voir user_can_access_billing()
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

if (!function_exists('user_can_access_billing')) {
    /**
     * Facturation (factures, devis, clients, récurrences, statistiques) et Relances :
     * ouverts aussi aux Coordinateurs, qui pilotent l'activité au quotidien (ils ont en
     * plus le Radar subventions), et à l'adhérent qui a le droit « Gérer les finances »
     * (trésorier non administrateur, coché dans admin-modifier-utilisateur.php) : le
     * menu Finances lui montre Facturation et Relances, les pages doivent s'ouvrir.
     * Jamais à un suiveur (financeur externe), même coché. Les budgets restent réservés
     * aux rôles de user_can_view_finances().
     */
    function user_can_access_billing(?array $user = null): bool {
        if ($user === null) {
            if (!function_exists('current_user')) return false;
            $user = current_user();
        }
        if (!$user || !is_array($user)) return false;
        $role = (string)($user['role'] ?? '');
        if (user_can_view_finances($user) || $role === 'coordinator') return true;
        if ($role === 'follower' || (int)($user['org_id'] ?? 0) <= 0) return false;
        if (!array_key_exists('can_manage_finances', $user)) {
            // Utilisateur partiel (colonnes choisies) : droit relu en base
            if (empty($user['id'])) return false;
            global $pdo;
            try {
                $st = $pdo->prepare("SELECT can_manage_finances FROM users WHERE id = ? LIMIT 1");
                $st->execute([(int)$user['id']]);
                $user['can_manage_finances'] = (int)$st->fetchColumn();
            } catch (Throwable $e) { return false; }
        }
        return !empty($user['can_manage_finances']);
    }
}

if (!function_exists('require_billing_access')) {
    /** Comme require_finance_access(), avec la règle de user_can_access_billing(). */
    function require_billing_access(string $sidebar_active = 'dashboard', string $context = 'cette page'): void {
        if (user_can_access_billing()) return;
        require_finance_access($sidebar_active, $context);   // affiche le refus et s'arrête
    }
}

if (!function_exists('ak_finance_a_contexte')) {
    /**
     * « à » + contexte, avec la contraction du français : « à le paiement… » → « au paiement… »,
     * « à les factures » → « aux factures » ; « à la… », « à l'… », « à cette page » inchangés.
     */
    function ak_finance_a_contexte(string $context): string {
        $context = trim($context);
        if (preg_match('/^les\s+(.*)$/isu', $context, $m)) return 'aux ' . $m[1];
        if (preg_match('/^le\s+(.*)$/isu', $context, $m)) return 'au ' . $m[1];
        return 'à ' . $context;
    }
}

if (!function_exists('ak_copilote_allowed')) {
    /**
     * Copilote IA : rôles de pilotage d'une association (admin, coordinateur, fondateur,
     * super admin). Règle de mon-asso-copilote.php et mon-asso-copilote-ask.php, reprise
     * par la bulle flottante (includes-layout.php) : la bulle ne s'affiche que si la
     * question posée sera acceptée.
     */
    function ak_copilote_allowed(?array $user = null): bool {
        if ($user === null) {
            if (!function_exists('current_user')) return false;
            $user = current_user();
        }
        if (!$user || !is_array($user) || (int)($user['org_id'] ?? 0) <= 0) return false;
        $role = strtolower((string)($user['role'] ?? ''));
        return in_array($role, ['admin', 'coordinator', 'founder'], true)
            || !empty($user['is_founder']) || !empty($user['is_super_admin']);
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
            echo 'L\'accès ' . htmlspecialchars(ak_finance_a_contexte($context)) . ' est strictement réservé aux <strong>Administrateurs</strong> de l\'association.';
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
