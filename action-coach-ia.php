<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-coach-ia.php';
@require_once __DIR__ . '/ai-helper.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400); die('Bad request.');
}
$user = current_user();
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin requis.'); }

$action = $_POST['action'] ?? '';
$org_id = (int)$user['org_id'];

// Échec : retour sur /coach-ia avec un bandeau lisible (et non une page
// blanche sans menu). Le détail technique part dans le journal serveur.
$coach_fail = function (string $message, string $detail = '') use ($org_id) {
    if ($detail !== '') error_log('[coach-ia] org ' . $org_id . ' : ' . $detail);
    $_SESSION['flash_coach'] = $message;
    header('Location: /coach-ia');
    exit;
};

if ($action === 'generate_now') {
    // Accès limité (formule Démarrage) : le coach est coupé
    require_once __DIR__ . '/plan-controls.php';
    if (akc_readonly($pdo, $org_id)) $coach_fail(akc_readonly_message() . ' Le coach hebdomadaire n’est pas disponible.');
    if (!function_exists('ask_claude') || (function_exists('is_ai_enabled') && !is_ai_enabled())) {
        $coach_fail('L’IA n’est pas configurée sur ce serveur : le rapport ne peut pas être généré pour le moment.');
    }
    // Rapport demandé à la main : 1 crédit IA (chemin « coach-manuel »), contrôlé AVANT l'appel.
    // Seul le coach hebdomadaire (cron-coach-ia.php) est gratuit. Fréquence bornée comme dans
    // l'appli (api/app-coach-generate.php) : 20 rapports par heure et par association.
    @require_once __DIR__ . '/plan-helpers.php';
    if (function_exists('ak_ai_quota_block')) {
        $why = ak_ai_quota_block($pdo, $org_id, 1);
        if ($why !== null) $coach_fail($why . ' Le rapport hebdomadaire du lundi reste envoyé, sans crédit.');
    }
    @require_once __DIR__ . '/rate-limit-helper.php';
    if (function_exists('ak_rate_limit') && (!ak_rate_limit('coach_now', 5, 60, (string)(int)$user['id']) || !ak_rate_limit('coach_now_org', 20, 3600, 'org' . $org_id))) {
        $coach_fail('Trop de rapports demandés en peu de temps. Réessayez dans un moment.');
    }
    try {
        $ctx = coach_build_context($pdo, $org_id);
        $prompt = coach_build_prompt($ctx);
        $resp = ask_claude(
            "Tu es AssoCoach, un coach IA hebdomadaire pour les responsables associatifs. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans texte autour.",
            [['role' => 'user', 'content' => $prompt]],
            1500,
            // rapport demandé à la main : modèle rapide (ai-models.php) ; registre IA « coach-manuel », 1 crédit
            ['purpose' => 'fast', 'path' => 'coach-manuel', 'org_id' => $org_id, 'user_id' => (int)$user['id'], 'credits' => 1]
        );
        $raw = ($resp && !empty($resp['success'])) ? ($resp['content'] ?? '') : '';
        if (!$raw) {
            $coach_fail('Le service IA est momentanément indisponible (surchargé ou injoignable). Réessayez dans quelques minutes.',
                (string)($resp['error'] ?? 'réponse vide'));
        }
        $parsed = coach_parse_response($raw);
        if (!$parsed) {
            $coach_fail('La réponse de l’IA était illisible. Relancez la génération : un nouvel essai suffit en général.',
                'JSON invalide : ' . mb_substr($raw, 0, 200));
        }
        $report_id = coach_save_report($pdo, $org_id, $ctx, $parsed, $raw, (int)$user['id']);
        header('Location: /coach-ia?id=' . $report_id);
        exit;
    } catch (Throwable $e) {
        $coach_fail('Le rapport n’a pas pu être enregistré à cause d’une erreur technique. Réessayez dans quelques minutes.', $e->getMessage());
    }
}

http_response_code(400); die('Action inconnue.');
