<?php
/**
 * api/app-cotisation-action.php — Actions sur un paiement de cotisation (natif).
 * Reproduit action-cotisation-payment.php (mark_paid, cancel). JSON.
 *
 * Rôle : admin ou coordinateur pour mark_paid ; admin seul pour cancel (parité web).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

$role = (string) ($user['role'] ?? '');
$is_admin = ($role === 'admin') || !empty($user['is_founder']) || !empty($user['is_super_admin']);
$is_coord = ($role === 'coordinator');
if (!$is_admin && !$is_coord) app_fail(403, 'role', 'Rôle insuffisant.');

$payment_id = (int) ($input['payment_id'] ?? 0);
$action     = (string) ($input['action'] ?? '');
if ($payment_id <= 0) app_fail(422, 'invalid', 'Paiement manquant.');

// Scope org obligatoire
$st = $pdo->prepare("SELECT id, campaign_id, status, payer_name, amount FROM cotisation_payments WHERE id = ? AND org_id = ? LIMIT 1");
$st->execute([$payment_id, $org_id]);
$p = $st->fetch(PDO::FETCH_ASSOC);
if (!$p) app_fail(404, 'not_found', 'Paiement introuvable.');

try {
    if ($action === 'mark_paid') {
        if ((string) $p['status'] === 'paid') app_fail(409, 'state', 'Ce paiement est déjà encaissé.');
        $pdo->prepare("UPDATE cotisation_payments SET status='paid', paid_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$payment_id, $org_id]);
        echo json_encode([
            'ok' => true, 'campaign_id' => (int) $p['campaign_id'],
            'message' => 'Paiement de ' . trim((string) $p['payer_name']) . ' encaissé ('
                . number_format((float) $p['amount'], 2, ',', ' ') . ' €).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'cancel') {
        if (!$is_admin) app_fail(403, 'role', 'Seul un administrateur peut annuler un paiement.');
        if ((string) $p['status'] === 'cancelled') app_fail(409, 'state', 'Ce paiement est déjà annulé.');
        $pdo->prepare("UPDATE cotisation_payments SET status='cancelled' WHERE id=? AND org_id=?")
            ->execute([$payment_id, $org_id]);
        echo json_encode([
            'ok' => true, 'campaign_id' => (int) $p['campaign_id'],
            'message' => 'Paiement annulé.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    app_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    error_log('[app-cotisation-action] ' . $e->getMessage());
    app_fail(500, 'server', 'Action impossible.');
}
