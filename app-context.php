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
 * La requête vient-elle de l'app iPhone ? (agent « AssokitApp » sans « Android »)
 *
 * Sur iPhone, rien n'est vendu ni essayé dans l'app (règle 3.1.1 d'Apple) :
 * l'inscription y est une DEMANDE de création d'espace, examinée par notre
 * équipe sous 48 h ; rien n'est actif avant la validation, et l'essai
 * démarre seulement à ce moment-là (signup.php, signup-review-helpers.php ;
 * mobile/App.js, IOS_SIGNUP_REQUEST). L'app Android garde l'inscription
 * avec essai immédiat, comme le site.
 */
function ak_is_ios_app(): bool
{
    if (!ak_is_mobile_app()) return false;
    return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Android') === false;
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
 *     de suspension (/connexion) ;
 *   - demande de création d'espace faite dans l'app iPhone, pas encore
 *     validée par le Fondateur (ak_signup_review_pending) → aucune session :
 *     la connexion affiche « Votre espace est en cours de validation… »,
 *     comme une association fermée. Une association « à valider » créée par
 *     un super-admin reste, elle, utilisable (cockpit : « fonctionnelle »).
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
 * ak_session_guard() est appelée par includes-layout.php (pages et API), par
 * activity-tracker.php (actions qui ne chargent pas le layout) et par
 * require_login() du config.php de production ; connexion.php et
 * connexion-2fa.php appliquent les mêmes règles à l'ouverture de la session.
 * ------------------------------------------------------------------
 */
if (!function_exists('ak_session_guard')) {

/** Adresse donnée aux associations pour toute question d'accès. */
define('AK_SUPPORT_EMAIL', 'contact@assokit.fr');

/**
 * Marque d'une demande de création d'espace faite dans l'app iPhone et pas encore validée :
 * l'association est « suspended » avec ce motif exact et validation_status « pending_founder »
 * (signup.php). Valider (ak_signup_review_validate) ou refuser (ak_signup_review_reject) l'efface.
 */
define('AK_SIGNUP_REVIEW_REASON', 'Demande de création d’espace en attente de validation');

/** Toujours ouverts : connexion, déconnexion, changement de mot de passe, fin d'incarnation. */
define('AK_GUARD_OPEN', [
    'connexion.php', 'connexion-2fa.php', 'deconnexion.php', 'changer-mot-de-passe.php',
    'super-admin-stop-impersonation.php',
    'api/app-csrf.php', 'api/app-account.php', 'api/app-change-password.php',
]);

/**
 * Pages publiques à jeton (émargement, convocation d'AG, validation d'une demande d'espace) ou à
 * mot de passe (retrait d'une demande d'espace, ouvert aussi au demandeur refusé dont la session
 * est suspendue) : jamais concernées par l'état de la session du navigateur.
 */
define('AK_GUARD_PUBLIC', ['emarger.php', 'ag-public.php', 'valider-inscription.php', 'retirer-demande.php']);

/** Ouverts en plus quand l'association est suspendue : régulariser, joindre le support, gérer son compte. */
define('AK_GUARD_SUSPENDED_OPEN', [
    'parametres.php', 'action-parametres.php', 'securite-2fa.php', 'rgpd-export.php', 'rgpd-supprimer-compte.php',
    'abonnement.php', 'action-abonnement.php', 'mon-asso-plan.php', 'mon-asso-paiement.php',
    'mon-asso-paiement-success.php', 'mon-asso-annuler-abonnement.php', 'mon-asso-factures.php',
    'facture-abonnement-pdf.php', 'stripe-create-payment-intent.php',
    'support.php', 'support-nouveau.php', 'support-ticket.php', 'support-tickets.php',
    'api/app-update-account.php', 'api/app-delete-account.php', 'api/app-subscription-invoices.php', 'api/app-tickets.php',
]);

/**
 * Ouverts à un agent de collectivité sans association, en plus des pages mairie-*, action-mairie*
 * et d'AK_GUARD_OPEN : fiche projet (lecture), notifications, son compte personnel (mot de
 * passe, double authentification, export et suppression RGPD) et l'accueil de l'appli
 * (api/app-dashboard.php y renvoie le profil « mairie » et le lien de l'espace sur le site).
 * Dans l'appli, ses notifications se marquent lues (api/app-notif-read.php, comme
 * action-mark-read.php sur le site) et son téléphone s'inscrit aux notifications push
 * (api/app-register-push.php) : sinon le 403 'mairie_space' ramenait l'appli à l'accueil.
 */
define('AK_GUARD_MAIRIE_OPEN', [
    'projet.php', 'notifications.php',
    'parametres.php', 'securite-2fa.php', 'rgpd-export.php', 'rgpd-supprimer-compte.php',
    'api/app-dashboard.php', 'api/app-notifications.php', 'api/app-update-account.php', 'api/app-delete-account.php',
    'api/app-notif-read.php', 'api/app-register-push.php',
    // Lectures de l'appli déjà installée (avant le profil « mairie ») : liste vide ou allowed:false
    // pour un compte sans association (org_id -1), comme avant ce garde. Un 403 la laissait sur
    // « Chargement… » (Projets, Membres, Adhérents) ou « Chargement impossible » (Tableau de bord complet).
    'api/app-projects.php', 'api/app-members.php', 'api/app-events.php', 'api/app-channels.php',
    'api/app-tickets.php', 'api/app-dashboard-full.php', 'api/app-folders.php',
]);

/**
 * Demande de création d'espace (app iPhone) qui attend la décision du Fondateur ?
 * $org : ligne organizations complète. Une association « à valider » créée par un
 * super-admin n'a pas ce motif : elle n'est pas concernée.
 */
function ak_signup_review_pending(array $org): bool
{
    return ($org['validation_status'] ?? '') === 'pending_founder'
        && ($org['status'] ?? '') === 'suspended'
        && trim((string)($org['suspended_reason'] ?? '')) === AK_SIGNUP_REVIEW_REASON
        && empty($org['deleted_at']);
}

/**
 * Accès d'une association : 'ok', 'pending' (demande de création d'espace pas encore
 * validée), 'suspended' (suspension manuelle, motif renseigné) ou 'closed' (résiliée
 * ou supprimée).
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
    if (ak_signup_review_pending($o)) return 'pending';
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
 *   'anonymous', 'ok', 'account_closed', 'org_closed', 'org_pending', 'must_change_password', 'org_suspended'.
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
        if ($org === 'pending') return ['state' => 'org_pending', 'user' => $u];
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
            // Aussi pour un agent retiré par sa collectivité ou par Assokit : formulation neutre
            return 'Votre compte a été désactivé par un administrateur. Contactez l’administrateur de votre association ou de votre collectivité pour le réactiver.';
        case 'org_closed':
            return 'L’accès de votre association à Assokit est fermé. Pour toute question, écrivez à ' . AK_SUPPORT_EMAIL . '.';
        case 'org_pending':
            return 'Votre espace est en cours de validation par notre équipe (48 h maximum). Vous recevrez un e-mail dès son activation.';
        case 'org_suspended':
            return 'L’accès de votre association à Assokit est suspendu. Vos données sont conservées. Pour le rétablir, contactez ' . AK_SUPPORT_EMAIL . '.';
        case 'must_change_password':
            return 'Votre mot de passe est provisoire : choisissez-en un nouveau (Paramètres › Mot de passe) pour continuer.';
        case 'mairie_space':
            return 'Cette fonctionnalité est réservée aux associations. Votre compte donne accès à l’espace collectivité (associations suivies, messagerie, emailing), sur le site.';
    }
    return '';
}

/**
 * Blocage après des échecs de connexion : '' si l'essai est permis, sinon le message à afficher.
 * Vérifié AVANT le mot de passe par /connexion et par /retirer-demande (qui demande aussi le mot de
 * passe) : mêmes compteurs, mêmes seuils, une page ne sert jamais à contourner le blocage de l'autre.
 * Compteur : événements « login_failed » des 15 dernières minutes (assokit_activity_log, rempli par
 * activity_log_login_failed), par compte puis par adresse IP ($ip : activity_get_ip, la même que
 * celle enregistrée avec l'échec).
 */
function ak_login_lockout(PDO $pdo, string $email, string $ip): string
{
    $fails = function (string $where, array $params) use ($pdo): int {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM assokit_activity_log WHERE event_type = 'login_failed' AND $where AND created_at > (NOW() - INTERVAL 15 MINUTE)");
            $s->execute($params);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };
    // Blocage par compte : 10 échecs en 15 minutes, même avec le bon mot de passe ensuite.
    // Comptes de démo partagés : comptés pour cette adresse IP seulement, sinon n'importe
    // qui bloquerait la démo en se trompant 10 fois.
    $is_demo = (bool)preg_match('/@demo-f\.assokit\.fr$/i', $email);
    $account_fails = $is_demo
        ? $fails('user_email = ? AND ip = ?', [$email, $ip])
        : $fails('user_email = ?', [$email]);
    if ($account_fails >= 10) {
        return 'Trop de tentatives de connexion échouées. Pour votre sécurité, patientez 15 minutes avant de réessayer.';
    }
    // Plafond par IP, bon mot de passe compris : bloque l'essai d'un mot de passe sur beaucoup de
    // comptes. Bien plus haut que le blocage par compte, pour que quelques erreurs de collègues
    // derrière la même box ne gênent pas les autres.
    if ($fails('ip = ?', [$ip]) >= 50) {
        return 'Trop de tentatives de connexion échouées depuis votre connexion internet. Patientez 15 minutes avant de réessayer.';
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
 * includes-layout.php, activity-tracker.php et require_login(). Ne fait rien en
 * ligne de commande ni pour un état normal.
 */
function ak_session_guard(): void
{
    static $done = false; // layout, activity-tracker puis require_login() : une seule vérification
    if ($done || PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    global $pdo;
    if (!($pdo instanceof PDO)) return;
    $done = true;
    if (in_array(ak_guard_script(), AK_GUARD_PUBLIC, true)) return;

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

    // Demande d'espace pas encore validée : comme une association fermée, aucune page ni API ; l'appli
    // reçoit 401 'auth' avec le message et l'affiche sur son écran de connexion
    if ($state === 'account_closed' || $state === 'org_closed' || $state === 'org_pending') {
        ak_session_end();
        $acces = ['org_closed' => 'ferme', 'org_pending' => 'attente'][$state] ?? 'desactive';
        ak_guard_stop($api, 401, 'auth', $state, '/connexion?acces=' . $acces);
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

/**
 * Agent de collectivité (parent_org_id) sans association : seul l'espace mairie lui est ouvert.
 * Sans association, les pages association (/dashboard, /projets, /nouveau-dossier, /adherents…)
 * travaillaient dans un espace « org 0 » commun à toutes les collectivités : un dossier créé par
 * un agent d'Évry apparaissait chez l'agent du Département. Page → /mairie-dashboard ; API →
 * 403 'mairie_space'. La session compte autant que la base : un agent retiré depuis sa connexion
 * (parent_org_id vidé) est encore renvoyé vers l'espace mairie, qui lui refuse l'accès.
 * Fondateur, super-admin et membres d'une association ne sont pas concernés. Appelée par
 * includes-layout.php, après ak_session_guard() ; en cas d'erreur de lecture, on laisse passer.
 */
function ak_mairie_space_guard(): void
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    $done = true;
    if (in_array(ak_guard_script(), AK_GUARD_PUBLIC, true)) return;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || (int)($_SESSION['org_id'] ?? 0) > 0) return;
    global $pdo;
    if (!($pdo instanceof PDO)) return;
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[ak_mairie_space_guard] ' . $e->getMessage());
        return;
    }
    if (!$u || ak_user_is_platform($u) || (int)($u['org_id'] ?? 0) > 0) return;
    if (empty($_SESSION['parent_org_id']) && empty($u['parent_org_id'])) return;

    $script = ak_guard_script();
    if (preg_match('#^(mairie-|action-mairie|super-admin-mairie)#', $script)
        || in_array($script, AK_GUARD_OPEN, true) || in_array($script, AK_GUARD_MAIRIE_OPEN, true)) return;
    ak_guard_stop(ak_guard_is_api($script), 403, 'mairie_space', 'mairie_space', '/mairie-dashboard');
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

if (!function_exists('ak_account_sole_org')) {

/**
 * Seul compte actif de son association (administrateur, personne d'autre) : id de l'association,
 * sinon 0. Dans l'application, ce compte peut se supprimer lui-même, ce qui ferme l'espace de
 * l'association (api/app-delete-account.php) : un compte créé dans l'app (« Créer ma démo ») est
 * justement dans ce cas, et Apple (règle 5.1.1 v) n'admet pas une suppression par e-mail au support.
 * Fondateur, administrateur de la plateforme, agent de collectivité : jamais (0).
 */
function ak_account_sole_org(PDO $pdo, int $uid): int
{
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$u || !empty($u['is_founder']) || !empty($u['is_platform_admin']) || !empty($u['parent_org_id'])) return 0;
        $org = (int)($u['org_id'] ?? 0);
        if (($u['role'] ?? '') !== 'admin' || $org <= 0) return 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL AND id <> ?");
        $st->execute([$org, $uid]);
        return (int)$st->fetchColumn() === 0 ? $org : 0;
    } catch (Throwable $e) {
        error_log('[ak_account_sole_org] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Fermeture de l'espace d'une association quand son seul compte se supprime (ak_account_sole_org) :
 * dans l'app (api/app-delete-account.php) et au retrait d'une demande de création d'espace
 * (ak_signup_review_withdraw, signup-review-helpers.php). Comme une résiliation du Fondateur
 * (api/app-founder-action.php) :
 *   1. abonnements Stripe arrêtés D'ABORD (sa_stripe_cancel_for_org) : un échec n'efface rien
 *      (pas de carte prélevée pour un espace fermé) et la fonction renvoie false ;
 *   2. fin d'essai (plan Démarrage) et association « cancelled », dans une transaction (celle de
 *      l'appelant s'il en a ouvert une) ;
 *   3. libellé du plan resynchronisé, journal « self_delete_close_org ».
 * Les données de l'association suivent la règle de fermeture (CGU § 8, /suppression-compte § 6) :
 * 30 jours, puis suppression. Une erreur SQL remonte (compte et association intacts).
 */
function ak_account_close_sole_org(PDO $pdo, int $org_id): bool
{
    require_once __DIR__ . '/trial-helpers.php';
    if (is_file(__DIR__ . '/sa-plan-state-helpers.php')) require_once __DIR__ . '/sa-plan-state-helpers.php';
    $stripe = ['cancelled' => [], 'failed' => []];
    if (function_exists('sa_stripe_cancel_for_org')) {
        try { $stripe = sa_stripe_cancel_for_org($pdo, $org_id); }
        catch (Throwable $e) {
            error_log('[ak_account_close_sole_org] Stripe org #' . $org_id . ' : ' . get_class($e) . ' ' . $e->getMessage());
            $stripe['failed']['?'] = 'erreur technique';
        }
    }
    if ($stripe['failed']) {
        error_log('[ak_account_close_sole_org] org #' . $org_id . ' : abonnement Stripe non annulé, fermeture refusée');
        return false;
    }
    $own_tx = !$pdo->inTransaction();
    try {
        if ($own_tx) $pdo->beginTransaction();
        $st = function_exists('ak_org_plan_state') ? ak_org_plan_state($pdo, $org_id) : [];
        if (!empty($st['is_trial']) || !empty($st['trial_expired'])) {
            $free_id = (int) $pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            if ($free_id > 0 && function_exists('ak_org_set_plan')) ak_org_set_plan($pdo, $org_id, $free_id);
        }
        $pdo->prepare("UPDATE organizations SET status = 'cancelled' WHERE id = ?")->execute([$org_id]);
        if ($own_tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;   // compte intact : l'utilisateur peut réessayer (Stripe déjà arrêté n'est pas relancé)
    }
    if (function_exists('ak_org_sync_plan_label')) { try { ak_org_sync_plan_label($pdo, $org_id); } catch (Throwable $e) {} }
    try {
        $pdo->prepare("INSERT INTO assokit_activity_log (organization_id, event_type, event_action, ip, created_at) VALUES (?, 'account', 'self_delete_close_org', ?, NOW())")
            ->execute([$org_id, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}
    return true;
}

}

if (!function_exists('ak_account_self_anonymise')) {

/**
 * Suppression de son propre compte : anonymisation immédiate de la ligne users, la même sur le
 * site (rgpd-supprimer-compte.php) et dans l'app (api/app-delete-account.php). Ce qu'elle efface
 * est décrit sur la page publique /suppression-compte (§ 4) : la garder alignée.
 *
 *   1. identité et coordonnées (nom, e-mail, téléphone, ville), lien d'agenda, compte désactivé —
 *      colonnes présentes partout, une seule requête comme avant ;
 *   2. tout le reste de la ligne qui touche à la personne : mot de passe, double authentification
 *      et codes de secours, notes d'un administrateur sur elle, contrat (type et dates), dates
 *      d'adhésion, dernière connexion. Seulement les colonnes qui existent dans la base ;
 *   3. ses jetons : notifications push, liens de mot de passe non utilisés, liens d'agenda.
 *
 * Les contenus partagés dans l'association (messages, comptes rendus, fichiers…) restent,
 * signés « Compte supprimé » ; les paiements et pièces comptables aussi (10 ans).
 * Renvoie le nombre de comptes anonymisés (0 si déjà supprimé). Une erreur sur l'étape 1 lève
 * l'exception PDO ; les étapes 2 et 3 sont journalisées sans arrêter la suppression.
 */
function ak_account_self_anonymise(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare("
        UPDATE users SET
            email              = CONCAT('deleted_', id, '@anonyme.assokit.local'),
            first_name         = 'Compte',
            last_name          = 'supprimé',
            phone              = NULL,
            city               = NULL,
            ics_token          = NULL,
            is_active          = 0,
            deleted_at         = NOW(),
            deleted_by_user_id = id
        WHERE id = ? AND deleted_at IS NULL
    ");
    $st->execute([$uid]);
    $done = $st->rowCount();
    if ($done === 0) return 0;

    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[$c['Field']] = strtoupper((string)$c['Null']) === 'YES';
        }
        $set = [];
        if (isset($cols['password_hash'])) $set[] = "password_hash = ''";   // plus aucune connexion possible
        if (isset($cols['totp_enabled']))  $set[] = 'totp_enabled = 0';
        foreach (['totp_secret', 'totp_backup_codes', 'notes_admin', 'contract_type', 'contract_start_date',
                  'contract_end_date', 'adhesion_date', 'adhesion_valid_until', 'last_login_at', 'organization_name',
                  'mobile', 'address', 'postal_code', 'zip_code', 'birth_date', 'birthdate'] as $c) {
            if (!empty($cols[$c])) $set[] = "`$c` = NULL";   // colonne présente et qui accepte NULL
        }
        if ($set) $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([$uid]);
    } catch (Throwable $e) {
        error_log('[ak_account_self_anonymise] users ' . $e->getMessage());
    }

    foreach (['DELETE FROM asso_push_tokens WHERE user_id = ?',
              'DELETE FROM user_password_tokens WHERE user_id = ? AND used_at IS NULL',
              'DELETE FROM user_calendar_tokens WHERE user_id = ?'] as $q) {
        try { $pdo->prepare($q)->execute([$uid]); } catch (Throwable $e) {}   // table absente : rien à effacer
    }
    return $done;
}

}
