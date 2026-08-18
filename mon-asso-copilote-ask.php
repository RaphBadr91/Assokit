<?php
/**
 * mon-asso-copilote-ask.php
 * --------------------------------------------------------------
 * Endpoint JSON du Copilote IA. Reçoit {question}, renvoie {answer, table, action}.
 * Sécurité : login + CSRF + rate-limit ; org_id vient EXCLUSIVEMENT de la session.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/copilote-engine.php';
@require_once __DIR__ . '/rate-limit-helper.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$user   = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid    = (int)($user['id'] ?? 0);
if ($org_id <= 0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_org']); exit; }

// Le copilote expose des données financières → réservé aux rôles de pilotage.
$role = strtolower((string)($user['role'] ?? ''));
$is_priv = in_array($role, ['admin','coordinator','founder'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$is_priv) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

// Corps JSON ou form
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

// CSRF (accepte le champ POST ou l'en-tête X-CSRF-Token pour l'app)
$csrf = (string)($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!check_csrf($csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

// Anti-abus (20 questions / minute / utilisateur)
if (function_exists('ak_rate_limit_or_die')) {
    ak_rate_limit_or_die('copilote', 20, 60, (string)$uid);
}

$question = trim((string)($body['question'] ?? ''));
if (mb_strlen($question) < 2) { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
$question = mb_substr($question, 0, 500);

$t0 = microtime(true);
try {
    $res = ak_cop_answer($pdo, $org_id, $uid, $question);
} catch (Throwable $e) {
    error_log('[copilote-ask] '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']); exit;
}

// Audit (best-effort — ne bloque jamais la réponse)
try {
    $st = $pdo->prepare("INSERT INTO copilote_audit_log (org_id, user_id, question, intent, row_count, latency_ms, status, created_at)
                         VALUES (:o,:u,:q,:i,:rc,:ms,:s,NOW())");
    $st->execute([
        ':o'=>$org_id, ':u'=>$uid, ':q'=>mb_substr($question,0,500),
        ':i'=>$res['intent'] ?? null,
        ':rc'=>isset($res['table']['rows']) ? count($res['table']['rows']) : 0,
        ':ms'=>(int)round((microtime(true)-$t0)*1000),
        ':s'=>($res['ok'] ?? false) ? 'ok' : 'ko',
    ]);
} catch (Throwable $e) { /* table absente ? on ignore */ }

echo json_encode(['schema'=>'1'] + $res, JSON_UNESCAPED_UNICODE);
