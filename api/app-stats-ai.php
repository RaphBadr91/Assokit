<?php
/**
 * api/app-stats-ai.php — Cockpit IA : analyse + insights + recommandations pour l'ecran Stats.
 * Reutilise les helpers stats + ask_claude(). JSON, scope org, admin. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
@require_once __DIR__ . '/../asso-stats-helpers.php';
@require_once __DIR__ . '/../ai-helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

function e2($cents) { return number_format($cents / 100, 0, ',', ' ') . ' €'; }

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'founder', 'super_admin'], true) && empty($user['is_founder'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    @require_once __DIR__ . '/../rate-limit-helper.php';
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_ai', 10, 60, (string) ($_SESSION['user_id'] ?? ''));

    $k = function_exists('ak_stats_global_kpis') ? ak_stats_global_kpis($pdo, $org_id) : [];
    $top = function_exists('ak_stats_top_clients') ? ak_stats_top_clients($pdo, $org_id, 5) : [];
    $monthly = function_exists('ak_stats_revenue_monthly') ? ak_stats_revenue_monthly($pdo, $org_id, 6) : ['labels' => [], 'paid' => []];

    if (!function_exists('ask_claude')) {
        echo json_encode(['ok' => true, 'allowed' => true, 'cockpit' => null, 'message' => 'IA indisponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Contexte chiffre pour l'IA
    $topLines = '';
    foreach ((array) $top as $c) {
        if (empty($c['nb_invoices'])) continue;
        $topLines .= "  · " . ($c['display_name'] ?? '') . " : " . e2((int) ($c['total_paid_cents'] ?? 0)) . " encaissé, " . e2((int) ($c['total_pending_cents'] ?? 0)) . " en attente\n";
    }
    $trend = '';
    $labels = $monthly['labels'] ?? []; $paid = $monthly['paid'] ?? [];
    foreach ($labels as $i => $lab) { $trend .= $lab . '=' . round((float) ($paid[$i] ?? 0)) . '€ '; }

    $ctx = "Données de facturation (année " . (int) ($k['year'] ?? date('Y')) . ") :\n"
        . "- CA encaissé : " . e2((int) ($k['revenue_paid_cents'] ?? 0)) . "\n"
        . "- En attente : " . e2((int) ($k['revenue_pending_cents'] ?? 0)) . "\n"
        . "- Impayés/retard : " . e2((int) ($k['revenue_overdue_cents'] ?? 0)) . " (" . (int) ($k['nb_overdue'] ?? 0) . " factures)\n"
        . "- Factures : " . (int) ($k['total_invoices'] ?? 0) . " (dont " . (int) ($k['nb_paid'] ?? 0) . " payées)\n"
        . "- Délai moyen de paiement : " . round((float) ($k['avg_payment_days'] ?? 0)) . " jours\n"
        . "- Devis : " . (int) ($k['total_quotes'] ?? 0) . ", taux de conversion : " . round((float) ($k['conversion_rate'] ?? 0), 1) . "%\n"
        . "- Tendance encaissé (6 mois) : " . trim($trend) . "\n"
        . ($topLines ? ("- Principaux clients :\n" . $topLines) : '');

    $prompt = $ctx . "\nTu es un directeur financier virtuel. Analyse ces données et réponds UNIQUEMENT en JSON valide (sans markdown) avec exactement cette forme :\n"
        . '{"health": "un mot: Solide|Correct|Fragile", "summary": "2-3 phrases d\'analyse de la santé financière", '
        . '"insights": ["constat chiffré 1", "constat 2", "constat 3"], '
        . '"actions": [{"icon": "💡", "title": "action courte", "why": "bénéfice concret"}, {"icon":"📈","title":"...","why":"..."}, {"icon":"⏱️","title":"...","why":"..."}]}';

    $resp = ask_claude(
        "Tu es AssoFinance, un directeur financier IA pour associations et TPE. Concis, chiffré, orienté action. Tu réponds UNIQUEMENT en JSON valide sans texte autour.",
        [['role' => 'user', 'content' => $prompt]],
        900
    );
    $raw = ($resp && !empty($resp['success'])) ? (string) ($resp['content'] ?? '') : '';
    $raw = trim(preg_replace('/^```(json)?|```$/m', '', $raw));
    $parsed = json_decode($raw, true);

    if (!is_array($parsed)) {
        echo json_encode(['ok' => true, 'allowed' => true, 'cockpit' => ['health' => '', 'summary' => trim($raw) ?: 'Analyse indisponible.', 'insights' => [], 'actions' => []]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cockpit = [
        'health'   => (string) ($parsed['health'] ?? ''),
        'summary'  => (string) ($parsed['summary'] ?? ''),
        'insights' => array_values(array_map('strval', array_slice((array) ($parsed['insights'] ?? []), 0, 4))),
        'actions'  => array_map(fn($x) => [
            'icon'  => (string) ($x['icon'] ?? '💡'),
            'title' => (string) ($x['title'] ?? ''),
            'why'   => (string) ($x['why'] ?? ''),
        ], array_slice((array) ($parsed['actions'] ?? []), 0, 3)),
    ];

    echo json_encode(['ok' => true, 'allowed' => true, 'cockpit' => $cockpit], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-stats-ai] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
