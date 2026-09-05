<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Bad request.'); }
$user = current_user(); $org_id = (int)$user['org_id']; $user_id = (int)$user['id'];
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin requis.'); }

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

// CREATE / UPDATE
if ($action === 'create' || $action === 'update') {
    $title = trim($_POST['title'] ?? ''); if (!$title) die('Titre requis.');
    $type = in_array($_POST['type'] ?? '', ['ag_ord','ag_ext','ca','bureau'], true) ? $_POST['type'] : 'ag_ord';
    $sched = $_POST['scheduled_at'] ?? ''; if (!$sched) die('Date requise.');
    $sched_dt = date('Y-m-d H:i:s', strtotime($sched));
    $loc = trim($_POST['location'] ?? '') ?: null;
    $loc_url = trim($_POST['location_url'] ?? '') ?: null;
    $desc = trim($_POST['description'] ?? '') ?: null;
    $quorum = $_POST['quorum_required'] !== '' ? (int)$_POST['quorum_required'] : null;
    $notes = trim($_POST['notes_internal'] ?? '') ?: null;

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO assemblies (org_id, type, title, description, scheduled_at, location, location_url, quorum_required, notes_internal, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$org_id, $type, $title, $desc, $sched_dt, $loc, $loc_url, $quorum, $notes, $user_id]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $existing = ag_load($pdo, $id, $org_id);
        if (!$existing) { http_response_code(404); die('AG introuvable.'); }
        $pdo->prepare("UPDATE assemblies SET type=?, title=?, description=?, scheduled_at=?, location=?, location_url=?, quorum_required=?, notes_internal=? WHERE id=? AND org_id=?")
            ->execute([$type, $title, $desc, $sched_dt, $loc, $loc_url, $quorum, $notes, $id, $org_id]);
    }

    // Résolutions : remplace
    $reso_ids = $_POST['reso_id'] ?? []; $reso_titles = $_POST['reso_title'] ?? []; $reso_types = $_POST['reso_type'] ?? [];
    $existing_ids = []; try { $stmt = $pdo->prepare("SELECT id FROM assembly_resolutions WHERE assembly_id = ?"); $stmt->execute([$id]); $existing_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    $kept = [];
    foreach ($reso_titles as $i => $t) {
        $t = trim($t); if (!$t) continue;
        $rid = (int)($reso_ids[$i] ?? 0);
        $vt = in_array($reso_types[$i] ?? '', ['simple','qualifie_2_3','qualifie_3_4','unanime','consultatif'], true) ? $reso_types[$i] : 'simple';
        if ($rid > 0 && in_array($rid, $existing_ids, true)) {
            $pdo->prepare("UPDATE assembly_resolutions SET title=?, vote_type=?, position=? WHERE id=? AND assembly_id=?")->execute([$t, $vt, $i, $rid, $id]);
            $kept[] = $rid;
        } else {
            $pdo->prepare("INSERT INTO assembly_resolutions (assembly_id, position, title, vote_type) VALUES (?,?,?,?)")->execute([$id, $i, $t, $vt]);
            $kept[] = (int)$pdo->lastInsertId();
        }
    }
    $to_del = array_diff($existing_ids, $kept);
    if ($to_del) {
        $in = implode(',', array_fill(0, count($to_del), '?'));
        $pdo->prepare("DELETE FROM assembly_resolutions WHERE id IN ($in) AND assembly_id = ?")->execute([...$to_del, $id]);
    }
    header('Location: /assemblee/' . $id); exit;
}

// IMPORT ADHERENTS
if ($action === 'import_adherents') {
    $ag = ag_load($pdo, $id, $org_id); if (!$ag) die('AG introuvable.');
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '') ORDER BY last_name, first_name");
        $stmt->execute([$org_id]);
        $count = 0;
        foreach ($stmt->fetchAll() as $a) {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); if (!$name) continue;
            // Vérifier doublon par adherent_id ou email
            $check = $pdo->prepare("SELECT 1 FROM assembly_attendees WHERE assembly_id = ? AND (adherent_id = ? OR (email IS NOT NULL AND email = ?))");
            $check->execute([$id, (int)$a['id'], $a['email'] ?? '']);
            if ($check->fetch()) continue;
            $token = ag_random_token();
            $pdo->prepare("INSERT INTO assembly_attendees (assembly_id, adherent_id, full_name, email, access_token) VALUES (?,?,?,?,?)")
                ->execute([$id, (int)$a['id'], $name, $a['email'] ?? null, $token]);
            $count++;
        }
    } catch (Throwable $e) {}
    header('Location: /assemblee/' . $id); exit;
}

// ADD ATTENDEE
if ($action === 'add_attendee') {
    $name = trim($_POST['full_name'] ?? ''); if (!$name) die('Nom requis.');
    $email = trim($_POST['email'] ?? '') ?: null;
    $token = ag_random_token();
    $pdo->prepare("INSERT INTO assembly_attendees (assembly_id, full_name, email, access_token) VALUES (?,?,?,?)")
        ->execute([$id, $name, $email, $token]);
    header('Location: /assemblee/' . $id); exit;
}

// REMOVE ATTENDEE
if ($action === 'remove_attendee') {
    $aid = (int)($_POST['attendee_id'] ?? 0);
    $pdo->prepare("DELETE FROM assembly_attendees WHERE id = ? AND assembly_id = ?")->execute([$aid, $id]);
    header('Location: /assemblee/' . $id); exit;
}

// SEND INVITATIONS
if ($action === 'send_invitations') {
    $ag = ag_load($pdo, $id, $org_id); if (!$ag) die('AG introuvable.');
    $stmt = $pdo->prepare("SELECT * FROM assembly_attendees WHERE assembly_id = ? AND email IS NOT NULL AND email != ''");
    $stmt->execute([$id]);
    $atts = $stmt->fetchAll();
    $sent = 0;
    foreach ($atts as $a) {
        if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
        $url = "https://" . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . "/ag-public/" . $a['access_token'];
        $subject = "📨 Convocation : " . $ag['title'];
        $when = fr_format_date('%A %d %B %Y à %H:%M', strtotime($ag['scheduled_at']));
        $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;background:#f9fafb;">'
            . '<div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e5e7eb;">'
            . '<h1 style="font-size:20px;margin:0 0 12px;color:#111827;">📨 Convocation</h1>'
            . '<p style="color:#4b5563;font-size:14px;line-height:1.6;">Bonjour <strong>' . htmlspecialchars($a['full_name']) . '</strong>,</p>'
            . '<p style="color:#4b5563;font-size:14px;line-height:1.6;">Vous êtes convoqué·e à :</p>'
            . '<div style="background:#ECFDF5;padding:14px 18px;border-radius:10px;margin:14px 0;">'
            . '<strong style="font-size:16px;color:#065F46;">' . htmlspecialchars($ag['title']) . '</strong><br>'
            . '<span style="font-size:13px;color:#374151;">📅 ' . $when . '</span>'
            . ($ag['location'] ? '<br><span style="font-size:13px;color:#374151;">📍 ' . htmlspecialchars($ag['location']) . '</span>' : '')
            . '</div>'
            . '<p style="color:#4b5563;font-size:14px;">Merci de cliquer sur le lien ci-dessous pour confirmer votre présence et signer électroniquement :</p>'
            . '<a href="' . $url . '" style="display:inline-block;background:#10B981;color:#fff;padding:14px 26px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin:10px 0;">Accéder à mon espace personnel →</a>'
            . '<p style="font-size:11px;color:#6b7280;margin-top:18px;">Ce lien est strictement personnel et ne doit pas être partagé.</p>'
            . '</div></div>';
        $text = "Convocation : " . $ag['title'] . "\n\n" . $when . "\n\nLien personnel : " . $url;
        try {
            if (function_exists('send_email_resend')) { send_email_resend($a['email'], $subject, $html, $text); }
            else {
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: AssoKit <noreply@assokit.fr>\r\n";
                @mail($a['email'], $subject, $html, $headers);
            }
            $pdo->prepare("UPDATE assembly_attendees SET invitation_sent_at = NOW() WHERE id = ?")->execute([(int)$a['id']]);
            $sent++;
        } catch (Throwable $e) {}
    }
    $pdo->prepare("UPDATE assemblies SET convocation_sent_at = NOW(), status = 'sent' WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /assemblee/' . $id); exit;
}

// OPEN
if ($action === 'open') {
    $pdo->prepare("UPDATE assemblies SET status = 'in_progress', opened_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /assemblee/' . $id); exit;
}

// CLOSE + compute results
if ($action === 'close') {
    $resos = ag_load_resolutions($pdo, $id);
    foreach ($resos as $r) {
        $stats = ag_resolution_stats($pdo, (int)$r['id']);
        $result = ag_compute_result($r['vote_type'], $stats);
        $pdo->prepare("UPDATE assembly_resolutions SET is_open = 0, closed_at = NOW(), result = ? WHERE id = ?")->execute([$result, (int)$r['id']]);
    }
    $pdo->prepare("UPDATE assemblies SET status = 'closed', closed_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /assemblee/' . $id); exit;
}

http_response_code(400); die('Action inconnue.');
