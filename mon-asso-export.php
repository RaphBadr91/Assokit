<?php
/**
 * mon-asso-export.php — Export CSV (compatible Excel)
 * Types : invoice, quote, client
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-search-helpers.php';

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

$type = $_GET['type'] ?? 'invoice';
$period = $_GET['period'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$tag_id = (int)($_GET['tag'] ?? 0);
$min = !empty($_GET['min']) ? (float)$_GET['min'] : null;
$max = !empty($_GET['max']) ? (float)$_GET['max'] : null;

[$dateStart, $dateEnd] = ak_search_period_dates($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

// Fonction CSV avec BOM UTF-8 pour Excel
function ak_export_csv(string $filename, array $headers, array $rows): void {
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

if ($type === 'invoice') {
    $sql = "SELECT i.*, c.display_name AS client_name, c.email AS client_email, c.address_zip, c.address_city, c.siren AS client_siren FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id WHERE i.org_id = :org";
    $params = [':org' => $org_id];
    if ($status !== 'all' && in_array($status, ['draft','pending','paid','overdue','cancelled'])) {
        $sql .= " AND i.status = :st"; $params[':st'] = $status;
    }
    if ($dateStart) { $sql .= " AND i.issued_at >= :ds"; $params[':ds'] = $dateStart; }
    if ($dateEnd) { $sql .= " AND i.issued_at <= :de"; $params[':de'] = $dateEnd; }
    if ($min !== null) { $sql .= " AND i.amount_ttc_cents >= :min"; $params[':min'] = (int)round($min * 100); }
    if ($max !== null) { $sql .= " AND i.amount_ttc_cents <= :max"; $params[':max'] = (int)round($max * 100); }
    if ($tag_id > 0) {
        $sql .= " AND i.id IN (SELECT entity_id FROM asso_tag_links WHERE entity_type='invoice' AND tag_id = :tid)";
        $params[':tid'] = $tag_id;
    }
    $sql .= " ORDER BY i.issued_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($invoices as $i) {
        $rows[] = [
            $i['invoice_number'],
            date('d/m/Y', strtotime($i['issued_at'])),
            !empty($i['due_at']) ? date('d/m/Y', strtotime($i['due_at'])) : '',
            $i['client_name'] ?? '',
            $i['client_email'] ?? '',
            $i['address_zip'] ?? '',
            $i['address_city'] ?? '',
            $i['client_siren'] ?? '',
            number_format($i['amount_ht_cents'] / 100, 2, ',', ''),
            number_format($i['amount_vat_cents'] / 100, 2, ',', ''),
            number_format($i['amount_ttc_cents'] / 100, 2, ',', ''),
            ['draft'=>'Brouillon','pending'=>'En attente','paid'=>'Payée','overdue'=>'En retard','cancelled'=>'Annulée'][$i['status']] ?? $i['status'],
            !empty($i['paid_at']) ? date('d/m/Y', strtotime($i['paid_at'])) : '',
        ];
    }

    ak_export_csv('factures_assokit', [
        'N° facture', 'Date émission', 'Date échéance', 'Client', 'Email', 'CP', 'Ville', 'SIREN',
        'Montant HT', 'TVA', 'Montant TTC', 'Statut', 'Date paiement'
    ], $rows);
}

if ($type === 'quote') {
    $sql = "SELECT q.*, c.display_name AS client_name, c.email AS client_email, c.address_zip, c.address_city FROM asso_quotes q LEFT JOIN asso_clients c ON c.id = q.client_id WHERE q.org_id = :org";
    $params = [':org' => $org_id];
    if ($status !== 'all' && in_array($status, ['draft','sent','signed','refused','expired','converted','cancelled'])) {
        $sql .= " AND q.status = :st"; $params[':st'] = $status;
    }
    if ($dateStart) { $sql .= " AND q.issued_at >= :ds"; $params[':ds'] = $dateStart; }
    if ($dateEnd) { $sql .= " AND q.issued_at <= :de"; $params[':de'] = $dateEnd; }
    if ($min !== null) { $sql .= " AND q.amount_ttc_cents >= :min"; $params[':min'] = (int)round($min * 100); }
    if ($max !== null) { $sql .= " AND q.amount_ttc_cents <= :max"; $params[':max'] = (int)round($max * 100); }
    if ($tag_id > 0) {
        $sql .= " AND q.id IN (SELECT entity_id FROM asso_tag_links WHERE entity_type='quote' AND tag_id = :tid)";
        $params[':tid'] = $tag_id;
    }
    $sql .= " ORDER BY q.issued_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($quotes as $q) {
        $statusLbl = ['draft'=>'Brouillon','sent'=>'Envoyé','signed'=>'Signé','refused'=>'Refusé','expired'=>'Expiré','converted'=>'Converti','cancelled'=>'Annulé'][$q['status']] ?? $q['status'];
        $rows[] = [
            $q['quote_number'],
            date('d/m/Y', strtotime($q['issued_at'])),
            date('d/m/Y', strtotime($q['expires_at'])),
            $q['client_name'] ?? '',
            $q['client_email'] ?? '',
            $q['address_zip'] ?? '',
            $q['address_city'] ?? '',
            number_format($q['amount_ht_cents'] / 100, 2, ',', ''),
            number_format($q['amount_vat_cents'] / 100, 2, ',', ''),
            number_format($q['amount_ttc_cents'] / 100, 2, ',', ''),
            $statusLbl,
            !empty($q['signed_at']) ? date('d/m/Y', strtotime($q['signed_at'])) : '',
            $q['signature_name'] ?? '',
        ];
    }

    ak_export_csv('devis_assokit', [
        'N° devis', 'Date émission', 'Validité', 'Client', 'Email', 'CP', 'Ville',
        'Montant HT', 'TVA', 'Montant TTC', 'Statut', 'Date signature', 'Signataire'
    ], $rows);
}

if ($type === 'client') {
    $sql = "
        SELECT c.*,
               (SELECT COUNT(*) FROM asso_invoices WHERE client_id = c.id) AS nb_invoices,
               (SELECT COUNT(*) FROM asso_quotes WHERE client_id = c.id) AS nb_quotes,
               (SELECT COALESCE(SUM(amount_ttc_cents),0) FROM asso_invoices WHERE client_id = c.id AND status='paid') AS total_paid
        FROM asso_clients c
        WHERE c.org_id = :org AND c.deleted_at IS NULL
        ORDER BY c.display_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':org' => $org_id]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($clients as $c) {
        $rows[] = [
            $c['display_name'],
            $c['client_type'] === 'individual' ? 'Particulier' : 'Entreprise/Asso',
            $c['legal_name'] ?? '',
            $c['contact_first_name'] ?? '',
            $c['contact_last_name'] ?? '',
            $c['email'],
            $c['phone'] ?? '',
            $c['address_street'] ?? '',
            $c['address_zip'] ?? '',
            $c['address_city'] ?? '',
            $c['siren'] ?? '',
            (int)$c['nb_invoices'],
            (int)$c['nb_quotes'],
            number_format($c['total_paid'] / 100, 2, ',', ''),
        ];
    }

    ak_export_csv('clients_assokit', [
        'Nom affiché', 'Type', 'Raison sociale', 'Prénom contact', 'Nom contact',
        'Email', 'Téléphone', 'Rue', 'CP', 'Ville', 'SIREN',
        'Nb factures', 'Nb devis', 'CA encaissé'
    ], $rows);
}

http_response_code(400);
die('Type d\'export inconnu.');
