<?php
/**
 * api/app-founder-activity.php — Journal d'activité de la plateforme (Fondateur, lecture seule).
 * GET → dernières actions (platform_activity_log) enrichies (org + libellé). NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

function act_info(string $a): array {
    $map = [
        'founder_validate' => ['✅', 'Association validée', '#059669'],
        'founder_activate' => ['▶', 'Réactivation', '#059669'],
        'founder_suspend'  => ['⏸', 'Suspension', '#B45309'],
        'founder_reject'   => ['✕', 'Refus', '#DC2626'],
        'founder_resiliate'=> ['✕', 'Résiliation', '#DC2626'],
        'founder_edit'     => ['✏️', 'Édition', '#2563EB'],
        'org_created'      => ['🏢', 'Organisation créée', '#059669'],
        'user_login'       => ['🔑', 'Connexion', '#64748B'],
        'invoice_paid'     => ['💰', 'Facture payée', '#059669'],
    ];
    return $map[$a] ?? ['•', $a, '#94A3B8'];
}

try {
    $rows = [];
    try {
        $st = $pdo->query("
            SELECT l.*, o.name AS org_name,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS actor
            FROM platform_activity_log l
            LEFT JOIN organizations o ON l.org_id = o.id
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC LIMIT 120");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            [$icon, $label, $color] = act_info((string) ($l['action'] ?? ''));
            $when = !empty($l['created_at']) ? date('d/m/Y H:i', strtotime($l['created_at'])) : '';
            $rows[] = [
                'icon'  => $icon,
                'label' => $label,
                'color' => $color,
                'org'   => (string) ($l['org_name'] ?? ($l['org_name_snapshot'] ?? '')),
                'actor' => trim((string) ($l['actor'] ?? '')),
                'detail'=> (string) ($l['detail'] ?? ($l['description'] ?? '')),
                'when'  => $when,
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'activity' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-activity] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
