<?php
/**
 * api/app-update-quote.php — Édition d'un devis depuis l'app (natif).
 * Réutilise les helpers éprouvés (ak_asso_line_compute, find_or_create_client,
 * ak_asso_quote_render_pdf) — même logique que mon-asso-devis-save (action=edit).
 * Renvoie du JSON. NE MODIFIE PAS le site.
 *
 * Règle : un devis SIGNÉ ou CONVERTI n'est plus modifiable (parité web).
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';
@require_once __DIR__ . '/../asso-quote-helpers.php';

if (!function_exists('ak_asso_line_compute')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
}

$quote_id = (int) ($input['quote_id'] ?? 0);
if ($quote_id <= 0) app_fail(422, 'invalid', 'Devis manquant.');

// Charger le devis (scopé à l'org) + garde signé/converti
$st = $pdo->prepare("SELECT id, status, quote_number FROM asso_quotes WHERE id = ? AND org_id = ? LIMIT 1");
$st->execute([$quote_id, $org_id]);
$q = $st->fetch(PDO::FETCH_ASSOC);
if (!$q) app_fail(404, 'not_found', 'Devis introuvable.');
if (in_array((string) $q['status'], ['signed', 'converted'], true)) {
    app_fail(409, 'locked', 'Ce devis est ' . ($q['status'] === 'signed' ? 'signé' : 'converti') . ' : il ne peut plus être modifié.');
}

// Client : existant (client_id) ou nouveau (display_name + email)
$client_id = (int) ($input['client_id'] ?? 0);
if ($client_id > 0) {
    $cs = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
    $cs->execute([$client_id, $org_id]);
    if (!$cs->fetchColumn()) app_fail(422, 'invalid', 'Client introuvable.');
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

$status = in_array(($input['status'] ?? 'sent'), ['draft', 'sent'], true) ? $input['status'] : 'sent';
$validity_days = (int) ($input['validity_days'] ?? 30);
if ($validity_days < 0 || $validity_days > 365) $validity_days = 30;

try {
    $total_ht = 0; $total_vat = 0; $total_ttc = 0;
    foreach ($lines as $ln) {
        $comp = ak_asso_line_compute($ln['quantity'], $ln['unit_price_ht'], $ln['vat_rate']);
        $total_ht  += $comp['total_ht_cents'];
        $total_vat += $comp['total_vat_cents'];
        $total_ttc += $comp['total_ttc_cents'];
    }

    $issued_at  = date('Y-m-d H:i:s');
    $expires_at = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $validity_days . ' days'));

    // Snapshot client à jour (le PDF lit le snapshot).
    $cs = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? LIMIT 1");
    $cs->execute([$client_id]);
    $client_full = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
    $client_snap = $client_full ? json_encode($client_full, JSON_UNESCAPED_UNICODE) : null;

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE asso_quotes SET
            client_id = :cli, issued_at = :iss, expires_at = :exp,
            amount_ht_cents = :ht, amount_vat_cents = :vat, amount_ttc_cents = :ttc,
            status = :status, client_snapshot = COALESCE(:snap, client_snapshot), updated_at = NOW()
        WHERE id = :id AND org_id = :org
    ")->execute([
        ':cli' => $client_id, ':iss' => $issued_at, ':exp' => $expires_at,
        ':ht' => $total_ht, ':vat' => $total_vat, ':ttc' => $total_ttc,
        ':status' => $status, ':snap' => $client_snap, ':id' => $quote_id, ':org' => $org_id,
    ]);

    $pdo->prepare("DELETE FROM asso_quote_lines WHERE quote_id = ?")->execute([$quote_id]);
    $insL = $pdo->prepare("
        INSERT INTO asso_quote_lines
            (quote_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
             total_ht_cents, total_vat_cents, total_ttc_cents)
        VALUES (:q, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc)
    ");
    foreach ($lines as $i => $ln) {
        $comp = ak_asso_line_compute($ln['quantity'], $ln['unit_price_ht'], $ln['vat_rate']);
        $insL->execute([
            ':q' => $quote_id, ':ord' => $i, ':des' => $ln['designation'],
            ':qty' => $ln['quantity'], ':pu' => (int) round($ln['unit_price_ht'] * 100),
            ':vat' => $ln['vat_rate'],
            ':tht' => $comp['total_ht_cents'], ':tvat' => $comp['total_vat_cents'], ':tttc' => $comp['total_ttc_cents'],
        ]);
    }

    $pdo->commit();

    if (function_exists('ak_asso_quote_render_pdf')) {
        try { ak_asso_quote_render_pdf($pdo, $quote_id); } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok'      => true,
        'id'      => $quote_id,
        'number'  => (string) ($q['quote_number'] ?? ''),
        'message' => 'Devis ' . ($q['quote_number'] ?? '') . ' modifié.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    error_log('[app-update-quote] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de modifier le devis.');
}
