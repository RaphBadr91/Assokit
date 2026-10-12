<?php
/**
 * mon-asso-recurrence-delete.php
 * --------------------------------------------------------------
 * Suppression d'une récurrence — Pack PHASE 3
 * Les factures déjà générées NE SONT PAS supprimées (FK SET NULL côté invoice)
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

try {
    $pdo->beginTransaction();

    // Détacher les factures générées (on garde l'historique)
    $pdo->prepare("UPDATE asso_invoices SET recurrence_id = NULL WHERE recurrence_id = :id AND org_id = :o")
        ->execute([':id' => $rec_id, ':o' => $org_id]);

    // Supprimer l'historique des générations : table absente tant que la migration
    // 2026-10-11-L05-facturation.sql n'est pas passée, ce qui ne doit pas bloquer
    // la suppression (MariaDB : l'échec d'une requête n'annule pas la transaction).
    try {
        $pdo->prepare("DELETE FROM asso_invoice_recurrence_runs WHERE recurrence_id = :id")
            ->execute([':id' => $rec_id]);
    } catch (Throwable $e) {
        error_log('[mon-asso-recurrence-delete] historique : ' . $e->getMessage());
    }

    // Supprimer la récurrence
    $pdo->prepare("DELETE FROM asso_invoice_recurrences WHERE id = :id AND org_id = :o")
        ->execute([':id' => $rec_id, ':o' => $org_id]);

    $pdo->commit();
    ak_recurrence_flash('success', 'Récurrence « ' . $rec['title'] . ' » supprimée. Les factures déjà générées sont conservées.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[mon-asso-recurrence-delete] ' . $e->getMessage());
    ak_recurrence_flash('error', 'La récurrence n’a pas pu être supprimée. Réessayez dans un instant.');
}
header('Location: /mon-asso-recurrences');
exit;
