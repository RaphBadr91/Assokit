<?php
/**
 * ============================================================
 * ASSOKIT — Endpoint refresh manuel des suggestions Today AI
 * ============================================================
 * URL : /today-ai-refresh (POST, AJAX)
 *
 * Rate-limit : max 3 refresh / jour / user (TODAY_AI_MAX_REFRESH_PER_DAY)
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

try {
    $result = today_get_or_generate($pdo, $user, true);

    echo json_encode([
        'ok' => true,
        'suggestions' => $result['suggestions'],
        'refresh_count' => $result['refresh_count'] ?? 0,
        'can_refresh' => $result['can_refresh'] ?? false,
        'rate_limited' => $result['rate_limited'] ?? false,
        'has_error' => $result['has_error'] ?? false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('today-ai-refresh error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
