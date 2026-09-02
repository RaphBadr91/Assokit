<?php
/**
 * /ical/<token>.ics — Feed iCal pour abonnement Google/Apple/Outlook
 * Pas de session, pas de cookie : auth via token uniquement.
 */
require_once __DIR__ . '/config.php';

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    die('Token invalide.');
}

// Vérifier token
$stmt = $pdo->prepare("SELECT t.id AS tok_id, t.user_id, t.label, u.org_id, u.role, u.first_name, u.last_name
                       FROM user_calendar_tokens t
                       JOIN users u ON u.id = t.user_id
                       WHERE t.token = ? AND t.revoked_at IS NULL AND u.is_active = 1
                       LIMIT 1");
$stmt->execute([$token]);
$ctx = $stmt->fetch();
if (!$ctx) { http_response_code(404); die('Token introuvable ou révoqué.'); }

$org_id  = (int)$ctx['org_id'];
$user_id = (int)$ctx['user_id'];

// Stats d'usage (silent fail)
try {
    $pdo->prepare("UPDATE user_calendar_tokens SET fetch_count = fetch_count + 1, last_used_at = NOW() WHERE id = ?")
        ->execute([(int)$ctx['tok_id']]);
} catch (Throwable $e) {}

// Charger events
// Filtrage : org + (admin/coord voient tout, sinon events de leurs projets)
$is_admin = ($ctx['role'] === 'admin' || $ctx['role'] === 'coordinator');
$events = [];
try {
    if ($is_admin) {
        $stmt = $pdo->prepare("SELECT e.*, p.name AS project_name FROM events e
                               LEFT JOIN projects p ON p.id = e.project_id
                               LEFT JOIN folders f ON f.id = p.folder_id
                               WHERE (e.org_id = ? OR f.org_id = ?) AND e.deleted_at IS NULL
                               ORDER BY e.starts_at DESC LIMIT 500");
        $stmt->execute([$org_id, $org_id]);
    } else {
        $stmt = $pdo->prepare("SELECT e.*, p.name AS project_name FROM events e
                               LEFT JOIN projects p ON p.id = e.project_id
                               LEFT JOIN folders f ON f.id = p.folder_id
                               LEFT JOIN project_members pm ON pm.project_id = e.project_id AND pm.user_id = ?
                               WHERE (e.org_id = ? OR f.org_id = ?) AND e.deleted_at IS NULL
                                 AND (pm.user_id IS NOT NULL OR p.referent_id = ? OR e.created_by = ?)
                               GROUP BY e.id
                               ORDER BY e.starts_at DESC LIMIT 500");
        $stmt->execute([$user_id, $org_id, $org_id, $user_id, $user_id]);
    }
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    // Si schéma diffère, fallback : org_id uniquement
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE org_id = ? AND deleted_at IS NULL ORDER BY starts_at DESC LIMIT 500");
        $stmt->execute([$org_id]);
        $events = $stmt->fetchAll();
    } catch (Throwable $e2) { $events = []; }
}

// Helpers iCal
function ic_esc(string $v): string {
    $v = str_replace(["\\", ",", ";", "\n", "\r"], ["\\\\", "\\,", "\\;", "\\n", ""], $v);
    return $v;
}
function ic_fold(string $line): string {
    // RFC 5545 : lignes max 75 octets, plier avec CRLF + space
    if (strlen($line) <= 75) return $line . "\r\n";
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line . "\r\n";
}
function ic_dt(string $iso, bool $allDay = false): string {
    $t = strtotime($iso);
    if (!$t) $t = time();
    return $allDay ? date('Ymd', $t) : date('Ymd\THis\Z', $t);
}

$host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
$cal_name = trim('AssoKit — ' . ($ctx['first_name'] ?? '') . ' ' . ($ctx['last_name'] ?? ''));
if ($ctx['label']) $cal_name = $ctx['label'];

// Headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="assokit.ics"');
header('Cache-Control: private, max-age=300'); // 5 min cache

// Sortie
$out  = "BEGIN:VCALENDAR\r\n";
$out .= "VERSION:2.0\r\n";
$out .= "PRODID:-//AssoKit//Calendar Sync//FR\r\n";
$out .= "CALSCALE:GREGORIAN\r\n";
$out .= "METHOD:PUBLISH\r\n";
$out .= ic_fold("X-WR-CALNAME:" . ic_esc($cal_name));
$out .= ic_fold("X-WR-TIMEZONE:Europe/Paris");
$out .= ic_fold("X-WR-CALDESC:Événements AssoKit synchronisés");

foreach ($events as $e) {
    $title = $e['title'] ?? 'Événement';
    $desc = $e['description'] ?? '';
    if (!empty($e['project_name'])) $desc = '[' . $e['project_name'] . "]\n" . $desc;
    $loc = $e['location'] ?? '';
    $all_day = !empty($e['all_day']);
    $start = $e['starts_at'] ?? null;
    $end = $e['end_at'] ?? $start;
    if (!$start) continue;

    $uid = 'ak-event-' . (int)$e['id'] . '@' . $host;
    $created = !empty($e['created_at']) ? ic_dt($e['created_at']) : ic_dt('now');

    $out .= "BEGIN:VEVENT\r\n";
    $out .= ic_fold("UID:" . $uid);
    $out .= ic_fold("DTSTAMP:" . ic_dt('now'));
    $out .= ic_fold("CREATED:" . $created);
    if ($all_day) {
        $out .= ic_fold("DTSTART;VALUE=DATE:" . ic_dt($start, true));
        $out .= ic_fold("DTEND;VALUE=DATE:"   . ic_dt($end ?: $start, true));
    } else {
        $out .= ic_fold("DTSTART:" . ic_dt($start));
        $out .= ic_fold("DTEND:"   . ic_dt($end ?: $start));
    }
    $out .= ic_fold("SUMMARY:" . ic_esc($title));
    if ($desc) $out .= ic_fold("DESCRIPTION:" . ic_esc($desc));
    if ($loc)  $out .= ic_fold("LOCATION:" . ic_esc($loc));
    if (!empty($e['project_id'])) {
        $out .= ic_fold("URL:https://" . $host . "/projet/" . (int)$e['project_id']);
    }
    $out .= "END:VEVENT\r\n";
}

$out .= "END:VCALENDAR\r\n";
echo $out;
