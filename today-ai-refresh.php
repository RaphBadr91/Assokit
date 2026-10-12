<?php
/**
 * ============================================================
 * ASSOKIT — Endpoint refresh manuel des suggestions Today AI
 * ============================================================
 * URL : /today-ai-refresh (POST, AJAX)
 *
 * - sans paramètre : rafraîchissement manuel (bouton ↻), compté dans la
 *   limite de 3 / jour / user (TODAY_AI_MAX_REFRESH_PER_DAY) ;
 * - auto=1 : génération du jour lancée par le tableau de bord juste après
 *   l'affichage (cache servi s'il existe, non compté dans la limite).
 * La réponse contient aussi le HTML des cartes ('html') pour un remplacement
 * sans rechargement.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/today-ai-helper.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// CSRF check
if (function_exists('check_csrf')) {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
        exit;
    }
}

$user = current_user();

// Suiveur (financeur externe) : pas de tableau de bord ni de suggestions sur l'association
if (($user['role'] ?? '') === 'follower') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// L'appel IA peut durer : on libère la session pour ne pas bloquer les autres pages
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$auto = !empty($_POST['auto']);

try {
    $result = today_get_or_generate($pdo, $user, !$auto);

    echo json_encode([
        'ok' => true,
        'suggestions' => $result['suggestions'],
        'refresh_count' => $result['refresh_count'] ?? 0,
        'can_refresh' => $result['can_refresh'] ?? false,
        'rate_limited' => $result['rate_limited'] ?? false,
        'has_error' => $result['has_error'] ?? false,
        'kept_previous' => $result['kept_previous'] ?? false,
        'html' => today_render_items($result['suggestions'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('today-ai-refresh error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
