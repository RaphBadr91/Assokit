<?php
/**
 * mon-asso-devis-save.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-devis'); exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('Session expirée.');
}

$action = $_POST['action'] ?? 'create';

$lines_post = $_POST['lines'] ?? [];
$lines = [];
foreach ($lines_post as $line) {
    $designation = trim((string)($line['designation'] ?? ''));
    if ($designation === '') continue;
    $lines[] = [
        'designation' => mb_substr($designation, 0, 500),
        'quantity' => (float) str_replace(',', '.', $line['quantity'] ?? '1'),
        'unit_price_ht' => (float) str_replace(',', '.', $line['unit_price_ht'] ?? '0'),
        'vat_rate' => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
    ];
}

if (empty($lines)) {
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Au moins une ligne est requise.'];
    header('Location: /mon-asso-devis-new'); exit;
}

$client_data = [
    'id' => (int)($_POST['client_id'] ?? 0) ?: null,
    'client_type' => in_array($_POST['client_type'] ?? '', ['individual', 'company']) ? $_POST['client_type'] : 'company',
    'display_name' => trim((string)($_POST['display_name'] ?? '')),
    'legal_name' => trim((string)($_POST['legal_name'] ?? '')) ?: null,
    'contact_first_name' => trim((string)($_POST['contact_first_name'] ?? '')) ?: null,
    'contact_last_name' => trim((string)($_POST['contact_last_name'] ?? '')) ?: null,
    'email' => trim((string)($_POST['email'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
    'address_street' => trim((string)($_POST['address_street'] ?? '')) ?: null,
    'address_complement' => trim((string)($_POST['address_complement'] ?? '')) ?: null,
    'address_zip' => trim((string)($_POST['address_zip'] ?? '')) ?: null,
    'address_city' => trim((string)($_POST['address_city'] ?? '')) ?: null,
    'siren' => trim((string)($_POST['siren'] ?? '')) ?: null,
];

if (empty($client_data['display_name']) || empty($client_data['email'])) {
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Nom et email obligatoires.'];
    header('Location: /mon-asso-devis-new'); exit;
}

$issued_at = !empty($_POST['issued_at']) ? $_POST['issued_at'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
$validity_days = (int)($_POST['validity_days'] ?? 30);
$status = !empty($_POST['save_draft']) ? 'draft' : 'sent';

try {
    if ($action === 'edit' && !empty($_POST['quote_id'])) {
        $quote_id = (int)$_POST['quote_id'];
        $stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE id = :id AND org_id = :org LIMIT 1");
        $stmt->execute([':id' => $quote_id, ':org' => $org_id]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new RuntimeException('Devis introuvable.');

        // On ne peut pas éditer un devis signé
        if ($quote['status'] === 'signed' || $quote['status'] === 'converted') {
            throw new RuntimeException('Ce devis est ' . ($quote['status'] === 'signed' ? 'signé' : 'converti') . ' et ne peut plus être modifié.');
        }

        $client_id = (int)($client_data['id'] ?? 0);
        if (!$client_id) {
            $client_id = ak_asso_find_or_create_client($pdo, $org_id, array_merge($client_data, ['created_by_user_id' => (int)$user['id']]));
        }

        $total_ht = 0; $total_vat = 0; $total_ttc = 0;
        foreach ($lines as $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $total_ht += $c['total_ht_cents'];
            $total_vat += $c['total_vat_cents'];
            $total_ttc += $c['total_ttc_cents'];
        }

        $expires_at = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $validity_days . ' days'));

        // Le PDF lit client_snapshot : on le rafraîchit à chaque édition (cf. facture-save).
        $client_snap = null;
        try {
            $cs = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? LIMIT 1");
            $cs->execute([$client_id]);
            $cfull = $cs->fetch(PDO::FETCH_ASSOC);
            if ($cfull) $client_snap = json_encode($cfull, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {}

        $pdo->prepare("UPDATE asso_quotes SET client_id=:cli, client_snapshot=COALESCE(:snap, client_snapshot), issued_at=:iss, expires_at=:exp, amount_ht_cents=:ht, amount_vat_cents=:vat, amount_ttc_cents=:ttc, status=:status, description=:desc, terms=:terms, internal_notes=:notes, updated_at=NOW() WHERE id=:id")
            ->execute([
                ':cli' => $client_id, ':snap' => $client_snap, ':iss' => $issued_at, ':exp' => $expires_at,
                ':ht' => $total_ht, ':vat' => $total_vat, ':ttc' => $total_ttc,
                ':status' => $status,
                ':desc' => trim((string)($_POST['description'] ?? '')) ?: null,
                ':terms' => trim((string)($_POST['terms'] ?? '')) ?: null,
                ':notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
                ':id' => $quote_id,
            ]);

        $pdo->prepare("DELETE FROM asso_quote_lines WHERE quote_id = :id")->execute([':id' => $quote_id]);
        $stmt = $pdo->prepare("INSERT INTO asso_quote_lines (quote_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate, total_ht_cents, total_vat_cents, total_ttc_cents) VALUES (:q, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc)");
        foreach ($lines as $i => $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $stmt->execute([
                ':q' => $quote_id, ':ord' => $i,
                ':des' => $line['designation'], ':qty' => $line['quantity'],
                ':pu' => (int) round($line['unit_price_ht'] * 100),
                ':vat' => $line['vat_rate'],
                ':tht' => $c['total_ht_cents'], ':tvat' => $c['total_vat_cents'], ':tttc' => $c['total_ttc_cents'],
            ]);
        }

        $pdf_path = ak_asso_quote_render_pdf($pdo, $quote_id);

        $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => 'Devis ' . $quote['quote_number'] . ' modifié.', 'pdf_link' => $pdf_path];
        header('Location: /mon-asso-devis-edit?id=' . $quote_id); exit;

    } else {
        $result = ak_asso_quote_create($pdo, $org_id, (int)$user['id'], [
            'client' => $client_data, 'lines' => $lines,
            'issued_at' => $issued_at, 'validity_days' => $validity_days,
            'status' => $status,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'terms' => trim((string)($_POST['terms'] ?? '')) ?: null,
            'internal_notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
        ]);
        $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => 'Devis ' . $result['quote_number'] . ' créé.', 'pdf_link' => $result['pdf_path']];
        header('Location: /mon-asso-devis'); exit;
    }
} catch (Throwable $e) {
    error_log('[ASSO QUOTE SAVE] ' . $e->getMessage());
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    header('Location: /mon-asso-devis-new'); exit;
}
