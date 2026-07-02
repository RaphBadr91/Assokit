<?php
/**
 * mon-asso-devis-convert.php — Conversion devis signé → facture
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-devis'); exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('Session expirée.');
}

$quote_id = (int)($_POST['quote_id'] ?? 0);
if ($quote_id <= 0) { header('Location: /mon-asso-devis'); exit; }

// Vérif appartenance
$stmt = $pdo->prepare("SELECT id FROM asso_quotes WHERE id = :id AND org_id = :org");
$stmt->execute([':id' => $quote_id, ':org' => $org_id]);
if (!$stmt->fetchColumn()) {
    http_response_code(404); die('Devis introuvable.');
}

try {
    $invoice = ak_asso_quote_convert_to_invoice($pdo, $quote_id, (int)$user['id']);

    $_SESSION['flash_asso_factures'] = [
        'type' => 'success',
        'message' => '✅ Facture ' . $invoice['invoice_number'] . ' créée à partir du devis.',
        'pdf_link' => $invoice['pdf_path'],
    ];

    header('Location: /mon-asso-facture-edit?id=' . $invoice['id']);
    exit;

} catch (Throwable $e) {
    error_log('[ASSO QUOTE CONVERT] ' . $e->getMessage());
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    header('Location: /mon-asso-devis-edit?id=' . $quote_id);
    exit;
}
