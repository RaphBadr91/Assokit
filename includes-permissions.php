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
 * Collectivité de rattachement d'un agent, quel que soit son statut (null si aucune).
 * Lecture brute : pour l'accès à l'espace, passer par current_parent_org().
 */
function ak_parent_org_row_for_user($user): ?array {
    global $pdo;
    if (empty($user) || !is_array($user)) return null;
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
 * L'espace d'une collectivité n'est ouvert qu'une fois validée par Assokit
 * (status 'active') : en attente, suspendue ou refusée, ses agents ne voient plus
 * rien des associations rattachées et ne peuvent plus leur écrire.
 */
function parent_org_is_open(?array $parent_org): bool {
    return $parent_org !== null && (string)($parent_org['status'] ?? '') === 'active';
}

/**
 * Retourne la mairie/collectivité parente de l'utilisateur courant, seulement si son
 * espace est ouvert (parent_org_is_open). Le fondateur (is_platform_admin) la garde
 * dans tous les cas. Toutes les pages et actions mairie s'appuient sur ce retour :
 * null y vaut refus d'accès.
 */
function current_parent_org(): ?array {
    if (!function_exists('current_user')) return null;
    $user = current_user();
    if (empty($user)) return null;
    $po = ak_parent_org_row_for_user($user);
    if ($po && !parent_org_is_open($po) && !is_platform_admin($user)) return null;
    return $po;
}

/**
 * Message affiché à l'agent d'une collectivité dont l'espace n'est pas ouvert.
 */
function ak_parent_org_closed_message(?array $parent_org): string {
    $contact = defined('AK_SUPPORT_EMAIL') ? AK_SUPPORT_EMAIL : 'contact@assokit.fr';
    switch ((string)($parent_org['status'] ?? '')) {
        case 'pending':
            $msg = 'L’espace de votre collectivité est en attente de validation par Assokit. Vous y aurez accès dès qu’il sera validé.';
            break;
        case 'rejected':
            $msg = 'La demande d’ouverture de l’espace de votre collectivité n’a pas été validée.';
            break;
        case 'suspended':
            $msg = 'L’accès de votre collectivité à Assokit est suspendu. Vos données sont conservées.';
            break;
        default:
            $msg = 'L’espace de votre collectivité n’est pas accessible.';
    }
    return $msg . ' Pour toute question, écrivez à ' . $contact . '.';
}

/**
 * Arrête la requête d'un agent dont l'espace collectivité n'est pas ouvert :
 * JSON (403) pour les appels de l'appli ou en fetch, page d'explication sinon.
 */
function ak_parent_org_closed_stop(?array $parent_org): void {
    $msg = ak_parent_org_closed_message($parent_org);
    $script = ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    $api = strpos($script, 'api/') === 0 || strpos($script, 'api-') === 0
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if (!headers_sent()) http_response_code(403);
    if ($api) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'parent_org_closed', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = htmlspecialchars((string)($parent_org['name'] ?? 'Votre collectivité'), ENT_QUOTES, 'UTF-8');
    $title = ($parent_org['status'] ?? '') === 'pending' ? 'Espace en attente de validation' : 'Espace collectivité fermé';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . $title . ' · Assokit</title></head>'
       . '<body style="margin:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#0F172A;">'
       . '<main style="max-width:560px;margin:72px auto;padding:32px 28px;background:#fff;border:1px solid #E2E8F0;border-radius:14px;text-align:center;">'
       . '<div style="font-size:48px;margin-bottom:12px;">🏛️</div>'
       . '<h1 style="font-size:21px;margin:0 0 6px;">' . $title . '</h1>'
       . '<p style="color:#64748B;font-size:13px;margin:0 0 18px;">' . $name . '</p>'
       . '<p style="color:#334155;font-size:14.5px;line-height:1.6;margin:0 0 24px;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<a href="/deconnexion.php" style="display:inline-block;background:#0F172A;color:#fff;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">Se déconnecter</a>'
       . '</main></body></html>';
    exit;
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
 * Liste des asso (organizations) rattachées à une mairie. Une association supprimée
 * depuis le cockpit n'en fait plus partie (ni liste, ni quota, ni accès).
 */
function get_orgs_for_parent_org(int $parent_org_id): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT o.id, o.name, o.legal_name, o.billing_address_city, o.parent_org_linked_at,
               o.logo_path, o.siret, o.rna_number
        FROM organizations o
        WHERE o.parent_org_id = ? AND o.deleted_at IS NULL
        ORDER BY o.name ASC
    ");
    $stmt->execute([$parent_org_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Compte les asso d'une mairie (celles qui occupent le quota : supprimées exclues).
 */
function count_orgs_for_parent_org(int $parent_org_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE parent_org_id = ? AND deleted_at IS NULL");
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
    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE parent_org_id = ? AND deleted_at IS NULL");
    $stmt->execute([$parent['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Branche « agent mairie » des droits sur une asso : l'asso est rattachée à la
 * collectivité de l'agent, n'a pas été supprimée, et l'espace de la collectivité
 * est ouvert (status 'active' : ni en attente, ni suspendue, ni refusée).
 */
function ak_parent_org_user_covers_org(int $org_id, $user): bool {
    global $pdo;
    if (empty($user) || $org_id <= 0) return false;
    $user_parent_id = $user['parent_org_id'] ?? null;   // fallback base si absent de la session
    if ($user_parent_id === null && !empty($user['id'])) {
        $s = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user['id']]);
        $user_parent_id = $s->fetchColumn();
    }
    if (empty($user_parent_id)) return false;
    $stmt = $pdo->prepare("
        SELECT 1 FROM organizations o
        INNER JOIN parent_orgs po ON po.id = o.parent_org_id AND po.status = 'active'
        WHERE o.id = ? AND o.parent_org_id = ? AND o.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$org_id, (int)$user_parent_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Peut-il administrer (lire + écrire) l'asso ?
 * - Super Admin Fondateur : oui sur tout
 * - Admin/Président de l'asso : oui sur la sienne
 * - Agent mairie : oui sur les asso de SA mairie (espace ouvert, asso non supprimée)
 */
function user_can_admin_org(int $org_id, $user = null): bool {
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (is_platform_admin($user)) return true;
    $role = $user['role'] ?? '';
    if (!empty($user['org_id']) && (int)$user['org_id'] === $org_id
        && in_array($role, ['admin', 'super_admin', 'president'], true)) {
        return true;
    }
    return ak_parent_org_user_covers_org($org_id, $user);
}

/**
 * Peut-il VOIR (lecture seule) l'asso ?
 * Plus permissif : inclut les membres normaux + spectateurs externes.
 */
function user_can_view_org(int $org_id, $user = null): bool {
    if ($user === null && function_exists('current_user')) $user = current_user();
    if (empty($user)) return false;
    if (is_platform_admin($user)) return true;
    if (!empty($user['org_id']) && (int)$user['org_id'] === $org_id) return true;
    return ak_parent_org_user_covers_org($org_id, $user);
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
    if (is_platform_admin()) return;
    if (!is_parent_org_user()) {
        http_response_code(403);
        die('<h1>⛔ Accès refusé</h1><p>Cette page est réservée aux mairies et collectivités.</p>');
    }
    // Collectivité en attente de validation, suspendue ou refusée : page d'explication
    if (!current_parent_org()) {
        ak_parent_org_closed_stop(ak_parent_org_row_for_user(current_user()));
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
