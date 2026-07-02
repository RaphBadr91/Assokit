<?php
/**
 * ============================================================
 * ASSOKIT — impersonation-helpers.php
 * Fonctions pour gérer l'incarnation admin (Pack #4)
 * ============================================================
 * À déposer à la RACINE du site (public_html/)
 * Inclus automatiquement par config.php (voir patch Étape 4)
 * ============================================================
 */

if (!defined('IMPERSONATION_MAX_DURATION')) {
    // Session d'incarnation auto-terminée après 1h (sécurité)
    define('IMPERSONATION_MAX_DURATION', 3600);
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
    $stmt = $pdo->prepare("SELECT id, is_active, deleted_at, is_founder FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['is_active'] !== 1 || !empty($target['deleted_at'])) {
        return ['success' => false, 'error' => 'Utilisateur cible introuvable ou inactif.', 'session_id' => null];
    }

    // Validation 5 : pas d'incarnation déjà active (anti-chaînage)
    if (is_impersonating()) {
        return ['success' => false, 'error' => 'Une incarnation est déjà active. Arrêtez-la d\'abord.', 'session_id' => null];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO impersonation_sessions
                (admin_user_id, target_user_id, reason, started_at, ip_address, user_agent, created_at)
            VALUES
                (:admin, :target, :reason, NOW(), :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':admin'  => $adminUserId,
            ':target' => $targetUserId,
            ':reason' => $reason,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        $sessionId = (int) $pdo->lastInsertId();

        $pdo->commit();

        // Pose les variables de session
        $_SESSION['impersonation_session_id']       = $sessionId;
        $_SESSION['impersonation_admin_user_id']    = $adminUserId;
        $_SESSION['impersonation_target_user_id']   = $targetUserId;
        $_SESSION['impersonation_started_at']       = time();

        // On change aussi le user_id principal pour que current_user() retourne le user incarné
        $_SESSION['_real_user_id_backup'] = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $targetUserId;

        // Log immédiat
        log_impersonation_action('other', 'Démarrage de session', [
            'reason' => $reason,
            'target_user_id' => $targetUserId,
        ]);

        return ['success' => true, 'error' => null, 'session_id' => $sessionId];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
            $stmt = $pdo->prepare("
                UPDATE impersonation_sessions
                SET ended_at = NOW(),
                    auto_ended = :auto
                WHERE id = :id AND ended_at IS NULL
            ");
            $stmt->execute([
                ':id' => $sessionId,
                ':auto' => $autoEnded ? 1 : 0,
            ]);

            if (!$autoEnded) {
                log_impersonation_action('other', 'Fin de session (manuelle)');
            } else {
                log_impersonation_action('other', 'Fin de session (timeout auto 1h)');
            }
        } catch (Throwable $e) {
            error_log('[IMPERSONATION] stop error: ' . $e->getMessage());
        }
    }

    // Restaure l'user_id réel
    if (!empty($_SESSION['_real_user_id_backup'])) {
        $_SESSION['user_id'] = $_SESSION['_real_user_id_backup'];
    }

    // Nettoie la session
    unset(
        $_SESSION['impersonation_session_id'],
        $_SESSION['impersonation_admin_user_id'],
        $_SESSION['impersonation_target_user_id'],
        $_SESSION['impersonation_started_at'],
        $_SESSION['_real_user_id_backup']
    );

    // Efface aussi le cache de current_user() s'il existe (fonction de config.php)
    if (function_exists('current_user')) {
        // L'appel à current_user() avec $cached static ne peut pas être vidé facilement.
        // On laisse PHP recalculer à la requête suivante (on redirige juste après stop).
    }
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

        $stmt = $pdo->prepare("
            INSERT INTO impersonation_actions_log
                (session_id, admin_user_id, target_user_id, action_type, method, url, description, details, ip_address, created_at)
            VALUES
                (:sid, :aid, :tid, :at, :method, :url, :desc, :details, :ip, NOW())
        ");
        $stmt->execute([
            ':sid'    => $sessionId,
            ':aid'    => $adminId,
            ':tid'    => $targetId,
            ':at'     => $actionType,
            ':method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            ':url'    => mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            ':desc'   => $description ? mb_substr($description, 0, 500) : null,
            ':details' => !empty($safeDetails) ? json_encode($safeDetails, JSON_UNESCAPED_UNICODE) : null,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        // Incrémente le compteur cache dans impersonation_sessions
        $pdo->prepare("UPDATE impersonation_sessions SET actions_count = actions_count + 1 WHERE id = :id")
            ->execute([':id' => $sessionId]);

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

// ============================================================
// MIDDLEWARE — À appeler en début de chaque requête
// ============================================================

/**
 * Appelé automatiquement par config.php (voir patch).
 * Vérifie le timeout et log la page vue si incarnation active.
 */
function impersonation_middleware(): void
{
    if (!is_impersonating()) return;

    // Vérif timeout
    if (!check_impersonation_timeout()) {
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
