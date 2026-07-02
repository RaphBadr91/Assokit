<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Bad request.'); }
$user = current_user(); $org_id = (int)$user['org_id']; $user_id = (int)$user['id'];
if (!in_array($user['role'], ['admin','coordinator'], true)) { http_response_code(403); die('Réservé.'); }

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'create' || $action === 'update') {
    $title = trim($_POST['title'] ?? ''); if (!$title) die('Titre requis.');
    $loc = trim($_POST['location'] ?? '') ?: null;
    $desc = trim($_POST['description'] ?? '') ?: null;
    $starts = $_POST['starts_at'] ?? ''; if (!$starts) die('Date de début requise.');
    $starts_dt = date('Y-m-d H:i:s', strtotime($starts));
    $ends = trim($_POST['ends_at'] ?? '');
    $ends_dt = $ends ? date('Y-m-d H:i:s', strtotime($ends)) : null;
    $req_sig = !empty($_POST['require_signature']) ? 1 : 0;
    $project_id = (int)($_POST['project_id'] ?? 0) ?: null;

    if ($action === 'create') {
        $token = att_random_token();
        $stmt = $pdo->prepare("INSERT INTO attendance_sessions (org_id, project_id, title, description, location, starts_at, ends_at, access_token, require_signature, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$org_id, $project_id, $title, $desc, $loc, $starts_dt, $ends_dt, $token, $req_sig, $user_id]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $existing = att_load($pdo, $id, $org_id);
        if (!$existing) { http_response_code(404); die('Introuvable.'); }
        $pdo->prepare("UPDATE attendance_sessions SET project_id=?, title=?, description=?, location=?, starts_at=?, ends_at=?, require_signature=? WHERE id=? AND org_id=?")
            ->execute([$project_id, $title, $desc, $loc, $starts_dt, $ends_dt, $req_sig, $id, $org_id]);
    }
    header('Location: /emargement/' . $id); exit;
}

if ($action === 'close') {
    $pdo->prepare("UPDATE attendance_sessions SET is_open = 0, closed_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /emargement/' . $id); exit;
}

if ($action === 'reopen') {
    $pdo->prepare("UPDATE attendance_sessions SET is_open = 1, closed_at = NULL WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /emargement/' . $id); exit;
}

if ($action === 'remove_record') {
    $rid = (int)($_POST['record_id'] ?? 0);
    $sess = att_load($pdo, $id, $org_id); if (!$sess) die('Introuvable.');
    $pdo->prepare("DELETE FROM attendance_records WHERE id = ? AND session_id = ?")->execute([$rid, $id]);
    header('Location: /emargement/' . $id); exit;
}

if ($action === 'archive') {
    $pdo->prepare("UPDATE attendance_sessions SET archived_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /emargement'); exit;
}

http_response_code(400); die('Action inconnue.');
