<?php
/**
 * Authentification fondateur (autonome, indépendante de /connexion)
 */

require_once __DIR__ . '/db.php';

// --- Démarrage de session sécurisé ---
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Régénération périodique du token de session
    if (!isset($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    } elseif (time() - $_SESSION['_last_regen'] > SESSION_REGENERATE_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}

// --- Vérifie si l'utilisateur est connecté en tant que fondateur ---
function auth_is_logged_in(): bool
{
    auth_start_session();
    if (empty($_SESSION['founder_logged']) || empty($_SESSION['login_time'])) {
        return false;
    }
    // Expiration manuelle (en plus du cookie)
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        auth_logout();
        return false;
    }
    return true;
}

// --- Vérifie qu'on est connecté, sinon redirige vers login ---
function auth_require(): void
{
    if (!auth_is_logged_in()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin-blog/');
        header("Location: /admin-blog/login.php?redirect={$redirect}");
        exit;
    }
    // IP whitelist (optionnelle)
    auth_check_ip_whitelist();
}

// --- IP whitelist (si configurée) ---
function auth_ip_whitelist(): array
{
    $whitelist = trim((string) config_get('ip_whitelist', ''));
    return $whitelist === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $whitelist))));
}

function auth_check_ip_whitelist(): void
{
    $whitelist = trim((string) config_get('ip_whitelist', ''));
    if ($whitelist === '') {
        return; // pas de restriction
    }
    $allowed = auth_ip_whitelist();
    $current = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($current, $allowed, true)) {
        admin_log('ip_blocked', "IP refusée: {$current}", 'warning');
        http_response_code(403);
        die('Access denied: IP not allowed');
    }
}

// --- Anti-bruteforce : blocage temporaire après plusieurs échecs ---
// Un seul mot de passe protège cet espace (publication sur le site, clé IA) :
// au-delà de 5 échecs en 15 min depuis une IP, ou de 30 échecs en 1 h toutes
// IP confondues, on refuse d'essayer le mot de passe. Les échecs sont comptés
// dans asso_blog_admin_logs (trace conservée) ; un compteur fichier atomique
// arrête en plus les rafales de requêtes envoyées en parallèle.
// Le plafond global ne bloque pas une IP connue (liste blanche, ou connexion
// réussie depuis cette IP ces 90 derniers jours) : REMOTE_ADDR ne se falsifie
// pas, donc entretenir le plafond depuis d'autres IP n'enferme pas le fondateur.
const AUTH_MAX_FAILS_IP     = 5;    // par IP, sur 15 minutes
const AUTH_MAX_FAILS_GLOBAL = 30;   // toutes IP, sur 1 heure

/** Message à afficher si la tentative est refusée, null si elle peut être vérifiée. */
function auth_login_blocked(): ?string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $by_ip = $global = 0;
    $known = false;
    try {
        $by_ip = (int) (DB::fetch(
            "SELECT COUNT(*) AS n FROM asso_blog_admin_logs
             WHERE action = 'login_failed' AND ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [$ip]
        )['n'] ?? 0);
        $global = (int) (DB::fetch(
            "SELECT COUNT(*) AS n FROM asso_blog_admin_logs
             WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )['n'] ?? 0);
        $known = $global >= AUTH_MAX_FAILS_GLOBAL && $ip !== '' && (in_array($ip, auth_ip_whitelist(), true) || DB::fetch(
            "SELECT 1 AS ok FROM asso_blog_admin_logs
             WHERE action = 'login_success' AND ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1",
            [$ip]
        ) !== null);
    } catch (Throwable $e) {
        // Journal illisible : le compteur fichier ci-dessous reste actif
    }
    require_once __DIR__ . '/../../rate-limit-helper.php';
    $burst_ok = ak_rate_limit('blog_admin_login', 10, 900);

    $global_hit = !$known && $global >= AUTH_MAX_FAILS_GLOBAL;
    if ($global_hit || $by_ip >= AUTH_MAX_FAILS_IP || !$burst_ok) {
        admin_log('login_blocked', "Trop de tentatives (IP {$ip}" . ($known ? ' connue' : '') . " : {$by_ip} échecs/15 min, total : {$global} échecs/1 h)", 'warning');
        return $global_hit
            ? 'Trop de tentatives. Réessaie dans une heure.'
            : 'Trop de tentatives. Réessaie dans 15 minutes.';
    }
    return null;
}

// --- Login ---
function auth_login(string $password): bool
{
    auth_start_session();
    $hash = (string) config_get('admin_password_hash', '');
    if ($hash === '') {
        admin_log('login_failed', 'Mot de passe admin non configuré', 'error');
        return false;
    }
    if (!password_verify($password, $hash)) {
        admin_log('login_failed', 'Mauvais mot de passe', 'warning');
        // Anti-bruteforce : pause aléatoire
        usleep(random_int(500000, 1500000));
        return false;
    }

    // Rotation du hash si l'algo a évolué
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        config_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    $_SESSION['founder_logged'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['_last_regen'] = time();
    session_regenerate_id(true);
    admin_log('login_success', 'Connexion fondateur', 'success');
    return true;
}

// --- Logout ---
function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// --- CSRF tokens ---
function csrf_token(): string
{
    auth_start_session();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(?string $token): bool
{
    auth_start_session();
    if (empty($token) || empty($_SESSION['_csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf'], $token);
}

function csrf_require(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrf_check($token)) {
        http_response_code(403);
        die('CSRF token invalid');
    }
}

// --- Headers de sécurité ---
function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
