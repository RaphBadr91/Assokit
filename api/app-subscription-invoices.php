<?php
/**
 * api/app-subscription-invoices.php — Factures d'abonnement Assokit (Stripe) pour l'app.
 * JSON, lecture seule, scope org. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$META = [
    'paid'          => ['label' => 'Payée',        'kind' => 'done'],
    'open'          => ['label' => 'En attente',   'kind' => 'wait'],
    'void'          => ['label' => 'Annulée',      'kind' => 'off'],
    'uncollectible' => ['label' => 'Irrécouvrable', 'kind' => 'late'],
    'draft'         => ['label' => 'Brouillon',    'kind' => 'draft'],
];

function sfd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    // Parité site (abonnement.php) : factures d'abonnement réservées aux administrateurs.
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'super_admin'], true) && empty($user['is_founder']) && empty($user['is_super_admin'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'invoices' => [], 'stats' => [], 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT invoice_number, amount_cents, tax_cents, currency, status,
                   invoice_pdf_url, hosted_invoice_url, paid_at, created_at, period_start, period_end
            FROM asso_invoices_stripe
            WHERE org_id = ?
            ORDER BY COALESCE(created_at, paid_at) DESC, id DESC
            LIMIT 200
        ");
        $stmt->execute([$org_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $invoices = [];
    $total_paid = 0; $nb_paid = 0; $nb_pending = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'open');
        $meta = $META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $cents = (int) ($r['amount_cents'] ?? 0);
        if ($status === 'paid') { $total_paid += $cents; $nb_paid++; }
        elseif ($status === 'open') { $nb_pending++; }
        $ps = sfd($r['period_start'] ?? null);
        $pe = sfd($r['period_end'] ?? null);
        $invoices[] = [
            'number'       => (string) ($r['invoice_number'] ?? ''),
            'amount'       => $cents / 100,
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'date'         => sfd($r['paid_at'] ?? ($r['created_at'] ?? null)),
            'period'       => ($ps && $pe) ? ($ps . ' → ' . $pe) : '',
            'pdf'          => (string) ($r['invoice_pdf_url'] ?? ($r['hosted_invoice_url'] ?? '')),
        ];
    }

    echo json_encode([
        'ok' => true,
        'stats' => ['total_paid' => $total_paid / 100, 'nb_paid' => $nb_paid, 'nb_pending' => $nb_pending, 'nb' => count($invoices)],
        'invoices' => $invoices,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-subscription-invoices] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
