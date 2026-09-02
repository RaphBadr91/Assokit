<?php
/**
 * api/app-founder-billing.php — Paiements & Abonnements (cockpit Fondateur, natif).
 * GET ?filter=all|unpaid|paid  · réservé Fondateur/Super Admin. NE MODIFIE PAS le site.
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

$filter = (string) ($_GET['filter'] ?? 'all');
$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

$INV_META = [
    'paid'      => ['label' => 'Payée',      'kind' => 'done'],
    'sent'      => ['label' => 'Envoyée',    'kind' => 'wait'],
    'overdue'   => ['label' => 'En retard',  'kind' => 'late'],
    'draft'     => ['label' => 'Brouillon',  'kind' => 'draft'],
    'cancelled' => ['label' => 'Annulée',    'kind' => 'off'],
];

try {
    // Synthèse
    $mrr = 0.0;
    try {
        // Même source de vérité que super-admin.php (asso_subscriptions + asso_plans, essais exclus).
        $mrr = (float) $pdo->query("
            SELECT COALESCE(SUM(p.price_cents), 0) / 100
            FROM asso_subscriptions s
            INNER JOIN asso_plans p ON p.id = s.plan_id
            WHERE s.status = 'active' AND p.is_trial = 0
              AND s.id = (SELECT MAX(s2.id) FROM asso_subscriptions s2 WHERE s2.org_id = s.org_id)")->fetchColumn();
    } catch (Throwable $e) {}
    $ca_paid      = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid'");
    $unpaid_total = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status IN ('sent','overdue')");
    $unpaid_nb    = (int) $scalar("SELECT COUNT(*) FROM subscription_invoices WHERE status IN ('sent','overdue')");

    $where = "1=1";
    if ($filter === 'unpaid') $where = "i.status IN ('sent','overdue')";
    elseif ($filter === 'paid') $where = "i.status = 'paid'";

    $rows = [];
    try {
        $st = $pdo->query("
            SELECT i.id, i.invoice_number, i.amount_ttc, i.status, i.due_date, i.paid_at, i.created_at,
                   i.org_id, o.name AS org_name
            FROM subscription_invoices i
            LEFT JOIN organizations o ON o.id = i.org_id
            WHERE $where
            ORDER BY i.created_at DESC, i.id DESC LIMIT 150");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $invoices = [];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'sent');
        if ($status === 'sent' && !empty($r['due_date']) && $r['due_date'] !== '0000-00-00' && strtotime($r['due_date']) < time()) $status = 'overdue';
        $meta = $INV_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $invoices[] = [
            'id'           => (int) $r['id'],
            'number'       => (string) ($r['invoice_number'] ?? ('AB-' . $r['id'])),
            'org'          => (string) ($r['org_name'] ?? '—'),
            'amount'       => round((float) ($r['amount_ttc'] ?? 0), 2),
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'date'         => !empty($r['created_at']) ? date('d/m/Y', strtotime($r['created_at'])) : '',
            'can_pay'      => in_array($status, ['sent', 'overdue'], true),
        ];
    }

    echo json_encode([
        'ok' => true,
        'filter' => $filter,
        'summary' => ['mrr' => round($mrr, 2), 'ca_paid' => round($ca_paid, 2), 'unpaid_total' => round($unpaid_total, 2), 'unpaid_nb' => $unpaid_nb],
        'invoices' => $invoices,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-billing] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
