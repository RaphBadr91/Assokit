<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-helper.php';

require_login();
$user = current_user();

if ($user['role'] !== 'admin') {
    header('Location: /mon-agenda?error=not_admin');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-agenda');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /mon-agenda?error=csrf');
    exit;
}

$action = $_POST['action'] ?? '';

function push_all_assokit_events_now($org_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT id FROM events
        WHERE org_id = ? AND deleted_at IS NULL AND google_event_id IS NULL
          AND ends_at >= NOW() - INTERVAL 1 MONTH
        ORDER BY starts_at ASC
        LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $event_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($event_ids as $event_id) {
        sync_event_to_google($event_id);
    }
}

if ($action === 'select_calendar') {
    $calendar_id = trim($_POST['calendar_id'] ?? '');
    $calendar_name = trim($_POST['calendar_name'] ?? '');

    if ($calendar_id === '') {
        header('Location: /mon-agenda?error=no_calendar');
        exit;
    }

    $connection = get_org_google_connection($user['org_id']);
    if (!$connection) {
        header('Location: /mon-agenda?error=not_connected');
        exit;
    }

    $calendars = list_user_calendars($connection);
    $valid = false;
    foreach ($calendars as $cal) {
        if ($cal['id'] === $calendar_id) {
            $valid = true;
            if (empty($calendar_name)) $calendar_name = $cal['summary'] ?? 'Agenda';
            break;
        }
    }
    if (!$valid) {
        header('Location: /mon-agenda?error=invalid_calendar');
        exit;
    }

    $pdo->prepare("
        UPDATE org_google_calendar
        SET google_calendar_id = ?, google_calendar_name = ?, sync_token = NULL
        WHERE id = ?
    ")->execute([$calendar_id, $calendar_name, $connection['id']]);

    push_all_assokit_events_now($user['org_id']);

    header('Location: /mon-agenda?calendar_selected=1');
    exit;
}

if ($action === 'sync_now') {
    $result = pull_events_from_google($user['org_id']);
    if (!$result['success']) {
        header('Location: /mon-agenda?error=' . urlencode($result['error'] ?? 'sync_failed'));
        exit;
    }
    $msg = "imported={$result['imported']}&updated={$result['updated']}&deleted={$result['deleted']}";
    header('Location: /mon-agenda?synced=1&' . $msg);
    exit;
}

if ($action === 'disconnect') {
    disconnect_google($user['org_id']);
    header('Location: /mon-agenda?disconnected=1');
    exit;
}

if ($action === 'regenerate') {
    $new_token = sha1($user['id'] . '-' . $user['email'] . '-' . mt_rand() . '-' . time());
    $pdo->prepare("UPDATE users SET ics_token = ? WHERE id = ?")
        ->execute([$new_token, $user['id']]);
    header('Location: /mon-agenda?ics_regenerated=1');
    exit;
}

header('Location: /mon-agenda');
exit;
