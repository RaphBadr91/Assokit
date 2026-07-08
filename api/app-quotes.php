<?php
/**
 * api/app-quotes.php — Liste des devis pour l'ecran natif. JSON, scope org.
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

function qfd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT q.id, q.quote_number, q.status, q.amount_ttc_cents, q.issued_at, q.expires_at,
               c.display_name AS client_name
        FROM asso_quotes q
        LEFT JOIN asso_clients c ON q.client_id = c.id
        WHERE q.org_id = ?
        ORDER BY q.issued_at DESC, q.id DESC
        LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $quotes = [];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'draft');
        if ($status === 'sent' && !empty($r['expires_at']) && $r['expires_at'] !== '0000-00-00' && strtotime($r['expires_at']) < time()) {
            $status = 'expired';
        }
        $meta = $Q_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $quotes[] = [
            'id'           => (int) $r['id'],
            'number'       => (string) ($r['quote_number'] ?? ('DEVIS-' . $r['id'])),
            'client'       => (string) ($r['client_name'] ?? ''),
            'amount'       => ((int) ($r['amount_ttc_cents'] ?? 0)) / 100,
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'date'         => qfd($r['issued_at'] ?? null),
        ];
    }

    echo json_encode(['ok' => true, 'quotes' => $quotes], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-quotes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
