<?php
/**
 * super-admin-facture-delete.php
 * Suppression d'une facture (uniquement en mode TEST)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';

require_login();
$user = current_user();

// Vérif Fondateur uniquement
$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $is_founder = $row && (int)$row['is_founder'] === 1;
} catch (Throwable $e) {}

if (!$is_founder) {
    http_response_code(403);
    die('Accès réservé au Fondateur.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super-admin-factures.php');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée.');
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    header('Location: /super-admin-factures.php');
    exit;
}

// Vérif que la facture existe et qu'elle est en mode test
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    $_SESSION['flash_factures'] = ['type' => 'error', 'message' => 'Facture introuvable.'];
    header('Location: /super-admin-factures.php');
    exit;
}

if ((int)$invoice['is_test_mode'] !== 1) {
    $_SESSION['flash_factures'] = ['type' => 'error', 'message' => 'Suppression refusée : cette facture n\'est pas en mode TEST.'];
    header('Location: /super-admin-factures.php');
    exit;
}

try {
    // Supprimer le PDF physique
    if (!empty($invoice['pdf_path'])) {
        $pdf_full_path = __DIR__ . $invoice['pdf_path'];
        if (file_exists($pdf_full_path)) {
            @unlink($pdf_full_path);
        }
    }

    // Supprimer la ligne en BDD
    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = :id");
    $stmt->execute([':id' => $invoice_id]);

    error_log('[INVOICE DELETE] Facture ' . $invoice['invoice_number'] . ' supprimée par user ' . $user['id']);

    $_SESSION['flash_factures'] = [
        'type' => 'success',
        'message' => 'Facture ' . $invoice['invoice_number'] . ' supprimée définitivement.'
    ];

} catch (Throwable $e) {
    error_log('[INVOICE DELETE] Error : ' . $e->getMessage());
    $_SESSION['flash_factures'] = [
        'type' => 'error',
        'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
    ];
}

header('Location: /super-admin-factures.php');
exit;
