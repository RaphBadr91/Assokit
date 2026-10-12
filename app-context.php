<?php
/**
 * app-context.php — Savoir d'où vient la requête, et si l'on a le droit
 * d'y afficher quoi que ce soit qui ressemble à un achat.
 * ------------------------------------------------------------------
 * Pourquoi ce fichier existe :
 *
 * La directive 3.1.1 d'Apple interdit qu'une app affiche un bouton, un lien
 * ou même une simple mention renvoyant vers un achat effectué ailleurs que
 * dans l'App Store. Bloquer la navigation ne suffit pas : le seul fait
 * d'afficher « S'abonner » dans l'app est un motif de rejet.
 *
 * L'app Assokit affiche le site dans une WebView dont l'agent utilisateur
 * porte « AssokitApp/1.0 » (mobile/App.js, applicationNameForUserAgent).
 * Le serveur peut donc reconnaître ces requêtes et retirer toute surface
 * d'achat — bandeau d'essai, entrée « Abonnement » du menu, pages de plan
 * et de paiement.
 *
 * Sur le site consulté depuis un navigateur, rien ne change : les clients
 * gèrent leur abonnement normalement sur assokit.fr.
 *
 * Ce qui N'EST PAS masqué : /mon-asso-facturation et les factures que
 * l'association émet vers SES clients. Ce n'est pas un achat dans l'app,
 * c'est le métier de l'utilisateur.
 *
 * Le fichier dit aussi ce qu'une session ouverte a encore le droit de
 * faire (ak_session_guard, plus bas) : il est chargé en tête de
 * includes-layout.php, donc de toutes les pages et de toutes les API.
 * ------------------------------------------------------------------
 */

/** Adresse du compte remis à Apple pour l'examen de l'app. */
const AK_APPLE_REVIEW_EMAIL = 'apple.review@assokit.fr';

if (!function_exists('ak_is_mobile_app')) {

/**
 * La requête vient-elle de la WebView de l'app iOS/Android ?
 */
function ak_is_mobile_app(): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $cache = (stripos($ua, 'AssokitApp') !== false);
}

/**
 * Faut-il supprimer toute surface d'achat de cette page ?
 *
 * Vrai dans l'app (règle Apple 3.1.1), et vrai aussi pour le compte
 * d'examen même hors app : si l'examinateur ouvre le site à côté,
 * il ne doit pas non plus y trouver de tunnel de paiement.
 */
function ak_billing_hidden(): bool
{
    if (ak_is_mobile_app()) return true;

    $email = $_SESSION['user_email'] ?? '';
    if (!is_string($email)) return false;
    // Espace de démonstration DEMO F : pas d'abonnement Assokit à souscrire
    $login = $email !== '' ? $email
        : (string)((function_exists('current_user') ? ((current_user() ?: [])['email'] ?? '') : ''));
    if (substr(strtolower($login), -strlen('@demo-f.assokit.fr')) === '@demo-f.assokit.fr') return true;
    return strcasecmp($email, AK_APPLE_REVIEW_EMAIL) === 0;
}

/**
 * À appeler en tête des pages d'abonnement et de paiement.
 * Renvoie vers le tableau de bord au lieu d'afficher le tunnel.
 */
function ak_block_billing_page(string $to = '/dashboard'): void
{
    if (!ak_billing_hidden()) return;

    if (!headers_sent()) {
        header('Location: ' . $to, true, 302);
    }
    exit;
}

}

/* ==================================================================
 * Accès de la session
 * ==================================================================
 * Une session reste ouverte des heures, parfois des jours. Ce qui a
 * changé depuis la connexion doit pourtant s'appliquer tout de suite :
 *
 *   - compte désactivé ou supprimé par l'administrateur → session fermée ;
 *   - association résiliée ou supprimée → session fermée ;
 *   - mot de passe provisoire (réinitialisé par un administrateur) → tant
 *     qu'il n'est pas remplacé, tout renvoie vers /changer-mot-de-passe ;
 *   - association suspendue à la main par le Fondateur (impayé, refus…) →
 *     seuls l'abonnement et le paiement, le support, les paramètres du
 *     compte et l'export RGPD restent ouverts ; le reste affiche l'avis
 *     de suspension (/connexion).
 *
 * La fin d'essai n'est PAS une suspension : ak_trial_expire() passe
 * l'association en « suspended » SANS motif et sur le plan gratuit
 * Démarrage, qu'elle continue d'utiliser. Seul un motif renseigné
 * (ak_org_suspend) coupe l'accès.
 *
 * Fondateurs et super-admins ne sont jamais coupés par l'état de leur
 * association, et rien n'est vérifié pendant une incarnation (c'est le
 * Fondateur qui agit). En cas d'erreur de lecture, on laisse passer :
 * une panne de base ne doit pas fermer le site à tout le monde.
 *
 * ak_session_guard() est appelée par includes-layout.php (pages et API) et
 * par require_login() ; connexion.php et connexion-2fa.php appliquent les
 * mêmes règles à l'ouverture de la session.
 * ------------------------------------------------------------------
 */
if (!function_exists('ak_session_guard')) {

/** Adresse donnée aux associations pour toute question d'accès. */
define('AK_SUPPORT_EMAIL', 'contact@assokit.fr');

/** Toujours ouverts : connexion, déconnexion, changement de mot de passe, fin d'incarnation. */
define('AK_GUARD_OPEN', [
    'connexion.php', 'connexion-2fa.php', 'deconnexion.php', 'changer-mot-de-passe.php',
    'super-admin-stop-impersonation.php',
    'api/app-csrf.php', 'api/app-account.php', 'api/app-change-password.php',
]);

/** Ouverts en plus quand l'association est suspendue : régulariser, joindre le support, gérer son compte. */
define('AK_GUARD_SUSPENDED_OPEN', [
    'parametres.php', 'action-parametres.php', 'securite-2fa.php', 'rgpd-export.php', 'rgpd-supprimer-compte.php',
    'abonnement.php', 'action-abonnement.php', 'mon-asso-plan.php', 'mon-asso-paiement.php',
    'mon-asso-paiement-success.php', 'mon-asso-annuler-abonnement.php', 'mon-asso-factures.php',
    'facture-abonnement-pdf.php', 'stripe-create-payment-intent.php',
    'support.php', 'support-nouveau.php', 'support-ticket.php', 'support-tickets.php',
    'api/app-update-account.php', 'api/app-delete-account.php', 'api/app-subscription-invoices.php',
]);

/**
 * Accès d'une association : 'ok', 'suspended' (suspension manuelle, motif renseigné)
 * ou 'closed' (résiliée ou supprimée).
 */
function ak_org_access(PDO $pdo, int $org_id): string
{
    if ($org_id <= 0) return 'ok';
    try {
        $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $st->execute([$org_id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ak_org_access] ' . $e->getMessage());
        return 'ok';
    }
    if (!$o) return 'ok';
    if (!empty($o['deleted_at']) || ($o['status'] ?? '') === 'cancelled') return 'closed';
    // Sans la colonne du motif, impossible de distinguer une fin d'essai : on ne coupe rien.
    if (($o['status'] ?? '') === 'suspended' && trim((string)($o['suspended_reason'] ?? '')) !== '') return 'suspended';
    return 'ok';
}

/** Compte au-dessus des associations (Fondateur, super-admin) : jamais coupé par l'état d'une association. */
function ak_user_is_platform(array $u): bool
{
    return !empty($u['is_super_admin']) || !empty($u['is_founder']) || !empty($u['is_platform_admin'])
        || in_array((string)($u['role'] ?? ''), ['super_admin', 'founder'], true);
}

/**
 * État de la session courante, relu en base :
 *   'anonymous', 'ok', 'account_closed', 'org_closed', 'must_change_password', 'org_suspended'.
 * @return array{state:string, user:?array}
 */
function ak_session_access(PDO $pdo): array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return ['state' => 'anonymous', 'user' => null];
    if (!empty($_SESSION['impersonation_target_user_id'])) return ['state' => 'ok', 'user' => null];
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[ak_session_access] ' . $e->getMessage());
        return ['state' => 'ok', 'user' => null];
    }
    if (!$u || (array_key_exists('is_active', $u) && (int)$u['is_active'] !== 1) || !empty($u['deleted_at'])) {
        return ['state' => 'account_closed', 'user' => $u];
    }
    $org = 'ok';
    if (!ak_user_is_platform($u)) {
        // L'association de la session (le compte de démonstration commercial en change)
        $org = ak_org_access($pdo, (int)($_SESSION['org_id'] ?? 0) ?: (int)($u['org_id'] ?? 0));
        if ($org === 'closed') return ['state' => 'org_closed', 'user' => $u];
    }
    if (!empty($u['must_change_password'])) return ['state' => 'must_change_password', 'user' => $u];
    if ($org === 'suspended') return ['state' => 'org_suspended', 'user' => $u];
    return ['state' => 'ok', 'user' => $u];
}

/** Message affiché (page ou API) pour chaque état bloquant. */
function ak_session_access_message(string $state): string
{
    switch ($state) {
        case 'account_closed':
            return 'Votre compte a été désactivé par l’administrateur de votre association. Contactez-le pour le réactiver.';
        case 'org_closed':
            return 'L’accès de votre association à Assokit est fermé. Pour toute question, écrivez à ' . AK_SUPPORT_EMAIL . '.';
        case 'org_suspended':
            return 'L’accès de votre association à Assokit est suspendu. Vos données sont conservées. Pour le rétablir, contactez ' . AK_SUPPORT_EMAIL . '.';
        case 'must_change_password':
            return 'Votre mot de passe est provisoire : choisissez-en un nouveau (Paramètres › Mot de passe) pour continuer.';
    }
    return '';
}

/**
 * Adresse de retour après connexion (?next=) : un chemin du site seulement, jamais
 * une autre adresse ni une page du parcours de connexion. Renvoie '' si refusée.
 */
function ak_login_next($next): string
{
    $next = is_string($next) ? trim($next) : '';
    if ($next === '' || strlen($next) > 1000 || $next[0] !== '/') return '';
    if (preg_match('#^/[/\\\\]#', $next) || preg_match('/[\x00-\x1F\x7F\\\\]/', $next)) return '';
    $path = strtolower((string)parse_url('https://assokit.fr' . $next, PHP_URL_PATH));
    if ($path === '' || $path === '/' || $path === '/index.php') return '';
    if (preg_match('#^/(connexion|deconnexion|changer-mot-de-passe|definir-mot-de-passe|mot-de-passe-oublie|signup|verifier-email|api/)#', $path)) return '';
    return $next;
}

/**
 * Où envoyer un utilisateur qui vient de se connecter (mot de passe, puis 2FA).
 * Priorités : compte de démonstration commercial, mot de passe provisoire, association
 * suspendue (avis de suspension), page demandée avant la connexion, accueil du rôle.
 */
function ak_login_destination(array $u, string $next = '', string $org_access = 'ok'): string
{
    if (strcasecmp((string)($u['email'] ?? ''), 'demo@assokit.fr') === 0) return '/demo-selector.php';
    $next = ak_login_next($next);
    $is_sa = !empty($u['is_super_admin']) || ($u['role'] ?? '') === 'super_admin';
    $home  = $is_sa ? '/super-admin' : (!empty($u['parent_org_id']) ? '/mairie-dashboard' : '/dashboard');
    $to    = $next !== '' ? $next : $home;
    if (!empty($u['must_change_password'])) {
        return '/changer-mot-de-passe' . ($next !== '' || $is_sa ? '?next=' . rawurlencode($to) : '');
    }
    if ($org_access === 'suspended' && !ak_user_is_platform($u)) return '/connexion';
    return $to;
}

/** Ferme la session (même effet que /deconnexion), sans redirection. */
function ak_session_end(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

/** Script en cours, relatif à la racine du site (« parametres.php », « api/app-csrf.php »). */
function ak_guard_script(): string
{
    return ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
}

/** Requête qui attend du JSON : API de l'app (api/…), API du site (api-….php), appels fetch. */
function ak_guard_is_api(string $script): bool
{
    return strpos($script, 'api/') === 0 || strpos($script, 'api-') === 0
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

/** Arrête la requête : JSON pour les API, redirection pour les pages. */
function ak_guard_stop(bool $api, int $code, string $error, string $state, string $location): void
{
    if ($api) {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'error' => $error, 'message' => ak_session_access_message($state)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!headers_sent()) {
        header('Location: ' . $location, true, 302);
    } else {
        echo '<p><a href="' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '">Continuer</a></p>';
    }
    exit;
}

/**
 * Visiteur non connecté sur une page protégée : la redirection vers /connexion
 * (posée plus loin par require_login) emporte l'adresse demandée en ?next=, pour
 * y revenir après la connexion (liens reçus par e-mail, notifications).
 */
function ak_login_remember_target(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (ak_guard_is_api(ak_guard_script()) || ak_login_next($uri) === '') return;
    header_register_callback(function () use ($uri) {
        $code = http_response_code();
        if ($code < 300 || $code >= 400) return;
        foreach (headers_list() as $h) {
            if (preg_match('#^Location:\s*((?:https?://[^/\s]+)?/(?:connexion(?:\.php)?|login))\s*$#i', $h, $m)) {
                header('Location: ' . $m[1] . '?next=' . rawurlencode($uri), true);
                return;
            }
        }
    });
}

/**
 * À appeler en tête de chaque page et de chaque API, après config.php : par
 * includes-layout.php et par require_login(). Ne fait rien en ligne de commande
 * ni pour un état normal.
 */
function ak_session_guard(): void
{
    static $done = false; // includes-layout.php puis require_login() : une seule vérification
    if ($done || PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    global $pdo;
    if (!($pdo instanceof PDO)) return;
    $done = true;

    // Incarnation par le Fondateur : fin automatique après 1 h et journal des pages vues. Rien
    // d'autre n'est vérifié (c'est le Fondateur qui agit, cf. ak_session_access).
    if (!empty($_SESSION['impersonation_target_user_id'])) {
        require_once __DIR__ . '/impersonation-helpers.php';
        impersonation_middleware();
        return;
    }

    $acc = ak_session_access($pdo);
    $state = $acc['state'];
    if ($state === 'anonymous') { ak_login_remember_target(); return; }
    if ($state === 'ok') return;

    $script = ak_guard_script();
    $api = ak_guard_is_api($script);

    if ($state === 'account_closed' || $state === 'org_closed') {
        ak_session_end();
        ak_guard_stop($api, 401, 'auth', $state, '/connexion?acces=' . ($state === 'org_closed' ? 'ferme' : 'desactive'));
    }
    if (in_array($script, AK_GUARD_OPEN, true)) return;
    if ($state === 'must_change_password') {
        $next = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? ak_login_next((string)($_SERVER['REQUEST_URI'] ?? '')) : '';
        ak_guard_stop($api, 403, 'must_change_password', $state, '/changer-mot-de-passe' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    }
    if ($state === 'org_suspended' && !in_array($script, AK_GUARD_SUSPENDED_OPEN, true)) {
        ak_guard_stop($api, 403, 'org_suspended', $state, '/connexion');
    }
}

}

/* ==================================================================
 * Suppression de son propre compte (Paramètres › Compte, ou l'app)
 * ==================================================================
 * Mêmes règles sur le site (rgpd-supprimer-compte.php) et dans l'app
 * (api/app-delete-account.php, api/app-account.php → can_delete).
 * ------------------------------------------------------------------
 */
if (!function_exists('ak_account_delete_blocker')) {

/**
 * Pourquoi ce compte ne peut pas se supprimer lui-même : '' s'il le peut.
 *   - Fondateur ou administrateur de la plateforme : la propriété doit d'abord être transférée ;
 *   - seul administrateur actif de son association (ou de sa collectivité, pour un agent de
 *     mairie) : personne ne pourrait plus gérer l'administration, les paramètres ni l'abonnement.
 *     Même règle que la suppression d'un adhérent par un admin (supprimer-adherent.php).
 * Le compte est relu en base (son association à lui, pas celle de la session).
 */
function ak_account_delete_blocker(PDO $pdo, int $uid): string
{
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[ak_account_delete_blocker] ' . $e->getMessage());
        return 'Vérification impossible pour le moment. Merci de réessayer.';
    }
    if (!$u) return '';
    if (!empty($u['is_founder']) || !empty($u['is_platform_admin'])) {
        return 'Votre compte gère la structure : contactez le support pour transférer la propriété avant suppression.';
    }
    try {
        if (!empty($u['parent_org_id'])) {
            if (($u['parent_org_role'] ?? '') !== 'admin') return '';
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE parent_org_id = ? AND parent_org_role = 'admin' AND is_active = 1 AND deleted_at IS NULL AND id <> ?");
            $st->execute([(int)$u['parent_org_id'], $uid]);
            $hint = 'de votre collectivité : nommez un autre administrateur (menu Paramètres)';
        } elseif (($u['role'] ?? '') === 'admin' && (int)($u['org_id'] ?? 0) > 0) {
            // Personne d'autre dans l'association : il n'y a personne à nommer, c'est l'association
            // qu'il faut fermer (support)
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL AND id <> ?");
            $st->execute([(int)$u['org_id'], $uid]);
            if ((int)$st->fetchColumn() === 0) {
                return 'Vous êtes le seul compte de l’association : pour la fermer et supprimer vos données, écrivez à '
                    . (defined('AK_SUPPORT_EMAIL') ? AK_SUPPORT_EMAIL : 'contact@assokit.fr') . '.';
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1 AND deleted_at IS NULL AND id <> ?");
            $st->execute([(int)$u['org_id'], $uid]);
            $hint = 'de l’association : nommez un autre administrateur (menu Administration)';
        } else {
            return '';
        }
        if ((int)$st->fetchColumn() === 0) {
            return 'Vous êtes le seul administrateur ' . $hint . ' avant de supprimer votre compte.';
        }
    } catch (Throwable $e) {
        error_log('[ak_account_delete_blocker] ' . $e->getMessage());
        return 'Vérification impossible pour le moment. Merci de réessayer.';
    }
    return '';
}

}
