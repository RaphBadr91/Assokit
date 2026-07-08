<?php
/**
 * api/app-assemblies.php — Assemblées (AG) pour l'ecran natif. JSON, scope org, admin.
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

$AG_META = [
    'draft'       => ['label' => 'Brouillon', 'kind' => 'draft'],
    'sent'        => ['label' => 'Convoquée', 'kind' => 'wait'],
    'in_progress' => ['label' => 'En cours',  'kind' => 'done'],
    'closed'      => ['label' => 'Clôturée',  'kind' => 'off'],
    'archived'    => ['label' => 'Archivée',  'kind' => 'off'],
];
$AG_TYPE = ['ag_ord' => 'AG Ordinaire', 'ag_ext' => 'AG Extraordinaire', 'ca' => 'Conseil d\'Administration', 'bureau' => 'Bureau'];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'founder', 'super_admin'], true) && empty($user['is_founder'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, title, type, scheduled_at, location, status FROM assemblies WHERE org_id = ? AND archived_at IS NULL ORDER BY scheduled_at DESC LIMIT 200");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $upcoming = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'draft');
        $meta = $AG_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $ts = strtotime((string) $r['scheduled_at']);
        if (in_array($status, ['draft', 'sent'], true) && $ts >= time()) $upcoming++;
        $items[] = [
            'id'           => (int) $r['id'],
            'title'        => (string) $r['title'],
            'type'         => $AG_TYPE[$r['type'] ?? ''] ?? '',
            'date'         => $ts ? date('d/m/Y H:i', $ts) : '',
            'location'     => (string) ($r['location'] ?? ''),
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
        ];
    }

    echo json_encode(['ok' => true, 'allowed' => true, 'stats' => ['total' => count($items), 'upcoming' => $upcoming], 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-assemblies] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
