<?php
/**
 * mon-asso-facture-save.php
 * Endpoint POST création/édition de facture
 * v2 : URLs propres (sans .php) pour éviter le bug 301 du htaccess
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) {
    http_response_code(403);
    die('Aucune association associée.');
}
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $role = $stmt->fetchColumn();
    $is_admin = in_array($role, ['admin', 'coordinator'], true);
} catch (Throwable $e) {}

if (!$is_admin) {
    http_response_code(403);
    die('Accès réservé aux administrateurs.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-factures');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée.');
}

$action = $_POST['action'] ?? 'create';

// Lignes
$lines_post = $_POST['lines'] ?? [];
if (!is_array($lines_post) || count($lines_post) === 0) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Au moins une ligne est requise.'];
    header('Location: /mon-asso-facture-new');
    exit;
}

$lines = [];
foreach ($lines_post as $line) {
    $designation = trim((string)($line['designation'] ?? ''));
    if ($designation === '') continue;
    $lines[] = [
        'designation'   => mb_substr($designation, 0, 500),
        'quantity'      => (float) str_replace(',', '.', $line['quantity'] ?? '1'),
        'unit_price_ht' => (float) str_replace(',', '.', $line['unit_price_ht'] ?? '0'),
        'vat_rate'      => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
    ];
}

if (empty($lines)) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Renseignez au moins une désignation.'];
    header('Location: /mon-asso-facture-new');
    exit;
}

// Client
$client_data = [
    'id'                 => (int)($_POST['client_id'] ?? 0) ?: null,
    'client_type'        => in_array($_POST['client_type'] ?? '', ['individual', 'company']) ? $_POST['client_type'] : 'company',
    'display_name'       => trim((string)($_POST['display_name'] ?? '')),
    'legal_name'         => trim((string)($_POST['legal_name'] ?? '')) ?: null,
    'contact_first_name' => trim((string)($_POST['contact_first_name'] ?? '')) ?: null,
    'contact_last_name'  => trim((string)($_POST['contact_last_name'] ?? '')) ?: null,
    'email'              => trim((string)($_POST['email'] ?? '')),
    'phone'              => trim((string)($_POST['phone'] ?? '')) ?: null,
    'address_street'     => trim((string)($_POST['address_street'] ?? '')) ?: null,
    'address_complement' => trim((string)($_POST['address_complement'] ?? '')) ?: null,
    'address_zip'        => trim((string)($_POST['address_zip'] ?? '')) ?: null,
    'address_city'       => trim((string)($_POST['address_city'] ?? '')) ?: null,
    'siren'              => trim((string)($_POST['siren'] ?? '')) ?: null,
];

if (empty($client_data['display_name']) || empty($client_data['email'])) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Nom et email du client obligatoires.'];
    header('Location: /mon-asso-facture-new');
    exit;
}

$issued_at = !empty($_POST['issued_at']) ? $_POST['issued_at'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
$due_days  = (int)($_POST['due_days'] ?? 30);
$status = !empty($_POST['save_draft']) ? 'draft' : 'pending';

try {
    if ($action === 'edit' && !empty($_POST['invoice_id'])) {
        $invoice_id = (int)$_POST['invoice_id'];
        $stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
        $stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new RuntimeException('Facture introuvable.');

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

        $due_at = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $due_days . ' days'));

        $pdo->prepare("
            UPDATE asso_invoices SET
                client_id = :cli,
                issued_at = :issued, due_at = :due,
                amount_ht_cents = :ht, amount_vat_cents = :vat, amount_ttc_cents = :ttc,
                status = :status,
                description = :desc, internal_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ':cli' => $client_id,
            ':issued' => $issued_at,
            ':due' => $due_at,
            ':ht' => $total_ht,
            ':vat' => $total_vat,
            ':ttc' => $total_ttc,
            ':status' => $status,
            ':desc' => trim((string)($_POST['description'] ?? '')) ?: null,
            ':notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
            ':id' => $invoice_id,
        ]);

        $pdo->prepare("DELETE FROM asso_invoice_lines WHERE invoice_id = :id")->execute([':id' => $invoice_id]);
        $stmt = $pdo->prepare("
            INSERT INTO asso_invoice_lines (
                invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                total_ht_cents, total_vat_cents, total_ttc_cents
            ) VALUES (
                :inv, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc
            )
        ");
        foreach ($lines as $i => $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $stmt->execute([
                ':inv'  => $invoice_id,
                ':ord'  => $i,
                ':des'  => $line['designation'],
                ':qty'  => $line['quantity'],
                ':pu'   => (int) round($line['unit_price_ht'] * 100),
                ':vat'  => $line['vat_rate'],
                ':tht'  => $c['total_ht_cents'],
                ':tvat' => $c['total_vat_cents'],
                ':tttc' => $c['total_ttc_cents'],
            ]);
        }

        $pdf_path = ak_asso_invoice_render_pdf($pdo, $invoice_id);

        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => 'Facture ' . $invoice['invoice_number'] . ' modifiée.',
            'pdf_link' => $pdf_path,
        ];

        header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
        exit;

    } else {
        // ────── CRÉATION ──────
        $result = ak_asso_invoice_create($pdo, $org_id, (int)$user['id'], [
            'client'       => $client_data,
            'lines'        => $lines,
            'issued_at'    => $issued_at,
            'due_days'     => $due_days,
            'status'       => $status,
            'description'  => trim((string)($_POST['description'] ?? '')) ?: null,
            'internal_notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
        ]);

        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => 'Facture ' . $result['invoice_number'] . ' créée avec succès.',
            'pdf_link' => $result['pdf_path'],
        ];

        header('Location: /mon-asso-factures');
        exit;
    }
} catch (Throwable $e) {
    error_log('[ASSO INVOICE SAVE] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['flash_asso_factures'] = [
        'type' => 'error',
        'message' => 'Erreur : ' . $e->getMessage(),
    ];
    header('Location: /mon-asso-facture-new');
    exit;
}
