<?php
/**
 * api/app-coach-generate.php — Genere un rapport Coach IA depuis l'app (natif).
 * Reproduit action-coach-ia.php (generate_now), retour JSON. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../includes-coach-ia.php';
@require_once __DIR__ . '/../ai-helper.php';

if (($user['role'] ?? '') !== 'admin' && empty($user['is_founder'])) {
    app_fail(403, 'role', 'Réservé aux administrateurs.');
}
if (!function_exists('ask_claude') || !function_exists('coach_build_context')) {
    app_fail(503, 'unavailable', 'IA non configurée.');
}

try {
    $ctx = coach_build_context($pdo, $org_id);
    $prompt = coach_build_prompt($ctx);
    $resp = ask_claude(
        "Tu es AssoCoach, un coach IA hebdomadaire pour les responsables associatifs. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans texte autour.",
        [['role' => 'user', 'content' => $prompt]],
        1500
    );
    $raw = ($resp && !empty($resp['success'])) ? ($resp['content'] ?? '') : '';
    if (!$raw) app_fail(502, 'ai', 'Pas de réponse de l\'IA.');
    $parsed = coach_parse_response($raw);
    if (!$parsed) app_fail(502, 'ai', 'Réponse IA invalide.');
    coach_save_report($pdo, $org_id, $ctx, $parsed, $raw, $uid);

    $report = [
        'summary'    => (string) ($parsed['summary'] ?? ''),
        'highlights' => array_values(array_map('strval', (array) ($parsed['highlights'] ?? []))),
        'warnings'   => array_values(array_map('strval', (array) ($parsed['warnings'] ?? []))),
        'recos'      => array_map(fn($x) => [
            'icon'  => (string) ($x['icon'] ?? '🎯'),
            'title' => (string) ($x['title'] ?? ''),
            'why'   => (string) ($x['why'] ?? ''),
        ], array_slice((array) ($parsed['recos'] ?? []), 0, 3)),
        'week'       => '',
    ];
    echo json_encode(['ok' => true, 'report' => $report, 'message' => 'Rapport généré.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-coach-generate] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de générer le rapport.');
}
