<?php
/**
 * api/app-grant.php — Detail d'une subvention pour l'ecran natif.
 * Memes helpers que subvention-detail.php (etapes, journal). JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
require_once __DIR__ . '/../includes-grants.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$G_KIND = ['draft' => 'draft', 'submitted' => 'wait', 'in_review' => 'wait', 'granted' => 'done',
           'rejected' => 'late', 'reported' => 'done', 'archived' => 'off'];

function grd($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    $can_manage = in_array($role, ['admin', 'coordinator'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
    $is_admin = ($role === 'admin') || !empty($user['is_founder']) || !empty($user['is_super_admin']);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }
    if (!function_exists('gr_load')) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'unavailable']); exit; }

    $g = gr_load($pdo, $id, $org_id);
    if (!$g) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $status = (string) ($g['status'] ?? 'draft');
    $meta = gr_status_meta($status);

    $steps = [];
    foreach (gr_load_steps($pdo, $id) as $s) {
        $steps[] = [
            'id'    => (int) $s['id'],
            'title' => (string) $s['title'],
            'done'  => !empty($s['is_completed']),
            'done_at' => grd($s['completed_at'] ?? null),
        ];
    }

    $activity = [];
    try {
        $st = $pdo->prepare("SELECT l.*, u.first_name, u.last_name FROM grant_activity_log l
                             LEFT JOIN users u ON u.id = l.user_id
                             WHERE l.grant_id = ? ORDER BY l.created_at DESC LIMIT 30");
        $st->execute([$id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $l) {
            $activity[] = [
                'label' => (string) ($l['action_label'] ?? ''),
                'who'   => trim((string) ($l['first_name'] ?? '') . ' ' . (string) ($l['last_name'] ?? '')),
                'when'  => grd($l['created_at'] ?? null),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'can_manage' => $can_manage,
        'is_admin'   => $is_admin,
        'grant' => [
            'id'           => (int) $g['id'],
            'name'         => (string) $g['name'],
            'funder'       => (string) ($g['funder'] ?? ''),
            'funder_type'  => function_exists('gr_funder_label') ? gr_funder_label((string) ($g['funder_type'] ?? 'autre')) : (string) ($g['funder_type'] ?? ''),
            'status'       => $status,
            'status_label' => $meta[0],
            'status_kind'  => $G_KIND[$status] ?? 'wait',
            'requested'    => $g['amount_requested'] !== null ? (float) $g['amount_requested'] : null,
            'granted'      => $g['amount_granted'] !== null ? (float) $g['amount_granted'] : null,
            'deadline'     => grd($g['deadline_apply'] ?? null),
            'submitted_at' => grd($g['submitted_at'] ?? null),
            'decision_at'  => grd($g['decision_at'] ?? null),
            'deadline_report' => grd($g['deadline_report'] ?? null),
            'project'      => (string) ($g['project_name'] ?? ''),
            'project_id'   => (int) ($g['project_id'] ?? 0),
            'description'  => (string) ($g['description'] ?? ''),
            'notes'        => (string) ($g['notes'] ?? ''),
            'cerfa'        => (string) ($g['cerfa_number'] ?? ''),
            'reference'    => (string) ($g['reference'] ?? ''),
            'platform'     => (string) ($g['platform'] ?? ''),
            'platform_url' => (string) ($g['platform_url'] ?? ''),
            'contact_name' => (string) ($g['contact_name'] ?? ''),
            'contact_email' => (string) ($g['contact_email'] ?? ''),
            'contact_phone' => (string) ($g['contact_phone'] ?? ''),
            'last_relance' => grd($g['last_relance_at'] ?? null),
            'archived'     => !empty($g['archived_at']),
        ],
        'steps'    => $steps,
        'activity' => $activity,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-grant] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
