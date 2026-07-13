<?php
/**
 * api/app-founder.php — Données du cockpit Fondateur pour l'app mobile (natif).
 * Réponse JSON, réservée au Fondateur / Super Admin. NE MODIFIE PAS le site.
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
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$q = function (string $sql) use ($pdo) { try { return $pdo->query($sql); } catch (Throwable $e) { return null; } };
$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

try {
    $nb_orgs_total = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL");
    $nb_orgs_active = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='active' AND deleted_at IS NULL");
    $nb_orgs_trial = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='trial' AND deleted_at IS NULL");
    $nb_users = (int) $scalar("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active=1 AND deleted_at IS NULL");
    $nb_orgs_30 = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL");
    $nb_users_30 = (int) $scalar("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active=1 AND deleted_at IS NULL");
    $ca_paid_30 = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

    $mrr = 0.0;
    try {
        $mrr = (float) $pdo->query("
            SELECT COALESCE(SUM(CASE WHEN billing_cycle='monthly' THEN price_ht*(1+tva_rate/100)
                                     WHEN billing_cycle='yearly' THEN (price_ht*(1+tva_rate/100))/12 ELSE 0 END),0)
            FROM subscriptions WHERE status='active'")->fetchColumn();
    } catch (Throwable $e) {}

    $ia = ['nb' => 0, 'cost' => 0.0];
    try { $ia = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros),0) AS cost FROM communication_campaigns WHERE ai_generated=1")->fetch(PDO::FETCH_ASSOC) ?: $ia; } catch (Throwable $e) {}

    // Alertes / signaux
    $nb_pending = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE validation_status='pending_founder' AND deleted_at IS NULL");
    $unpaid = ['nb' => 0, 'total' => 0.0];
    try { $unpaid = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc),0) AS total FROM subscription_invoices WHERE status IN ('sent','overdue')")->fetch(PDO::FETCH_ASSOC) ?: $unpaid; } catch (Throwable $e) {}
    $nb_expiring = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE status='trial' AND trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");

    // Dernières associations
    $orgs = [];
    try {
        $st = $pdo->query("
            SELECT o.id, o.name, o.status, o.plan, o.created_at,
                   COALESCE(o.validation_status,'validated') AS validation_status,
                   (SELECT COUNT(*) FROM users WHERE org_id=o.id AND deleted_at IS NULL AND is_active=1) AS nb_users
            FROM organizations o WHERE o.deleted_at IS NULL ORDER BY o.created_at DESC LIMIT 6");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $orgs[] = [
                'id' => (int) $o['id'],
                'name' => (string) $o['name'],
                'status' => (string) $o['status'],
                'plan' => ucfirst((string) $o['plan']),
                'validated' => ($o['validation_status'] !== 'pending_founder' && $o['validation_status'] !== 'rejected'),
                'pending' => ($o['validation_status'] === 'pending_founder'),
                'nb_users' => (int) $o['nb_users'],
                'created' => date('d/m/Y', strtotime($o['created_at'])),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'first_name' => trim((string) ($user['first_name'] ?? '')),
        'kpis' => [
            'mrr' => round($mrr, 2),
            'orgs_total' => $nb_orgs_total,
            'orgs_active' => $nb_orgs_active,
            'orgs_trial' => $nb_orgs_trial,
            'users' => $nb_users,
            'ia_nb' => (int) $ia['nb'],
            'ia_cost' => round((float) $ia['cost'], 2),
        ],
        'month' => [
            'new_orgs' => $nb_orgs_30,
            'new_users' => $nb_users_30,
            'ca_paid' => round($ca_paid_30, 2),
        ],
        'signals' => [
            'pending' => $nb_pending,
            'unpaid_nb' => (int) $unpaid['nb'],
            'unpaid_total' => round((float) $unpaid['total'], 2),
            'expiring' => $nb_expiring,
        ],
        'orgs' => $orgs,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
