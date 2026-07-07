<?php
/**
 * api/app-create-invoice.php — Creation d'une facture depuis l'app (natif).
 * Reutilise ak_asso_invoice_create() du site (logique testee : client + lignes + snapshots).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';

if (!function_exists('ak_asso_invoice_create')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
}

// Client : soit existant (client_id), soit nouveau (display_name + email)
$client = [];
$client_id = (int) ($input['client_id'] ?? 0);
if ($client_id > 0) {
    // Verifier qu'il appartient bien a l'org
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

// Lignes
$lines = [];
foreach ((array) ($input['lines'] ?? []) as $l) {
    $des = trim((string) ($l['designation'] ?? ''));
    if ($des === '') continue;
    $lines[] = [
        'designation'   => mb_substr($des, 0, 500),
        'quantity'      => (float) ($l['quantity'] ?? 1),
        'unit_price_ht' => (float) ($l['unit_price_ht'] ?? 0),
        'vat_rate'      => (isset($l['vat_rate']) && $l['vat_rate'] !== '' && $l['vat_rate'] !== null) ? (float) $l['vat_rate'] : null,
    ];
}
if (!$lines) app_fail(422, 'invalid', 'Ajoutez au moins une ligne.');

$status = in_array(($input['status'] ?? 'pending'), ['draft', 'pending'], true) ? $input['status'] : 'pending';
$due_days = (int) ($input['due_days'] ?? 30);
if ($due_days < 0 || $due_days > 365) $due_days = 30;

try {
    $res = ak_asso_invoice_create($pdo, $org_id, $uid, [
        'client'   => $client,
        'lines'    => $lines,
        'status'   => $status,
        'due_days' => $due_days,
    ]);
    echo json_encode([
        'ok'      => true,
        'id'      => (int) ($res['id'] ?? 0),
        'number'  => (string) ($res['invoice_number'] ?? ''),
        'message' => 'Facture ' . ($res['invoice_number'] ?? '') . ' créée.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-invoice] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer la facture.');
}
