<?php
/**
 * ============================================================
 * Assokit — Helpers permissions étendues (Session 9)
 * Mairies/Collectivités + multi-tenant
 * À inclure dans les pages qui ont besoin de ces helpers.
 * ============================================================
 */

if (defined('AK_PERMS_LOADED')) return;
define('AK_PERMS_LOADED', 1);

/**
 * Super Admin Fondateur (Raphaël) ? Voit tout, contrôle tout.
 */
function is_platform_admin($user = null): bool {
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (!array_key_exists('is_platform_admin', $user) && !empty($user['id'])) {
        global $pdo;
        $s = $pdo->prepare("SELECT is_platform_admin FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        return (int)$s->fetchColumn() === 1;
    }
    return !empty($user['is_platform_admin']);
}

/**
 * L'utilisateur est-il un agent mairie/collectivité ?
 */
function is_parent_org_user($user = null): bool {
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (!array_key_exists('parent_org_id', $user) && !empty($user['id'])) {
        global $pdo;
        $s = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        return !empty($s->fetchColumn());
    }
    return !empty($user['parent_org_id']);
}

/**
 * Retourne la mairie/collectivité parente de l'utilisateur courant.
 */
function current_parent_org(): ?array {
    global $pdo;
    if (!function_exists('current_user')) return null;
    $user = current_user();
    if (empty($user)) return null;
    $parent_id = $user['parent_org_id'] ?? null;
    if ($parent_id === null && !empty($user['id'])) {
        $s = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        $parent_id = $s->fetchColumn();
    }
    if (empty($parent_id)) return null;
    $stmt = $pdo->prepare("SELECT * FROM parent_orgs WHERE id = ? LIMIT 1");
    $stmt->execute([$parent_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Récupère une mairie par ID.
 */
function get_parent_org(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM parent_orgs WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Liste des asso (organizations) rattachées à une mairie.
 */
function get_orgs_for_parent_org(int $parent_org_id): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT o.id, o.name, o.legal_name, o.billing_address_city, o.parent_org_linked_at,
               o.logo_path, o.siret, o.rna_number
        FROM organizations o
        WHERE o.parent_org_id = ?
        ORDER BY o.name ASC
    ");
    $stmt->execute([$parent_org_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Compte les asso d'une mairie.
 */
function count_orgs_for_parent_org(int $parent_org_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE parent_org_id = ?");
    $stmt->execute([$parent_org_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * IDs des asso gérées par la mairie de l'utilisateur courant (utile pour les requêtes).
 */
function parent_org_managed_org_ids(): array {
    global $pdo;
    $parent = current_parent_org();
    if (!$parent) return [];
    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE parent_org_id = ?");
    $stmt->execute([$parent['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Peut-il administrer (lire + écrire) l'asso ?
 * - Super Admin Fondateur : oui sur tout
 * - Admin/Président de l'asso : oui sur la sienne
 * - Agent mairie : oui sur les asso de SA mairie
 */
function user_can_admin_org(int $org_id, $user = null): bool {
    global $pdo;
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (is_platform_admin($user)) return true;
    $role = $user['role'] ?? '';
    if (!empty($user['org_id']) && (int)$user['org_id'] === $org_id
        && in_array($role, ['admin', 'super_admin', 'president'], true)) {
        return true;
    }
    // Fallback DB si parent_org_id pas en session
    $user_parent_id = $user['parent_org_id'] ?? null;
    if ($user_parent_id === null && !empty($user['id'])) {
        $s = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        $user_parent_id = $s->fetchColumn();
    }
    if (!empty($user_parent_id)) {
        $stmt = $pdo->prepare("SELECT parent_org_id FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        $parent = (int)$stmt->fetchColumn();
        if ($parent && $parent === (int)$user_parent_id) return true;
    }
    return false;
}

/**
 * Peut-il VOIR (lecture seule) l'asso ?
 * Plus permissif : inclut les membres normaux + spectateurs externes.
 */
function user_can_view_org(int $org_id, $user = null): bool {
    global $pdo;
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (is_platform_admin($user)) return true;
    if (!empty($user['org_id']) && (int)$user['org_id'] === $org_id) return true;
    $user_parent_id = $user['parent_org_id'] ?? null;
    if ($user_parent_id === null && !empty($user['id'])) {
        $s = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        $user_parent_id = $s->fetchColumn();
    }
    if (!empty($user_parent_id)) {
        $stmt = $pdo->prepare("SELECT parent_org_id FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        $parent = (int)$stmt->fetchColumn();
        if ($parent && $parent === (int)$user_parent_id) return true;
    }
    return false;
}

// ============================================================
// MIDDLEWARE (bloque la page si pas les droits)
// ============================================================

function require_platform_admin(): void {
    if (!is_platform_admin()) {
        http_response_code(403);
        die('<h1>⛔ Accès refusé</h1><p>Cette page est réservée au Super Admin Fondateur.</p><p><a href="/dashboard">Retour</a></p>');
    }
}

function require_parent_org_user(): void {
    if (!is_parent_org_user() && !is_platform_admin()) {
        http_response_code(403);
        die('<h1>⛔ Accès refusé</h1><p>Cette page est réservée aux mairies et collectivités.</p>');
    }
}

function require_can_admin_org(int $org_id): void {
    if (!user_can_admin_org($org_id)) {
        http_response_code(403);
        die('<h1>⛔ Accès refusé</h1><p>Vous n\'avez pas les droits d\'administration sur cette association.</p>');
    }
}

function require_can_view_org(int $org_id): void {
    if (!user_can_view_org($org_id)) {
        http_response_code(403);
        die('<h1>⛔ Accès refusé</h1><p>Vous n\'avez pas accès à cette association.</p>');
    }
}
