<?php
/**
 * /ical/<token>.ics — Feed iCal pour abonnement Google/Apple/Outlook
 * Pas de session, pas de cookie : auth via token uniquement.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-attendance.php';   // ak_event_visibility_sql(), ak_event_plain_text()

$token = is_string($_GET['t'] ?? null) ? trim($_GET['t']) : '';
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    die('Token invalide.');
}

// Vérifier token
$stmt = $pdo->prepare("SELECT t.id AS tok_id, t.user_id, t.label, u.org_id, u.role, u.first_name, u.last_name
                       FROM user_calendar_tokens t
                       JOIN users u ON u.id = t.user_id
                       WHERE t.token = ? AND t.revoked_at IS NULL AND u.is_active = 1 AND u.deleted_at IS NULL
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

// Charger events : les mêmes que l'agenda web du titulaire du lien (/agenda) :
// suiveur limité à ses projets, « Équipe du projet uniquement » réservé à l'équipe.
// Pas de repli « toute l'association » si la requête échoue : un flux vide plutôt qu'une fuite.
$events = [];
try {
    [$vis_sql, $vis_params] = ak_event_visibility_sql($pdo, ['id' => $user_id, 'role' => (string)$ctx['role']]);
    $stmt = $pdo->prepare("SELECT e.*, p.name AS project_name FROM events e
                           LEFT JOIN projects p ON p.id = e.project_id
                           WHERE e.org_id = ? AND e.deleted_at IS NULL" . $vis_sql . "
                           ORDER BY e.starts_at DESC LIMIT 500");
    $stmt->execute(array_merge([$org_id], $vis_params));
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[ical-feed] ' . $e->getMessage());
}

// Helpers iCal
function ic_esc(string $v): string {
    $v = str_replace(["\\", ",", ";", "\n", "\r"], ["\\\\", "\\,", "\\;", "\\n", ""], $v);
    return $v;
}
function ic_fold(string $line): string {
    // RFC 5545 : lignes max 75 octets (espace de continuation compris), pliées par CRLF + espace,
    // sans couper un caractère UTF-8 en deux (sinon « é » devient « �� » dans l'agenda)
    $out = '';
    $max = 75;
    while (strlen($line) > $max) {
        $chunk = mb_strcut($line, 0, $max, 'UTF-8');
        if ($chunk === '') $chunk = substr($line, 0, $max);
        $out .= $chunk . "\r\n ";
        $line = substr($line, strlen($chunk));
        $max = 74;
    }
    return $out . $line . "\r\n";
}
// Les dates sont stockées en heure de Paris (fuseau de l'appli) : conversion en UTC pour le suffixe Z.
function ic_dt(string $iso, bool $allDay = false): string {
    $t = strtotime($iso);
    if (!$t) $t = time();
    return $allDay ? date('Ymd', $t) : gmdate('Ymd\THis\Z', $t);
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
    $desc = ak_event_plain_text($e['description'] ?? '');   // description Google en HTML → texte
    if (!empty($e['project_name'])) $desc = '[' . $e['project_name'] . "]\n" . $desc;
    $loc = $e['location'] ?? '';
    $all_day = !empty($e['is_all_day']);
    $start = $e['starts_at'] ?? null;
    $end = !empty($e['ends_at']) ? $e['ends_at'] : $start;
    if (!$start) continue;

    $uid = 'ak-event-' . (int)$e['id'] . '@' . $host;
    $created = !empty($e['created_at']) ? ic_dt($e['created_at']) : ic_dt('now');

    $out .= "BEGIN:VEVENT\r\n";
    $out .= ic_fold("UID:" . $uid);
    $out .= ic_fold("DTSTAMP:" . ic_dt('now'));
    $out .= ic_fold("CREATED:" . $created);
    if ($all_day) {
        // Journée entière : DTEND exclusif = lendemain du dernier jour (RFC 5545)
        $out .= ic_fold("DTSTART;VALUE=DATE:" . ic_dt($start, true));
        $out .= ic_fold("DTEND;VALUE=DATE:"   . date('Ymd', strtotime(date('Y-m-d', strtotime($end) ?: strtotime($start)) . ' +1 day')));
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
