<?php
/**
 * mon-asso-recurrence-delete.php
 * --------------------------------------------------------------
 * Suppression d'une récurrence — Pack PHASE 3
 * Les factures déjà générées NE SONT PAS supprimées (FK SET NULL côté invoice)
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

try {
    $pdo->beginTransaction();

    // Détacher les factures générées (on garde l'historique)
    $pdo->prepare("UPDATE asso_invoices SET recurrence_id = NULL WHERE recurrence_id = :id AND org_id = :o")
        ->execute([':id' => $rec_id, ':o' => $org_id]);

    // Supprimer les runs (historique)
    $pdo->prepare("DELETE FROM asso_invoice_recurrence_runs WHERE recurrence_id = :id")
        ->execute([':id' => $rec_id]);

    // Supprimer la récurrence
    $pdo->prepare("DELETE FROM asso_invoice_recurrences WHERE id = :id AND org_id = :o")
        ->execute([':id' => $rec_id, ':o' => $org_id]);

    $pdo->commit();
    $_SESSION['flash_success'] = 'Récurrence supprimée. Les factures déjà générées sont conservées.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = 'Erreur : ' . $e->getMessage();
}
header('Location: /mon-asso-recurrences');
exit;
