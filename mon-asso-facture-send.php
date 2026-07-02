<?php
/**
 * mon-asso-facture-send.php
 * Envoie une facture au client par email
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-invoice-email-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) {
    http_response_code(403); die('Aucune asso.');
}
$org_id = (int)$user['org_id'];

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('factures', 'l\'envoi de factures');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-factures'); exit;
}

$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419); exit('Session expirée.');
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$email_type = $_POST['email_type'] ?? 'initial';
if ($invoice_id <= 0) {
    header('Location: /mon-asso-factures'); exit;
}

// Vérif appartenance
$stmt = $pdo->prepare("SELECT id FROM asso_invoices WHERE id = :id AND org_id = :org");
$stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
if (!$stmt->fetchColumn()) {
    http_response_code(404); die('Facture introuvable.');
}

try {
    $result = ak_asso_invoice_send_email($pdo, $invoice_id, $email_type, (int)$user['id']);
    $_SESSION['flash_asso_factures'] = [
        'type' => $result['success'] ? 'success' : 'error',
        'message' => $result['message'],
    ];
} catch (Throwable $e) {
    error_log('[ASSO INVOICE SEND] ' . $e->getMessage());
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
}

header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
exit;
