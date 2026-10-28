<?php
/**
 * api/app-subscription-invoices.php — Factures d'abonnement Assokit pour l'app.
 * JSON, lecture seule, scope org. NE MODIFIE PAS le site.
 * Même liste que « Mes factures » du site (ak_get_org_invoices_all : Stripe, manuelles
 * et factures AK-AAAA-NNNNNN), réservée aux administrateurs comme « Mon abonnement ».
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id
require_once __DIR__ . '/../finance-permissions.php';
require_once __DIR__ . '/../stripe-helpers.php';

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
    // Parité site (« Mon abonnement », abonnement.php) : abonnement Assokit réservé aux administrateurs.
    if (!user_can_view_finances($user)) {
        echo json_encode(['ok' => true, 'allowed' => false, 'invoices' => [], 'stats' => [], 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = ($org_id > 0 && function_exists('ak_get_org_invoices_all')) ? ak_get_org_invoices_all($pdo, $org_id, 200) : [];

    $invoices = [];
    $total_paid = 0; $nb_paid = 0; $nb_pending = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'open');
        $meta = $META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $cents = (int) ($r['amount_cents'] ?? 0);
        if ($status === 'paid') { $total_paid += $cents; $nb_paid++; }
        elseif ($status === 'open') { $nb_pending++; }
        // Jamais une page de paiement Stripe dans l'appli (Apple 3.1.1, règles de paiement de Google Play) :
        // ni la page de la facture hébergée par Stripe (hosted_invoice_url, « Payer »), ni le PDF d'une
        // facture Stripe qui n'est pas payée (ouverte, irrécouvrable : il porte un lien « Payer en ligne »)
        $pdf = (string) ($r['invoice_pdf_url'] ?? '');
        if (($r['source'] ?? '') === 'stripe' && $status !== 'paid') $pdf = '';
        $ps = sfd($r['period_start'] ?? null);
        $pe = sfd($r['period_end'] ?? null);
        $invoices[] = [
            'id'           => (int) ($r['id'] ?? 0),
            'source'       => (string) ($r['source'] ?? 'stripe'),
            'number'       => (string) ($r['invoice_number'] ?? ''),
            'amount'       => $cents / 100,
            'status'       => $status,
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'date'         => sfd($r['paid_at'] ?? ($r['created_at'] ?? null)),
            'period'       => ($ps && $pe) ? ($ps . ' → ' . $pe) : '',
            'pdf'          => $pdf,
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
