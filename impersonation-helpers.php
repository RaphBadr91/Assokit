<?php
/**
 * ============================================================
 * ASSOKIT — impersonation-helpers.php
 * Fonctions pour gérer l'incarnation admin (Pack #4)
 * ============================================================
 * À déposer à la RACINE du site (public_html/)
 * Chargé par ak_session_guard() (app-context.php) dès qu'une incarnation
 * est en cours : fin automatique après 1 h et journal des pages vues
 * (impersonation_middleware), puis bannière « Revenir à mon compte »
 * affichée par includes-layout.php (render_impersonation_banner).
 *
 * Écritures adaptées au schéma réel (ak_db_insert / ak_db_update) : les
 * tables impersonation_* de la production ne sont pas versionnées ; voir
 * migrations/2026-10-11-L01-auth.sql pour leur structure complète.
 * ============================================================
 */

require_once __DIR__ . '/trial-helpers.php'; // ak_db_insert(), ak_db_update(), ak_db_columns()

if (!defined('IMPERSONATION_MAX_DURATION')) {
    // Session d'incarnation auto-terminée après 1h (sécurité)
    define('IMPERSONATION_MAX_DURATION', 3600);
}

if (!defined('IMPERSONATION_SESSION_KEYS')) {
    /**
     * Identité de session remplacée par celle de la personne incarnée (association, rôle, menus,
     * droits plateforme…), puis restaurée à la fin. Sans cela, l'incarnation gardait l'association
     * et les droits super-admin du Fondateur avec le compte de la cible.
     */
    define('IMPERSONATION_SESSION_KEYS', [
        'user_id', 'org_id', 'user_email', 'user_name', 'user_role', 'parent_org_id', 'parent_org_role',
        'is_super_admin', 'demo_active', 'demo_org_name', 'demo_org_slug', 'demo_org_plan', 'demo_real_email',
    ]);
}

// ============================================================
// CHECK — Est-ce qu'une incarnation est active ?
// ============================================================

function is_impersonating(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    return !empty($_SESSION['impersonation_session_id']) && !empty($_SESSION['impersonation_target_user_id']);
}

/**
 * Retourne l'ID de la session d'incarnation active, ou null.
 */
function get_impersonation_session_id(): ?int
{
    if (!is_impersonating()) return null;
    return (int) $_SESSION['impersonation_session_id'];
}

/**
 * Retourne l'ID du user incarné, ou null.
 */
function get_impersonated_user_id(): ?int
{
    if (!is_impersonating()) return null;
    return (int) $_SESSION['impersonation_target_user_id'];
}

/**
 * Retourne l'ID de l'admin réel (celui qui a lancé l'incarnation).
 */
function get_real_admin_user_id(): ?int
{
    if (!is_impersonating()) return null;
    return (int) ($_SESSION['impersonation_admin_user_id'] ?? 0);
}

/**
 * Retourne le user réel (le Fondateur), même si on est en incarnation.
 * Utile pour la bannière "Revenir à mon compte Hakim".
 */
function get_real_user(): ?array
{
    global $pdo;
    $realId = get_real_admin_user_id();
    if (!$realId) return null;
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, is_founder FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $realId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ============================================================
// AUTO-EXPIRATION — Vérification timeout 1h
// ============================================================

/**
 * Vérifie si la session d'incarnation dépasse 1h.
 * Si oui, la termine automatiquement.
 * Retourne true si encore valide, false si expirée.
 */
function check_impersonation_timeout(): bool
{
    if (!is_impersonating()) return false;

    $startedAt = (int) ($_SESSION['impersonation_started_at'] ?? 0);
    if ($startedAt === 0) {
        stop_impersonation(true);
        return false;
    }

    if (time() - $startedAt > IMPERSONATION_MAX_DURATION) {
        stop_impersonation(true); // auto-ended
        return false;
    }

    return true;
}

// ============================================================
// START — Démarre une incarnation
// ============================================================

/**
 * Démarre une session d'incarnation.
 *
 * @param int    $adminUserId  ID du Fondateur qui incarne
 * @param int    $targetUserId ID du user à incarner
 * @param string $reason       Raison obligatoire (min 10 car)
 *
 * @return array ['success' => bool, 'error' => ?string, 'session_id' => ?int]
 */
function start_impersonation(int $adminUserId, int $targetUserId, string $reason): array
{
    global $pdo;

    // Validation 1 : raison obligatoire
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        return ['success' => false, 'error' => 'La raison doit faire au moins 10 caractères.', 'session_id' => null];
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    // Validation 2 : pas soi-même
    if ($adminUserId === $targetUserId) {
        return ['success' => false, 'error' => 'Vous ne pouvez pas vous incarner vous-même.', 'session_id' => null];
    }

    // Validation 3 : l'admin doit être Fondateur
    $stmt = $pdo->prepare("SELECT is_founder, is_active, deleted_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $adminUserId]);
    $admin = $stmt->fetch();
    if (!$admin || (int)$admin['is_founder'] !== 1 || (int)$admin['is_active'] !== 1 || !empty($admin['deleted_at'])) {
        return ['success' => false, 'error' => 'Seul un Fondateur actif peut incarner.', 'session_id' => null];
    }

    // Validation 4 : la cible doit exister, être active, non supprimée
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['is_active'] !== 1 || !empty($target['deleted_at'])) {
        return ['success' => false, 'error' => 'Utilisateur cible introuvable ou inactif.', 'session_id' => null];
    }

    // Validation 4bis : on n'incarne JAMAIS un compte privilégié (séparation des rôles / non-répudiation — exigence PDP)
    if ((int)($target['is_founder'] ?? 0) === 1
        || (int)($target['is_super_admin'] ?? 0) === 1
        || in_array((string)($target['role'] ?? ''), ['founder', 'super_admin'], true)) {
        return ['success' => false, 'error' => 'Impossible d\'incarner un compte fondateur ou super-admin.', 'session_id' => null];
    }

    // Validation 5 : pas d'incarnation déjà active (anti-chaînage)
    if (is_impersonating()) {
        return ['success' => false, 'error' => 'Une incarnation est déjà active. Arrêtez-la d\'abord.', 'session_id' => null];
    }

    try {
        $sessionId = ak_db_insert($pdo, 'impersonation_sessions', [
            'admin_user_id'  => $adminUserId,
            'target_user_id' => $targetUserId,
            'reason'         => $reason,
            'ip_address'     => impersonation_client_ip(),
            'user_agent'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'actions_count'  => 0,
        ], ['started_at' => 'NOW()', 'created_at' => 'NOW()']);

        // Pose les variables de session
        $_SESSION['impersonation_session_id']       = $sessionId;
        $_SESSION['impersonation_admin_user_id']    = $adminUserId;
        $_SESSION['impersonation_target_user_id']   = $targetUserId;
        $_SESSION['impersonation_started_at']       = time();

        // Identité de session : celle du Fondateur est mise de côté, celle de la cible prend sa
        // place (current_user(), l'association, le rôle et les menus deviennent ceux de la cible)
        $backup = [];
        foreach (IMPERSONATION_SESSION_KEYS as $k) {
            if (array_key_exists($k, $_SESSION)) $backup[$k] = $_SESSION[$k];
            unset($_SESSION[$k]);
        }
        $_SESSION['_real_session_backup'] = $backup;
        $_SESSION['_real_user_id_backup'] = $backup['user_id'] ?? null;
        $_SESSION['user_id']         = $targetUserId;
        $_SESSION['org_id']          = (int)($target['org_id'] ?? 0);
        $_SESSION['user_email']      = (string)($target['email'] ?? '');
        $_SESSION['user_name']       = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
        $_SESSION['user_role']       = (string)($target['role'] ?? '');
        $_SESSION['parent_org_id']   = $target['parent_org_id'] ?? null;
        $_SESSION['parent_org_role'] = $target['parent_org_role'] ?? null;
        $_SESSION['is_super_admin']  = 0;   // jamais de droits plateforme sous l'identité d'un client

        // Log immédiat
        log_impersonation_action('other', 'Démarrage de session', [
            'reason' => $reason,
            'target_user_id' => $targetUserId,
        ]);

        return ['success' => true, 'error' => null, 'session_id' => $sessionId];

    } catch (Throwable $e) {
        error_log('[IMPERSONATION] start error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur technique : ' . $e->getMessage(), 'session_id' => null];
    }
}

// ============================================================
// STOP — Arrête une incarnation
// ============================================================

/**
 * Arrête l'incarnation en cours.
 *
 * @param bool $autoEnded True si arrêt automatique (timeout)
 */
function stop_impersonation(bool $autoEnded = false): void
{
    global $pdo;

    $sessionId = get_impersonation_session_id();
    if ($sessionId) {
        try {
            ak_db_update($pdo, 'impersonation_sessions', $sessionId, ['auto_ended' => $autoEnded ? 1 : 0], ['ended_at' => 'NOW()']);

            if (!$autoEnded) {
                log_impersonation_action('other', 'Fin de session (manuelle)');
            } else {
                log_impersonation_action('other', 'Fin de session (timeout auto 1h)');
            }
        } catch (Throwable $e) {
            error_log('[IMPERSONATION] stop error: ' . $e->getMessage());
        }
    }

    // Restaure l'identité de session du Fondateur (association, rôle, droits plateforme…)
    $backup = $_SESSION['_real_session_backup'] ?? null;
    if (is_array($backup)) {
        foreach (IMPERSONATION_SESSION_KEYS as $k) {
            if (array_key_exists($k, $backup)) $_SESSION[$k] = $backup[$k];
            else unset($_SESSION[$k]);
        }
    } elseif (!empty($_SESSION['_real_user_id_backup'])) {
        // Incarnation ouverte avant la sauvegarde complète : seul l'identifiant avait été remplacé
        $_SESSION['user_id'] = $_SESSION['_real_user_id_backup'];
    }

    // Nettoie la session
    unset(
        $_SESSION['impersonation_session_id'],
        $_SESSION['impersonation_admin_user_id'],
        $_SESSION['impersonation_target_user_id'],
        $_SESSION['impersonation_started_at'],
        $_SESSION['_real_user_id_backup'],
        $_SESSION['_real_session_backup']
    );
    // current_user() garde la personne incarnée en cache pour cette requête : l'appelant redirige
    // juste après, la requête suivante relit le compte du Fondateur.
}

// ============================================================
// LOG — Enregistre une action effectuée en incarnation
// ============================================================

/**
 * Log une action effectuée en mode incarnation.
 * Appelée automatiquement par le middleware (voir config.php patch).
 */
function log_impersonation_action(string $actionType = 'page_view', ?string $description = null, array $details = []): void
{
    if (!is_impersonating()) return;

    global $pdo;

    try {
        $sessionId = get_impersonation_session_id();
        $adminId   = get_real_admin_user_id();
        $targetId  = get_impersonated_user_id();

        // Anonymise les données sensibles des POST
        $safeDetails = anonymize_sensitive_data($details);

        ak_db_insert($pdo, 'impersonation_actions_log', [
            'session_id'     => $sessionId,
            'admin_user_id'  => $adminId,
            'target_user_id' => $targetId,
            'action_type'    => $actionType,
            'method'         => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'url'            => mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            'description'    => $description ? mb_substr($description, 0, 500) : null,
            'details'        => !empty($safeDetails) ? json_encode($safeDetails, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'     => impersonation_client_ip(),
        ], ['created_at' => 'NOW()']);

        // Incrémente le compteur cache dans impersonation_sessions
        if (isset(ak_db_columns($pdo, 'impersonation_sessions')['actions_count'])) {
            $pdo->prepare("UPDATE impersonation_sessions SET actions_count = COALESCE(actions_count, 0) + 1 WHERE id = :id")
                ->execute([':id' => $sessionId]);
        }

    } catch (Throwable $e) {
        error_log('[IMPERSONATION] log error: ' . $e->getMessage());
    }
}

/**
 * Retire les données sensibles (mots de passe, tokens) des logs.
 */
function anonymize_sensitive_data(array $data): array
{
    $sensitive = ['password', 'password_hash', 'pwd', 'token', 'csrf_token', 'csrf', 'secret', 'api_key'];
    $clean = [];
    foreach ($data as $k => $v) {
        $lk = strtolower((string)$k);
        $isSensitive = false;
        foreach ($sensitive as $s) {
            if (strpos($lk, $s) !== false) { $isSensitive = true; break; }
        }
        if ($isSensitive) {
            $clean[$k] = '[MASQUÉ]';
        } elseif (is_array($v)) {
            $clean[$k] = anonymize_sensitive_data($v);
        } else {
            $clean[$k] = $v;
        }
    }
    return $clean;
}

/** Adresse du visiteur (derrière Cloudflare / le proxy de l'hébergeur), comme le journal d'activité. */
function impersonation_client_ip(): string
{
    if (function_exists('activity_get_ip')) return (string)activity_get_ip();
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Bannière orange « Revenir à mon compte », à afficher juste après <body> sur chaque page
 * (includes-layout.php render_head). Ne fait rien hors incarnation.
 */
function render_impersonation_banner(): void
{
    if (!is_impersonating()) return;
    include __DIR__ . '/impersonation-banner.php';
}

// ============================================================
// MIDDLEWARE — À appeler en début de chaque requête
// ============================================================

/**
 * Appelé par ak_session_guard() (app-context.php) en tête de chaque page et de chaque API.
 * Vérifie le timeout et log la page vue si incarnation active.
 */
function impersonation_middleware(): void
{
    static $done = false; // une seule fois par requête (si un config.php l'appelle aussi : pas de double journal)
    if ($done || !is_impersonating()) return;
    $done = true;

    // Vérif timeout
    if (!check_impersonation_timeout()) {
        // Session expirée : le Fondateur a retrouvé son compte. Une API ne doit pas continuer
        // sous son identité ce qui avait été lancé dans l'espace de la cible.
        $script = ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if (strpos($script, 'api/') === 0 || strpos($script, 'api-') === 0
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            if (!headers_sent()) {
                http_response_code(409);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => 'impersonation_expired',
                'message' => 'Incarnation terminée (1 h) : vous êtes revenu sur votre compte.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Session expirée → redirection vers le dashboard SA
        header('Location: /super-admin?impersonation=expired');
        exit;
    }

    // Log automatique de la page vue (sauf pour les endpoints de contrôle)
    $url = $_SERVER['REQUEST_URI'] ?? '';
    $skipLog = [
        '/super-admin/incarner-stop',
        '/ajax/',
        '/api/',
        '.css', '.js', '.woff', '.svg', '.png', '.jpg', '.ico',
    ];
    foreach ($skipLog as $skip) {
        if (strpos($url, $skip) !== false) return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actionType = 'page_view';
    $details = [];

    if ($method === 'POST') {
        $actionType = 'form_post';
        // Détecte si c'est une action destructive
        $destructivePatterns = ['supprimer', 'delete', 'archiver', 'purger'];
        foreach ($destructivePatterns as $p) {
            if (stripos($url, $p) !== false) { $actionType = 'destructive'; break; }
        }
        $details = $_POST;
    }

    log_impersonation_action($actionType, null, $details);
}
