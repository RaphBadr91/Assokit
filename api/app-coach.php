<?php
/**
 * api/app-coach.php — Dernier rapport Coach IA pour l'ecran natif. JSON, scope org, admin.
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
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'founder', 'super_admin'], true) && empty($user['is_founder'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $report = null;
    try {
        $stmt = $pdo->prepare("SELECT summary_md, highlights_json, warnings_json, recos_json, week_start, week_end, generated_at
                               FROM coach_reports WHERE org_id = ? ORDER BY week_start DESC LIMIT 1");
        $stmt->execute([$org_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $dec = fn($j) => is_array($v = json_decode((string) $j, true)) ? $v : [];
            $ws = strtotime((string) ($r['week_start'] ?? ''));
            $we = strtotime((string) ($r['week_end'] ?? ''));
            $report = [
                'summary'    => (string) ($r['summary_md'] ?? ''),
                'highlights' => array_values(array_filter(array_map('strval', $dec($r['highlights_json'])))),
                'warnings'   => array_values(array_filter(array_map('strval', $dec($r['warnings_json'])))),
                'recos'      => array_map(fn($x) => [
                    'icon'  => (string) ($x['icon'] ?? '🎯'),
                    'title' => (string) ($x['title'] ?? ''),
                    'why'   => (string) ($x['why'] ?? ''),
                ], array_slice($dec($r['recos_json']), 0, 3)),
                'week'       => ($ws && $we) ? (date('d/m', $ws) . ' → ' . date('d/m/Y', $we)) : '',
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'allowed' => true, 'report' => $report], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-coach] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
