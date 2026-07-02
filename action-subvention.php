<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-grants.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400); die('Bad request.');
}

$user = current_user(); $org_id = (int)$user['org_id']; $user_id = (int)$user['id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }

$action = $_POST['action'] ?? '';

// ============================================================
// TOGGLE STEP
// ============================================================
if ($action === 'toggle_step') {
    $grant_id = (int)($_POST['grant_id'] ?? 0);
    $step_id = (int)($_POST['step_id'] ?? 0);
    $g = gr_load($pdo, $grant_id, $org_id);
    if (!$g) { http_response_code(404); die('Subvention introuvable.'); }
    try {
        $stmt = $pdo->prepare("SELECT is_completed, title FROM grant_steps WHERE id = ? AND grant_id = ?");
        $stmt->execute([$step_id, $grant_id]);
        $st = $stmt->fetch();
        if ($st) {
            $new = $st['is_completed'] ? 0 : 1;
            $pdo->prepare("UPDATE grant_steps SET is_completed = ?, completed_at = ?, completed_by = ? WHERE id = ?")
                ->execute([$new, $new ? date('Y-m-d H:i:s') : null, $new ? $user_id : null, $step_id]);
            gr_log($pdo, $grant_id, $user_id, 'step_toggle', ($new ? '✅ Étape cochée : ' : '↩️ Décochée : ') . $st['title']);
        }
    } catch (Throwable $e) {}
    header('Location: /subvention/' . $grant_id);
    exit;
}

// ============================================================
// CREATE / UPDATE
// ============================================================
if ($action === 'create' || $action === 'update') {
    $name = trim($_POST['name'] ?? '');
    $funder = trim($_POST['funder'] ?? '');
    if (!$name || !$funder) { die('Nom et financeur requis.'); }

    $valid_types = ['etat','region','departement','commune','epci','caf','fondation','entreprise','europe','autre'];
    $valid_statuses = ['draft','submitted','in_review','granted','rejected','reported','archived'];
    $funder_type = in_array($_POST['funder_type'] ?? '', $valid_types, true) ? $_POST['funder_type'] : 'autre';
    $status = in_array($_POST['status'] ?? '', $valid_statuses, true) ? $_POST['status'] : 'draft';
    $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $amount_req = $_POST['amount_requested'] !== '' ? (float)$_POST['amount_requested'] : null;
    $amount_gr = $_POST['amount_granted'] !== '' ? (float)$_POST['amount_granted'] : null;
    $currency = ($_POST['currency'] ?? 'EUR') === 'CHF' ? 'CHF' : 'EUR';
    $deadline_apply = $_POST['deadline_apply'] ?? null ?: null;
    $submitted_at = $_POST['submitted_at'] ?? null ?: null;
    $decision_at = $_POST['decision_at'] ?? null ?: null;
    $deadline_report = $_POST['deadline_report'] ?? null ?: null;
    $reported_at = $_POST['reported_at'] ?? null ?: null;
    $cerfa = trim($_POST['cerfa_number'] ?? '') ?: null;
    $reference = trim($_POST['reference'] ?? '') ?: null;
    $platform = trim($_POST['platform'] ?? '') ?: null;
    $platform_url = trim($_POST['platform_url'] ?? '') ?: null;
    // Validation URL
    if ($platform_url && !filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $platform_url = null;
    }
    $contact_name = trim($_POST['contact_name'] ?? '') ?: null;
    $contact_email = trim($_POST['contact_email'] ?? '') ?: null;
    $contact_phone = trim($_POST['contact_phone'] ?? '') ?: null;

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO grants (org_id, project_id, name, funder, funder_type, description, amount_requested, amount_granted, currency, status,
            deadline_apply, submitted_at, decision_at, deadline_report, reported_at, cerfa_number, reference, platform, platform_url,
            contact_name, contact_email, contact_phone, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$org_id, $project_id, $name, $funder, $funder_type, $description, $amount_req, $amount_gr, $currency, $status,
            $deadline_apply, $submitted_at, $decision_at, $deadline_report, $reported_at, $cerfa, $reference, $platform, $platform_url,
            $contact_name, $contact_email, $contact_phone, $notes, $user_id]);
        $grant_id = (int)$pdo->lastInsertId();
        gr_log($pdo, $grant_id, $user_id, 'create', '🆕 Demande créée : ' . $name);
    } else {
        $grant_id = (int)($_POST['id'] ?? 0);
        $existing = gr_load($pdo, $grant_id, $org_id);
        if (!$existing) { http_response_code(404); die('Introuvable.'); }
        $stmt = $pdo->prepare("UPDATE grants SET project_id=?, name=?, funder=?, funder_type=?, description=?, amount_requested=?, amount_granted=?, currency=?, status=?,
            deadline_apply=?, submitted_at=?, decision_at=?, deadline_report=?, reported_at=?, cerfa_number=?, reference=?, platform=?, platform_url=?,
            contact_name=?, contact_email=?, contact_phone=?, notes=? WHERE id=? AND org_id=?");
        $stmt->execute([$project_id, $name, $funder, $funder_type, $description, $amount_req, $amount_gr, $currency, $status,
            $deadline_apply, $submitted_at, $decision_at, $deadline_report, $reported_at, $cerfa, $reference, $platform, $platform_url,
            $contact_name, $contact_email, $contact_phone, $notes, $grant_id, $org_id]);
        // Détecter changement de statut
        if ($existing['status'] !== $status) {
            $m = gr_status_meta($status);
            gr_log($pdo, $grant_id, $user_id, 'status_change', '🔄 Statut → ' . $m[0]);
        } else {
            gr_log($pdo, $grant_id, $user_id, 'update', '✏️ Modification du dossier');
        }
    }

    // Étapes : remplacement complet
    $step_ids = $_POST['step_id'] ?? [];
    $step_titles = $_POST['step_title'] ?? [];
    $step_dones = $_POST['step_done'] ?? [];

    // Récupère IDs existants pour cette subvention
    $existing_ids = [];
    try {
        $stmt = $pdo->prepare("SELECT id FROM grant_steps WHERE grant_id = ?");
        $stmt->execute([$grant_id]);
        $existing_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}

    $kept_ids = [];
    foreach ($step_titles as $i => $title) {
        $title = trim($title);
        if (!$title) continue;
        $sid = (int)($step_ids[$i] ?? 0);
        $done = (int)($step_dones[$i] ?? 0);
        if ($sid > 0 && in_array($sid, $existing_ids, true)) {
            $pdo->prepare("UPDATE grant_steps SET title=?, position=? WHERE id=? AND grant_id=?")
                ->execute([$title, $i, $sid, $grant_id]);
            $kept_ids[] = $sid;
        } else {
            $pdo->prepare("INSERT INTO grant_steps (grant_id, position, title, is_completed, completed_at) VALUES (?,?,?,?,?)")
                ->execute([$grant_id, $i, $title, $done, $done ? date('Y-m-d H:i:s') : null]);
            $kept_ids[] = (int)$pdo->lastInsertId();
        }
    }
    // Supprime les étapes retirées
    $to_delete = array_diff($existing_ids, $kept_ids);
    if (!empty($to_delete)) {
        $in = implode(',', array_fill(0, count($to_delete), '?'));
        $pdo->prepare("DELETE FROM grant_steps WHERE id IN ($in) AND grant_id = ?")->execute([...$to_delete, $grant_id]);
    }

    header('Location: /subvention/' . $grant_id);
    exit;
}

// ============================================================
// LOG RELANCE (manuelle envoyée au financeur)
// ============================================================
if ($action === 'log_relance') {
    $grant_id = (int)($_POST['id'] ?? 0);
    $g = gr_load($pdo, $grant_id, $org_id);
    if (!$g) { http_response_code(404); die('Introuvable.'); }
    $pdo->prepare("UPDATE grants SET last_relance_at = NOW(), last_relance_by = ? WHERE id = ? AND org_id = ?")
        ->execute([$user_id, $grant_id, $org_id]);
    gr_log($pdo, $grant_id, $user_id, 'relance_sent', '📧 Relance envoyée au financeur (' . ($g['contact_email'] ?: 'contact') . ')');
    header('Location: /subvention/' . $grant_id);
    exit;
}

// ============================================================
// ARCHIVE
// ============================================================
if ($action === 'delete') {
    // [Filet de sécurité] Toute requête POST delete est redirigée vers la
    // page de confirmation GitHub-style (cascade complète + garde-fou).
    // Voir /supprimer-subvention/{id}
    $grant_id = (int)($_POST['grant_id'] ?? 0);
    if ($grant_id > 0) {
        header('Location: /supprimer-subvention/' . $grant_id);
    } else {
        header('Location: /subventions');
    }
    exit;
}

if ($action === 'archive') {
    $grant_id = (int)($_POST['id'] ?? 0);
    if (!$is_admin) { http_response_code(403); die('Admin requis.'); }
    $g = gr_load($pdo, $grant_id, $org_id);
    if (!$g) { http_response_code(404); die('Introuvable.'); }
    $pdo->prepare("UPDATE grants SET archived_at = NOW(), status = 'archived' WHERE id = ? AND org_id = ?")
        ->execute([$grant_id, $org_id]);
    gr_log($pdo, $grant_id, $user_id, 'archive', '📦 Dossier archivé');
    header('Location: /subventions');
    exit;
}

http_response_code(400); die('Action inconnue.');
