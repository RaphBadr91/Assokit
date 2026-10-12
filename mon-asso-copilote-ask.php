<?php
/**
 * mon-asso-copilote-ask.php
 * --------------------------------------------------------------
 * Endpoint JSON du Copilote IA. Reçoit {question}, renvoie {answer, table, action}.
 * Sécurité : login + CSRF + rate-limit ; org_id vient EXCLUSIVEMENT de la session.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, mot de passe provisoire
ak_session_guard();
require_once __DIR__ . '/copilote-engine.php';
require_once __DIR__ . '/rate-limit-helper.php';

header('Content-Type: application/json; charset=utf-8');

// Toute erreur : ok=false, un code (error) et un texte affichable (message).
function ak_cop_fail(int $http, string $code, string $message): void {
    http_response_code($http);
    echo json_encode(['ok'=>false, 'error'=>$code, 'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ak_cop_fail(405, 'method', 'Requête invalide.');

require_login();
$user   = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid    = (int)($user['id'] ?? 0);
if ($org_id <= 0) ak_cop_fail(403, 'no_org', 'Accès non autorisé.');

// Le copilote expose des données financières → réservé aux rôles de pilotage
// (règle unique de finance-permissions.php, reprise par la bulle flottante).
require_once __DIR__ . '/finance-permissions.php';
if (!ak_copilote_allowed($user)) ak_cop_fail(403, 'forbidden', 'Accès non autorisé.');

// Corps JSON ou form
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

// CSRF (accepte le champ POST ou l'en-tête X-CSRF-Token pour l'app)
$csrf = (string)($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!check_csrf($csrf)) ak_cop_fail(403, 'csrf', 'Votre session a expiré : rechargez la page puis réessayez.');

// Anti-abus — fail-closed : chaque question déclenche 2 appels LLM payants.
// Réponse au même format que les autres erreurs (ok=false) : la bulle du
// copilote affichait un message vide quand la limite était atteinte.
if (!function_exists('ak_rate_limit')) ak_cop_fail(500, 'rl_unavailable', 'Service momentanément indisponible.');
if (!ak_rate_limit('copilote', 20, 60, (string)$uid)                 // par utilisateur
    || !ak_rate_limit('copilote_org', 120, 60, 'org'.$org_id)) {     // + plafond par organisation
    header('Retry-After: 60');
    ak_cop_fail(429, 'too_many_requests', 'Trop de questions en peu de temps. Patientez une minute avant de réessayer.');
}

$question = trim((string)($body['question'] ?? ''));
if (mb_strlen($question) < 2) ak_cop_fail(200, 'empty', 'Posez une question un peu plus précise.');
$question = mb_substr($question, 0, 500);

$t0 = microtime(true);
try {
    $res = ak_cop_answer($pdo, $org_id, $uid, $question);
} catch (Throwable $e) {
    error_log('[copilote-ask] '.$e->getMessage());
    ak_cop_fail(500, 'internal', 'Service momentanément indisponible. Réessayez dans un instant.');
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
