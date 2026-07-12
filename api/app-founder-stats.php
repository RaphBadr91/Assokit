<?php
/**
 * api/app-founder-stats.php — Statistiques plateforme (cockpit Fondateur, natif).
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

try {
    $orgs_total  = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL");
    $orgs_active = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='active' AND deleted_at IS NULL");
    $orgs_trial  = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='trial' AND deleted_at IS NULL");
    $orgs_susp   = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='suspended' AND deleted_at IS NULL");
    $orgs_new30  = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL");
    $users_total = (int) $scalar("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active=1 AND deleted_at IS NULL");
    $users_new30 = (int) $scalar("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active=1 AND deleted_at IS NULL");

    $mrr = 0.0;
    try {
        $mrr = (float) $pdo->query("
            SELECT COALESCE(SUM(CASE WHEN billing_cycle='monthly' THEN price_ht*(1+tva_rate/100)
                                     WHEN billing_cycle='yearly' THEN (price_ht*(1+tva_rate/100))/12 ELSE 0 END),0)
            FROM subscriptions WHERE status='active'")->fetchColumn();
    } catch (Throwable $e) {}

    $ca_paid = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid'");
    $ca_paid_30 = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $unpaid_total = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status IN ('sent','overdue')");
    $unpaid_nb = (int) $scalar("SELECT COUNT(*) FROM subscription_invoices WHERE status IN ('sent','overdue')");

    $ia = ['nb' => 0, 'cost' => 0.0];
    try { $ia = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros),0) AS cost FROM communication_campaigns WHERE ai_generated=1")->fetch(PDO::FETCH_ASSOC) ?: $ia; } catch (Throwable $e) {}

    // Courbe : associations créées par mois (6 derniers mois)
    $curve = [];
    try {
        $st = $pdo->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
            FROM organizations WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY ym ORDER BY ym ASC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $curve[] = ['ym' => $r['ym'], 'n' => (int) $r['n']];
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'orgs' => ['total' => $orgs_total, 'active' => $orgs_active, 'trial' => $orgs_trial, 'suspended' => $orgs_susp, 'new30' => $orgs_new30],
        'users' => ['total' => $users_total, 'new30' => $users_new30],
        'revenue' => ['mrr' => round($mrr, 2), 'ca_paid' => round($ca_paid, 2), 'ca_paid_30' => round($ca_paid_30, 2), 'unpaid_total' => round($unpaid_total, 2), 'unpaid_nb' => $unpaid_nb],
        'ia' => ['nb' => (int) $ia['nb'], 'cost' => round((float) $ia['cost'], 2)],
        'curve' => $curve,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
