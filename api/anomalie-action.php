<?php
/**
 * api/anomalie-action.php
 * ------------------------------------------------------------------
 * Endpoint JSON de la détection d'anomalies.
 * Actions : dismiss | restore
 * Sécurité : login + can('manage_finances') + CSRF ; org_id = session.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

require_login();
$user   = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid    = (int)($user['id'] ?? 0);
if ($org_id <= 0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_org']); exit; }
if (!function_exists('can') || !can('manage_finances')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$csrf = (string)($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!check_csrf($csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

$action = (string)($body['action'] ?? '');
$hash = (string)($body['hash'] ?? '');
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) { echo json_encode(['ok'=>false,'error'=>'bad_hash']); exit; }
$cat = mb_substr((string)($body['category'] ?? ''), 0, 48);

try {
    if ($action === 'dismiss') {
        $st = $pdo->prepare("INSERT IGNORE INTO anomaly_dismissed (org_id, finding_hash, category, dismissed_by) VALUES (?,?,?,?)");
        $st->execute([$org_id, $hash, $cat, $uid]);
        echo json_encode(['ok'=>true]);
    } elseif ($action === 'restore') {
        $pdo->prepare("DELETE FROM anomaly_dismissed WHERE org_id = ? AND finding_hash = ?")->execute([$org_id, $hash]);
        echo json_encode(['ok'=>true]);
    } else {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('[anomalie-action] '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']);
}
