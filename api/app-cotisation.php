<?php
/**
 * api/app-cotisation.php — Detail d'une campagne de cotisation pour l'ecran natif.
 * Memes helpers que cotisation-detail.php (stats, tarifs, paiements). JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
require_once __DIR__ . '/../includes-cotisations.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

function ckd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    // Parité cotisation-detail.php : gestion réservée admin/coordinateur
    $can_manage = in_array($role, ['admin', 'coordinator'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    if (!function_exists('ck_load_campaign')) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'unavailable']); exit; }

    $c = ck_load_campaign($pdo, $id, $org_id);
    if (!$c) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $stats = ck_campaign_stats($pdo, $id);
    $tiers = [];
    foreach (ck_load_tiers($pdo, $id) as $t) {
        $tiers[] = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'amount' => (float) $t['amount'], 'description' => (string) ($t['description'] ?? '')];
    }

    $payments = [];
    foreach (ck_load_payments($pdo, $id) as $p) {
        $b = ck_status_badge((string) $p['status']);
        $name = !empty($p['adherent_id']) ? trim(($p['a_first'] ?? '') . ' ' . ($p['a_last'] ?? '')) : '';
        if ($name === '') $name = (string) ($p['payer_name'] ?? '');
        $payments[] = [
            'id'           => (int) $p['id'],
            'name'         => $name !== '' ? $name : 'Sans nom',
            'email'        => (string) ($p['payer_email'] ?? ''),
            'amount'       => (float) $p['amount'],
            'tier'         => (string) ($p['tier_name'] ?? ''),
            'method'       => (string) ($p['payment_method'] ?? ''),
            'method_label' => ck_method_label((string) ($p['payment_method'] ?? 'other')),
            'status'       => (string) $p['status'],
            'status_label' => $b[0],
            'status_color' => $b[1],
            'status_bg'    => $b[2],
            'paid_at'      => ckd($p['paid_at'] ?? null),
            'created_at'   => ckd($p['created_at'] ?? null),
            'reference'    => (string) ($p['reference'] ?? ''),
            'notes'        => (string) ($p['notes'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'can_manage' => $can_manage,
        'campaign' => [
            'id'          => (int) $c['id'],
            'name'        => (string) $c['name'],
            'year'        => (int) ($c['year'] ?? 0),
            'active'      => !empty($c['is_active']),
            'description' => (string) ($c['description'] ?? ''),
            'closes_at'   => ckd($c['closes_at'] ?? null),
            'currency'    => (string) ($c['currency'] ?? 'EUR'),
        ],
        'stats' => [
            'amount_paid'    => (float) $stats['amount_paid'],
            'amount_pending' => (float) $stats['amount_pending'],
            'count_paid'     => (int) $stats['count_paid'],
            'count_pending'  => (int) $stats['count_pending'],
        ],
        'tiers'    => $tiers,
        'payments' => $payments,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-cotisation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
