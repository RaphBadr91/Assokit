<?php
/**
 * api/app-client.php — Detail d'un client (profil TPE) pour l'ecran natif.
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

function fd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? AND org_id = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1");
    $stmt->execute([$id, $org_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $name = trim((string) ($c['display_name'] ?? '')) ?: (string) ($c['email'] ?? 'Client');
    $ini = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 2) : substr($name, 0, 2));

    // Adresse composee
    $addr = array_filter([
        trim((string) ($c['address_street'] ?? '')),
        trim((string) ($c['address_complement'] ?? '')),
        trim(trim((string) ($c['address_zip'] ?? '')) . ' ' . trim((string) ($c['address_city'] ?? ''))),
    ], fn($x) => $x !== '');

    // Factures du client
    $invoices = [];
    $paid = 0; $pending = 0; $overdue = 0; $total = 0; $nb = 0;
    try {
        $st = $pdo->prepare("SELECT id, invoice_number, status, amount_ttc_cents, issued_at, due_at
                             FROM asso_invoices WHERE client_id = ? AND org_id = ? ORDER BY issued_at DESC, id DESC LIMIT 100");
        $st->execute([$id, $org_id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $status = (string) ($r['status'] ?? 'pending');
            if ($status === 'pending' && !empty($r['due_at']) && $r['due_at'] !== '0000-00-00' && strtotime($r['due_at']) < time()) {
                $status = 'overdue';
            }
            $cents = (int) ($r['amount_ttc_cents'] ?? 0);
            $total += $cents; $nb++;
            if ($status === 'paid') $paid += $cents;
            elseif ($status === 'overdue') $overdue += $cents;
            elseif ($status === 'pending') $pending += $cents;
            $meta = $INV_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
            $invoices[] = [
                'id'           => (int) $r['id'],
                'number'       => (string) ($r['invoice_number'] ?? ('FACT-' . $r['id'])),
                'amount'       => $cents / 100,
                'status'       => $status,
                'status_label' => $meta['label'],
                'status_kind'  => $meta['kind'],
                'date'         => fd($r['issued_at'] ?? null),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'client' => [
            'id'         => (int) $c['id'],
            'name'       => $name,
            'initials'   => $ini !== '' ? $ini : '?',
            'type'       => (string) ($c['client_type'] ?? 'company'),
            'email'      => (string) ($c['email'] ?? ''),
            'phone'      => (string) ($c['phone'] ?? ''),
            'city'       => (string) ($c['address_city'] ?? ''),
            'address'    => implode("\n", $addr),
            'siren'      => (string) ($c['siren'] ?? ''),
            'vat_number' => (string) ($c['vat_number'] ?? ''),
        ],
        'stats' => [
            'nb'      => $nb,
            'total'   => $total / 100,
            'paid'    => $paid / 100,
            'pending' => $pending / 100,
            'overdue' => $overdue / 100,
        ],
        'invoices' => $invoices,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-client] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
