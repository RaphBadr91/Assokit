<?php
/**
 * api/app-invoice.php — Detail d'une facture pour l'ecran natif.
 * JSON, authentifie par session, scope a l'org du user. NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

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
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    // Parité site (mon-asso-facture-edit.php, require_billing_access) : admins, fondateur,
    // super-admin et coordinateurs (finance-permissions.php).
    @require_once __DIR__ . '/../finance-permissions.php';
    @require_once __DIR__ . '/../platform-flags.php';
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    if (!function_exists('user_can_access_billing') || !user_can_access_billing($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'role', 'message' => 'Réservé aux administrateurs et coordinateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT i.*, c.display_name AS client_name, c.email AS client_email
        FROM asso_invoices i
        LEFT JOIN asso_clients c ON i.client_id = c.id AND c.org_id = i.org_id
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

    // Avoirs (clés ajoutées, les clés existantes sont inchangées) : un avoir s'affiche
    // « Avoir » ; une facture annulée par avoir indique son numéro.
    @require_once __DIR__ . '/../asso-invoice-helpers.php';
    $is_credit = ($inv['invoice_type'] ?? 'invoice') === 'credit_note';
    $parent_number = null; $credit_note = null;
    if ($is_credit) {
        $meta = ['label' => 'Avoir', 'kind' => 'off'];
        if (!empty($inv['parent_invoice_id'])) {
            $ps = $pdo->prepare("SELECT invoice_number FROM asso_invoices WHERE id = ? AND org_id = ? LIMIT 1");
            $ps->execute([(int) $inv['parent_invoice_id'], $org_id]);
            $parent_number = $ps->fetchColumn() ?: null;
        }
    } elseif (function_exists('ak_asso_invoice_credit_note_of') && (string) $inv['status'] !== 'draft') {
        $credit_note = ak_asso_invoice_credit_note_of($pdo, $org_id, (int) $inv['id']);
    }

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
        if ($di && $dd && $dd >= $di) $due_days = (int) floor(($dd - $di) / 86400);
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
            'invoice_type' => $is_credit ? 'credit_note' : 'invoice',
            'parent_invoice_id'     => $is_credit ? (int) ($inv['parent_invoice_id'] ?? 0) : null,
            'parent_invoice_number' => $parent_number,
            'credit_note_id'        => $credit_note ? (int) $credit_note['id'] : null,
            'credit_note_number'    => $credit_note ? (string) $credit_note['invoice_number'] : null,
            // Action « credit_note » de app-invoice-action.php disponible (facture émise, sans avoir)
            'can_credit_note'       => !$is_credit && !$credit_note && in_array((string) $inv['status'], ['pending', 'overdue', 'paid'], true),
        ],
        'lines' => $lines,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-invoice] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
