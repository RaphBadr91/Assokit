<?php
/**
 * api/app-client.php — Detail d'un client (profil TPE) pour l'ecran natif.
 * JSON, authentifie par session, scope a l'org du user. NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

$INV_META = [
    'paid'      => ['label' => 'Payée',      'kind' => 'done'],
    'pending'   => ['label' => 'En attente', 'kind' => 'wait'],
    'overdue'   => ['label' => 'En retard',  'kind' => 'late'],
    'draft'     => ['label' => 'Brouillon',  'kind' => 'draft'],
    'cancelled' => ['label' => 'Annulée',    'kind' => 'off'],
    'refunded'  => ['label' => 'Remboursée', 'kind' => 'off'],
];

function fd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    // Parité site (mon-asso-client-detail.php, require_billing_access) : fiche client et
    // facturation ouvertes aux admins, fondateur, super-admin et coordinateurs.
    @require_once __DIR__ . '/../finance-permissions.php';
    @require_once __DIR__ . '/../platform-flags.php';
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    $is_admin = function_exists('user_can_access_billing') && user_can_access_billing($user);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    // Sans droit Facturation : rien du fichier client (le site refuse la fiche), pas même
    // le nom ; réponse identique que le client existe ou non. Même forme qu'avant pour l'app.
    if (!$is_admin) {
        echo json_encode([
            'ok' => true,
            'allowed' => false,
            'admin' => false,
            'client' => [
                'id'       => $id,
                'name'     => 'Client',
                'initials' => '?',
                'type'     => 'company',
                'email'    => '', 'phone' => '', 'city' => '', 'address' => '',
                'siren'    => '', 'vat_number' => '',
            ],
            'stats'    => ['nb' => 0, 'total' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0],
            'invoices' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? AND org_id = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1");
    $stmt->execute([$id, $org_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $name = trim((string) ($c['display_name'] ?? '')) ?: (string) ($c['email'] ?? 'Client');
    $ini = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 2) : substr($name, 0, 2));

    // Adresse composee
    $addr = array_filter([
        trim((string) ($c['address_street'] ?? '')),
        trim((string) ($c['address_complement'] ?? '')),
        trim(trim((string) ($c['address_zip'] ?? '')) . ' ' . trim((string) ($c['address_city'] ?? ''))),
    ], fn($x) => $x !== '');

    // Factures du client
    $invoices = [];
    $paid = 0; $pending = 0; $overdue = 0; $total = 0; $nb = 0;
    try {
        $st = $pdo->prepare("SELECT id, invoice_number, status, amount_ttc_cents, issued_at, due_at
                             FROM asso_invoices WHERE client_id = ? AND org_id = ? ORDER BY issued_at DESC, id DESC LIMIT 100");
        $st->execute([$id, $org_id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $status = (string) ($r['status'] ?? 'pending');
            if ($status === 'pending' && !empty($r['due_at']) && $r['due_at'] !== '0000-00-00' && strtotime($r['due_at']) < time()) {
                $status = 'overdue';
            }
            $cents = (int) ($r['amount_ttc_cents'] ?? 0);
            if (in_array($status, ['paid', 'pending', 'overdue'], true)) { $total += $cents; $nb++; }
            if ($status === 'paid') $paid += $cents;
            elseif ($status === 'overdue') $overdue += $cents;
            elseif ($status === 'pending') $pending += $cents;
            $meta = $INV_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
            $invoices[] = [
                'id'           => (int) $r['id'],
                'number'       => (string) ($r['invoice_number'] ?? ('FACT-' . $r['id'])),
                'amount'       => $cents / 100,
                'status'       => $status,
                'status_label' => $meta['label'],
                'status_kind'  => $meta['kind'],
                'date'         => fd($r['issued_at'] ?? null),
            ];
        }
    } catch (Throwable $e) {}

    // Indicateurs : même calcul que la fiche client du site (toutes les factures émises,
    // brouillons et annulées exclus), et non sur les 100 dernières de la liste.
    @require_once __DIR__ . '/../asso-invoice-helpers.php';
    if (function_exists('ak_asso_client_stats')) {
        try {
            $cs = ak_asso_client_stats($pdo, $id, $org_id);
            $nb      = (int) $cs['nb_invoices'];
            $total   = (int) $cs['total_ttc_cents'];
            $paid    = (int) $cs['paid_ttc_cents'];
            $pending = (int) $cs['pending_ttc_cents'];
            $overdue = (int) $cs['overdue_ttc_cents'];
        } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok' => true,
        'allowed' => true,
        'admin' => true,
        'client' => [
            'id'         => (int) $c['id'],
            'name'       => $name,
            'initials'   => $ini !== '' ? $ini : '?',
            'type'       => (string) ($c['client_type'] ?? 'company'),
            'email'      => (string) ($c['email'] ?? ''),
            'phone'      => (string) ($c['phone'] ?? ''),
            'city'       => (string) ($c['address_city'] ?? ''),
            'address'    => implode("\n", $addr),
            'siren'      => (string) ($c['siren'] ?? ''),
            'vat_number' => (string) ($c['vat_number'] ?? ''),
        ],
        'stats' => [
            'nb'      => $nb,
            'total'   => $total / 100,
            'paid'    => $paid / 100,
            'pending' => $pending / 100,
            'overdue' => $overdue / 100,
        ],
        'invoices' => $invoices,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-client] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
