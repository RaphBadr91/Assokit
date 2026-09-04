<?php
/**
 * api/app-update-invoice.php — Édition d'une facture BROUILLON depuis l'app (natif).
 * Réutilise les helpers éprouvés du site (ak_asso_line_compute, find_or_create_client,
 * render_pdf) — même logique que mon-asso-facture-save (action=edit). Renvoie du JSON.
 *
 * Règle légale (art. 289 CGI) : seule une facture au statut 'draft' est modifiable.
 * Une facture émise est inaltérable → correction par avoir. L'endpoint refuse donc
 * toute édition d'une facture non-brouillon.
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../finance-permissions.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';

// Même contrôle d'accès que le site (mon-asso-facture-save.php) : finances réservées.
if (!function_exists('user_can_view_finances') || !user_can_view_finances($user)) {
    app_fail(403, 'role', 'Accès réservé aux administrateurs.');
}

if (!function_exists('ak_asso_line_compute')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
}

$invoice_id = (int) ($input['invoice_id'] ?? 0);
if ($invoice_id <= 0) app_fail(422, 'invalid', 'Facture manquante.');

// Charger la facture (scopée à l'org) + garde brouillon-seul
$st = $pdo->prepare("SELECT id, status, invoice_number FROM asso_invoices WHERE id = ? AND org_id = ? LIMIT 1");
$st->execute([$invoice_id, $org_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) app_fail(404, 'not_found', 'Facture introuvable.');
if ((string) $inv['status'] !== 'draft') {
    app_fail(409, 'locked', 'Cette facture est finalisée : elle ne peut plus être modifiée. Pour la corriger, émettez un avoir.');
}

// Client : existant (client_id) ou nouveau (display_name + email)
$client_id = (int) ($input['client_id'] ?? 0);
if ($client_id > 0) {
    $st = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$client_id, $org_id]);
    if (!$st->fetchColumn()) app_fail(422, 'invalid', 'Client introuvable.');
} else {
    $c = is_array($input['client'] ?? null) ? $input['client'] : [];
    $email = trim((string) ($c['email'] ?? ''));
    $name  = trim((string) ($c['display_name'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email client invalide.');
    if ($name === '') app_fail(422, 'invalid', 'Nom du client obligatoire.');
    if (!function_exists('ak_asso_find_or_create_client')) app_fail(500, 'unavailable', 'Fonction client indisponible.');
    $client_id = (int) ak_asso_find_or_create_client($pdo, $org_id, [
        'client_type'  => ($c['client_type'] ?? 'company') === 'individual' ? 'individual' : 'company',
        'display_name' => $name,
        'email'        => $email,
        'phone'        => trim((string) ($c['phone'] ?? '')) ?: null,
        'address_city' => trim((string) ($c['address_city'] ?? '')) ?: null,
        'created_by_user_id' => $uid,
    ]);
    if ($client_id <= 0) app_fail(500, 'server', 'Client impossible à enregistrer.');
}

// Lignes — normalise les décimales à virgule (clavier FR) avant le cast float.
$num = static fn($v) => (float) str_replace([' ', ','], ['', '.'], (string) $v);
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

// Valeur transmise par l'app, sinon 'pending' : on ne relit jamais une clé absente
$_status = (string) ($input['status'] ?? 'pending');
$status = in_array($_status, ['draft', 'pending'], true) ? $_status : 'pending';
$due_days = (int) ($input['due_days'] ?? 30);
if ($due_days < 0 || $due_days > 365) $due_days = 30;

try {
    // Totaux (mêmes calculs que le site)
    $total_ht = 0; $total_vat = 0; $total_ttc = 0;
    foreach ($lines as $ln) {
        $comp = ak_asso_line_compute($ln['quantity'], $ln['unit_price_ht'], $ln['vat_rate']);
        $total_ht  += $comp['total_ht_cents'];
        $total_vat += $comp['total_vat_cents'];
        $total_ttc += $comp['total_ttc_cents'];
    }

    $issued_at = date('Y-m-d H:i:s');
    $due_at    = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $due_days . ' days'));

    // Snapshot client à jour (le PDF lit le snapshot, pas le client live).
    $cs = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? LIMIT 1");
    $cs->execute([$client_id]);
    $client_full = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
    $client_snap = $client_full ? json_encode($client_full, JSON_UNESCAPED_UNICODE) : null;

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE asso_invoices SET
            client_id = :cli, issued_at = :issued, due_at = :due,
            amount_ht_cents = :ht, amount_vat_cents = :vat, amount_ttc_cents = :ttc,
            status = :status, client_snapshot = COALESCE(:snap, client_snapshot), updated_at = NOW()
        WHERE id = :id AND org_id = :org
    ")->execute([
        ':cli' => $client_id, ':issued' => $issued_at, ':due' => $due_at,
        ':ht' => $total_ht, ':vat' => $total_vat, ':ttc' => $total_ttc,
        ':status' => $status, ':snap' => $client_snap, ':id' => $invoice_id, ':org' => $org_id,
    ]);

    $pdo->prepare("DELETE FROM asso_invoice_lines WHERE invoice_id = ?")->execute([$invoice_id]);
    $insL = $pdo->prepare("
        INSERT INTO asso_invoice_lines
            (invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
             total_ht_cents, total_vat_cents, total_ttc_cents)
        VALUES (:inv, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc)
    ");
    foreach ($lines as $i => $ln) {
        $comp = ak_asso_line_compute($ln['quantity'], $ln['unit_price_ht'], $ln['vat_rate']);
        $insL->execute([
            ':inv' => $invoice_id, ':ord' => $i, ':des' => $ln['designation'],
            ':qty' => $ln['quantity'], ':pu' => (int) round($ln['unit_price_ht'] * 100),
            ':vat' => $ln['vat_rate'],
            ':tht' => $comp['total_ht_cents'], ':tvat' => $comp['total_vat_cents'], ':tttc' => $comp['total_ttc_cents'],
        ]);
    }

    $pdo->commit();

    // Régénération du PDF (best-effort, hors transaction)
    if (function_exists('ak_asso_invoice_render_pdf')) {
        try { ak_asso_invoice_render_pdf($pdo, $invoice_id); } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok'      => true,
        'id'      => $invoice_id,
        'number'  => (string) ($inv['invoice_number'] ?? ''),
        'message' => 'Facture ' . ($inv['invoice_number'] ?? '') . ' modifiée.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    error_log('[app-update-invoice] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de modifier la facture.');
}
