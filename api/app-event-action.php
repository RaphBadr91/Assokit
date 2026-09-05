<?php
/**
 * api/app-event-action.php — Modifier ou supprimer un événement depuis l'app.
 *
 * Parité stricte avec action-evenement.php : mêmes droits (admin, coordinateur
 * ou créateur), mêmes listes de valeurs autorisées, mêmes contrôles de dates,
 * même synchronisation Google. Le site n'est pas modifié.
 */
require_once __DIR__ . '/_app-write-boot.php';

$event_id = (int) ($input['event_id'] ?? 0);
$action   = (string) ($input['action'] ?? '');
if ($event_id <= 0) app_fail(422, 'invalid', 'Événement introuvable.');

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$event_id, $org_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) app_fail(422, 'invalid', 'Événement introuvable.');

$role = (string) ($user['role'] ?? 'member');
$can_edit = ($role === 'admin' || $role === 'coordinator' || (int) ($event['created_by'] ?? 0) === $uid);
if (!$can_edit) app_fail(403, 'role', 'Vous ne pouvez pas modifier cet événement.');

/* ── Suppression ─────────────────────────────────────────────────────── */
if ($action === 'delete') {
    if (!empty($event['google_event_id'])) {
        try {
            require_once __DIR__ . '/../google-helper.php';
            if (function_exists('delete_event_from_google')) delete_event_from_google($event_id);
        } catch (Throwable $e) { error_log('[app-event-action google] ' . $e->getMessage()); }
    }
    $pdo->prepare("DELETE FROM events WHERE id = ? AND org_id = ?")->execute([$event_id, $org_id]);
    echo json_encode(['ok' => true, 'deleted' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'update') app_fail(422, 'invalid', 'Action inconnue.');

/* ── Modification ────────────────────────────────────────────────────── */
$title       = trim((string) ($input['title'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$location    = trim((string) ($input['location'] ?? ''));
$start_date  = trim((string) ($input['start_date'] ?? ''));
$start_time  = trim((string) ($input['start_time'] ?? '14:00'));
$end_date    = trim((string) ($input['end_date'] ?? ''));
$end_time    = trim((string) ($input['end_time'] ?? '16:00'));
$is_all_day  = !empty($input['is_all_day']) ? 1 : 0;
$project_id  = (int) ($input['project_id'] ?? 0);

if ($title === '') app_fail(422, 'invalid', 'Le titre est obligatoire.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) app_fail(422, 'invalid', 'Date de début invalide.');
if ($end_date === '') $end_date = $start_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) app_fail(422, 'invalid', 'Date de fin invalide.');
if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time = '14:00';
if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) $end_time = '16:00';

// Les valeurs hors liste retombent sur le défaut du site, jamais sur null.
$VALID_TYPES = ['meeting', 'workshop', 'public', 'internal', 'deadline', 'other'];
$VALID_COLORS = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red'];
$VALID_VIS = ['public', 'organization', 'project_only'];

$event_type = (string) ($input['event_type'] ?? 'other');
if (!in_array($event_type, $VALID_TYPES, true)) $event_type = 'other';

$color_theme = (string) ($input['color_theme'] ?? '');
$color_theme = in_array($color_theme, $VALID_COLORS, true) ? $color_theme : null;

$visibility = (string) ($input['visibility'] ?? 'organization');
if (!in_array($visibility, $VALID_VIS, true)) $visibility = 'organization';

if ($is_all_day) {
    $starts_at = $start_date . ' 00:00:00';
    $ends_at   = $end_date . ' 23:59:59';
} else {
    $starts_at = $start_date . ' ' . $start_time . ':00';
    $ends_at   = $end_date . ' ' . $end_time . ':00';
}
if (strtotime($ends_at) < strtotime($starts_at)) app_fail(422, 'invalid', 'La fin ne peut pas précéder le début.');

if ($project_id > 0) {
    $chk = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? AND f.org_id = ?");
    $chk->execute([$project_id, $org_id]);
    if (!$chk->fetch()) $project_id = 0;
}

try {
    $pdo->prepare("
        UPDATE events
        SET title = ?, description = ?, location = ?, event_type = ?, color_theme = ?,
            project_id = ?, starts_at = ?, ends_at = ?, is_all_day = ?, visibility = ?
        WHERE id = ? AND org_id = ?
    ")->execute([
        mb_substr($title, 0, 200), $description ?: null, $location ?: null, $event_type, $color_theme,
        $project_id > 0 ? $project_id : null, $starts_at, $ends_at, $is_all_day, $visibility,
        $event_id, $org_id,
    ]);
} catch (Throwable $e) {
    error_log('[app-event-action update] ' . $e->getMessage());
    app_fail(500, 'server', 'Enregistrement impossible.');
}

// Synchronisation Google : au mieux, sans bloquer la réponse.
try {
    require_once __DIR__ . '/../google-helper.php';
    if (function_exists('sync_event_to_google')) sync_event_to_google($event_id);
} catch (Throwable $e) { error_log('[app-event-action google-sync] ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'id' => $event_id], JSON_UNESCAPED_UNICODE);
