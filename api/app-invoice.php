<?php
/**
 * api/app-invoice.php — Detail d'une facture pour l'ecran natif.
 * JSON, authentifie par session, scope a l'org du user. NE MODIFIE PAS le site.
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

$INV_META = [
    'paid'      => ['label' => 'Payée',      'kind' => 'done'],
    'pending'   => ['label' => 'En attente', 'kind' => 'wait'],
    'overdue'   => ['label' => 'En retard',  'kind' => 'late'],
    'draft'     => ['label' => 'Brouillon',  'kind' => 'draft'],
    'cancelled' => ['label' => 'Annulée',    'kind' => 'off'],
    'refunded'  => ['label' => 'Remboursée', 'kind' => 'off'],
];

function fdt($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    $stmt = $pdo->prepare("
        SELECT i.*, c.display_name AS client_name, c.email AS client_email
        FROM asso_invoices i
        LEFT JOIN asso_clients c ON i.client_id = c.id
        WHERE i.id = ? AND i.org_id = ? LIMIT 1
    ");
    $stmt->execute([$id, $org_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $status = (string) ($inv['status'] ?? 'pending');
    if ($status === 'pending' && !empty($inv['due_at']) && $inv['due_at'] !== '0000-00-00' && strtotime($inv['due_at']) < time()) {
        $status = 'overdue';
    }
    $meta = $INV_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];

    // Nom du client : live sinon snapshot
    $client_name = trim((string) ($inv['client_name'] ?? ''));
    if ($client_name === '' && !empty($inv['client_snapshot'])) {
        $snap = json_decode((string) $inv['client_snapshot'], true);
        if (is_array($snap)) $client_name = trim((string) ($snap['display_name'] ?? ''));
    }

    // Lignes
    $lines = [];
    try {
        $st = $pdo->prepare("SELECT designation, quantity, unit_price_ht_cents, vat_rate, total_ttc_cents FROM asso_invoice_lines WHERE invoice_id = ? ORDER BY line_order");
        $st->execute([$id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $l) {
            $lines[] = [
                'label'    => (string) ($l['designation'] ?? ''),
                'qty'      => (float) ($l['quantity'] ?? 1),
                'unit'     => ((int) ($l['unit_price_ht_cents'] ?? 0)) / 100,
                'vat'      => $l['vat_rate'] !== null ? (float) $l['vat_rate'] : null,
                'total'    => ((int) ($l['total_ttc_cents'] ?? 0)) / 100,
            ];
        }
    } catch (Throwable $e) {}

    // Échéance en jours (pour pré-remplir l'édition native)
    $due_days = 30;
    if (!empty($inv['issued_at']) && !empty($inv['due_at'])) {
        $di = strtotime((string) $inv['issued_at']);
        $dd = strtotime((string) $inv['due_at']);
        if ($di && $dd && $dd >= $di) $due_days = (int) round(($dd - $di) / 86400);
    }

    echo json_encode([
        'ok' => true,
        'invoice' => [
            'id'           => (int) $inv['id'],
            'number'       => (string) ($inv['invoice_number'] ?? ('FACT-' . $inv['id'])),
            'client'       => $client_name,
            'client_id'    => (int) ($inv['client_id'] ?? 0),
            'client_email' => (string) ($inv['client_email'] ?? ''),
            'due_days'     => $due_days,
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'issued_at'    => fdt($inv['issued_at'] ?? null),
            'due_at'       => fdt($inv['due_at'] ?? null),
            'paid_at'      => fdt($inv['paid_at'] ?? null),
            'amount_ht'    => ((int) ($inv['amount_ht_cents'] ?? 0)) / 100,
            'amount_vat'   => ((int) ($inv['amount_vat_cents'] ?? 0)) / 100,
            'amount_ttc'   => ((int) ($inv['amount_ttc_cents'] ?? 0)) / 100,
            'description'  => (string) ($inv['description'] ?? ''),
            'public_uuid'  => (string) ($inv['public_uuid'] ?? ''),
        ],
        'lines' => $lines,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-invoice] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
