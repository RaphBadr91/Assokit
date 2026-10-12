<?php
/**
 * api/app-event.php — Detail d'un evenement pour la fiche native. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
require_once __DIR__ . '/../includes-attendance.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$TYPE_LABEL = ['meeting' => 'Réunion', 'workshop' => 'Atelier', 'public' => 'Public', 'internal' => 'Interne', 'deadline' => 'Échéance', 'other' => 'Autre'];
$TYPE_COLOR = ['meeting' => 'blue', 'workshop' => 'purple', 'public' => 'green', 'internal' => 'teal', 'deadline' => 'red', 'other' => 'amber'];
$DAYS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    // Même visibilité que l'agenda : un événement invisible pour ce compte est introuvable.
    [$vis_sql, $vis_params] = ak_event_visibility_sql($pdo, $user ?: []);
    $stmt = $pdo->prepare("
        SELECT e.*, p.name AS project_name, f.color_theme AS folder_color
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN folders f ON p.folder_id = f.id
        WHERE e.id = ? AND e.org_id = ? AND e.deleted_at IS NULL" . $vis_sql . " LIMIT 1
    ");
    $stmt->execute(array_merge([$id, $org_id], $vis_params));
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$e) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $ct = trim((string) ($e['color_theme'] ?? ''));
    if ($ct !== '' && $ct[0] === '#') {
        $color = $ct;
    } elseif (($e['sync_origin'] ?? '') === 'google') {
        $GPAL = ['#7986CB', '#33B679', '#8E24AA', '#E67C73', '#F6BF26', '#039BE5', '#3F51B5', '#0B8043', '#D50000', '#F4511E'];
        $b = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $e['title'])) : strtolower(trim((string) $e['title']));
        $color = $b === '' ? $GPAL[0] : $GPAL[abs(crc32($b)) % count($GPAL)];
    } else {
        $theme = $ct;
        if ($theme === '') $theme = trim((string) ($e['folder_color'] ?? ''));
        if ($theme === '') $theme = $TYPE_COLOR[$e['event_type'] ?? 'other'] ?? 'blue';
        $color = function_exists('folder_color_hex') ? folder_color_hex($theme) : '#3B82F6';
    }

    $ts = strtotime((string) $e['starts_at']);
    $te = strtotime((string) $e['ends_at']);
    $wd = (int) date('w', $ts);
    $date_full = $DAYS[$wd] . ' ' . (int) date('j', $ts) . ' ' . ($MONTHS[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    if (!empty($e['is_all_day'])) {
        $when = $date_full . ' · Journée entière';
    } else {
        $sameday = date('Y-m-d', $ts) === date('Y-m-d', $te);
        $when = $date_full . ' · ' . date('H:i', $ts) . ($te ? (' → ' . ($sameday ? date('H:i', $te) : date('d/m H:i', $te))) : '');
    }

    echo json_encode([
        'ok' => true,
        'event' => [
            'id'          => (int) $e['id'],
            'title'       => (string) $e['title'],
            'when'        => $when,
            'location'    => (string) ($e['location'] ?? ''),
            // Texte brut pour l'écran natif : balises (Google/Outlook/éditeur) → sauts de ligne, entités décodées.
            // Renvoyé tel quel à la modification, il ne remplace pas la description d'origine (cf. app-event-action).
            'description' => ak_event_plain_text($e['description'] ?? ''),
            'project'     => (string) ($e['project_name'] ?? ''),
            'type_label'  => $TYPE_LABEL[$e['event_type'] ?? 'other'] ?? 'Autre',
            'color'       => $color,
            'all_day'     => !empty($e['is_all_day']),
            // Champs bruts pour l'édition native : le formulaire attend des dates
            // et heures déjà découpées, sans nouvel appel au serveur.
            'start_date'  => date('Y-m-d', $ts),
            'start_time'  => date('H:i', $ts),
            'end_date'    => $te ? date('Y-m-d', $te) : date('Y-m-d', $ts),
            'end_time'    => $te ? date('H:i', $te) : date('H:i', $ts),
            'is_all_day'  => !empty($e['is_all_day']),
            'event_type'  => (string) ($e['event_type'] ?? 'other'),
            'visibility'  => (string) ($e['visibility'] ?? 'organization'),
            'color_theme' => (string) ($e['color_theme'] ?? ''),
            'project_id'  => (int) ($e['project_id'] ?? 0),
        ],
        // Mêmes droits que action-evenement.php : admin, coordinateur, droit « Gérer les
        // événements » ou créateur ; jamais un suiveur.
        'can_edit' => ak_event_can_edit($user ?: [], $e),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-event] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
