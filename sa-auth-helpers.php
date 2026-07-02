<?php
/**
 * ============================================================
 * ASSOKIT — sa-auth-helpers.php
 * Helpers pour la double authentification SA (Niveau 2 sécurité)
 * ============================================================
 * À déposer à la RACINE du site (public_html/)
 * ============================================================
 */

// Durée de la session SA après double auth : 30 minutes
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

    setcookie('ak_sa_session', $cookie, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
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
            'samesite' => 'Strict',
        ]);
    }
    unset($_COOKIE['ak_sa_session']);
}

// ============================================================
// VÉRIFICATION ACCÈS SA — à appeler sur chaque page /super-admin/*
// ============================================================

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
        header('Location: /super-admin-login');
        exit;
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

    // 4. Log de l'accès (silencieux si erreur)
    sa_auth_log_access((int)$u['id']);

    return $row;
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
