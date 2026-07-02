<?php
/**
 * ============================================================
 * ASSOKIT — Export iCalendar (.ics)
 * ============================================================
 * 2 modes d'utilisation :
 *
 * 1) Export d'un seul événement :
 *    /agenda-ics?event=42
 *    → Télécharge un .ics avec juste cet événement
 *    → L'utilisateur double-clique, son agenda l'ajoute
 *
 * 2) Abonnement complet (URL permanente) :
 *    /agenda-ics?token=XYZ
 *    → Renvoie TOUS les événements de l'org de l'utilisateur
 *    → L'utilisateur colle cette URL dans Apple/Google Calendar
 *    → Son agenda se sync auto toutes les heures
 *
 * Format respecté : RFC 5545 (iCalendar standard)
 * ============================================================
 */
require_once __DIR__ . '/config.php';

$mode = null;
$events = [];
$filename = 'assokit.ics';
$calendar_name = 'Assokit';

// =========================================================
// MODE 1 : Export d'un seul événement (nécessite d'être connecté)
// =========================================================
if (isset($_GET['event'])) {
    require_login();
    $current = current_user();
    $event_id = (int)$_GET['event'];

    $stmt = $pdo->prepare("
        SELECT e.*, p.name AS project_name
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        WHERE e.id = ? AND e.org_id = ?
    ");
    $stmt->execute([$event_id, $current['org_id']]);
    $event = $stmt->fetch();

    if (!$event) {
        http_response_code(404);
        die('Événement introuvable.');
    }

    $events = [$event];
    $filename = 'assokit-event-' . $event_id . '.ics';
    $calendar_name = 'Assokit — ' . $event['title'];
    $mode = 'single';
}

// =========================================================
// MODE 2 : Abonnement permanent via token (pas besoin d'être connecté)
// =========================================================
elseif (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    if (!preg_match('/^[a-f0-9]{40}$/i', $token)) {
        http_response_code(403);
        die('Token invalide.');
    }

    // Retrouver l'utilisateur à partir du token
    $stmt = $pdo->prepare("SELECT id, org_id, first_name, last_name FROM users WHERE ics_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user_from_token = $stmt->fetch();

    if (!$user_from_token) {
        http_response_code(403);
        die('Token invalide ou révoqué.');
    }

    // Charger tous les événements de l'org
    $stmt = $pdo->prepare("
        SELECT e.*, p.name AS project_name
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        WHERE e.org_id = ?
        ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$user_from_token['org_id']]);
    $events = $stmt->fetchAll();

    // Récupérer le nom de l'organisation
    $org_stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $org_stmt->execute([$user_from_token['org_id']]);
    $org = $org_stmt->fetch();

    $filename = 'assokit-' . preg_replace('/[^a-z0-9]/', '', strtolower($org['name'] ?? 'agenda')) . '.ics';
    $calendar_name = 'Assokit — ' . ($org['name'] ?? 'Agenda');
    $mode = 'subscription';
}

else {
    http_response_code(400);
    die('Paramètre manquant. Utilisez ?event=X (un événement) ou ?token=XYZ (abonnement).');
}

// =========================================================
// GÉNÉRATION DU FICHIER .ICS
// =========================================================

// Helper : escape les caractères spéciaux du format iCal
function ics_escape($text) {
    $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
    return $text;
}

// Helper : formater une date/heure au format iCal
function ics_datetime($datetime, $all_day = false) {
    $ts = strtotime($datetime);
    if ($all_day) {
        return date('Ymd', $ts);
    }
    return gmdate('Ymd\THis\Z', $ts); // UTC
}

// Helper : découper les lignes à 75 caractères (exigence du standard)
function ics_fold($line) {
    if (mb_strlen($line) <= 75) return $line;
    $result = '';
    $remaining = $line;
    while (mb_strlen($remaining) > 75) {
        $result .= mb_substr($remaining, 0, 75) . "\r\n ";
        $remaining = mb_substr($remaining, 75);
    }
    $result .= $remaining;
    return $result;
}

// Envoi des headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
// Permet aux agendas de revenir chercher les mises à jour
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// Construction du fichier .ics
$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//Assokit//Assokit ' . (defined('SITE_NAME') ? SITE_NAME : 'Agenda') . '//FR';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:' . ics_escape($calendar_name);
$lines[] = 'X-WR-TIMEZONE:Europe/Paris';
$lines[] = 'X-WR-CALDESC:' . ics_escape('Agenda des événements Assokit — synchronisé en direct');
// Rafraîchissement toutes les heures pour les agendas abonnés
$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
$lines[] = 'X-PUBLISHED-TTL:PT1H';

foreach ($events as $event) {
    $is_all_day = (bool)$event['is_all_day'];
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:assokit-event-' . $event['id'] . '@' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr');
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $lines[] = 'CREATED:' . gmdate('Ymd\THis\Z', strtotime($event['created_at']));
    $lines[] = 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', strtotime($event['updated_at']));

    if ($is_all_day) {
        $lines[] = 'DTSTART;VALUE=DATE:' . ics_datetime($event['starts_at'], true);
        // Pour un événement journée, DTEND est le lendemain selon le standard
        $end_date_ts = strtotime($event['ends_at'] . ' +1 day');
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', $end_date_ts);
    } else {
        $lines[] = 'DTSTART:' . ics_datetime($event['starts_at']);
        $lines[] = 'DTEND:' . ics_datetime($event['ends_at']);
    }

    $lines[] = ics_fold('SUMMARY:' . ics_escape($event['title']));

    if ($event['location']) {
        $lines[] = ics_fold('LOCATION:' . ics_escape($event['location']));
    }

    // Description enrichie avec lien vers Assokit
    $desc_parts = [];
    if ($event['description']) {
        $desc_parts[] = $event['description'];
    }
    if ($event['project_name']) {
        $desc_parts[] = "Projet : " . $event['project_name'];
    }
    $desc_parts[] = "";
    $desc_parts[] = "Ouvrir dans Assokit : https://" . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . "/evenement/" . $event['id'];
    $description = implode("\n", $desc_parts);
    $lines[] = ics_fold('DESCRIPTION:' . ics_escape($description));

    // Catégorie selon le type
    $type_labels = [
        'meeting' => 'Réunion',
        'workshop' => 'Atelier',
        'public' => 'Événement public',
        'internal' => 'Interne',
        'deadline' => 'Deadline',
        'other' => 'Autre',
    ];
    if (isset($type_labels[$event['event_type']])) {
        $lines[] = 'CATEGORIES:' . $type_labels[$event['event_type']];
    }

    // URL canonique (certains agendas l'utilisent)
    $lines[] = 'URL:https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/evenement/' . $event['id'];

    // Statut (tous nos événements sont confirmés par défaut)
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'TRANSP:OPAQUE';

    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

// Les lignes doivent être terminées par CRLF selon le standard
echo implode("\r\n", $lines) . "\r\n";
exit;
