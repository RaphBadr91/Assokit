<?php
/**
 * api/app-founder-billing-action.php — Marquer une facture d'abonnement payée (Fondateur).
 * POST { invoice_id:int, action:'mark_paid', csrf }. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$id = (int) ($input['invoice_id'] ?? 0);
$action = (string) ($input['action'] ?? '');
if ($id <= 0) app_fail(400, 'invoice_id');
if ($action !== 'mark_paid') app_fail(400, 'action');

try {
    try {
        $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE subscription_invoices SET status='paid' WHERE id=?")->execute([$id]);
    }
    echo json_encode(['ok' => true, 'invoice_id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-billing-action] ' . $e->getMessage());
    app_fail(500, 'server');
}
