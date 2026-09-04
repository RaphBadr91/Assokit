<?php
/**
 * api/app-grant-action.php — Actions sur une subvention depuis l'app (natif).
 * Reproduit action-subvention.php (toggle_step, log_relance, archive, status). JSON.
 *
 * Rôle : admin ou coordinateur (archive : admin seul, parité web).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../includes-grants.php';

$role = (string) ($user['role'] ?? '');
$is_admin = in_array($role, ['admin', 'super_admin'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$is_admin && $role !== 'coordinator') app_fail(403, 'role', 'Rôle insuffisant.');
if (!function_exists('gr_load')) app_fail(500, 'unavailable', 'Module subventions indisponible.');

$grant_id = (int) ($input['grant_id'] ?? 0);
$action   = (string) ($input['action'] ?? '');
if ($grant_id <= 0) app_fail(422, 'invalid', 'Subvention manquante.');

$g = gr_load($pdo, $grant_id, $org_id);
if (!$g) app_fail(404, 'not_found', 'Subvention introuvable.');

try {
    /* Cocher / décocher une étape du dossier */
    if ($action === 'toggle_step') {
        $step_id = (int) ($input['step_id'] ?? 0);
        $st = $pdo->prepare("SELECT is_completed, title FROM grant_steps WHERE id = ? AND grant_id = ?");
        $st->execute([$step_id, $grant_id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) app_fail(404, 'not_found', 'Étape introuvable.');
        $new = empty($s['is_completed']) ? 1 : 0;
        $pdo->prepare("UPDATE grant_steps SET is_completed = ?, completed_at = ?, completed_by = ? WHERE id = ?")
            ->execute([$new, $new ? date('Y-m-d H:i:s') : null, $new ? $uid : null, $step_id]);
        gr_log($pdo, $grant_id, $uid, 'step_toggle', ($new ? '✅ Étape cochée : ' : '↩️ Décochée : ') . $s['title']);
        echo json_encode(['ok' => true, 'id' => $grant_id, 'done' => (bool) $new, 'message' => $new ? 'Étape validée.' : 'Étape décochée.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Ajouter une étape (checklist du dossier) */
    if ($action === 'add_step') {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') app_fail(422, 'invalid', 'Intitulé de l\'étape obligatoire.');
        $pos = 0;
        try {
            $q = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM grant_steps WHERE grant_id = ?");
            $q->execute([$grant_id]); $pos = (int) $q->fetchColumn();
        } catch (Throwable $e) {}
        $pdo->prepare("INSERT INTO grant_steps (grant_id, position, title, is_completed, completed_at) VALUES (?,?,?,0,NULL)")
            ->execute([$grant_id, $pos, mb_substr($title, 0, 255)]);
        gr_log($pdo, $grant_id, $uid, 'step_add', '➕ Étape ajoutée : ' . $title);
        echo json_encode(['ok' => true, 'id' => $grant_id, 'message' => 'Étape ajoutée.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Changer le statut du dossier */
    if ($action === 'set_status') {
        if (!empty($g['archived_at'])) app_fail(409, 'state', 'Ce dossier est archivé : désarchivez-le sur le site pour le rouvrir.');
        $valid = ['draft', 'submitted', 'in_review', 'granted', 'rejected', 'reported'];
        $new = (string) ($input['status'] ?? '');
        if (!in_array($new, $valid, true)) app_fail(422, 'invalid', 'Statut invalide.');
        $amount_granted = null;
        if ($new === 'granted' && isset($input['amount_granted']) && $input['amount_granted'] !== '' && $input['amount_granted'] !== null) {
            $amount_granted = (float) str_replace([' ', ','], ['', '.'], (string) $input['amount_granted']);
        }
        if ($amount_granted !== null) {
            $pdo->prepare("UPDATE grants SET status = ?, amount_granted = ?, decision_at = COALESCE(decision_at, CURDATE()) WHERE id = ? AND org_id = ?")
                ->execute([$new, $amount_granted, $grant_id, $org_id]);
        } else {
            $sets = "status = ?";
            if ($new === 'submitted') $sets .= ", submitted_at = COALESCE(submitted_at, CURDATE())";
            if (in_array($new, ['granted', 'rejected'], true)) $sets .= ", decision_at = COALESCE(decision_at, CURDATE())";
            $pdo->prepare("UPDATE grants SET $sets WHERE id = ? AND org_id = ?")->execute([$new, $grant_id, $org_id]);
        }
        $m = gr_status_meta($new);
        gr_log($pdo, $grant_id, $uid, 'status_change', '🔄 Statut → ' . $m[0]);
        echo json_encode(['ok' => true, 'id' => $grant_id, 'message' => 'Statut : ' . $m[0] . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Journaliser une relance envoyée au financeur */
    if ($action === 'log_relance') {
        $pdo->prepare("UPDATE grants SET last_relance_at = NOW(), last_relance_by = ? WHERE id = ? AND org_id = ?")
            ->execute([$uid, $grant_id, $org_id]);
        gr_log($pdo, $grant_id, $uid, 'relance_sent', '📧 Relance envoyée au financeur (' . ($g['contact_email'] ?: 'contact') . ')');
        echo json_encode(['ok' => true, 'id' => $grant_id, 'message' => 'Relance enregistrée.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Archiver le dossier (admin seul, parité web) */
    if ($action === 'archive') {
        if (!$is_admin) app_fail(403, 'role', 'Seul un administrateur peut archiver un dossier.');
        $pdo->prepare("UPDATE grants SET archived_at = NOW(), status = 'archived' WHERE id = ? AND org_id = ?")
            ->execute([$grant_id, $org_id]);
        gr_log($pdo, $grant_id, $uid, 'archive', '📦 Dossier archivé');
        echo json_encode(['ok' => true, 'id' => $grant_id, 'archived' => true, 'message' => 'Dossier archivé.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    app_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    error_log('[app-grant-action] ' . $e->getMessage());
    app_fail(500, 'server', 'Action impossible.');
}
