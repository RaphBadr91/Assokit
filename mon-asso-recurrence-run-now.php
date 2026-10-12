<?php
/**
 * mon-asso-recurrence-run-now.php
 * --------------------------------------------------------------
 * Force la génération immédiate d'une facture depuis une récurrence
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, mot de passe provisoire
ak_session_guard();
require_once __DIR__ . '/asso-recurrence-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_login();
$user = current_user();
if (!$user) { header('Location: /login'); exit; }
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /mon-asso-recurrences'); exit; }

require_once __DIR__ . '/finance-permissions.php';
require_billing_access();
if (!check_csrf($_POST['csrf_token'] ?? '')) { header('Location: /mon-asso-recurrences?error=csrf'); exit; }

$rec_id = (int)($_POST['id'] ?? 0);
$rec = ak_recurrence_load($pdo, $rec_id, $org_id);
if (!$rec) { header('Location: /mon-asso-recurrences'); exit; }
$back = (($_POST['return'] ?? '') === 'edit') ? '/mon-asso-recurrence-edit?id=' . $rec_id : '/mon-asso-recurrences';

// Récurrence terminée ou annulée : plus aucune facture
if (in_array((string)$rec['status'], ['ended', 'cancelled'], true) || ($why = ak_recurrence_end_reason($rec))) {
    ak_recurrence_flash('error', 'Aucune facture générée : cette récurrence est terminée'
        . (!empty($why) ? ' (' . $why . ')' : '') . '. Modifiez-la pour la prolonger.');
    header('Location: ' . $back);
    exit;
}

// Génération de l'échéance prévue, comme le cron : la récurrence avance d'une période
// (le cron ne refacture pas cette échéance) et la génération entre dans l'historique.
$result = ak_recurrence_run_one($pdo, $rec, (int)$user['id']);

if ($result['ok']) {
    $fin = $result['finalize'];
    $ok  = !$fin['auto_send'] || $fin['sent'];
    if (!$ok) {
        $msg = 'Facture ' . $result['invoice_number'] . ' générée, mais elle n’a pas pu être envoyée au client : '
             . preg_replace('/^Envoi impossible : /', '', (string)$fin['send_message']) . '. Envoyez-la depuis cette page.';
    } elseif ($fin['auto_send']) {
        $msg = 'Facture ' . $result['invoice_number'] . ' générée et envoyée. ' . $fin['send_message'] . '.';
    } else {
        $msg = 'Facture ' . $result['invoice_number'] . ' générée en brouillon : vérifiez-la puis envoyez-la au client.';
    }
    if (!empty($result['next_run_date'])) {
        $msg .= ' Prochaine facture de la récurrence : le ' . date('d/m/Y', strtotime($result['next_run_date'])) . '.';
    }
    // La page d'édition affiche flash_asso_factures (succès ou avertissement).
    $_SESSION['flash_asso_factures'] = ['type' => $ok ? 'success' : 'error', 'message' => $msg, 'pdf_link' => $fin['pdf_path']];
    header('Location: /mon-asso-facture-edit?id=' . (int)$result['invoice_id']);
    exit;
} else {
    ak_recurrence_flash('error', $result['code'] === 'busy'
        ? $result['error']
        : 'Aucune facture générée : ' . ($result['error'] ?? 'erreur inconnue'));
    header('Location: ' . $back);
    exit;
}
