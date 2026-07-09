<?php
/**
 * api/app-events.php — Agenda (evenements a venir) pour l'ecran natif.
 * JSON, lecture seule, scope org. NE MODIFIE PAS le site.
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

$TYPE_COLOR = [
    'meeting' => 'blue', 'workshop' => 'purple', 'public' => 'green',
    'internal' => 'teal', 'deadline' => 'red', 'other' => 'amber',
];
$DAYS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$MONTHS = ['', 'janv.', 'févr.', 'mars', 'avril', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $now = date('Y-m-d 00:00:00');
    $end = date('Y-m-d 23:59:59', strtotime('+120 days'));

    $stmt = $pdo->prepare("
        SELECT e.id, e.title, e.location, e.event_type, e.color_theme, e.sync_origin,
               e.starts_at, e.ends_at, e.is_all_day, e.project_id,
               p.name AS project_name, f.color_theme AS folder_color
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN folders f ON p.folder_id = f.id
        WHERE e.org_id = ? AND e.deleted_at IS NULL
          AND e.ends_at >= ? AND e.starts_at <= ?
        ORDER BY e.starts_at ASC
        LIMIT 150
    ");
    $stmt->execute([$org_id, $now, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $GPAL = ['#7986CB', '#33B679', '#8E24AA', '#E67C73', '#F6BF26', '#039BE5', '#3F51B5', '#0B8043', '#D50000', '#F4511E'];
    $events = [];
    foreach ($rows as $r) {
        $ct = trim((string) ($r['color_theme'] ?? ''));
        if ($ct !== '' && $ct[0] === '#') {
            $color = $ct; // couleur Google explicite
        } elseif (($r['sync_origin'] ?? '') === 'google') {
            $b = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $r['title'])) : strtolower(trim((string) $r['title']));
            $color = $b === '' ? $GPAL[0] : $GPAL[abs(crc32($b)) % count($GPAL)];
        } else {
            $theme = $ct;
            if ($theme === '') $theme = trim((string) ($r['folder_color'] ?? ''));
            if ($theme === '') $theme = $TYPE_COLOR[$r['event_type'] ?? 'other'] ?? 'blue';
            $color = function_exists('folder_color_hex') ? folder_color_hex($theme) : '#3B82F6';
        }

        $ts = strtotime((string) $r['starts_at']);
        $day_key = date('Y-m-d', $ts);
        $wd = (int) date('w', $ts);
        $day_label = $DAYS[$wd] . ' ' . (int) date('j', $ts) . ' ' . ($MONTHS[(int) date('n', $ts)] ?? '');
        $time = !empty($r['is_all_day']) ? 'Journée' : date('H:i', $ts);

        $events[] = [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'location' => (string) ($r['location'] ?? ''),
            'project'  => (string) ($r['project_name'] ?? ''),
            'color'    => $color,
            'all_day'  => !empty($r['is_all_day']),
            'time'     => $time,
            'day_key'  => $day_key,
            'day_label'=> $day_label,
        ];
    }

    echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
