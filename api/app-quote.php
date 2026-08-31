<?php
/**
 * api/app-quote.php — Detail d'un devis pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
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

$Q_META = [
    'draft'     => ['label' => 'Brouillon', 'kind' => 'draft'],
    'sent'      => ['label' => 'Envoyé',    'kind' => 'wait'],
    'signed'    => ['label' => 'Signé',     'kind' => 'done'],
    'converted' => ['label' => 'Converti',  'kind' => 'done'],
    'refused'   => ['label' => 'Refusé',    'kind' => 'late'],
    'expired'   => ['label' => 'Expiré',    'kind' => 'off'],
    'cancelled' => ['label' => 'Annulé',    'kind' => 'off'],
];

function qdt($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    $stmt = $pdo->prepare("
        SELECT q.*, c.display_name AS client_name, c.email AS client_email
        FROM asso_quotes q LEFT JOIN asso_clients c ON q.client_id = c.id
        WHERE q.id = ? AND q.org_id = ? LIMIT 1
    ");
    $stmt->execute([$id, $org_id]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $status = (string) ($q['status'] ?? 'draft');
    if ($status === 'sent' && !empty($q['expires_at']) && $q['expires_at'] !== '0000-00-00' && strtotime($q['expires_at']) < time()) {
        $status = 'expired';
    }
    $meta = $Q_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];

    $client_name = trim((string) ($q['client_name'] ?? ''));
    if ($client_name === '' && !empty($q['client_snapshot'])) {
        $snap = json_decode((string) $q['client_snapshot'], true);
        if (is_array($snap)) $client_name = trim((string) ($snap['display_name'] ?? ''));
    }

    $lines = [];
    try {
        $st = $pdo->prepare("SELECT designation, quantity, unit_price_ht_cents, vat_rate, total_ttc_cents FROM asso_quote_lines WHERE quote_id = ? ORDER BY line_order");
        $st->execute([$id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $l) {
            $lines[] = [
                'label' => (string) ($l['designation'] ?? ''),
                'qty'   => (float) ($l['quantity'] ?? 1),
                'unit'  => ((int) ($l['unit_price_ht_cents'] ?? 0)) / 100,
                'vat'   => $l['vat_rate'] !== null ? (float) $l['vat_rate'] : null,
                'total' => ((int) ($l['total_ttc_cents'] ?? 0)) / 100,
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'invoice' => [ // même forme que app-invoice pour réutiliser l'écran natif
            'id'           => (int) $q['id'],
            'number'       => (string) ($q['quote_number'] ?? ('DEVIS-' . $q['id'])),
            'client'       => $client_name,
            'client_id'    => (int) ($q['client_id'] ?? 0),
            'client_email' => (string) ($q['client_email'] ?? ''),
            'validity_days' => (function () use ($q) {
                if (empty($q['issued_at']) || empty($q['expires_at'])) return 30;
                $a = strtotime((string) $q['issued_at']); $b = strtotime((string) $q['expires_at']);
                return ($a && $b && $b >= $a) ? (int) round(($b - $a) / 86400) : 30;
            })(),
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'issued_at'    => qdt($q['issued_at'] ?? null),
            'due_at'       => qdt($q['expires_at'] ?? null),
            'paid_at'      => '',
            'amount_ht'    => ((int) ($q['amount_ht_cents'] ?? 0)) / 100,
            'amount_vat'   => ((int) ($q['amount_vat_cents'] ?? 0)) / 100,
            'amount_ttc'   => ((int) ($q['amount_ttc_cents'] ?? 0)) / 100,
            'description'  => (string) ($q['description'] ?? ''),
            'public_uuid'  => (string) ($q['public_uuid'] ?? ''),
            'is_quote'     => true,
        ],
        'lines' => $lines,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-quote] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
