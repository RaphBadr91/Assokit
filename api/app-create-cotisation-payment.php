<?php
/**
 * api/app-create-cotisation-payment.php — Enregistrer un paiement de cotisation
 * depuis l'app (natif). Reproduit fidèlement action-cotisation-payment.php (action=create).
 * Renvoie du JSON. NE MODIFIE PAS le site.
 *
 * Rôle requis : admin ou coordinateur (parité web).
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../includes-cotisations.php';

if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Rôle insuffisant pour enregistrer un paiement.');
}
if (!function_exists('ck_load_campaign')) {
    app_fail(500, 'unavailable', 'Module cotisations indisponible.');
}

// Campagne (scopée à l'org)
$campaign_id = (int) ($input['campaign_id'] ?? 0);
$campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
if (!$campaign) app_fail(404, 'not_found', 'Campagne introuvable.');

// Normalise les décimales à virgule (clavier FR) avant le cast float.
$num = static fn($v) => (float) str_replace([' ', ','], ['', '.'], (string) $v);

$tier_id     = (int) ($input['tier_id'] ?? 0) ?: null;
$adherent_id = (int) ($input['adherent_id'] ?? 0) ?: null;
$payer_name  = trim((string) ($input['payer_name'] ?? ''));
$payer_email = trim((string) ($input['payer_email'] ?? '')) ?: null;
$amount      = $num($input['amount'] ?? 0);
$method      = in_array(($input['payment_method'] ?? ''), ['stripe', 'bank', 'check', 'cash', 'other'], true) ? $input['payment_method'] : 'other';
$status      = in_array(($input['status'] ?? ''), ['paid', 'pending', 'refunded', 'cancelled'], true) ? $input['status'] : 'paid';
$reference   = trim((string) ($input['reference'] ?? '')) ?: null;
$notes       = trim((string) ($input['notes'] ?? '')) ?: null;
if ($payer_email !== null && !filter_var($payer_email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email du payeur invalide.');

// Date de paiement : YYYY-MM-DD (sinon aujourd'hui)
$paid_at = (string) ($input['paid_at'] ?? '');
if ($paid_at && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_at)) $paid_at = '';
$paid_at_full = ($status === 'paid')
    ? (($paid_at ?: date('Y-m-d')) . ' ' . date('H:i:s'))
    : null;

// Cohérence de l'adhérent : doit appartenir à l'org (parité avec le select web sur `users`)
if ($adherent_id) {
    $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? LIMIT 1");
    $chk->execute([$adherent_id, $org_id]);
    if (!$chk->fetchColumn()) $adherent_id = null;
}
// Cohérence du tarif : doit appartenir à la campagne
if ($tier_id) {
    $chk = $pdo->prepare("SELECT id FROM cotisation_tiers WHERE id = ? AND campaign_id = ? LIMIT 1");
    $chk->execute([$tier_id, $campaign_id]);
    if (!$chk->fetchColumn()) $tier_id = null;
}

if ($payer_name === '') app_fail(422, 'invalid', 'Le nom du payeur est obligatoire.');
if ($amount <= 0)       app_fail(422, 'invalid', 'Le montant doit être supérieur à 0.');

try {
    $stmt = $pdo->prepare("INSERT INTO cotisation_payments
        (campaign_id, tier_id, org_id, adherent_id, payer_name, payer_email, amount, currency, payment_method, status, reference, notes, paid_at, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $campaign_id, $tier_id, $org_id, $adherent_id, $payer_name, $payer_email,
        $amount, ($campaign['currency'] ?? 'EUR'), $method, $status, $reference, $notes, $paid_at_full, $uid,
    ]);

    echo json_encode([
        'ok'      => true,
        'id'      => (int) $pdo->lastInsertId(),
        'message' => 'Paiement de ' . number_format($amount, 2, ',', ' ') . ' € enregistré pour ' . $payer_name . '.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-cotisation-payment] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible d\'enregistrer le paiement.');
}
