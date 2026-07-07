<?php
/**
 * api/_app-write-boot.php — Bootstrap commun aux endpoints d'ECRITURE de l'app.
 * Auth session + lecture du corps JSON + verification CSRF.
 * Expose : $pdo, $user, $org_id, $input (array), $uid (int).
 * NE MODIFIE PAS le site : dedie a l'application mobile.
 */
if (!defined('AK_APP_WRITE')) define('AK_APP_WRITE', 1);

ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function app_fail(int $code, string $err, ?string $msg = null): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) app_fail(401, 'auth');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_fail(405, 'method');

$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];

// CSRF : jeton de session (identique au site)
$token = (string) ($input['csrf'] ?? ($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
    app_fail(403, 'csrf', 'Session expirée, merci de réessayer.');
}

$user   = function_exists('current_user') ? current_user() : null;
$org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
$uid    = (int) ($user['id'] ?? ($_SESSION['user_id'] ?? 0));
if ($org_id <= 0) app_fail(400, 'org');
