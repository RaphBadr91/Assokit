<?php
/**
 * mon-asso-recurrence-run-now.php
 * --------------------------------------------------------------
 * Force la génération immédiate d'une facture depuis une récurrence
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-recurrence-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_login();
$user = current_user();
if (!$user) { header('Location: /login'); exit; }
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /mon-asso-recurrences'); exit; }

$rec_id = (int)($_POST['id'] ?? 0);
$rec = ak_recurrence_load($pdo, $rec_id, $org_id);
if (!$rec) { header('Location: /mon-asso-recurrences'); exit; }

$result = ak_recurrence_generate_invoice($pdo, $rec_id);

if ($result['ok']) {
    $msg = 'Facture ' . $result['invoice_number'] . ' générée avec succès.';

    // Si auto_send : tenter envoi (best effort, ne bloque pas)
    if (!empty($result['auto_send'])) {
        try {
            if (file_exists(__DIR__ . '/asso-invoice-email-helpers.php')) {
                require_once __DIR__ . '/asso-invoice-email-helpers.php';
                if (function_exists('ak_send_invoice_email')) {
                    ak_send_invoice_email($pdo, (int)$result['invoice_id']);
                    $msg .= ' Email envoyé au client.';
                }
            }
        } catch (Throwable $e) { /* silent — la facture est créée */ }
    }
    $_SESSION['flash_success'] = $msg;
    header('Location: /mon-asso-facture-edit?id=' . (int)$result['invoice_id']);
    exit;
} else {
    $_SESSION['flash_error'] = 'Erreur génération : ' . ($result['error'] ?? 'inconnue');
    header('Location: /mon-asso-recurrences');
    exit;
}
