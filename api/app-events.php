<?php
/**
 * api/app-events.php — Agenda (evenements a venir) pour l'ecran natif.
 * JSON, lecture seule, scope org. NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id
require_once __DIR__ . '/../includes-attendance.php';

$TYPE_COLOR = [
    'meeting' => 'blue', 'workshop' => 'purple', 'public' => 'green',
    'internal' => 'teal', 'deadline' => 'red', 'other' => 'amber',
];
$DAYS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$MONTHS = ['', 'janv.', 'févr.', 'mars', 'avril', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

try {
    if ($org_id <= 0) $org_id = -1;   // compte sans association (agent de collectivité) : aucune ligne « org 0 », réponses vides

    // Tous les événements à venir (même compte que « événements à venir » de l'accueil),
    // les plus proches d'abord. Facultatif : ?from=AAAA-MM-JJ (suite de la liste, cf. next_from)
    // et ?to=AAAA-MM-JJ (borne de fin).
    $LIMIT = 300;
    $from = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
    $to   = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
    $now = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from) ? $from . ' 00:00:00' : date('Y-m-d 00:00:00');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && strtotime($to) ? $to . ' 23:59:59' : null;

    // Même visibilité que le site (agenda.php) : suiveur limité à ses projets,
    // « Équipe du projet uniquement » réservé à l'équipe du projet.
    [$vis_sql, $vis_params] = ak_event_visibility_sql($pdo, $user ?: []);

    $stmt = $pdo->prepare("
        SELECT e.id, e.title, e.location, e.event_type, e.color_theme, e.sync_origin,
               e.starts_at, e.ends_at, e.is_all_day, e.project_id,
               p.name AS project_name, f.color_theme AS folder_color
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN folders f ON p.folder_id = f.id
        WHERE e.org_id = ? AND e.deleted_at IS NULL
          AND e.ends_at >= ?" . ($end !== null ? " AND e.starts_at <= ?" : '') . $vis_sql . "
        ORDER BY e.starts_at ASC, e.id ASC
        LIMIT " . ($LIMIT + 1) . "
    ");
    $stmt->execute(array_merge([$org_id, $now], $end !== null ? [$end] : [], $vis_params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $has_more = count($rows) > $LIMIT;
    if ($has_more) $rows = array_slice($rows, 0, $LIMIT);

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

    echo json_encode([
        'ok' => true,
        'events' => $events,
        // Liste tronquée : la suite se charge avec ?from=next_from (jour du dernier
        // événement renvoyé, à dédoublonner par id).
        'has_more' => $has_more,
        'next_from' => $has_more && $rows ? date('Y-m-d', strtotime((string) end($rows)['starts_at'])) : null,
        // Bouton « Nouvel événement » : masqué pour un suiveur (même règle que le site, agenda.php).
        // Compte sans association : aucun agenda où créer (la création répond 400 'org').
        'can_create' => $org_id > 0 && ak_event_can_create($user ?: []),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
