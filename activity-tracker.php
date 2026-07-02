<?php
/**
 * activity-tracker.php — Tracking d'activité utilisateurs
 * --------------------------------------------------------------
 * À inclure dans le layout principal (après init session + auth).
 * 
 * USAGE :
 *   require_once __DIR__ . '/activity-tracker.php';
 *   activity_track_pageview(); // à appeler sur chaque page
 *   activity_log_action('facture_created', ['facture_id' => 42]); // pour tracker une action
 * 
 * Crée automatiquement la table BDD au premier appel si elle n'existe pas.
 * --------------------------------------------------------------
 */

if (!function_exists('activity_ensure_table')) {

/**
 * Crée la table de logs si elle n'existe pas (idempotent).
 */
function activity_ensure_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `assokit_activity_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `user_email` VARCHAR(255) DEFAULT NULL,
            `user_role` VARCHAR(50) DEFAULT NULL,
            `organization_id` INT UNSIGNED DEFAULT NULL,
            `event_type` VARCHAR(50) NOT NULL,
            `event_action` VARCHAR(100) DEFAULT NULL,
            `event_target` VARCHAR(255) DEFAULT NULL,
            `event_meta` JSON DEFAULT NULL,
            `path` VARCHAR(500) DEFAULT NULL,
            `method` VARCHAR(10) DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `session_id` VARCHAR(64) DEFAULT NULL,
            `duration_ms` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_user_email` (`user_email`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_org` (`organization_id`),
            KEY `idx_session` (`session_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Table sessions actives pour détection d'inactivité
        $pdo->exec("CREATE TABLE IF NOT EXISTS `assokit_active_sessions` (
            `session_id` VARCHAR(64) NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `user_email` VARCHAR(255) DEFAULT NULL,
            `organization_id` INT UNSIGNED DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `pageviews` INT UNSIGNED NOT NULL DEFAULT 1,
            `actions_count` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`session_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_last_activity` (`last_activity_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $checked = true;
    } catch (Throwable $e) {
        error_log('activity_ensure_table: ' . $e->getMessage());
    }
}

/**
 * Récupère le PDO en cherchant dans l'environnement (variable globale ou via DB)
 */
function activity_get_pdo(): ?PDO
{
    global $pdo;
    if ($pdo instanceof PDO) return $pdo;
    
    // Si la classe DB existe (admin-blog par exemple)
    if (class_exists('DB') && method_exists('DB', 'pdo')) {
        try { return DB::pdo(); } catch (Throwable $e) {}
    }
    return null;
}

/**
 * Récupère les infos de l'utilisateur connecté depuis $_SESSION
 * À adapter aux variables de session que tu utilises dans Assokit.
 */
function activity_get_user_context(): array
{
    return [
        'user_id'         => $_SESSION['user_id']         ?? null,
        'user_email'      => $_SESSION['user_email']      ?? ($_SESSION['email'] ?? null),
        'user_role'       => $_SESSION['user_role']       ?? ($_SESSION['role'] ?? null),
        'organization_id' => $_SESSION['organization_id'] ?? ($_SESSION['org_id'] ?? null),
    ];
}

/**
 * Track une vue de page (à appeler dans le layout principal sur chaque page)
 */
function activity_track_pageview(?string $custom_path = null): void
{
    if (php_sapi_name() === 'cli') return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') return;
    
    $pdo = activity_get_pdo();
    if (!$pdo) return;
    activity_ensure_table($pdo);
    
    $ctx = activity_get_user_context();
    
    // Skip pour visiteurs non connectés sur pages publiques (optionnel mais évite spam)
    if (!$ctx['user_id'] && !$ctx['user_email']) return;
    
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $session_id = session_id();
    if (!$session_id) return;
    
    $path   = $custom_path ?? ($_SERVER['REQUEST_URI'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ip     = activity_get_ip();
    $ua     = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    
    try {
        // 1. Maintenir la session active
        $stmt = $pdo->prepare("
            INSERT INTO assokit_active_sessions 
                (session_id, user_id, user_email, organization_id, ip, user_agent, started_at, last_activity_at, pageviews)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
            ON DUPLICATE KEY UPDATE
                last_activity_at = NOW(),
                pageviews = pageviews + 1
        ");
        $stmt->execute([
            $session_id,
            $ctx['user_id'],
            $ctx['user_email'],
            $ctx['organization_id'],
            $ip,
            $ua,
        ]);
        
        // 2. Logger la pageview (sauf actions qui se loggent elles-mêmes)
        $stmt = $pdo->prepare("
            INSERT INTO assokit_activity_log
                (user_id, user_email, user_role, organization_id, event_type, path, method, ip, user_agent, session_id)
            VALUES (?, ?, ?, ?, 'pageview', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ctx['user_id'],
            $ctx['user_email'],
            $ctx['user_role'],
            $ctx['organization_id'],
            mb_substr($path, 0, 500),
            $method,
            $ip,
            $ua,
            $session_id,
        ]);
    } catch (Throwable $e) {
        error_log('activity_track_pageview: ' . $e->getMessage());
    }
}

/**
 * Track une action métier (création, modif, suppression, etc.)
 * 
 * @param string $action Ex: 'facture_created', 'project_updated', 'message_sent', 'document_uploaded'
 * @param array $meta Métadonnées libres (id de la ressource, montant, etc.)
 * @param string|null $target Ex: l'ID ou nom de l'objet concerné
 */
function activity_log_action(string $action, array $meta = [], ?string $target = null): void
{
    $pdo = activity_get_pdo();
    if (!$pdo) return;
    activity_ensure_table($pdo);
    
    $ctx = activity_get_user_context();
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $session_id = session_id();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO assokit_activity_log
                (user_id, user_email, user_role, organization_id, event_type, event_action, event_target, event_meta, path, method, ip, user_agent, session_id)
            VALUES (?, ?, ?, ?, 'action', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ctx['user_id'],
            $ctx['user_email'],
            $ctx['user_role'],
            $ctx['organization_id'],
            mb_substr($action, 0, 100),
            $target ? mb_substr($target, 0, 255) : null,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            activity_get_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            $session_id,
        ]);
        
        // Incrémenter compteur d'actions sur la session
        if ($session_id) {
            $pdo->prepare("UPDATE assokit_active_sessions SET actions_count = actions_count + 1, last_activity_at = NOW() WHERE session_id = ?")
                ->execute([$session_id]);
        }
    } catch (Throwable $e) {
        error_log('activity_log_action: ' . $e->getMessage());
    }
}

/**
 * Track une connexion réussie (à appeler après vérification du mot de passe)
 */
function activity_log_login(int $user_id, string $email, ?string $role = null, ?int $organization_id = null): void
{
    $pdo = activity_get_pdo();
    if (!$pdo) return;
    activity_ensure_table($pdo);
    
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $session_id = session_id();
    $ip = activity_get_ip();
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    
    try {
        // Démarrer une session active (suppression de l'ancienne au cas où)
        $pdo->prepare("DELETE FROM assokit_active_sessions WHERE session_id = ?")->execute([$session_id]);
        $pdo->prepare("
            INSERT INTO assokit_active_sessions 
                (session_id, user_id, user_email, organization_id, ip, user_agent, started_at, last_activity_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([$session_id, $user_id, $email, $organization_id, $ip, $ua]);
        
        // Log
        $pdo->prepare("
            INSERT INTO assokit_activity_log
                (user_id, user_email, user_role, organization_id, event_type, event_action, ip, user_agent, session_id)
            VALUES (?, ?, ?, ?, 'login', 'login_success', ?, ?, ?)
        ")->execute([$user_id, $email, $role, $organization_id, $ip, $ua, $session_id]);
    } catch (Throwable $e) {
        error_log('activity_log_login: ' . $e->getMessage());
    }
}

/**
 * Track une tentative de login échouée
 */
function activity_log_login_failed(string $email, string $reason = 'wrong_password'): void
{
    $pdo = activity_get_pdo();
    if (!$pdo) return;
    activity_ensure_table($pdo);
    
    try {
        $pdo->prepare("
            INSERT INTO assokit_activity_log
                (user_email, event_type, event_action, event_meta, ip, user_agent)
            VALUES (?, 'login_failed', ?, ?, ?, ?)
        ")->execute([
            mb_substr($email, 0, 255),
            $reason,
            json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
            activity_get_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log_login_failed: ' . $e->getMessage());
    }
}

/**
 * Track une déconnexion explicite
 */
function activity_log_logout(): void
{
    $pdo = activity_get_pdo();
    if (!$pdo) return;
    activity_ensure_table($pdo);
    
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $session_id = session_id();
    $ctx = activity_get_user_context();
    
    try {
        // Calculer durée de session
        $row = $pdo->prepare("SELECT started_at, pageviews, actions_count FROM assokit_active_sessions WHERE session_id = ?");
        $row->execute([$session_id]);
        $sess = $row->fetch(PDO::FETCH_ASSOC);
        
        $duration_ms = null;
        if ($sess) {
            $duration_ms = (int)((time() - strtotime($sess['started_at'])) * 1000);
        }
        
        $pdo->prepare("
            INSERT INTO assokit_activity_log
                (user_id, user_email, user_role, organization_id, event_type, event_action, event_meta, ip, user_agent, session_id, duration_ms)
            VALUES (?, ?, ?, ?, 'logout', 'logout_explicit', ?, ?, ?, ?, ?)
        ")->execute([
            $ctx['user_id'],
            $ctx['user_email'],
            $ctx['user_role'],
            $ctx['organization_id'],
            json_encode(['pageviews' => (int)($sess['pageviews'] ?? 0), 'actions' => (int)($sess['actions_count'] ?? 0)]),
            activity_get_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            $session_id,
            $duration_ms,
        ]);
        
        // Supprimer la session active
        $pdo->prepare("DELETE FROM assokit_active_sessions WHERE session_id = ?")->execute([$session_id]);
    } catch (Throwable $e) {
        error_log('activity_log_logout: ' . $e->getMessage());
    }
}

/**
 * Marque comme inactives les sessions sans activité depuis N minutes (par défaut 30).
 * À appeler depuis un cron, ou en passant par la page d'admin.
 */
function activity_cleanup_inactive_sessions(int $minutes_threshold = 30): int
{
    $pdo = activity_get_pdo();
    if (!$pdo) return 0;
    activity_ensure_table($pdo);
    
    try {
        // Récupérer les sessions inactives
        $stmt = $pdo->prepare("
            SELECT session_id, user_id, user_email, organization_id, started_at, last_activity_at, pageviews, actions_count
            FROM assokit_active_sessions
            WHERE last_activity_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$minutes_threshold]);
        $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($stale as $sess) {
            $duration_ms = (int)((strtotime($sess['last_activity_at']) - strtotime($sess['started_at'])) * 1000);
            $pdo->prepare("
                INSERT INTO assokit_activity_log
                    (user_id, user_email, organization_id, event_type, event_action, event_meta, session_id, duration_ms, created_at)
                VALUES (?, ?, ?, 'logout', 'logout_inactivity', ?, ?, ?, ?)
            ")->execute([
                $sess['user_id'],
                $sess['user_email'],
                $sess['organization_id'],
                json_encode([
                    'pageviews' => (int)$sess['pageviews'],
                    'actions'   => (int)$sess['actions_count'],
                    'inactive_minutes' => $minutes_threshold,
                ]),
                $sess['session_id'],
                $duration_ms,
                $sess['last_activity_at'],
            ]);
            $count++;
        }
        
        if ($count > 0) {
            $pdo->prepare("DELETE FROM assokit_active_sessions WHERE last_activity_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)")
                ->execute([$minutes_threshold]);
        }
        
        return $count;
    } catch (Throwable $e) {
        error_log('activity_cleanup_inactive_sessions: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Récupère l'IP réelle (en tenant compte des proxies CloudFlare/O2switch)
 */
function activity_get_ip(): string
{
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

}
