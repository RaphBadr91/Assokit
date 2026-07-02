<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-helper.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /agenda'); exit; }
if (!check_csrf($_POST['csrf_token'] ?? '')) { header('Location: /agenda?error=csrf'); exit; }

$action = $_POST['action'] ?? '';
$event_id = (int)($_POST['event_id'] ?? 0);
if ($event_id <= 0) { header('Location: /agenda'); exit; }

$stmt = $pdo->prepare("SELECT e.* FROM events e WHERE e.id = ? AND e.org_id = ? LIMIT 1");
$stmt->execute([$event_id, $user['org_id']]);
$event = $stmt->fetch();
if (!$event) { header('Location: /agenda?error=notfound'); exit; }

$can_edit = $user['role'] === 'admin' || $user['role'] === 'coordinator' || $event['created_by'] == $user['id'];
if (!$can_edit) { header('Location: /evenement/' . $event_id . '?error=permission'); exit; }

if ($action === 'delete') {
    if (!empty($event['google_event_id'])) delete_event_from_google($event_id);
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
    header('Location: /agenda?deleted=1');
    exit;
}

if ($action === 'update') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $event_type = $_POST['event_type'] ?? 'other';
    $color_theme = $_POST['color_theme'] ?? '';
    $project_id = (int)($_POST['project_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '14:00';
    $end_date = $_POST['end_date'] ?? '';
    $end_time = $_POST['end_time'] ?? '16:00';
    $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
    $visibility = $_POST['visibility'] ?? 'organization';

    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        header('Location: /evenement/' . $event_id . '?error=invalid'); exit;
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
        $starts_at = $start_date . ' ' . $start_time . ':00';
        $ends_at = $end_date . ' ' . $end_time . ':00';
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
        WHERE id = ?
    ");
    $stmt->execute([
        $title, $description ?: null, $location ?: null, $event_type, $color_theme,
        $project_id ?: null, $starts_at, $ends_at, $is_all_day, $visibility, $event_id,
    ]);

    sync_event_to_google($event_id);

    header('Location: /evenement/' . $event_id . '?updated=1');
    exit;
}

header('Location: /evenement/' . $event_id);
exit;
