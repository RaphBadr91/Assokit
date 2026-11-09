<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail partagée : attribution des conversations
 * ============================================================
 * Formule Équipe (feature_shared_inbox). POST JSON, jeton CSRF obligatoire.
 *   { thread_id, to: "me" }        m'attribuer la conversation
 *   { thread_id, to: <id membre> } l'attribuer à un gestionnaire de la même association
 *                                  (administrateur, coordinateur, référent)
 *   { thread_id, to: "none" }      retirer l'attribution
 * Écrit mail_threads.assigned_user_id, assigned_at, assigned_by ; la personne désignée
 * reçoit une notification interne (notification-helpers.php).
 * Réponse : { ok, assigned_user_id, assigned_name } ou { ok:false, error, message }.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, accès limité
ak_session_guard();
require_once __DIR__ . '/mail-helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $d, int $code = 200) {
    if (empty($d['ok']) && isset($d['error']) && !isset($d['message'])) $d['message'] = $d['error'];
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['ok' => false, 'error' => 'POST requis'], 405);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!check_csrf((string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) $out(['ok' => false, 'error' => 'Session expirée, rechargez la page.'], 403);

$user = current_user();
if (!mail_can_access($user)) $out(['ok' => false, 'error' => 'Accès réservé.'], 403);
if (!mail_schema_ready($pdo)) $out(['ok' => false, 'error' => 'Migration boîte mail non appliquée.'], 503);
$org = (int)($user['org_id'] ?? 0);
if ($org <= 0) $out(['ok' => false, 'error' => 'Accès réservé.'], 403);

// Formule : boîte mail, puis boîte partagée (Équipe et au-delà)
if (!mail_plan_inbox_ok($pdo, $org)) $out(['ok' => false, 'error' => mail_plan_message($pdo, $org, 'mail_inbox'), 'plan_feature' => true], 403);
if (!mail_assign_ready($pdo)) $out(['ok' => false, 'error' => 'L’attribution des conversations n’est pas encore activée (migration à appliquer).'], 503);
if (!mail_plan_shared_ok($pdo, $org)) $out(['ok' => false, 'error' => mail_plan_message($pdo, $org, 'shared_inbox'), 'plan_feature' => true], 403);

$t = mail_thread_for_user($pdo, $user, (int)($in['thread_id'] ?? 0));
if (!$t) $out(['ok' => false, 'error' => 'Conversation introuvable.'], 404);

$to_raw = (string)($in['to'] ?? '');
if ($to_raw === 'me') {
    $to = (int)$user['id'];
    // Le compte doit être un gestionnaire de l'association (pas un compte plateforme de passage)
    if (!isset(mail_assignable_users($pdo, $org)[$to])) $out(['ok' => false, 'error' => 'Seuls les gestionnaires de la structure peuvent se voir attribuer une conversation.'], 403);
} elseif ($to_raw === 'none' || $to_raw === '' || $to_raw === '0') {
    $to = null;
} elseif (ctype_digit($to_raw)) {
    $to = (int)$to_raw;
} else {
    $out(['ok' => false, 'error' => 'Demande invalide.'], 400);
}

$r = mail_assign($pdo, $user, $t, $to);
if (empty($r['ok'])) $out($r, 400);

// Journal d'activité (assokit_activity_log, repris par journal-equipe.php) : jamais bloquant
if (is_file(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
    if (function_exists('activity_log_action')) {
        activity_log_action($to === null ? 'boite-mail-attribuer:retirer' : 'boite-mail-attribuer:attribuer',
            ['assigned_user_id' => $to], 'thread_id=' . (int)$t['id']);
    }
}

$out($r);
