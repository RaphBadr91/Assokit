<?php
/**
 * api/app-invoice-action.php — Actions sur une facture depuis l'app (natif).
 * Reproduit fidèlement mon-asso-facture-send.php (envoi email) et la confirmation
 * de paiement (mon-asso-notification-respond.php). Renvoie du JSON.
 *
 * Actions : send (email au client + relance), mark_paid (encaissement constaté).
 * Rôle requis : accès finances (parité mon-asso-facture-send.php).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../finance-permissions.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';
@require_once __DIR__ . '/../asso-invoice-email-helpers.php';
@require_once __DIR__ . '/../rate-limit-helper.php';

if (!function_exists('user_can_view_finances') || !user_can_view_finances($user)) {
    app_fail(403, 'role', 'Accès réservé aux administrateurs.');
}

$invoice_id = (int) ($input['invoice_id'] ?? 0);
$action     = (string) ($input['action'] ?? '');
if ($invoice_id <= 0) app_fail(422, 'invalid', 'Facture manquante.');

// Appartenance à l'org
$st = $pdo->prepare("SELECT i.id, i.status, i.invoice_number, i.amount_ttc_cents, c.email AS client_email
                     FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id
                     WHERE i.id = ? AND i.org_id = ? LIMIT 1");
$st->execute([$invoice_id, $org_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) app_fail(404, 'not_found', 'Facture introuvable.');

/* ── Envoi de la facture par email ─────────────────────────────────── */
if ($action === 'send') {
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_inv_send', 10, 60, (string) $uid);
    if (!function_exists('ak_asso_invoice_send_email')) app_fail(500, 'unavailable', 'Envoi indisponible.');
    if ((string) $inv['status'] === 'draft') {
        app_fail(409, 'draft', 'Finalisez la facture avant de l\'envoyer (elle est encore en brouillon).');
    }
    if (empty($inv['client_email'])) {
        app_fail(422, 'no_email', 'Ce client n\'a pas d\'adresse email : ajoutez-la puis réessayez.');
    }
    $type = in_array(($input['email_type'] ?? 'initial'), ['initial', 'reminder'], true) ? $input['email_type'] : 'initial';
    try {
        $res = ak_asso_invoice_send_email($pdo, $invoice_id, $type, $uid);
        if (empty($res['success'])) app_fail(502, 'send_failed', $res['message'] ?? 'Envoi impossible.');
        echo json_encode(['ok' => true, 'id' => $invoice_id, 'message' => $res['message'] ?? 'Facture envoyée.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[app-invoice-action send] ' . $e->getMessage());
        app_fail(500, 'server', 'Envoi impossible : ' . $e->getMessage());
    }
    exit;
}

/* ── Encaissement constaté (facture payée) ─────────────────────────── */
if ($action === 'mark_paid') {
    if (!in_array((string) $inv['status'], ['pending', 'overdue'], true)) {
        $lbl = ['paid' => 'déjà payée', 'draft' => 'encore en brouillon', 'cancelled' => 'annulée', 'refunded' => 'remboursée'];
        app_fail(409, 'state', 'Cette facture est ' . ($lbl[$inv['status']] ?? 'dans un état non encaissable') . '.');
    }
    $paid_at = (string) ($input['paid_at'] ?? '');
    if ($paid_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_at)) $paid_at = '';
    $paid_at_full = ($paid_at ?: date('Y-m-d')) . ' ' . date('H:i:s');
    try {
        $pdo->prepare("UPDATE asso_invoices SET status = 'paid', paid_at = ?, updated_at = NOW() WHERE id = ? AND org_id = ?")
            ->execute([$paid_at_full, $invoice_id, $org_id]);
        echo json_encode([
            'ok' => true, 'id' => $invoice_id,
            'message' => 'Facture ' . $inv['invoice_number'] . ' marquée payée ('
                . number_format(((int) $inv['amount_ttc_cents']) / 100, 2, ',', ' ') . ' €).',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[app-invoice-action mark_paid] ' . $e->getMessage());
        app_fail(500, 'server', 'Impossible d\'enregistrer l\'encaissement.');
    }
    exit;
}

app_fail(400, 'action', 'Action inconnue.');
