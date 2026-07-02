<?php
/**
 * /action-cotisation-payment - Enregistrer / mettre à jour un paiement
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /cotisations'); exit; }
if (!check_csrf($_POST['csrf_token'] ?? '')) { header('Location: /cotisations?error=csrf'); exit; }

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    $campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
    if (!$campaign) { header('Location: /cotisations?error=notfound'); exit; }

    $tier_id = (int)($_POST['tier_id'] ?? 0) ?: null;
    $adherent_id = (int)($_POST['adherent_id'] ?? 0) ?: null;
    $payer_name = trim($_POST['payer_name'] ?? '');
    $payer_email = trim($_POST['payer_email'] ?? '') ?: null;
    $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);
    $method = in_array($_POST['payment_method'] ?? '', ['stripe','bank','check','cash','other']) ? $_POST['payment_method'] : 'other';
    $status = in_array($_POST['status'] ?? '', ['paid','pending','refunded','cancelled']) ? $_POST['status'] : 'paid';
    $reference = trim($_POST['reference'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $paid_at = $_POST['paid_at'] ?? null;
    if ($paid_at && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_at)) $paid_at = null;
    $paid_at_full = ($status === 'paid' && $paid_at) ? $paid_at . ' ' . date('H:i:s') : ($status === 'paid' ? date('Y-m-d H:i:s') : null);

    if ($payer_name === '' || $amount <= 0) { header('Location: /cotisation/' . $campaign_id . '?error=invalid'); exit; }

    $stmt = $pdo->prepare("INSERT INTO cotisation_payments (campaign_id, tier_id, org_id, adherent_id, payer_name, payer_email, amount, currency, payment_method, status, reference, notes, paid_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$campaign_id, $tier_id, $org_id, $adherent_id, $payer_name, $payer_email, $amount, $campaign['currency'], $method, $status, $reference, $notes, $paid_at_full, (int)$user['id']]);

    header('Location: /cotisation/' . $campaign_id . '?paid=1');
    exit;
}

if ($action === 'mark_paid') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE cotisation_payments SET status='paid', paid_at=NOW() WHERE id=? AND org_id=?");
    $stmt->execute([$id, $org_id]);
    $stmt = $pdo->prepare("SELECT campaign_id FROM cotisation_payments WHERE id=?");
    $stmt->execute([$id]);
    $cid = (int)($stmt->fetchColumn() ?: 0);
    header('Location: /cotisation/' . $cid);
    exit;
}

if ($action === 'cancel' && $is_admin) {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE cotisation_payments SET status='cancelled' WHERE id=? AND org_id=?");
    $stmt->execute([$id, $org_id]);
    $stmt = $pdo->prepare("SELECT campaign_id FROM cotisation_payments WHERE id=?");
    $stmt->execute([$id]);
    $cid = (int)($stmt->fetchColumn() ?: 0);
    header('Location: /cotisation/' . $cid);
    exit;
}

header('Location: /cotisations');
