<?php
/**
 * api/app-create-event.php — Création d'un événement agenda depuis l'app (natif).
 * Reproduit fidèlement nouveau-evenement.php (INSERT + sync Google).
 * Renvoie du JSON. NE MODIFIE PAS le site.
 *
 * Accessible à tous les membres de l'organisation (parité web).
 */
require __DIR__ . '/_app-write-boot.php';

$title       = trim((string) ($input['title'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$location    = trim((string) ($input['location'] ?? ''));
$event_type  = (string) ($input['event_type'] ?? 'meeting');
$color_theme = (string) ($input['color_theme'] ?? '');
$visibility  = (string) ($input['visibility'] ?? 'organization');
$project_id  = (int) ($input['project_id'] ?? 0);
$is_all_day  = !empty($input['is_all_day']) ? 1 : 0;
$start_date  = (string) ($input['start_date'] ?? '');
$start_time  = (string) ($input['start_time'] ?? '14:00');
$end_date    = (string) ($input['end_date'] ?? '');
$end_time    = (string) ($input['end_time'] ?? '16:00');

// Validations (identiques au site)
if ($title === '') app_fail(422, 'invalid', 'Le titre de l\'événement est obligatoire.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) app_fail(422, 'invalid', 'Date de début invalide.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   app_fail(422, 'invalid', 'Date de fin invalide.');
if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time = '14:00';
if (!preg_match('/^\d{2}:\d{2}$/', $end_time))   $end_time   = '16:00';

if ($is_all_day) {
    $starts_at = $start_date . ' 00:00:00';
    $ends_at   = $end_date . ' 23:59:59';
} else {
    $starts_at = $start_date . ' ' . $start_time . ':00';
    $ends_at   = $end_date . ' ' . $end_time . ':00';
}
if (strtotime($ends_at) < strtotime($starts_at)) {
    app_fail(422, 'invalid', 'La date/heure de fin est avant celle de début.');
}

// Validation projet (scope org)
if ($project_id > 0) {
    $check = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? AND f.org_id = ?");
    $check->execute([$project_id, $org_id]);
    if (!$check->fetch()) $project_id = 0;
}

$valid_types = ['meeting', 'workshop', 'public', 'internal', 'deadline', 'other'];
$valid_colors = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red'];
$valid_visibility = ['public', 'organization', 'project_only'];
if (!in_array($event_type, $valid_types, true)) $event_type = 'other';
$color_theme = in_array($color_theme, $valid_colors, true) ? $color_theme : null;
if (!in_array($visibility, $valid_visibility, true)) $visibility = 'organization';

try {
    $stmt = $pdo->prepare("INSERT INTO events
        (org_id, project_id, created_by, title, description, location, event_type, color_theme, starts_at, ends_at, is_all_day, visibility)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $org_id, $project_id ?: null, $uid, $title, $description ?: null, $location ?: null,
        $event_type, $color_theme, $starts_at, $ends_at, $is_all_day, $visibility,
    ]);
    $new_id = (int) $pdo->lastInsertId();

    // Sync Google (best-effort, hors chemin critique)
    if (file_exists(__DIR__ . '/../google-helper.php')) {
        @require_once __DIR__ . '/../google-helper.php';
        try { if (function_exists('is_google_enabled') && is_google_enabled() && function_exists('sync_event_to_google')) sync_event_to_google($new_id); } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok'      => true,
        'id'      => $new_id,
        'message' => 'Événement « ' . $title . ' » ajouté à l\'agenda.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-event] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer l\'événement.');
}
