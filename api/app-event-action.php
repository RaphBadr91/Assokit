<?php
/**
 * api/app-event-action.php — Modifier ou supprimer un événement depuis l'app.
 *
 * Parité stricte avec action-evenement.php : mêmes droits (admin, coordinateur,
 * droit « Gérer les événements » ou créateur ; jamais un suiveur), mêmes listes
 * de valeurs autorisées, mêmes contrôles de dates, même synchronisation Google.
 */
require_once __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/../includes-attendance.php';

$event_id = (int) ($input['event_id'] ?? 0);
$action   = (string) ($input['action'] ?? '');
if ($event_id <= 0) app_fail(422, 'invalid', 'Événement introuvable.');

// Même visibilité que l'agenda : un événement invisible pour ce compte est introuvable.
[$vis_sql, $vis_params] = ak_event_visibility_sql($pdo, $user);
$stmt = $pdo->prepare("SELECT e.* FROM events e WHERE e.id = ? AND e.org_id = ? AND e.deleted_at IS NULL" . $vis_sql . " LIMIT 1");
$stmt->execute(array_merge([$event_id, $org_id], $vis_params));
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) app_fail(422, 'invalid', 'Événement introuvable.');

if (!ak_event_can_edit($user, $event)) app_fail(403, 'role', 'Vous ne pouvez pas modifier cet événement.');

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
// Un champ que l'appli n'envoie pas garde sa valeur actuelle (couleur, visibilité…) :
// enregistrer le formulaire ne doit changer que ce que l'utilisateur a modifié.
$sent = fn(string $k): bool => array_key_exists($k, $input);

$title       = trim((string) ($input['title'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$location    = $sent('location') ? trim((string) $input['location']) : (string) ($event['location'] ?? '');
$start_date  = trim((string) ($input['start_date'] ?? ''));
$start_time  = trim((string) ($input['start_time'] ?? date('H:i', strtotime((string) $event['starts_at']))));
$end_date    = trim((string) ($input['end_date'] ?? ''));
$end_time    = trim((string) ($input['end_time'] ?? date('H:i', strtotime((string) $event['ends_at']))));
$is_all_day  = ($sent('is_all_day') ? !empty($input['is_all_day']) : !empty($event['is_all_day'])) ? 1 : 0;
$project_id  = $sent('project_id') ? (int) $input['project_id'] : (int) ($event['project_id'] ?? 0);

// L'appli affiche et renvoie la description en texte brut (api/app-event.php). Si ce texte
// n'a pas été modifié, on garde la description d'origine (liens et mise en forme
// d'un événement Google ou du site) au lieu de l'écraser par sa version sans balises.
$same_text = function (string $a, string $b): bool {
    $n = fn(string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));
    return $n($a) === $n($b);
};
if (!$sent('description') || $same_text($description, ak_event_plain_text($event['description'] ?? ''))) {
    $description = (string) ($event['description'] ?? '');
}

if ($title === '') app_fail(422, 'invalid', 'Le titre est obligatoire.');
if (!ak_event_valid_date($start_date)) app_fail(422, 'invalid', 'Date de début invalide.');
if ($end_date === '') $end_date = $start_date;
if (!ak_event_valid_date($end_date)) app_fail(422, 'invalid', 'Date de fin invalide.');
if (!ak_event_valid_time($start_time)) $start_time = '14:00';
if (!ak_event_valid_time($end_time)) $end_time = '16:00';
$start_time = substr($start_time, 0, 5);
$end_time = substr($end_time, 0, 5);

// Les valeurs hors liste retombent sur le défaut du site, jamais sur null.
$VALID_TYPES = ['meeting', 'workshop', 'public', 'internal', 'deadline', 'other'];
$VALID_COLORS = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red'];
$VALID_VIS = ['public', 'organization', 'project_only'];

$event_type = (string) ($sent('event_type') ? $input['event_type'] : ($event['event_type'] ?? 'other'));
if (!in_array($event_type, $VALID_TYPES, true)) $event_type = 'other';

// Couleur : absente → inchangée (y compris une couleur Google en #hex) ; vide → « Auto ».
if ($sent('color_theme')) {
    $color_theme = trim((string) $input['color_theme']);
    $color_theme = (in_array($color_theme, $VALID_COLORS, true) || preg_match('/^#[0-9a-f]{6}$/i', $color_theme)) ? $color_theme : null;
} else {
    $color_theme = ($event['color_theme'] ?? '') !== '' ? (string) $event['color_theme'] : null;
}

$visibility = (string) ($sent('visibility') ? $input['visibility'] : ($event['visibility'] ?? 'organization'));
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
