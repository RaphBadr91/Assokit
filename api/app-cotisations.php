<?php
/**
 * api/app-cotisations.php — Campagnes de cotisations pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
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

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.year, c.is_active, c.closes_at,
          (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'paid') AS count_paid,
          (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'pending') AS count_pending,
          (SELECT COALESCE(SUM(amount),0) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'paid') AS total_paid
        FROM cotisation_campaigns c
        WHERE c.org_id = ? AND c.archived_at IS NULL
        ORDER BY c.is_active DESC, c.year DESC, c.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $campaigns = [];
    $total_year = 0; $active = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_active'])) $active++;
        $tp = (float) ($r['total_paid'] ?? 0);
        $total_year += $tp;
        $campaigns[] = [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'year'    => (int) ($r['year'] ?? 0),
            'active'  => !empty($r['is_active']),
            'paid'    => (int) ($r['count_paid'] ?? 0),
            'pending' => (int) ($r['count_pending'] ?? 0),
            'total'   => $tp,
        ];
    }

    echo json_encode([
        'ok' => true,
        'stats' => ['total' => $total_year, 'active' => $active, 'nb' => count($campaigns)],
        'campaigns' => $campaigns,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-cotisations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
