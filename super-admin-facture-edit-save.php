<?php
/**
 * super-admin-facture-edit-save.php
 * v3 : Reçoit TTC + taux TVA, recalcule HT et TVA automatiquement
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/org-billing-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';

require_login();
$user = current_user();

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

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
    http_response_code(404);
    die('Facture introuvable.');
}

// Lecture champs
$description = trim((string)($_POST['description'] ?? ''));
$description = $description === '' ? null : mb_substr($description, 0, 255);

$amount_ttc = (float) str_replace(',', '.', $_POST['amount_ttc'] ?? '0');
$amount_ttc_cents = (int) round($amount_ttc * 100);
if ($amount_ttc_cents < 0) $amount_ttc_cents = 0;

$vat_rate_input = (float) str_replace(',', '.', $_POST['vat_rate'] ?? '0');

// Calcul auto HT et TVA depuis TTC
if ($vat_rate_input > 0 && $amount_ttc_cents > 0) {
    $amount_ht_cents = (int) round($amount_ttc_cents / (1 + $vat_rate_input / 100));
    $amount_vat_cents = $amount_ttc_cents - $amount_ht_cents;
    $vat_rate_db = $vat_rate_input;
} else {
    $amount_ht_cents = $amount_ttc_cents;
    $amount_vat_cents = 0;
    $vat_rate_db = null;
}

$period_start = !empty($_POST['period_start']) ? $_POST['period_start'] : null;
$period_end   = !empty($_POST['period_end'])   ? $_POST['period_end']   : null;
$due_at = !empty($_POST['due_at']) ? $_POST['due_at'] . ' 23:59:59' : $invoice['due_at'];

$valid_statuses = ['draft', 'pending', 'paid', 'overdue', 'cancelled', 'refunded'];
$status = in_array($_POST['status'] ?? '', $valid_statuses, true) ? $_POST['status'] : $invoice['status'];

$internal_notes = trim((string)($_POST['internal_notes'] ?? ''));
$internal_notes = $internal_notes === '' ? null : mb_substr($internal_notes, 0, 5000);

$paid_at = $invoice['paid_at'];
if ($status === 'paid' && empty($paid_at)) {
    $paid_at = date('Y-m-d H:i:s');
} elseif ($status !== 'paid') {
    $paid_at = null;
}

try {
    $stmt = $pdo->prepare("
        UPDATE invoices SET
            description = :desc,
            amount_ht_cents = :ht,
            vat_rate = :vat_rate,
            amount_vat_cents = :vat,
            amount_ttc_cents = :ttc,
            period_start = :pstart,
            period_end = :pend,
            due_at = :due,
            status = :status,
            internal_notes = :notes,
            paid_at = :paid_at,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':desc'    => $description,
        ':ht'      => $amount_ht_cents,
        ':vat_rate' => $vat_rate_db,
        ':vat'     => $amount_vat_cents,
        ':ttc'     => $amount_ttc_cents,
        ':pstart'  => $period_start,
        ':pend'    => $period_end,
        ':due'     => $due_at,
        ':status'  => $status,
        ':notes'   => $internal_notes,
        ':paid_at' => $paid_at,
        ':id'      => $invoice_id,
    ]);

    // Régénérer PDF
    try {
        ak_render_invoice_pdf($pdo, $invoice_id);
        $msg = 'Facture mise à jour et PDF régénéré avec succès.';
    } catch (Throwable $e) {
        error_log('[INVOICE EDIT] PDF regen error : ' . $e->getMessage());
        $msg = 'Facture mise à jour, mais erreur lors de la régénération du PDF : ' . $e->getMessage();
    }

    $_SESSION['flash_invoice_edit'] = ['type' => 'success', 'message' => $msg];
    $_SESSION['flash_factures'] = ['type' => 'success', 'message' => 'Facture ' . $invoice['invoice_number'] . ' modifiée avec succès.'];

} catch (Throwable $e) {
    error_log('[INVOICE EDIT] Save error : ' . $e->getMessage());
    $_SESSION['flash_invoice_edit'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
}

header('Location: /super-admin-facture-edit.php?id=' . $invoice_id);
exit;
