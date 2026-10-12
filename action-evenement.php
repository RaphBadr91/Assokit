<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/google-helper.php';
require_once __DIR__ . '/includes-attendance.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /agenda'); exit; }

$action = $_POST['action'] ?? '';
$event_id = (int)($_POST['event_id'] ?? 0);
// Session expirée : retour sur la fiche de l'événement, qui explique que rien n'a été enregistré.
if (!check_csrf($_POST['csrf_token'] ?? '')) { header('Location: ' . ($event_id > 0 ? '/evenement/' . $event_id : '/agenda') . '?error=csrf'); exit; }
if ($event_id <= 0) { header('Location: /agenda'); exit; }

// Mêmes règles que la fiche : un événement supprimé ou invisible pour ce compte est introuvable.
[$vis_sql, $vis_params] = ak_event_visibility_sql($pdo, $user);
$stmt = $pdo->prepare("SELECT e.* FROM events e WHERE e.id = ? AND e.org_id = ? AND e.deleted_at IS NULL" . $vis_sql . " LIMIT 1");
$stmt->execute(array_merge([$event_id, $user['org_id']], $vis_params));
$event = $stmt->fetch();
if (!$event) { header('Location: /agenda?error=notfound'); exit; }

// Admin, coordinateur, droit « Gérer les événements » ou créateur ; jamais un suiveur.
if (!ak_event_can_edit($user, $event)) { header('Location: /evenement/' . $event_id . '?error=permission'); exit; }

if ($action === 'delete') {
    if (!empty($event['google_event_id'])) delete_event_from_google($event_id);
    $pdo->prepare("DELETE FROM events WHERE id = ? AND org_id = ?")->execute([$event_id, $user['org_id']]);
    header('Location: /agenda?deleted=1');
    exit;
}

if ($action === 'update') {
    $str = fn(string $k, string $def = ''): string => is_scalar($_POST[$k] ?? null) ? (string)$_POST[$k] : $def;
    $title = trim($str('title'));
    $description = trim($str('description'));
    $location = trim($str('location'));
    $event_type = $str('event_type', 'other');
    $color_theme = $str('color_theme');
    $project_id = (int)$str('project_id', '0');
    $start_date = $str('start_date');
    $start_time = $str('start_time', '14:00');
    $end_date = $str('end_date');
    $end_time = $str('end_time', '16:00');
    $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
    $visibility = $str('visibility', 'organization');

    if ($title === '' || !ak_event_valid_date($start_date) || !ak_event_valid_date($end_date)) {
        header('Location: /evenement/' . $event_id . '?error=invalid'); exit;
    }
    // Heure vidée ou à moitié saisie : rien n'est enregistré (au lieu d'une erreur 500 ou d'une date fausse)
    if (!$is_all_day && (!ak_event_valid_time($start_time) || !ak_event_valid_time($end_time))) {
        header('Location: /evenement/' . $event_id . '?edit=1&error=time'); exit;
    }

    $valid_types = ['meeting', 'workshop', 'public', 'internal', 'deadline', 'other'];
    $valid_colors = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red'];
    $valid_visibility = ['public', 'organization', 'project_only'];
    if (!in_array($event_type, $valid_types, true)) $event_type = 'other';
    $color_theme = in_array($color_theme, $valid_colors, true) ? $color_theme : null;
    if (!in_array($visibility, $valid_visibility, true)) $visibility = 'organization';

    if ($is_all_day) {
        $starts_at = $start_date . ' 00:00:00';
        $ends_at = $end_date . ' 23:59:59';
    } else {
        $starts_at = $start_date . ' ' . substr($start_time, 0, 5) . ':00';
        $ends_at = $end_date . ' ' . substr($end_time, 0, 5) . ':00';
    }

    if (strtotime($ends_at) < strtotime($starts_at)) {
        header('Location: /evenement/' . $event_id . '?error=dates'); exit;
    }

    if ($project_id > 0) {
        $check = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? AND f.org_id = ?");
        $check->execute([$project_id, $user['org_id']]);
        if (!$check->fetch()) $project_id = 0;
    }

    $stmt = $pdo->prepare("
        UPDATE events
        SET title = ?, description = ?, location = ?, event_type = ?, color_theme = ?,
            project_id = ?, starts_at = ?, ends_at = ?, is_all_day = ?, visibility = ?
        WHERE id = ? AND org_id = ?
    ");
    $stmt->execute([
        $title, $description ?: null, $location ?: null, $event_type, $color_theme,
        $project_id ?: null, $starts_at, $ends_at, $is_all_day, $visibility, $event_id, $user['org_id'],
    ]);

    sync_event_to_google($event_id);

    header('Location: /evenement/' . $event_id . '?updated=1');
    exit;
}

header('Location: /evenement/' . $event_id);
exit;
