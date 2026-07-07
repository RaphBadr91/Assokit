<?php
/**
 * api/app-invoices.php — Liste des factures pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
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

$STATUS_META = [
    'paid'      => ['label' => 'Payée',      'kind' => 'done'],
    'pending'   => ['label' => 'En attente', 'kind' => 'wait'],
    'overdue'   => ['label' => 'En retard',  'kind' => 'late'],
    'draft'     => ['label' => 'Brouillon',  'kind' => 'draft'],
    'cancelled' => ['label' => 'Annulée',    'kind' => 'off'],
    'void'      => ['label' => 'Annulée',    'kind' => 'off'],
];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.status, i.amount_ttc_cents,
               i.issued_at, i.due_at,
               c.display_name AS client_name
        FROM asso_invoices i
        LEFT JOIN asso_clients c ON i.client_id = c.id
        WHERE i.org_id = ?
        ORDER BY i.issued_at DESC, i.id DESC
        LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $now = time();
    $invoices = [];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'pending');
        // Marquer en retard si echue et non payee
        if ($status === 'pending' && !empty($r['due_at'])
            && $r['due_at'] !== '0000-00-00' && strtotime($r['due_at']) < $now) {
            $status = 'overdue';
        }
        $meta = $STATUS_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];

        $issued = $r['issued_at'] ?? null;
        $date_fr = '';
        if (!empty($issued) && $issued !== '0000-00-00') {
            $ts = strtotime($issued);
            if ($ts) $date_fr = date('d/m/Y', $ts);
        }

        $invoices[] = [
            'id'          => (int) $r['id'],
            'number'      => (string) ($r['invoice_number'] ?? ('FACT-' . $r['id'])),
            'client'      => (string) ($r['client_name'] ?? ''),
            'amount'      => ((int) ($r['amount_ttc_cents'] ?? 0)) / 100,
            'status'      => $status,
            'status_label'=> $meta['label'],
            'status_kind' => $meta['kind'],
            'date'        => $date_fr,
        ];
    }

    echo json_encode(['ok' => true, 'invoices' => $invoices], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-invoices] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
