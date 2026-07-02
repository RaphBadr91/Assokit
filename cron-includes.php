<?php
/**
 * ============================================================
 * ASSOKIT — cron-includes.php (v5)
 * ============================================================
 * Changements v5 :
 *   - SUPPRESSION du mode token URL
 *   - Authentification via cookie signé HMAC avec réauth 15 min
 *   - Helper cron_issue_cockpit_cookie() pour connecter
 *   - Helper cron_clear_cockpit_cookie() pour déconnecter
 * ============================================================
 */

if (!defined('CRON_EXECUTING') && !defined('CRON_ADMIN_UI')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cron-email-templates.php';

// Durée de validité du cockpit : 15 minutes (sécurité maximale)
if (!defined('CRON_COCKPIT_DURATION')) {
    define('CRON_COCKPIT_DURATION', 15 * 60);
}

// -------------------------------------------------
// BDD
// -------------------------------------------------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . (defined('DB_HOST') ? DB_HOST : 'localhost')
            . ';dbname=' . (defined('DB_NAME') ? DB_NAME : 'pura7044_assokit')
            . ';charset=utf8mb4',
            defined('DB_USER') ? DB_USER : 'pura7044_ak',
            defined('DB_PASS') ? DB_PASS : '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Throwable $e) {
        error_log('[CRON] Connexion BDD impossible : ' . $e->getMessage());
        exit(1);
    }
}

// ============================================================
// LOGGER CRON
// ============================================================

function cron_log_start(PDO $pdo, string $jobName, string $triggeredBy = 'cron', ?int $userId = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO cron_runs (job_name, status, started_at, triggered_by, triggered_by_user_id)
        VALUES (:job, 'running', NOW(), :by, :uid)
    ");
    $stmt->execute([':job' => $jobName, ':by' => $triggeredBy, ':uid' => $userId]);
    return (int) $pdo->lastInsertId();
}

function cron_log_end(PDO $pdo, int $runId, string $status, array $stats = [], ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare("SELECT started_at FROM cron_runs WHERE id = :id");
    $stmt->execute([':id' => $runId]);
    $row = $stmt->fetch();
    $durationMs = 0;
    if ($row && !empty($row['started_at'])) {
        $durationMs = max(0, (int) round((microtime(true) - strtotime($row['started_at'])) * 1000));
    }

    $upd = $pdo->prepare("
        UPDATE cron_runs SET
            status = :status, finished_at = NOW(), duration_ms = :dur,
            items_processed = :proc, items_succeeded = :ok, items_failed = :ko,
            emails_sent = :emails, details = :det, error_message = :err
        WHERE id = :id
    ");
    $upd->execute([
        ':status' => $status,
        ':dur'    => $durationMs,
        ':proc'   => (int)($stats['processed']  ?? 0),
        ':ok'     => (int)($stats['succeeded']  ?? 0),
        ':ko'     => (int)($stats['failed']     ?? 0),
        ':emails' => (int)($stats['emails']     ?? 0),
        ':det'    => isset($stats['details'])   ? json_encode($stats['details'], JSON_UNESCAPED_UNICODE) : null,
        ':err'    => $errorMessage,
        ':id'     => $runId,
    ]);
}

// ============================================================
// EMAIL RESEND
// ============================================================

function cron_send_email(
    PDO $pdo, int $runId, string $emailTo, string $emailType,
    string $subject, string $htmlBody, array $context = []
): bool {
    $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
    $from   = defined('RESEND_FROM')    ? RESEND_FROM    : 'noreply@assokit.fr';

    if (empty($apiKey)) {
        cron_log_email($pdo, $runId, $emailTo, $emailType, $subject, 'failed', null, 'RESEND_API_KEY manquante', $context);
        return false;
    }

    $payload = [
        'from'    => 'Assokit <' . $from . '>',
        'to'      => [$emailTo],
        'subject' => $subject,
        'html'    => $htmlBody,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        cron_log_email($pdo, $runId, $emailTo, $emailType, $subject, 'failed', null, 'cURL: ' . $curlErr, $context);
        return false;
    }

    $resp = json_decode($raw, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($resp['id'])) {
        cron_log_email($pdo, $runId, $emailTo, $emailType, $subject, 'sent', $resp['id'], null, $context);
        return true;
    }

    $errMsg = $resp['message'] ?? ('HTTP ' . $httpCode . ' ' . substr($raw, 0, 200));
    cron_log_email($pdo, $runId, $emailTo, $emailType, $subject, 'failed', null, $errMsg, $context);
    return false;
}

function cron_log_email(
    PDO $pdo, int $runId, string $emailTo, string $emailType, string $subject,
    string $status, ?string $providerId, ?string $errorMessage, array $context = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO cron_email_log
            (cron_run_id, user_id, invoice_id, org_id, email_to, email_type, subject, status, provider_id, error_message, sent_at)
        VALUES
            (:run, :uid, :iid, :oid, :to, :type, :subj, :status, :pid, :err, NOW())
    ");
    $stmt->execute([
        ':run'    => $runId,
        ':uid'    => $context['user_id']    ?? null,
        ':iid'    => $context['invoice_id'] ?? null,
        ':oid'    => $context['org_id']     ?? null,
        ':to'     => $emailTo,
        ':type'   => $emailType,
        ':subj'   => mb_substr($subject, 0, 255),
        ':status' => $status,
        ':pid'    => $providerId,
        ':err'    => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
    ]);
}

// ============================================================
// ORG HELPERS
// ============================================================

function cron_get_org_admin(PDO $pdo, int $orgId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, role
        FROM users
        WHERE org_id = :oid AND is_active = 1 AND deleted_at IS NULL
          AND role IN ('admin', 'super_admin')
        ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ");
    $stmt->execute([':oid' => $orgId]);
    return $stmt->fetch() ?: null;
}

// ============================================================
// FORMATTERS
// ============================================================

function cron_format_euros(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function cron_format_date_fr(?string $date): string
{
    if (empty($date)) return '—';
    $ts = strtotime($date);
    if ($ts === false) return '—';
    $mois = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    return (int)date('j', $ts) . ' ' . $mois[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

// ============================================================
// COCKPIT COOKIE (HMAC signé, réauth 15 min)
// ============================================================

/**
 * Génère la clé secrète dérivée de CRON_TOKEN pour signer les cookies.
 */
function cron_cockpit_secret(): string
{
    $secret = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    return hash('sha256', $secret . '|cockpit-hmac|assokit');
}

/**
 * Pose le cookie cockpit après un login réussi.
 * Format : "{user_id}.{expires_ts}.{HMAC}"
 */
function cron_issue_cockpit_cookie(int $userId): void
{
    $expires = time() + CRON_COCKPIT_DURATION;
    $payload = $userId . '.' . $expires;
    $signature = hash_hmac('sha256', $payload, cron_cockpit_secret());
    $cookie = $payload . '.' . $signature;

    setcookie('ak_cockpit', $cookie, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE['ak_cockpit'] = $cookie; // Pour que la vérif marche dans la même requête
}

/**
 * Vérifie et retourne l'user_id si le cookie cockpit est valide.
 * Sinon null.
 */
function cron_verify_cockpit_cookie(): ?int
{
    $cookie = $_COOKIE['ak_cockpit'] ?? '';
    if (empty($cookie)) return null;

    $parts = explode('.', $cookie);
    if (count($parts) !== 3) return null;

    [$userIdStr, $expiresStr, $signature] = $parts;
    $userId  = (int) $userIdStr;
    $expires = (int) $expiresStr;

    // Vérif expiration
    if ($expires < time()) return null;

    // Vérif signature HMAC
    $payload  = $userIdStr . '.' . $expiresStr;
    $expected = hash_hmac('sha256', $payload, cron_cockpit_secret());
    if (!hash_equals($expected, $signature)) return null;

    return $userId > 0 ? $userId : null;
}

/**
 * Efface le cookie cockpit (logout).
 */
function cron_clear_cockpit_cookie(): void
{
    if (!headers_sent()) {
        setcookie('ak_cockpit', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    unset($_COOKIE['ak_cockpit']);
}

// ============================================================
// ACCESS CHECK — Cookie cockpit valide ?
// ============================================================

/**
 * Vérifie si l'utilisateur a un cookie cockpit valide
 * ET si son compte a toujours les flags is_super_admin OU is_founder.
 */
function cron_is_super_admin(): bool
{
    $userId = cron_verify_cockpit_cookie();
    if (!$userId) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT is_super_admin, is_founder
            FROM users
            WHERE id = :id AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        return ((int)$row['is_super_admin'] === 1) || ((int)$row['is_founder'] === 1);
    } catch (Throwable $e) {
        error_log('[CRON] cron_is_super_admin() erreur BDD : ' . $e->getMessage());
        return false;
    }
}

function cron_is_privileged(): bool
{
    return cron_is_super_admin();
}

/**
 * Retourne le user courant du cockpit (sa session, pas celle d'Assokit).
 */
function cron_current_cockpit_user(): ?array
{
    $userId = cron_verify_cockpit_cookie();
    if (!$userId) return null;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, role, is_super_admin, is_founder
            FROM users WHERE id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Retourne les secondes restantes avant expiration du cookie cockpit.
 */
function cron_cockpit_time_left(): int
{
    $cookie = $_COOKIE['ak_cockpit'] ?? '';
    if (empty($cookie)) return 0;
    $parts = explode('.', $cookie);
    if (count($parts) !== 3) return 0;
    $expires = (int) $parts[1];
    return max(0, $expires - time());
}

// ============================================================
// NUMÉROTATION FACTURES
// ============================================================

function cron_generate_invoice_number(PDO $pdo): string
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare("
        SELECT invoice_number FROM invoices
        WHERE invoice_number LIKE :prefix
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':prefix' => 'AK-' . $year . '-%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/AK-\d{4}-(\d+)/', $last, $m)) {
        $next = (int) $m[1] + 1;
    }
    return sprintf('AK-%d-%06d', $year, $next);
}

// ============================================================
// VERROU ANTI-DOUBLE EXECUTION
// ============================================================

function cron_try_lock(string $jobName)
{
    $lockDir = __DIR__ . '/cron-locks';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

    $lockFile = $lockDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $jobName) . '.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp === false) return false;

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function cron_unlock($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
