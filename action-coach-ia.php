<?php
require_once __DIR__ . '/config.php';
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

if ($action === 'generate_now') {
    if (!function_exists('ask_claude')) { die('IA non configurée.'); }
    try {
        $ctx = coach_build_context($pdo, $org_id);
        $prompt = coach_build_prompt($ctx);
        $resp = ask_claude(
            "Tu es AssoCoach, un coach IA hebdomadaire pour les responsables associatifs. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans texte autour.",
            [['role' => 'user', 'content' => $prompt]],
            1500
        );
        $raw = ($resp && !empty($resp['success'])) ? ($resp['content'] ?? '') : '';
        if (!$raw) { die('Pas de réponse IA.'); }
        $parsed = coach_parse_response($raw);
        if (!$parsed) { die('Réponse IA invalide.'); }
        $report_id = coach_save_report($pdo, $org_id, $ctx, $parsed, $raw, (int)$user['id']);
        header('Location: /coach-ia?id=' . $report_id);
        exit;
    } catch (Throwable $e) {
        die('Erreur : ' . htmlspecialchars($e->getMessage()));
    }
}

http_response_code(400); die('Action inconnue.');
