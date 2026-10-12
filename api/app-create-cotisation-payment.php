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

if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)
    && empty($user['is_founder']) && empty($user['is_super_admin'])) {
    app_fail(403, 'role', 'Rôle insuffisant pour enregistrer un paiement.');
}
if (!function_exists('ck_load_campaign')) {
    app_fail(500, 'unavailable', 'Module cotisations indisponible.');
}

// Campagne (scopée à l'org)
$campaign_id = (int) ($input['campaign_id'] ?? 0);
$campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
if (!$campaign) app_fail(404, 'not_found', 'Campagne introuvable.');

// Montant saisi au clavier FR : « 12,50 », « 1 234,50 », « 1.234,50 », « 25 € ». Renvoie null
// si ce n'est pas un nombre (« abc » était enregistré à 0 puis refusé avec un message trompeur).
$num = static function ($v): ?float {
    $t = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim((string) $v));
    if (strpos($t, ',') !== false) $t = str_replace(['.', ','], ['', '.'], $t);
    return is_numeric($t) ? (float) $t : null;
};

$tier_id     = (int) ($input['tier_id'] ?? 0) ?: null;
$adherent_id = (int) ($input['adherent_id'] ?? 0) ?: null;
$payer_name  = trim((string) ($input['payer_name'] ?? ''));
$payer_email = trim((string) ($input['payer_email'] ?? '')) ?: null;
$amount      = $num($input['amount'] ?? '');
$method      = in_array(($input['payment_method'] ?? ''), ['stripe', 'bank', 'check', 'cash', 'other'], true) ? $input['payment_method'] : 'other';
$status      = in_array(($input['status'] ?? ''), ['paid', 'pending', 'refunded', 'cancelled'], true) ? $input['status'] : 'paid';
$reference   = trim((string) ($input['reference'] ?? '')) ?: null;
$notes       = trim((string) ($input['notes'] ?? '')) ?: null;
if ($payer_email !== null && !filter_var($payer_email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email du payeur invalide.');

// Date de paiement (paiement « payé » seulement) : AAAA-MM-JJ ou JJ/MM/AAAA, comme l'affiche
// l'appli ; vide = aujourd'hui. Une date illisible est refusée au lieu d'être remplacée en
// silence par la date du jour (le paiement partait sur le mauvais mois ou le mauvais exercice).
$paid_at = '';
$paid_raw = trim((string) ($input['paid_at'] ?? ''));
if ($status === 'paid' && $paid_raw !== '') {
    $y = $mo = $d = 0;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $paid_raw, $m)) {
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $paid_raw, $m)) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
    if (!$y || !checkdate($mo, $d, $y)) app_fail(422, 'invalid', 'Date du paiement invalide : utilisez JJ/MM/AAAA.');
    $paid_at = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    // La date d'encaissement doit rester dans un exercice plausible (les KPI sont rattachés à paid_at)
    if ($paid_at > date('Y-m-d', strtotime('+1 day'))) app_fail(422, 'invalid', 'La date du paiement ne peut pas être dans le futur.');
    if ($paid_at < date('Y-m-d', strtotime('-5 years'))) app_fail(422, 'invalid', 'Date du paiement trop ancienne (5 ans au plus).');
}
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
if ($amount === null)   app_fail(422, 'invalid', 'Montant invalide : saisissez un nombre (ex. 12,50).');
if ($amount <= 0)       app_fail(422, 'invalid', 'Le montant doit être supérieur à 0.');
$payer_name = mb_substr($payer_name, 0, 120);

try {
    $stmt = $pdo->prepare("INSERT INTO cotisation_payments
        (campaign_id, tier_id, org_id, adherent_id, payer_name, payer_email, amount, currency, payment_method, status, reference, notes, paid_at, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $campaign_id, $tier_id, $org_id, $adherent_id, $payer_name, $payer_email,
        $amount, ($campaign['currency'] ?? 'EUR'), $method, $status, $reference, $notes, $paid_at_full, $uid,
    ]);

    $new_id = (int) $pdo->lastInsertId();

    // Cotisation payée : l'adhérent est à jour (plus de relance, « À jour » sur sa fiche).
    $until = ($status === 'paid' && $adherent_id && function_exists('ck_extend_membership'))
        ? ck_extend_membership($pdo, $org_id, $adherent_id, $campaign, $paid_at_full)
        : null;
    $not_extended = !$until && $status === 'paid' && $adherent_id && function_exists('ck_campaign_renews_membership')
        && ck_campaign_renews_membership($campaign) && ck_membership_end($campaign, $paid_at_full) === null;

    echo json_encode([
        'ok'      => true,
        'id'      => $new_id,
        'message' => 'Paiement de ' . (function_exists('ck_money') ? ck_money($amount, $campaign['currency'] ?? 'EUR') : number_format($amount, 2, ',', ' ') . ' €') . ' enregistré pour ' . $payer_name . '.'
                   . ($until ? ' Adhésion valable jusqu’au ' . date('d/m/Y', strtotime($until)) . '.' : '')
                   . ($not_extended ? ' Adhésion non prolongée : la campagne était close à la date du paiement.' : ''),
        'adhesion_until' => $until,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-cotisation-payment] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible d\'enregistrer le paiement.');
}
