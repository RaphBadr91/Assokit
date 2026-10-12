<?php
/**
 * api/app-invoices.php — Liste des factures pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$STATUS_META = [
    'paid'      => ['label' => 'Payée',      'kind' => 'done'],
    'pending'   => ['label' => 'En attente', 'kind' => 'wait'],
    'overdue'   => ['label' => 'En retard',  'kind' => 'late'],
    'draft'     => ['label' => 'Brouillon',  'kind' => 'draft'],
    'cancelled' => ['label' => 'Annulée',    'kind' => 'off'],
    'void'      => ['label' => 'Annulée',    'kind' => 'off'],
];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    // Parité site (mon-asso-factures-client.php, require_billing_access) : factures ouvertes
    // aux admins, fondateur, super-admin et coordinateurs (finance-permissions.php).
    @require_once __DIR__ . '/../finance-permissions.php';
    @require_once __DIR__ . '/../platform-flags.php';
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    if (!function_exists('user_can_access_billing') || !user_can_access_billing($user)) {
        echo json_encode(['ok' => true, 'allowed' => false, 'invoices' => [], 'message' => 'Réservé aux administrateurs et coordinateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Totaux calculés en base sur TOUTES les factures de l'organisation (pas sur la page
    // affichée), avec les règles du hub Facturation (overdue = carte « Impayés à recouvrer »
    // de l'accueil de l'app, app-dashboard.php) :
    //   total   = factures émises (payées + en attente + en retard), brouillons et annulées exclus ;
    //   pending = en attente non échue ; overdue = échue et non payée ; unpaid = pending + overdue.
    $stmt = $pdo->prepare("
        SELECT
          COUNT(*) AS nb_all,
          COALESCE(SUM(CASE WHEN status IN ('paid','pending','overdue') THEN amount_ttc_cents ELSE 0 END),0) AS total,
          COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_ttc_cents ELSE 0 END),0) AS paid,
          COALESCE(SUM(CASE WHEN status = 'pending' AND (due_at IS NULL OR due_at >= NOW()) THEN amount_ttc_cents ELSE 0 END),0) AS pending,
          COALESCE(SUM(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_at < NOW()) THEN amount_ttc_cents ELSE 0 END),0) AS overdue,
          COALESCE(SUM(CASE WHEN status IN ('paid','pending','overdue') THEN 1 ELSE 0 END),0) AS nb,
          COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END),0) AS nb_paid,
          COALESCE(SUM(CASE WHEN status = 'pending' AND (due_at IS NULL OR due_at >= NOW()) THEN 1 ELSE 0 END),0) AS nb_pending,
          COALESCE(SUM(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_at < NOW()) THEN 1 ELSE 0 END),0) AS nb_overdue,
          COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END),0) AS nb_draft
        FROM asso_invoices WHERE org_id = ?
    ");
    $stmt->execute([$org_id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totals = [
        'total'      => ((int) ($s['total'] ?? 0)) / 100,
        'paid'       => ((int) ($s['paid'] ?? 0)) / 100,
        'pending'    => ((int) ($s['pending'] ?? 0)) / 100,
        'overdue'    => ((int) ($s['overdue'] ?? 0)) / 100,
        'unpaid'     => ((int) ($s['pending'] ?? 0) + (int) ($s['overdue'] ?? 0)) / 100,
        'nb'         => (int) ($s['nb'] ?? 0),
        'nb_paid'    => (int) ($s['nb_paid'] ?? 0),
        'nb_pending' => (int) ($s['nb_pending'] ?? 0),
        'nb_overdue' => (int) ($s['nb_overdue'] ?? 0),
        'nb_draft'   => (int) ($s['nb_draft'] ?? 0),
    ];
    $count_all = (int) ($s['nb_all'] ?? 0);

    // Pagination (?p=2, 3…) : sans paramètre, la première page, comme avant.
    $per_page = 200;
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($page - 1) * $per_page;

    // Avoirs : colonne invoice_type (migration 2026-08-12), lue seulement si elle existe
    @require_once __DIR__ . '/../trial-helpers.php';
    $type_col = (function_exists('ak_db_columns') && isset(ak_db_columns($pdo, 'asso_invoices')['invoice_type'])) ? 'i.invoice_type' : "'invoice'";
    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.status, i.amount_ttc_cents,
               i.issued_at, i.due_at, $type_col AS invoice_type,
               c.display_name AS client_name
        FROM asso_invoices i
        LEFT JOIN asso_clients c ON i.client_id = c.id AND c.org_id = i.org_id
        WHERE i.org_id = ?
        ORDER BY i.issued_at DESC, i.id DESC
        LIMIT " . (int) $per_page . " OFFSET " . (int) $offset . "
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $now = time();
    $invoices = [];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'pending');
        // Marquer en retard si echue et non payee
        if ($status === 'pending' && !empty($r['due_at'])
            && $r['due_at'] !== '0000-00-00' && strtotime($r['due_at']) < $now) {
            $status = 'overdue';
        }
        $meta = $STATUS_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $is_credit = ($r['invoice_type'] ?? 'invoice') === 'credit_note';
        if ($is_credit) $meta = ['label' => 'Avoir', 'kind' => 'off'];

        $issued = $r['issued_at'] ?? null;
        $date_fr = '';
        if (!empty($issued) && $issued !== '0000-00-00') {
            $ts = strtotime($issued);
            if ($ts) $date_fr = date('d/m/Y', $ts);
        }

        $invoices[] = [
            'id'          => (int) $r['id'],
            'number'      => (string) ($r['invoice_number'] ?? ('FACT-' . $r['id'])),
            'client'      => (string) ($r['client_name'] ?? ''),
            'amount'      => ((int) ($r['amount_ttc_cents'] ?? 0)) / 100,
            'status'      => $status,
            'status_label'=> $meta['label'],
            'status_kind' => $meta['kind'],
            'date'        => $date_fr,
            'invoice_type'=> $is_credit ? 'credit_note' : 'invoice',
        ];
    }

    // Délai de paiement par défaut des paramètres de facturation (pré-remplissage « Échéance »)
    @require_once __DIR__ . '/../asso-invoice-helpers.php';
    $default_due_days = function_exists('ak_asso_default_due_days') ? ak_asso_default_due_days($pdo, $org_id) : 30;

    echo json_encode([
        'ok'          => true,
        'allowed'     => true,
        'default_due_days' => $default_due_days,
        'invoices'    => $invoices,
        'totals'      => $totals,
        'total_count' => $count_all,
        'page'        => $page,
        'per_page'    => $per_page,
        'has_more'    => ($offset + count($invoices)) < $count_all,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-invoices] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
