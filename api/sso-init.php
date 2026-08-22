<?php
/**
 * api/sso-init.php — Étape 1 du SSO WordPress.
 * Reçoit la clé SSO longue durée (POST), renvoie un jeton à USAGE UNIQUE
 * (courte durée) que le plugin utilisera pour ouvrir Assokit connecté.
 * Aucune session requise ici (appel serveur-à-serveur depuis WordPress).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rate-limit-helper.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

// Anti-brute-force sur la clé.
if (function_exists('ak_rate_limit_or_die')) {
    ak_rate_limit_or_die('sso_init', 20, 60, $_SERVER['REMOTE_ADDR'] ?? '0');
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$key = trim((string)($body['key'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/i', $key)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_key']); exit; }

$key_hash = hash('sha256', strtolower($key));
try {
    $st = $pdo->prepare("SELECT id, user_id FROM sso_keys WHERE key_hash = ? AND revoked = 0 LIMIT 1");
    $st->execute([$key_hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }

    // Jeton à usage unique (TTL 90 s).
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO sso_tokens (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 90 SECOND))")
        ->execute([(int)$row['user_id'], hash('sha256', $token)]);
    $pdo->prepare("UPDATE sso_keys SET last_used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);

    // Purge best-effort des jetons expirés.
    try { $pdo->query("DELETE FROM sso_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY"); } catch (Throwable $e) {}

    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr');
    echo json_encode(['ok'=>true, 'token'=>$token, 'url'=>$base.'/sso-consume.php?t='.$token]);
} catch (Throwable $e) {
    error_log('[sso-init] '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']);
}
