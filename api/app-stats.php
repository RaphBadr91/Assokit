<?php
/**
 * api/app-stats.php — Statistiques (KPIs facturation) pour l'ecran natif.
 * Reutilise ak_stats_global_kpis / ak_stats_top_clients / ak_stats_revenue_monthly.
 * Gate admin (comme le site). JSON, scope org. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
@require_once __DIR__ . '/../asso-stats-helpers.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    $is_admin = in_array($role, ['admin', 'founder', 'super_admin'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);

    if (!$is_admin) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $k = function_exists('ak_stats_global_kpis') ? ak_stats_global_kpis($pdo, $org_id) : [];
    $clients = function_exists('ak_stats_top_clients') ? ak_stats_top_clients($pdo, $org_id, 8) : [];
    $monthly = function_exists('ak_stats_revenue_monthly') ? ak_stats_revenue_monthly($pdo, $org_id, 6) : ['labels' => [], 'paid' => []];

    $top = [];
    foreach ((array) $clients as $c) {
        if (empty($c['nb_invoices'])) continue;
        $top[] = [
            'name'    => (string) ($c['display_name'] ?? ''),
            'paid'    => ((int) ($c['total_paid_cents'] ?? 0)) / 100,
            'pending' => ((int) ($c['total_pending_cents'] ?? 0)) / 100,
            'nb'      => (int) ($c['nb_invoices'] ?? 0),
        ];
    }

    $months = [];
    $labels = $monthly['labels'] ?? [];
    $paid = $monthly['paid'] ?? [];
    $maxv = 0;
    foreach ($paid as $v) { if ((float) $v > $maxv) $maxv = (float) $v; }
    foreach ($labels as $i => $lab) {
        $months[] = ['label' => (string) $lab, 'paid' => (float) ($paid[$i] ?? 0)];
    }

    echo json_encode([
        'ok' => true,
        'allowed' => true,
        'year' => (int) ($k['year'] ?? (int) date('Y')),
        'kpis' => [
            'ca'          => ((int) ($k['revenue_paid_cents'] ?? 0)) / 100,
            'pending'     => ((int) ($k['revenue_pending_cents'] ?? 0)) / 100,
            'overdue'     => ((int) ($k['revenue_overdue_cents'] ?? 0)) / 100,
            'nb_invoices' => (int) ($k['total_invoices'] ?? 0),
            'nb_paid'     => (int) ($k['nb_paid'] ?? 0),
            'nb_pending'  => (int) ($k['nb_pending'] ?? 0),
            'avg_days'    => (int) round((float) ($k['avg_payment_days'] ?? 0)),
            'quotes'      => (int) ($k['total_quotes'] ?? 0),
            'conversion'  => (float) ($k['conversion_rate'] ?? 0),
        ],
        'months'   => $months,
        'month_max' => $maxv,
        'top_clients' => $top,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
