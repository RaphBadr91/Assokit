<?php
/**
 * ============================================================
 * ASSOKIT — sa-auth-helpers.php
 * Helpers pour la double authentification SA (Niveau 2 sécurité)
 * ============================================================
 * À déposer à la RACINE du site (public_html/)
 * ============================================================
 */

// Durée de la session SA après double auth : 30 minutes SANS activité (chaque page du cockpit
// la prolonge, sa_auth_gate)
if (!defined('SA_SESSION_DURATION')) {
    define('SA_SESSION_DURATION', 30 * 60);
}

// ============================================================
// SIGNATURE COOKIE HMAC
// ============================================================

function sa_auth_secret(): string
{
    $secret = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    return hash('sha256', $secret . '|sa-cockpit-hmac|assokit-v2');
}

/**
 * Pose le cookie de session SA après un login réussi.
 * Format du cookie : "{user_id}.{expires_ts}.{HMAC}"
 */
function sa_auth_issue_cookie(int $userId): void
{
    $expires = time() + SA_SESSION_DURATION;
    $payload = $userId . '.' . $expires;
    $signature = hash_hmac('sha256', $payload, sa_auth_secret());
    $cookie = $payload . '.' . $signature;

    // Lax (et non Strict) : un lien reçu par e-mail (ticket, notification) arrive avec le cookie au
    // lieu de redemander le mot de passe ; les formulaires restent protégés par leur jeton CSRF.
    setcookie('ak_sa_session', $cookie, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['ak_sa_session'] = $cookie;
}

/**
 * Vérifie le cookie SA et retourne l'user_id si valide.
 */
function sa_auth_verify_cookie(): ?int
{
    $cookie = $_COOKIE['ak_sa_session'] ?? '';
    if (empty($cookie)) return null;

    $parts = explode('.', $cookie);
    if (count($parts) !== 3) return null;

    [$userIdStr, $expiresStr, $signature] = $parts;
    $userId  = (int) $userIdStr;
    $expires = (int) $expiresStr;

    if ($expires < time()) return null;

    $payload  = $userIdStr . '.' . $expiresStr;
    $expected = hash_hmac('sha256', $payload, sa_auth_secret());
    if (!hash_equals($expected, $signature)) return null;

    return $userId > 0 ? $userId : null;
}

/**
 * Supprime le cookie SA (logout).
 */
function sa_auth_clear_cookie(): void
{
    if (!headers_sent()) {
        setcookie('ak_sa_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE['ak_sa_session']);
}

// ============================================================
// VÉRIFICATION ACCÈS SA — à appeler sur chaque page /super-admin/*
// ============================================================

/**
 * Incarnation en cours : la session est celle de la personne incarnée (sans droits plateforme).
 * Plutôt qu'un 403, la page de sortie propose « Revenir à mon compte ». Même test que
 * is_impersonating() (impersonation-helpers.php), pour ne jamais boucler avec cette page.
 * À appeler juste avant le contrôle des droits de chaque page du cockpit (sa_require_super_admin()
 * le fait) ; jamais au chargement de ce fichier (aussi inclus par l'API de l'appli).
 */
function sa_leave_impersonation_first(): void
{
    if (PHP_SAPI !== 'cli' && !empty($_SESSION['impersonation_session_id']) && !empty($_SESSION['impersonation_target_user_id'])) {
        header('Location: /super-admin/incarner-stop');
        exit;
    }
}

/**
 * Vérifie que le user est :
 *   1. Connecté au site Assokit (via current_user())
 *   2. A un cookie SA valide (double auth)
 *   3. A is_super_admin=1 OU is_founder=1 (check BDD live)
 *
 * Si une condition échoue → redirige vers /super-admin-login
 */
function sa_auth_require(): array
{
    global $pdo;

    if (!function_exists('current_user')) {
        header('Location: /connexion');
        exit;
    }

    // 1. Doit être connecté au site
    $u = current_user();
    if (!$u) {
        header('Location: /connexion');
        exit;
    }

    // 2. Doit avoir un cookie SA valide
    $cookieUserId = sa_auth_verify_cookie();
    if (!$cookieUserId || $cookieUserId !== (int)$u['id']) {
        sa_auth_to_login();
    }

    // 3. Flags privilégiés vérifiés en BDD (live)
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, is_super_admin, is_founder
            FROM users
            WHERE id = :id AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$u['id']]);
        $row = $stmt->fetch();
        if (!$row || ((int)$row['is_super_admin'] !== 1 && (int)$row['is_founder'] !== 1)) {
            sa_auth_clear_cookie();
            http_response_code(403);
            exit('Privilèges révoqués.');
        }
    } catch (Throwable $e) {
        error_log('[SA AUTH] BDD : ' . $e->getMessage());
        http_response_code(500);
        exit('Erreur serveur.');
    }

    // 4. Session prolongée (30 min d'inactivité) + log de l'accès (silencieux si erreur)
    if (!headers_sent()) sa_auth_issue_cookie((int)$u['id']);
    sa_auth_log_access((int)$u['id']);

    return $row;
}

/**
 * Double authentification sur TOUTES les pages du cockpit (super-admin*, fondateur-*), après le
 * contrôle des droits de la page (sa_require_super_admin() l'appelle ; les pages fondateur-* qui ont
 * leur propre contrôle l'appellent juste après). Sans cookie valide pour le compte connecté (absent,
 * expiré, autre compte) : retour à /super-admin-login, puis à la page demandée. Cookie valide : la
 * session est prolongée de SA_SESSION_DURATION et l'accès est journalisé (Journal d'accès).
 * Sans effet en ligne de commande.
 */
function sa_auth_gate(): void
{
    if (PHP_SAPI === 'cli') return;
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u || empty($u['id'])) {
        header('Location: /connexion');
        exit;
    }
    $cookieUserId = sa_auth_verify_cookie();
    if (!$cookieUserId || $cookieUserId !== (int)$u['id']) {
        sa_auth_to_login();
    }
    if (!headers_sent()) sa_auth_issue_cookie((int)$u['id']);
    sa_auth_log_access((int)$u['id']);
}

/**
 * Chemin interne sûr pour revenir après la confirmation d'identité, sinon ''.
 * Jamais une autre adresse, ni la page de connexion du cockpit elle-même.
 */
function sa_auth_safe_return($path): string
{
    $path = is_string($path) ? trim($path) : '';
    if ($path === '' || strlen($path) > 1000 || $path[0] !== '/') return '';
    if (preg_match('#^/[/\\\\]#', $path) || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) return '';
    if (preg_match('#^/super-admin-log(in|out)#i', $path)) return '';
    return $path;
}

/** Redirige vers /super-admin-login (401 JSON pour un appel en arrière-plan) et arrête la requête. */
function sa_auth_to_login(): void
{
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === 0;
    if ($xhr) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'sa_reauth',
                          'message' => 'Session du cockpit expirée : confirmez votre identité sur /super-admin-login.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Retour : la page demandée (GET), ou la page du formulaire envoyé (POST, même site)
    $back = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $back = sa_auth_safe_return($_SERVER['REQUEST_URI'] ?? '');
    } else {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $ref_host = (string)parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '');
        if ($ref !== '' && strcasecmp($ref_host, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0) {
            $q = parse_url($ref, PHP_URL_QUERY);
            $back = sa_auth_safe_return((string)parse_url($ref, PHP_URL_PATH) . ($q ? '?' . $q : ''));
        }
    }
    header('Location: /super-admin-login' . ($back !== '' ? '?return=' . rawurlencode($back) : ''));
    exit;
}

// ============================================================
// LOGS
// ============================================================

function sa_auth_log_attempt(PDO $pdo, string $email, ?int $userId, string $status, ?string $errorDetail = null): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sa_auth_logs
                (email_attempted, user_id, status, ip_address, user_agent, error_detail, attempted_at)
            VALUES
                (:email, :uid, :status, :ip, :ua, :err, NOW())
        ");
        $stmt->execute([
            ':email'  => mb_substr($email, 0, 191),
            ':uid'    => $userId,
            ':status' => $status,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':err'    => $errorDetail ? mb_substr($errorDetail, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[SA AUTH] log_attempt : ' . $e->getMessage());
    }
}

function sa_auth_log_access(int $userId): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sa_access_log
                (user_id, method, url, ip_address, user_agent, accessed_at)
            VALUES
                (:uid, :method, :url, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':method' => mb_substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 10),
            ':url'    => mb_substr($_SERVER['REQUEST_URI'] ?? '/', 0, 500),
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[SA AUTH] log_access : ' . $e->getMessage());
    }
}

// ============================================================
// RATE LIMITING
// ============================================================

/**
 * Retourne true si l'IP a dépassé le quota d'échecs récents.
 */
function sa_auth_is_rate_limited(PDO $pdo, string $ip): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM sa_auth_logs
            WHERE ip_address = :ip
              AND status LIKE 'fail_%'
              AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute([':ip' => $ip]);
        return (int) $stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        return false;
    }
}

// ============================================================
// TIMER RESTANT
// ============================================================

function sa_auth_time_left(): int
{
    $cookie = $_COOKIE['ak_sa_session'] ?? '';
    if (empty($cookie)) return 0;
    $parts = explode('.', $cookie);
    if (count($parts) !== 3) return 0;
    $expires = (int) $parts[1];
    return max(0, $expires - time());
}

function sa_auth_is_active(): bool
{
    return sa_auth_verify_cookie() !== null;
}
