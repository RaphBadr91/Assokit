<?php
/**
 * ============================================================
 * PATCH CONFIG.PHP — Intégration Pack #4 Incarnation
 * ============================================================
 *
 * Ce fichier n'est PAS à uploader tel quel.
 * C'est un GUIDE de modifications à faire dans TON config.php.
 *
 * Il faut modifier 2 endroits dans config.php :
 *   A) Au tout début (après session_start), ajouter l'include + middleware
 *   B) Dans la fonction current_user(), vider le cache static quand on incarne
 *
 * ============================================================
 */

/**
 * A) À AJOUTER tout en haut de config.php, juste APRÈS session_start()
 *    (donc après la ligne 104 environ)
 */

/*
// ============================================================
// PACK #4 INCARNATION — Middleware auto
// ============================================================
if (is_file(__DIR__ . '/impersonation-helpers.php')) {
    require_once __DIR__ . '/impersonation-helpers.php';
    // On appelle le middleware qui vérifie le timeout et log la page
    if (function_exists('impersonation_middleware')) {
        impersonation_middleware();
    }
}
*/

/**
 * B) MODIFIER la fonction current_user() EXISTANTE.
 *
 *    Avant :
 *        static $cached = null;
 *        if ($cached !== null) return $cached;
 *
 *    Remplace par :
 */

/*
function current_user() {
    global $pdo;
    static $cached = null;
    static $cached_for_user_id = null;

    // INVALIDATION CACHE pour incarnation
    // Si user_id a changé (incarnation start/stop), on revide le cache
    $currentId = $_SESSION['user_id'] ?? null;
    if ($cached_for_user_id !== $currentId) {
        $cached = null;
        $cached_for_user_id = $currentId;
    }

    if ($cached !== null) return $cached;
    if (!is_logged_in()) return null;

    $stmt = $pdo->prepare('
        SELECT id, org_id, email, first_name, last_name, role, contract_type,
               contract_start_date, contract_end_date, organization_name,
               phone, city, adhesion_date, avatar_color, is_active,
               can_create_projects, can_manage_members, can_manage_finances,
               can_access_marketing, can_manage_events, can_moderate_messages,
               must_change_password, ics_token
        FROM users WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}
*/

/**
 * C) AJOUTER dans includes-layout.php, dans render_head() ou render_foot(),
 *    juste après le <body> ou juste avant </body>.
 *    Simplement ajouter :
 *
 *        require __DIR__ . '/impersonation-banner.php';
 *
 *    (le fichier s'auto-protège s'il n'y a pas d'incarnation active)
 */

// Voir INSTALLATION-PACK4.md pour le guide détaillé.
