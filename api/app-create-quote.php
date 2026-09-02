<?php
/**
 * api/app-create-quote.php — Creation d'un devis depuis l'app (natif).
 * Reutilise ak_asso_quote_create() du site (logique testee).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-quote-helpers.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';

// Même contrôle de rôle que le site (mon-asso-devis-save.php) : admin ou coordinateur.
if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Rôle insuffisant pour créer un devis.');
}

if (!function_exists('ak_asso_quote_create')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
}

// Normalise les décimales à virgule (clavier FR) avant le cast float : (float)"12,50" vaudrait 12.
$num = static fn($v) => (float) str_replace([' ', ','], ['', '.'], (string) $v);

$client = [];
$client_id = (int) ($input['client_id'] ?? 0);
if ($client_id > 0) {
    $st = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$client_id, $org_id]);
    if (!$st->fetchColumn()) app_fail(422, 'invalid', 'Client introuvable.');
    $client = ['id' => $client_id];
} else {
    $c = is_array($input['client'] ?? null) ? $input['client'] : [];
    $email = trim((string) ($c['email'] ?? ''));
    $name  = trim((string) ($c['display_name'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email client invalide.');
    if ($name === '') app_fail(422, 'invalid', 'Nom du client obligatoire.');
    $client = [
        'client_type'  => ($c['client_type'] ?? 'company') === 'individual' ? 'individual' : 'company',
        'display_name' => $name,
        'email'        => $email,
        'phone'        => trim((string) ($c['phone'] ?? '')) ?: null,
        'address_city' => trim((string) ($c['address_city'] ?? '')) ?: null,
    ];
}

$lines = [];
foreach ((array) ($input['lines'] ?? []) as $l) {
    $des = trim((string) ($l['designation'] ?? ''));
    if ($des === '') continue;
    $lines[] = [
        'designation'   => mb_substr($des, 0, 500),
        'quantity'      => $num($l['quantity'] ?? 1),
        'unit_price_ht' => $num($l['unit_price_ht'] ?? 0),
        'vat_rate'      => (isset($l['vat_rate']) && $l['vat_rate'] !== '' && $l['vat_rate'] !== null) ? $num($l['vat_rate']) : null,
    ];
}
if (!$lines) app_fail(422, 'invalid', 'Ajoutez au moins une ligne.');

$status = in_array(($input['status'] ?? 'draft'), ['draft', 'sent'], true) ? $input['status'] : 'draft';

try {
    // Validité saisie dans l'app (le helper la lit ; 30 j par défaut, borné 0-365)
    $validity_days = (int) ($input['validity_days'] ?? 30);
    if ($validity_days < 0 || $validity_days > 365) $validity_days = 30;

    $res = ak_asso_quote_create($pdo, $org_id, $uid, [
        'client'        => $client,
        'lines'         => $lines,
        'status'        => $status,
        'validity_days' => $validity_days,
    ]);
    echo json_encode([
        'ok'      => true,
        'id'      => (int) ($res['id'] ?? 0),
        'number'  => (string) ($res['quote_number'] ?? ''),
        'message' => 'Devis ' . ($res['quote_number'] ?? '') . ' créé.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-quote] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer le devis.');
}
