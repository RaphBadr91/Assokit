<?php
/**
 * mon-asso-recurrence-toggle.php
 * --------------------------------------------------------------
 * Pause / Reprise / Annulation d'une récurrence — Pack PHASE 3
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
$action = (string)($_POST['action'] ?? '');

$rec = ak_recurrence_load($pdo, $rec_id, $org_id);
if (!$rec) { header('Location: /mon-asso-recurrences'); exit; }

$new_status = $rec['status'];
switch ($action) {
    case 'pause':  $new_status = 'paused'; break;
    case 'resume': $new_status = 'active'; break;
    case 'cancel': $new_status = 'cancelled'; break;
    default: header('Location: /mon-asso-recurrences'); exit;
}

try {
    $pdo->prepare("UPDATE asso_invoice_recurrences SET status = :s, updated_at = NOW() WHERE id = :id AND org_id = :o")
        ->execute([':s' => $new_status, ':id' => $rec_id, ':o' => $org_id]);
    $_SESSION['flash_success'] = 'Statut mis à jour.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erreur : ' . $e->getMessage();
}
header('Location: /mon-asso-recurrences');
exit;
