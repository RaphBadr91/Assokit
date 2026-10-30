<?php
/**
 * payer-facture.php — POST /payer-facture
 * --------------------------------------------------------------
 * Bouton « Payer en ligne » de la page publique d'une facture (/facture/{uuid}) : ouvre la page de paiement
 * Stripe Checkout au nom du compte Stripe de l'association (stripe-connect-helpers.php). Le montant est celui
 * de la facture (TTC), jamais une valeur envoyée par le navigateur.
 * Jeton CSRF de la page publique (csrf_token_public) ; limite par adresse IP ; jamais dans l'application
 * (agent « AssokitApp » : aucune session de paiement, le paiement se fait dans le navigateur).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/stripe-connect-helpers.php';
require_once __DIR__ . '/rate-limit-helper.php';
require_once __DIR__ . '/app-context.php';

$uuid = (string)($_POST['uuid'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    http_response_code(404);
    die('Facture introuvable.');
}
$back = '/facture/' . strtolower($uuid);
$fail = function (string $msg) use ($back) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => $msg];
    header('Location: ' . $back . '#payer', true, 303);
    exit;
};
if (empty($_SESSION['csrf_token_public']) || !hash_equals((string)$_SESSION['csrf_token_public'], (string)($_POST['csrf_token'] ?? ''))) {
    $fail('Session expirée : rechargez la page puis cliquez de nouveau sur « Payer en ligne ».');
}
if (ak_is_mobile_app()) $fail('Le paiement en ligne s’ouvre dans votre navigateur, pas dans l’application.');
if (!ak_rate_limit('pay_invoice', 20, 600)) $fail('Trop de tentatives en peu de temps : patientez quelques minutes.');

$st = $pdo->prepare("SELECT i.*, c.display_name AS client_name, c.email AS client_email, o.name AS org_name
                     FROM asso_invoices i
                     LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
                     LEFT JOIN organizations o ON o.id = i.org_id
                     WHERE i.public_uuid = ? LIMIT 1");
$st->execute([$uuid]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv || ($inv['status'] ?? '') === 'draft') {
    http_response_code(404);
    die('Facture introuvable.');
}
if (!ak_connect_invoice_payable($inv)) {
    $fail(in_array($inv['status'], ['paid'], true) ? 'Cette facture est déjà payée : rien à régler.' : 'Cette facture ne se règle pas en ligne.');
}

$r = ak_connect_start_checkout($pdo, [
    'org_id' => (int)$inv['org_id'], 'kind' => 'invoice', 'invoice_id' => (int)$inv['id'],
    'amount_cents' => (int)$inv['amount_ttc_cents'], 'currency' => (string)($inv['currency'] ?? 'EUR') ?: 'EUR',
    'label' => 'Facture ' . $inv['invoice_number'] . ' — ' . $inv['org_name'],
    'description' => 'Facture ' . $inv['invoice_number'] . ' (' . $inv['org_name'] . ')',
    'payer_name' => (string)($inv['client_name'] ?? ''), 'payer_email' => (string)($inv['client_email'] ?? ''),
    'cancel_url' => ak_stripe_site_base() . $back . '?annule=1#payer',
    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
]);
if (!$r['ok']) $fail($r['error']);
header('Location: ' . $r['url'], true, 303);
exit;
