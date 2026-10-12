<?php
/**
 * api/app-founder-stats.php — Statistiques plateforme (cockpit Fondateur, natif).
 * Réservé au Fondateur. NE MODIFIE PAS le site : dédié à l'application.
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
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
require_once __DIR__ . '/_app-founder-plan.php';
// Statistiques avancées : Fondateur uniquement, comme /super-admin/stats et le bloc
// « Emails envoyés — plateforme » du cockpit web (sa-permissions.php : can_view_platform_stats)
if (!app_is_founder($pdo, $user)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

try {
    // Compteurs et MRR : mêmes règles qu'ak_org_plan_state() (un essai n'est jamais un revenu ;
    // un essai terminé n'est pas compté comme « suspendue » mais comme « essai terminé »)
    $pc = fd_plan_counts($pdo);
    $orgs_total  = $pc['total'];
    $orgs_active = $pc['active'];
    $orgs_trial  = $pc['trial'];
    $orgs_susp   = $pc['suspended'];
    $orgs_new30  = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL");
    $users_total = (int) $scalar("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active=1 AND deleted_at IS NULL");
    $users_new30 = (int) $scalar("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active=1 AND deleted_at IS NULL");

    $mrr = (float) $pc['mrr'];   // abonnements courants actifs sur un plan payant, essais exclus

    $ca_paid = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid'");
    $ca_paid_30 = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    // Impayés : associations supprimées exclues, comme le cockpit et le filtre « Impayés »
    $unpaid = fd_unpaid_summary($pdo);
    $unpaid_total = $unpaid['total'];
    $unpaid_nb = $unpaid['nb'];

    $ia = ['nb' => 0, 'cost' => 0.0];
    try { $ia = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros),0) AS cost FROM communication_campaigns WHERE ai_generated=1")->fetch(PDO::FETCH_ASSOC) ?: $ia; } catch (Throwable $e) {}

    // Emails & SMS envoyés — plateforme (semaine / mois / trimestre / année) : mêmes règles que
    // le cockpit web (super-admin.php) — envois réussis seulement (les échecs sont aussi journalisés,
    // status='failed'), sur 7 jours / 1 mois / 3 mois / 1 an
    $periods = [
        'week'    => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        'month'   => "created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)",
        'quarter' => "created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)",
        'year'    => "created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)",
    ];
    $emails = ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0];
    $sms    = ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0];
    foreach ($periods as $k => $cond) {
        $emails[$k] = (int) $scalar("SELECT COUNT(*) FROM email_log WHERE $cond AND status = 'sent'");
        $sms[$k]    = (int) $scalar("SELECT COUNT(*) FROM sms_log WHERE $cond AND status IN ('sent','delivered')");
    }

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
        'orgs' => ['total' => $orgs_total, 'active' => $orgs_active, 'trial' => $orgs_trial, 'suspended' => $orgs_susp, 'new30' => $orgs_new30,
                   'paying' => $pc['paying'], 'free' => $pc['free'], 'pending_payment' => $pc['pending_payment'],
                   'trial_expired' => $pc['trial_expired'], 'expiring' => $pc['expiring'], 'cancelled' => $pc['cancelled']],
        'users' => ['total' => $users_total, 'new30' => $users_new30],
        'revenue' => ['mrr' => round($mrr, 2), 'paying' => $pc['paying'], 'ca_paid' => round($ca_paid, 2), 'ca_paid_30' => round($ca_paid_30, 2), 'unpaid_total' => round($unpaid_total, 2), 'unpaid_nb' => $unpaid_nb, 'unpaid_orgs' => $unpaid['orgs']],
        'ia' => ['nb' => (int) $ia['nb'], 'cost' => round((float) $ia['cost'], 2)],
        'emails' => $emails,
        'sms' => $sms,
        'curve' => $curve,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
