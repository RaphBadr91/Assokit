<?php
/**
 * api/app-invoice-action.php — Actions sur une facture depuis l'app (natif).
 * Reproduit fidèlement mon-asso-facture-send.php (envoi email) et la confirmation
 * de paiement (mon-asso-notification-respond.php). Renvoie du JSON.
 *
 * Actions : send (email au client + relance), mark_paid (encaissement constaté),
 *           credit_note (avoir annulant une facture émise, comme « Créer un avoir » du site).
 * Rôle requis : accès finances (parité mon-asso-facture-send.php).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../finance-permissions.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';
@require_once __DIR__ . '/../asso-invoice-email-helpers.php';
@require_once __DIR__ . '/../rate-limit-helper.php';

// Même contrôle d'accès que le site (mon-asso-facture-send.php, require_billing_access) :
// admins, fondateur, super-admin et coordinateurs.
@require_once __DIR__ . '/../platform-flags.php';
if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
if (!function_exists('user_can_access_billing') || !user_can_access_billing($user)) {
    app_fail(403, 'role', 'Accès réservé aux administrateurs et coordinateurs.');
}

$invoice_id = (int) ($input['invoice_id'] ?? 0);
$action     = (string) ($input['action'] ?? '');
if ($invoice_id <= 0) app_fail(422, 'invalid', 'Facture manquante.');

// Appartenance à l'org
$st = $pdo->prepare("SELECT i.*, c.email AS client_email
                     FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
                     WHERE i.id = ? AND i.org_id = ? LIMIT 1");
$st->execute([$invoice_id, $org_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) app_fail(404, 'not_found', 'Facture introuvable.');
$is_credit = ($inv['invoice_type'] ?? 'invoice') === 'credit_note';

/* ── Envoi de la facture par email ─────────────────────────────────── */
if ($action === 'send') {
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_inv_send', 10, 60, (string) $uid);
    if (!function_exists('ak_asso_invoice_send_email')) app_fail(500, 'unavailable', 'Envoi indisponible.');
    if ((string) $inv['status'] === 'draft') {
        app_fail(409, 'draft', 'Finalisez la facture avant de l\'envoyer (elle est encore en brouillon).');
    }
    if (in_array((string) $inv['status'], ['cancelled', 'refunded'], true) && !$is_credit) {
        app_fail(409, 'closed', 'Cette facture est annulée : elle n\'est plus envoyée au client.');
    }
    if (empty($inv['client_email'])) {
        app_fail(422, 'no_email', 'Ce client n\'a pas d\'adresse email : ajoutez-la puis réessayez.');
    }
    // Parité mon-asso-facture-edit.php : 1er envoi = modèle « initial », renvoi = « manual » ;
    // facture payée : copie (« initial ») ; avoir : modèle dédié (choisi par le helper).
    // (Le helper ne renseigne sent_at qu'au premier envoi : un renvoi ne doit pas écraser la date d'envoi.)
    $type = (empty($inv['sent_at']) || (string) $inv['status'] === 'paid') ? 'initial' : 'manual';
    try {
        $res = ak_asso_invoice_send_email($pdo, $invoice_id, $type, $uid);
        // Essai gratuit : plafond d'envoi atteint (contrôlé par le helper d'envoi, ak_trial_send_block)
        if (!empty($res['trial_limit'])) app_fail(429, 'trial_limit', $res['message']);
        if (!empty($res['code']) && in_array($res['code'], ['draft', 'closed', 'paid'], true)) app_fail(409, $res['code'], $res['message']);
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
    if (!function_exists('ak_asso_invoice_mark_paid')) app_fail(500, 'unavailable', 'Fonction indisponible.');
    // Mêmes écritures que le site (« Marquer comme payée », mon-asso-facture-save.php) :
    // statut + date, qui a confirmé, moyen et référence, historique des règlements.
    // payment_method / reference : facultatifs (l'app actuelle ne les envoie pas).
    try {
        $res = ak_asso_invoice_mark_paid($pdo, $org_id, $invoice_id, $uid, [
            'paid_at'   => (string) ($input['paid_at'] ?? ''),
            'method'    => (string) ($input['payment_method'] ?? ''),
            'reference' => (string) ($input['reference'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[app-invoice-action mark_paid] ' . $e->getMessage());
        app_fail(500, 'server', 'Impossible d\'enregistrer l\'encaissement.');
    }
    if (!$res['ok']) {
        if ($res['code'] === 'not_found') app_fail(404, 'not_found', 'Facture introuvable.');
        app_fail(409, 'state', 'Cette facture est ' . ($res['state_label'] ?? 'dans un état non encaissable') . '.');
    }
    echo json_encode(['ok' => true, 'id' => $invoice_id, 'message' => $res['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Avoir : annulation d'une facture émise ────────────────────────── */
if ($action === 'credit_note') {
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_inv_credit', 10, 60, (string) $uid);
    if (!function_exists('ak_asso_credit_note_create')) app_fail(500, 'unavailable', 'Fonction indisponible.');
    try {
        $res = ak_asso_credit_note_create($pdo, $org_id, $invoice_id, $uid, (string) ($input['reason'] ?? ''));
    } catch (Throwable $e) {
        error_log('[app-invoice-action credit_note] ' . $e->getMessage());
        app_fail(500, 'server', 'L\'avoir n\'a pas pu être créé.');
    }
    if (!$res['ok']) {
        if ($res['code'] === 'not_found') app_fail(404, 'not_found', 'Facture introuvable.');
        app_fail($res['code'] === 'unavailable' ? 503 : 409, $res['code'], $res['message']);
    }
    echo json_encode(['ok' => true, 'id' => $invoice_id, 'credit_note_id' => (int) $res['id'],
                      'credit_note_number' => (string) $res['invoice_number'], 'message' => $res['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

app_fail(400, 'action', 'Action inconnue.');
