<?php
/**
 * api/app-broadcasts.php — Diffusions email (communication) pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

$B_META = [
    'sent'    => ['label' => 'Envoyée',   'kind' => 'done'],
    'partial' => ['label' => 'Envoi partiel', 'kind' => 'wait'],
    'draft'   => ['label' => 'Brouillon', 'kind' => 'draft'],
    'sending' => ['label' => 'En cours',  'kind' => 'wait'],
    'failed'  => ['label' => 'Échec',     'kind' => 'late'],
];

function bfd($v) {
    if (empty($v) || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime((string) $v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    // Même règle que le site (/communication : require_capability('access_marketing')) :
    // sans ce droit, ni les objets des diffusions ni les brouillons ne sont visibles.
    if (!function_exists('can') || !can('access_marketing')) {
        echo json_encode(['ok' => true, 'allowed' => false, 'stats' => ['nb' => 0, 'sent' => 0], 'items' => [],
                          'message' => 'Réservé à l\'équipe communication.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT b.id, b.subject, b.status, b.sent_at, b.created_at, b.nb_sent, b.nb_failed
            FROM communication_broadcasts b
            WHERE b.org_id = ?
            ORDER BY b.created_at DESC LIMIT 100
        ");
        $stmt->execute([$org_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $items = [];
    $sent = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'draft');
        $meta = $B_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'draft'];
        $went_out = $status === 'sent' || $status === 'partial';   // au moins un e-mail parti
        if ($went_out) $sent++;
        $items[] = [
            'id'           => (int) $r['id'],
            'subject'      => (string) ($r['subject'] ?? '(sans objet)'),
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'nb_sent'      => (int) ($r['nb_sent'] ?? 0),
            'nb_failed'    => (int) ($r['nb_failed'] ?? 0),
            'date'         => $went_out ? bfd($r['sent_at'] ?? null) : bfd($r['created_at'] ?? null),
        ];
    }

    echo json_encode(['ok' => true, 'stats' => ['nb' => count($items), 'sent' => $sent], 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-broadcasts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
