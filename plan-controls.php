<?php
/**
 * plan-controls.php — Contrôles de la grille tarifaire dans les modules (chantier « contrôles »)
 * ------------------------------------------------------------------
 * Petites fonctions qui branchent les verrous des formules (plan-helpers.php, chantier
 * « fondations ») dans les pages, les actions, les API du site et les tâches planifiées.
 *
 * Règle d'or : si une fonction des fondations manque ou échoue, on LAISSE PASSER, comme
 * avant la grille. Chaque module marche donc avec ou sans les autres chantiers, avec ou sans
 * Stripe.
 *
 * Aucune incitation à payer dans l'appli (règle Apple 3.1.1) : les messages viennent
 * d'ak_feature_gate() / ak_feature_denied_json(), neutres quand ak_billing_hidden() est vrai.
 *
 *   akc_load()                               charge plan-helpers.php si besoin
 *   akc_org_id()                             association de la session
 *   akc_is_platform()                        Fondateur / super-admin (jamais verrouillé à l'écran)
 *   akc_grille_live($pdo)                    grille appliquée en base ? (sinon aucun verrou)
 *   akc_has($pdo, $org, $feature)            la formule a-t-elle la fonction ? (vrai en cas de doute)
 *   akc_gate_message($pdo, $org, $feature)   message de verrou (site ou appli)
 *   akc_lock_page($pdo, $org, $feature, $title, $active)   page complète « fonction non incluse », puis exit
 *   akc_deny_json($pdo, $org, $feature)      réponse JSON 403 neutre, puis exit
 *   akc_readonly($pdo, $org)                 accès limité (formule Démarrage, une fois la grille appliquée) ?
 *   akc_public_pages_open($pdo, $org)        pages de cotisation diffusées : délai de grâce de 60 jours
 *   akc_ai_block($pdo, $org, $credits)       quota de crédits IA : null = permis, sinon message
 *   akc_email_block($pdo, $org, $n)          quota d'e-mails groupés : null = permis, sinon message
 *   akc_ai_opts($path, $org, $uid)           options du registre IA (ai_usage_ledger)
 *   akc_is_manager_role($role)               admin, coordinateur, référent
 *   akc_plan_name($pdo, $org)                nom de la formule (sans prix)
 *   akc_priority_support_orgs($pdo, $ids)    associations au support prioritaire (formule Équipe)
 * ------------------------------------------------------------------
 */

if (!function_exists('akc_load')) {

/** Charge les fondations (plan-helpers.php) si elles ne le sont pas. Vrai si elles sont là. */
function akc_load(): bool
{
    if (function_exists('ak_plan_has')) return true;
    if (is_file(__DIR__ . '/plan-helpers.php')) {
        try { require_once __DIR__ . '/plan-helpers.php'; } catch (Throwable $e) { error_log('[akc_load] ' . get_class($e)); }
    }
    return function_exists('ak_plan_has');
}

/** Association de la session (0 sans association). */
function akc_org_id(): int
{
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $org = (int)($u['org_id'] ?? 0);
    if ($org <= 0) $org = (int)($_SESSION['org_id'] ?? 0);
    return $org;
}

/**
 * Compte au-dessus des associations (Fondateur, super-admin) connecté en son nom : jamais
 * verrouillé à l'écran (il doit pouvoir tout voir pour aider). Pendant une incarnation,
 * current_user() est le compte incarné : le Fondateur voit alors ce que voit le client.
 */
function akc_is_platform(): bool
{
    if (!function_exists('current_user')) return false;
    $u = current_user() ?: [];
    if (!$u) return false;
    if (function_exists('ak_user_is_platform')) return ak_user_is_platform($u);
    return !empty($u['is_super_admin']) || !empty($u['is_founder'])
        || in_array((string)($u['role'] ?? ''), ['super_admin', 'founder'], true);
}

/**
 * La formule de l'association a-t-elle cette fonction ('anomalies' ou 'feature_anomalies') ?
 * Vrai si les fondations manquent, en cas d'erreur, sans association, ou pour un compte
 * plateforme ($for_user = true : écran ou action d'un utilisateur ; false dans un cron).
 */
function akc_has(PDO $pdo, int $org_id, string $feature, bool $for_user = true): bool
{
    if ($org_id <= 0) return true;
    if ($for_user && akc_is_platform()) return true;
    if (!akc_load()) return true;
    if (!akc_grille_live($pdo)) return true;   // grille pas encore appliquée : comportement d'avant
    try {
        return (bool)ak_plan_has($pdo, $org_id, $feature);
    } catch (Throwable $e) {
        error_log('[akc_has] ' . get_class($e));
        return true;
    }
}

/** Message de verrou : site « … à partir de la formule Équipe. », appli « … n'est pas incluse dans votre formule. » */
function akc_gate_message(PDO $pdo, int $org_id, string $feature): string
{
    if (function_exists('ak_feature_gate')) {
        try {
            $g = ak_feature_gate($pdo, $org_id, $feature);
            if (!empty($g['message'])) return (string)$g['message'];
        } catch (Throwable $e) {}
    }
    return 'Cette fonction n’est pas incluse dans votre formule.';
}

/**
 * Page entière « fonction non incluse » (carte ak_render_feature_lock dans le gabarit du site),
 * puis arrêt. Ne fait rien si la fonction est incluse.
 */
function akc_lock_page(PDO $pdo, int $org_id, string $feature, string $title, string $active = ''): void
{
    if (akc_has($pdo, $org_id, $feature)) return;
    $card = '';
    if (function_exists('ak_render_feature_lock')) {
        try { $card = ak_render_feature_lock($pdo, $org_id, $feature, $title); } catch (Throwable $e) { $card = ''; }
    }
    if ($card === '') {
        $card = '<div class="card" style="max-width:640px;margin:24px auto;padding:28px;text-align:center;">'
              . '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>'
              . htmlspecialchars(akc_gate_message($pdo, $org_id, $feature), ENT_QUOTES, 'UTF-8') . '</p></div>';
    }
    if (!headers_sent()) http_response_code(200);
    if (function_exists('render_head') && function_exists('render_sidebar') && function_exists('render_foot')) {
        render_head($title);
        echo render_sidebar($active !== '' ? $active : 'dashboard');
        echo '<main class="main"><div style="padding:24px 16px;">' . $card . '</div></main>';
        render_foot();
    } else {
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — Assokit</title></head>'
           . '<body style="font-family:system-ui,sans-serif;background:#F8FAFC;margin:0;padding:16px;">' . $card . '</body></html>';
    }
    exit;
}

/** Réponse JSON 403 « fonction non incluse » (message neutre, sans prix ni lien), puis arrêt. */
function akc_deny_json(PDO $pdo, int $org_id, string $feature): void
{
    $out = function_exists('ak_feature_denied_json')
        ? ak_feature_denied_json($pdo, $org_id, $feature)
        : ['ok' => false, 'error' => 'plan_feature', 'feature' => $feature, 'message' => 'Cette fonction n’est pas incluse dans votre formule.'];
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * La grille de novembre 2026 est-elle en service (au moins une ligne asso_plans portant
 * grille_version, posée par migrations/2026-11-09-grille-tarifaire.sql) ? Avant la migration,
 * aucun verrou ne s'applique : le code peut être mis en ligne avant la grille sans rien changer
 * pour les clients (une ancienne colonne feature_* à 0 ne ferme donc rien).
 */
function akc_grille_live(PDO $pdo): bool
{
    static $live = null;
    if ($live !== null) return $live;
    try {
        $live = (int)$pdo->query("SELECT COALESCE(MAX(grille_version), 0) FROM asso_plans")->fetchColumn() >= 202611;
    } catch (Throwable $e) {
        $live = false;   // colonne absente : migration pas encore passée
    }
    return $live;
}

/**
 * L'accès limité est-il en service ? Seulement quand les DEUX conditions sont réunies :
 *   - la grille est appliquée à la ligne demarrage (asso_plans.grille_version renseignée par
 *     migrations/2026-11-09-grille-tarifaire.sql) ;
 *   - le préavis de 30 jours a été envoyé aux comptes concernés et il est écoulé (cgu.php § 12) :
 *     le Fondateur ajoute alors define('AK_DEMARRAGE_READONLY_LIVE', true); dans config.php.
 * Sans la constante, rien ne change pour les comptes Démarrage (ni lecture seule, ni limites à 0 :
 * ak_plan_demarrage_overlay, plan-helpers.php), et /tarifs n'annonce pas l'accès limité.
 */
function akc_readonly_live(PDO $pdo): bool
{
    static $live = null;
    if ($live !== null) return $live;
    if (!defined('AK_DEMARRAGE_READONLY_LIVE') || !AK_DEMARRAGE_READONLY_LIVE) return $live = false;
    try {
        $v = $pdo->query("SELECT grille_version FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
        $live = $v !== false && $v !== null && (int)$v >= 202611;
    } catch (Throwable $e) {
        $live = false;   // colonne absente : migration pas encore passée
    }
    return $live;
}

/** Accès limité (formule Démarrage) ? Faux en cas de doute et pour un compte plateforme. */
function akc_readonly(PDO $pdo, int $org_id, bool $for_user = true): bool
{
    if ($org_id <= 0) return false;
    if ($for_user && akc_is_platform()) return false;
    if (!akc_load() || !function_exists('ak_plan_is_readonly')) return false;
    if (!akc_readonly_live($pdo)) return false;
    try { return (bool)ak_plan_is_readonly($pdo, $org_id); } catch (Throwable $e) { return false; }
}

/**
 * Pages de cotisation et de dons déjà diffusées (cotiser.php) : encore ouvertes ? Oui, sauf si
 * l'association est en accès limité depuis plus de $grace_days jours (60 par défaut). La date
 * de passage en Démarrage est lue sur la ligne d'abonnement courante (updated_at, sinon
 * started_at), à défaut la fin d'essai. Date introuvable ou erreur : ouvertes.
 */
function akc_public_pages_open(PDO $pdo, int $org_id, int $grace_days = 60): bool
{
    if (!akc_readonly($pdo, $org_id, false)) return true;
    try {
        $order = function_exists('ak_sub_order') ? ak_sub_order('s') : "(s.status = 'active') DESC, (s.status = 'trial') DESC, s.id DESC";
        $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
        $since = isset($cols['updated_at']) ? 'COALESCE(s.updated_at, s.started_at)' : 's.started_at';
        $st = $pdo->prepare("SELECT $since FROM asso_subscriptions s WHERE s.org_id = ? ORDER BY $order LIMIT 1");
        $st->execute([$org_id]);
        $d = (string)($st->fetchColumn() ?: '');
        if ($d === '') {
            $st = $pdo->prepare("SELECT trial_ends_at FROM organizations WHERE id = ?");
            $st->execute([$org_id]);
            $d = (string)($st->fetchColumn() ?: '');
        }
        $ts = $d !== '' ? strtotime($d) : false;
        if (!$ts) return true;
        return $ts > time() - $grace_days * 86400;
    } catch (Throwable $e) {
        return true;
    }
}

/** Message du bandeau d'accès limité. */
function akc_readonly_message(): string
{
    return function_exists('ak_readonly_message') ? ak_readonly_message()
         : 'Votre espace est en accès limité : vos données restent consultables et exportables.';
}

/** Quota de crédits IA d'un appel lancé par un utilisateur : null = permis, sinon le message. */
function akc_ai_block(PDO $pdo, int $org_id, int $credits = 1): ?string
{
    if ($org_id <= 0 || $credits <= 0) return null;
    if (!akc_load() || !function_exists('ak_ai_quota_block') || !akc_grille_live($pdo)) return null;
    try { return ak_ai_quota_block($pdo, $org_id, $credits); } catch (Throwable $e) { return null; }
}

/** Quota d'e-mails groupés pour $n destinataires : null = permis, sinon le message. */
function akc_email_block(PDO $pdo, int $org_id, int $n): ?string
{
    if ($org_id <= 0 || $n <= 0) return null;
    if (!akc_load() || !function_exists('ak_email_quota_block') || !akc_grille_live($pdo)) return null;
    try { return ak_email_quota_block($pdo, $org_id, $n); } catch (Throwable $e) { return null; }
}

/** Options du registre IA (ai_usage_ledger) : chemin, association, utilisateur. */
function akc_ai_opts(string $path, int $org_id = 0, int $user_id = 0): array
{
    $o = ['path' => $path];
    if ($org_id > 0) $o['org_id'] = $org_id;
    if ($user_id > 0) $o['user_id'] = $user_id;
    return $o;
}

/** Nom affiché de la formule courante (« Équipe », « Pro »…), '' si illisible. Jamais de prix. */
function akc_plan_name(PDO $pdo, int $org_id): string
{
    if ($org_id <= 0 || !akc_load() || !function_exists('ak_get_current_plan')) return '';
    try {
        $p = ak_get_current_plan($pdo, $org_id);
        if (!$p) return '';
        return function_exists('ak_plan_display_name') ? (string)ak_plan_display_name($p) : (string)($p['name'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Support prioritaire (feature_priority_support, formule Équipe) : parmi ces associations,
 * celles dont les demandes passent en tête de file. Faux en cas de doute (colonne ancienne,
 * 0 par défaut : rien n'est prioritaire sans la grille). @return array<int, true>
 */
function akc_priority_support_orgs(PDO $pdo, array $org_ids): array
{
    $out = [];
    if (!akc_load() || !function_exists('ak_get_current_plan')) return $out;
    foreach (array_unique(array_map('intval', $org_ids)) as $o) {
        if ($o <= 0) continue;
        try {
            $p = ak_get_current_plan($pdo, $o);
            if ($p && !empty($p['feature_priority_support'])) $out[$o] = true;
        } catch (Throwable $e) {}
    }
    return $out;
}

/** Rôle compté comme gestionnaire (admin, coordinateur, référent). */
function akc_is_manager_role(string $role): bool
{
    $roles = function_exists('ak_manager_roles') ? ak_manager_roles() : ['admin', 'coordinator', 'referent'];
    return in_array($role, $roles, true);
}

}
