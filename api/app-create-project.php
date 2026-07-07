<?php
/**
 * api/app-create-project.php — Creation d'un projet depuis l'app (natif).
 * Reproduit fidelement nouveau-projet.php : projet + etapes (min 4) + referent membre.
 * Supporte la creation inline d'un dossier. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

$name = trim((string) ($input['name'] ?? ''));
if ($name === '') app_fail(422, 'invalid', 'Le nom du projet est obligatoire.');

// Etapes (min 4, comme le site)
$steps = [];
foreach ((array) ($input['steps'] ?? []) as $s) {
    $t = trim((string) (is_array($s) ? ($s['title'] ?? '') : $s));
    if ($t !== '') $steps[] = mb_substr($t, 0, 255);
}
if (count($steps) < 4) app_fail(422, 'invalid', 'Un projet doit avoir au moins 4 étapes.');

// Dossier : existant ou nouveau
$folder_id = (int) ($input['folder_id'] ?? 0);
$new_folder = trim((string) ($input['new_folder'] ?? ''));

try {
    if ($folder_id > 0) {
        $st = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND org_id = ? LIMIT 1");
        $st->execute([$folder_id, $org_id]);
        if (!$st->fetchColumn()) app_fail(422, 'invalid', 'Dossier invalide.');
    } elseif ($new_folder !== '') {
        // Reutilise un dossier de meme nom si existant (evite doublon)
        $st = $pdo->prepare("SELECT id FROM folders WHERE org_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $st->execute([$org_id, $new_folder]);
        $existing = (int) $st->fetchColumn();
        if ($existing > 0) {
            $folder_id = $existing;
        } else {
            $st = $pdo->prepare("INSERT INTO folders (org_id, name, color_theme, created_by, created_at) VALUES (?, ?, 'blue', ?, NOW())");
            $st->execute([$org_id, mb_substr($new_folder, 0, 100), $uid]);
            $folder_id = (int) $pdo->lastInsertId();
        }
    } else {
        app_fail(422, 'invalid', 'Choisissez ou créez un dossier.');
    }

    // Referent optionnel
    $referent_id = (int) ($input['referent_id'] ?? 0);
    if ($referent_id > 0) {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? LIMIT 1");
        $st->execute([$referent_id, $org_id]);
        if (!$st->fetchColumn()) $referent_id = 0;
    }

    $description = trim((string) ($input['description'] ?? '')) ?: null;
    $objective   = trim((string) ($input['objective'] ?? '')) ?: null;
    $location    = trim((string) ($input['location'] ?? '')) ?: null;
    $budget      = (float) str_replace(',', '.', (string) ($input['budget_planned'] ?? '0'));
    $start_date  = trim((string) ($input['start_date'] ?? '')) ?: null;
    $end_date    = trim((string) ($input['end_date'] ?? '')) ?: null;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO projects (
            folder_id, name, location, description, objective, referent_id,
            budget_planned, budget_used, participants_count,
            participants_female, participants_male,
            start_date, end_date, status, progress_percent
        ) VALUES (
            :folder_id, :name, :location, :description, :objective, :referent_id,
            :budget_planned, 0, 0, 0, 0,
            :start_date, :end_date, 'active', 0
        )
    ");
    $stmt->execute([
        ':folder_id'      => $folder_id,
        ':name'           => mb_substr($name, 0, 200),
        ':location'       => $location,
        ':description'    => $description,
        ':objective'      => $objective,
        ':referent_id'    => $referent_id ?: null,
        ':budget_planned' => $budget,
        ':start_date'     => $start_date,
        ':end_date'       => $end_date,
    ]);
    $new_id = (int) $pdo->lastInsertId();

    $stmt_step = $pdo->prepare("INSERT INTO project_steps (project_id, position, title) VALUES (?, ?, ?)");
    foreach ($steps as $i => $title) {
        $stmt_step->execute([$new_id, $i + 1, $title]);
    }

    if ($referent_id > 0) {
        try {
            $pdo->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role_in_project, joined_at) VALUES (?, ?, 'referent', NOW())")
                ->execute([$new_id, $referent_id]);
        } catch (Throwable $e) {}
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'id' => $new_id, 'message' => 'Projet « ' . $name . ' » créé.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app-create-project] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer le projet.');
}
