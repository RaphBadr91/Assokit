<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/demo-guard.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/trial-limits-helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Bad request.'); }
$user = current_user(); $org_id = (int)$user['org_id']; $user_id = (int)$user['id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin requis.'); }

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

/** Retour sur la fiche de l'AG, avec un message : 'error' (rouge), 'warn' (orange) ou 'ok' (vert). */
function ag_back(int $id, ?string $msg = null, string $kind = 'error'): void {
    if ($msg !== null) $_SESSION[['ok' => 'flash_assemblee_ok', 'warn' => 'flash_assemblee_warn'][$kind] ?? 'flash_assemblee'] = $msg;
    header('Location: /assemblee/' . $id); exit;
}

// Toute action sur une AG existante : elle doit appartenir à l'association
// (sinon un admin d'une autre asso pourrait agir sur ses participants ou
// clore ses résolutions en changeant l'id).
$ag = null;
if ($action !== 'create') {
    $ag = ag_load($pdo, $id, $org_id);
    if (!$ag) { http_response_code(404); die('AG introuvable.'); }
}
$ag_status = $ag['status'] ?? 'draft';

// AG clôturée : figée. Le PV régénéré doit rester celui de la séance.
if ($ag && ag_is_frozen($ag_status)) {
    ag_back($id, 'Cette assemblée est clôturée : ses participants, ses résolutions et son procès-verbal ne sont plus modifiables.');
}

// CREATE / UPDATE
if ($action === 'create' || $action === 'update') {
    $title = trim($_POST['title'] ?? ''); if (!$title) die('Titre requis.');
    $loc = trim($_POST['location'] ?? '') ?: null;
    // Lien visio : uniquement une adresse web http(s) (jamais javascript: ou data:,
    // qui s'exécuteraient au clic sur la page publique du convoqué).
    $loc_url = trim((string)($_POST['location_url'] ?? '')) ?: null;
    $loc_url_refused = false;
    if ($loc_url !== null && (ag_safe_url($loc_url) === null || !parse_url($loc_url, PHP_URL_HOST) || preg_match('/[\s<>"]/', $loc_url))) {
        $loc_url = $ag ? ag_safe_url($ag['location_url'] ?? null) : null;   // modification : l'ancien lien valide reste
        $loc_url_refused = true;
    }
    $desc = trim($_POST['description'] ?? '') ?: null;
    $notes = trim($_POST['notes_internal'] ?? '') ?: null;

    // Séance ouverte : type, date, quorum et résolutions ne bougent plus (les votes sont en cours).
    $session_open = ($action === 'update' && $ag_status === 'in_progress');
    if ($session_open) {
        $type = $ag['type']; $sched_dt = $ag['scheduled_at']; $quorum = $ag['quorum_required'];
    } else {
        $type = in_array($_POST['type'] ?? '', ['ag_ord','ag_ext','ca','bureau'], true) ? $_POST['type'] : 'ag_ord';
        $sched = $_POST['scheduled_at'] ?? ''; if (!$sched) die('Date requise.');
        $sched_dt = date('Y-m-d H:i:s', strtotime($sched));
        $quorum = ($_POST['quorum_required'] ?? '') !== '' ? max(0, (int)$_POST['quorum_required']) : null;
    }

    if ($action === 'create') {
        // Statut écrit explicitement : la fiche ne doit pas dépendre d'une valeur par défaut en base.
        $stmt = $pdo->prepare("INSERT INTO assemblies (org_id, type, title, description, scheduled_at, location, location_url, quorum_required, notes_internal, created_by, status) VALUES (?,?,?,?,?,?,?,?,?,?,'draft')");
        $stmt->execute([$org_id, $type, $title, $desc, $sched_dt, $loc, $loc_url, $quorum, $notes, $user_id]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE assemblies SET type=?, title=?, description=?, scheduled_at=?, location=?, location_url=?, quorum_required=?, notes_internal=? WHERE id=? AND org_id=?")
            ->execute([$type, $title, $desc, $sched_dt, $loc, $loc_url, $quorum, $notes, $id, $org_id]);
    }

    // Résolutions : remplace (seulement avant l'ouverture de la séance)
    if (!$session_open) {
        $reso_ids = $_POST['reso_id'] ?? []; $reso_titles = $_POST['reso_title'] ?? []; $reso_types = $_POST['reso_type'] ?? [];
        $existing_ids = []; try { $stmt = $pdo->prepare("SELECT id FROM assembly_resolutions WHERE assembly_id = ?"); $stmt->execute([$id]); $existing_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
        $kept = [];
        foreach ((array)$reso_titles as $i => $t) {
            $t = trim((string)$t); if (!$t) continue;
            $rid = (int)($reso_ids[$i] ?? 0);
            $vt = in_array($reso_types[$i] ?? '', ['simple','qualifie_2_3','qualifie_3_4','unanime','consultatif'], true) ? $reso_types[$i] : 'simple';
            if ($rid > 0 && in_array($rid, $existing_ids, true)) {
                $pdo->prepare("UPDATE assembly_resolutions SET title=?, vote_type=?, position=? WHERE id=? AND assembly_id=?")->execute([$t, $vt, $i, $rid, $id]);
                $kept[] = $rid;
            } else {
                // Fermée au vote jusqu'à l'ouverture de la séance (action 'open').
                $pdo->prepare("INSERT INTO assembly_resolutions (assembly_id, position, title, vote_type, is_open, result) VALUES (?,?,?,?,0,'pending')")->execute([$id, $i, $t, $vt]);
                $kept[] = (int)$pdo->lastInsertId();
            }
        }
        $to_del = array_values(array_diff($existing_ids, $kept));
        if ($to_del) {
            $in = implode(',', array_fill(0, count($to_del), '?'));
            try { $pdo->prepare("DELETE FROM assembly_votes WHERE resolution_id IN ($in)")->execute($to_del); } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM assembly_resolutions WHERE id IN ($in) AND assembly_id = ?")->execute([...$to_del, $id]);
        }
    }
    if ($loc_url_refused) {
        ag_back($id, 'Lien visio non enregistré : saisissez une adresse web complète commençant par https:// (ex. https://meet.jit.si/mon-ag).', 'warn');
    }
    header('Location: /assemblee/' . $id); exit;
}

// IMPORT ADHERENTS : comptes actifs, adhésion à jour, hors financeurs (Suiveurs, externes)
if ($action === 'import_adherents') {
    try {
        $ucols = ak_db_columns($pdo, 'users');
        $has = fn($c) => !$ucols || isset($ucols[$c]);
        $where = ["org_id = ?"];
        if ($has('deleted_at')) $where[] = "(deleted_at IS NULL OR deleted_at = '')";
        if ($has('is_active'))  $where[] = "is_active = 1";
        if ($has('role'))       $where[] = "(role IS NULL OR role <> 'follower')";
        $expired = $has('adhesion_valid_until')
            ? "(adhesion_valid_until IS NOT NULL AND adhesion_valid_until > '1000-01-01' AND adhesion_valid_until < CURDATE())"
            : "0";
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, $expired AS expired FROM users WHERE " . implode(' AND ', $where) . " ORDER BY last_name, first_name");
        $stmt->execute([$org_id]);
        $members = $stmt->fetchAll();

        // Déjà convoqués : par compte lié (adherent_id ou user_id selon la base) ou par e-mail
        $known_ids = []; $known_emails = [];
        foreach (ag_load_attendees($pdo, $id) as $x) {
            foreach (['adherent_id', 'user_id'] as $c) if (!empty($x[$c])) $known_ids[(int)$x[$c]] = true;
            if (!empty($x['email'])) $known_emails[mb_strtolower(trim($x['email']))] = true;
        }
        $invited = ak_db_accepts($pdo, 'assembly_attendees', 'status', 'invited') ? ['status' => 'invited'] : [];

        $count = 0; $skipped_expired = 0;
        foreach ($members as $a) {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')); if (!$name) continue;
            $mail = trim((string)($a['email'] ?? ''));
            if (isset($known_ids[(int)$a['id']]) || ($mail !== '' && isset($known_emails[mb_strtolower($mail)]))) continue;
            if (!empty($a['expired'])) { $skipped_expired++; continue; }
            ak_db_insert($pdo, 'assembly_attendees', [
                'assembly_id' => $id, 'adherent_id' => (int)$a['id'], 'user_id' => (int)$a['id'],
                'full_name' => $name, 'email' => $mail !== '' ? $mail : null, 'access_token' => ag_random_token(),
            ] + $invited, ['created_at' => 'NOW()']);
            $known_ids[(int)$a['id']] = true;
            $count++;
        }
    } catch (Throwable $e) {
        error_log('[action-assemblee] import_adherents : ' . $e->getMessage());
        ag_back($id, 'L’import des adhérents a échoué. Réessayez, ou ajoutez les participants un par un.');
    }
    $msg = $count === 0 ? 'Aucun nouvel adhérent à importer : tous les adhérents actifs sont déjà dans la liste.'
                        : $count . ' adhérent' . ($count > 1 ? 's' : '') . ' actif' . ($count > 1 ? 's' : '') . ' importé' . ($count > 1 ? 's' : '') . '.';
    if ($skipped_expired > 0) {
        $msg .= ' ' . $skipped_expired . ' adhésion' . ($skipped_expired > 1 ? 's' : '') . ' expirée' . ($skipped_expired > 1 ? 's' : '')
              . ' non importée' . ($skipped_expired > 1 ? 's' : '') . ' (à ajouter avec « + Ajouter » si vos statuts donnent le droit de vote aux adhérents qui ne sont pas à jour).';
    }
    ag_back($id, $msg, 'ok');
}

// ADD ATTENDEE
if ($action === 'add_attendee') {
    $name = trim($_POST['full_name'] ?? ''); if (!$name) die('Nom requis.');
    $email = trim($_POST['email'] ?? '') ?: null;
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ag_back($id, '« ' . $email . ' » n’est pas une adresse e-mail valide : ' . $name . ' n’a pas été ajouté·e. Corrigez l’adresse, ou laissez-la vide pour convoquer cette personne autrement.');
    }
    if ($email !== null) {
        $dup = $pdo->prepare("SELECT 1 FROM assembly_attendees WHERE assembly_id = ? AND LOWER(email) = LOWER(?)");
        $dup->execute([$id, $email]);
        if ($dup->fetchColumn()) ag_back($id, $email . ' figure déjà dans la liste des participants.');
    }
    $invited = ak_db_accepts($pdo, 'assembly_attendees', 'status', 'invited') ? ['status' => 'invited'] : [];
    ak_db_insert($pdo, 'assembly_attendees', ['assembly_id' => $id, 'full_name' => $name, 'email' => $email, 'access_token' => ag_random_token()] + $invited, ['created_at' => 'NOW()']);
    header('Location: /assemblee/' . $id); exit;
}

// REMOVE ATTENDEE
if ($action === 'remove_attendee') {
    $aid = (int)($_POST['attendee_id'] ?? 0);
    // Essai gratuit : un participant convoqué il y a moins de 24 h reste compté dans le quota
    // d'envoi (sinon « ajouter → convoquer → retirer » permettrait d'écrire sans limite).
    if (ak_trial_limited($pdo, $org_id)) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM assembly_attendees WHERE id = ? AND assembly_id = ? AND invitation_sent_at >= NOW() - INTERVAL 1 DAY");
            $st->execute([$aid, $id]);
            if ($st->fetchColumn()) {
                $_SESSION['flash_assemblee'] = 'Pendant l’essai gratuit, un participant convoqué ne peut être retiré que 24 h après sa convocation. Passez à une formule Assokit pour lever la limite.';
                header('Location: /assemblee/' . $id); exit;
            }
        } catch (Throwable $e) {}
    }
    $st = $pdo->prepare("SELECT 1 FROM assembly_attendees WHERE id = ? AND assembly_id = ?");
    $st->execute([$aid, $id]);
    if ($st->fetchColumn()) {
        // Ses votes partent avec lui : ils ne doivent plus compter dans les résultats.
        try { $pdo->prepare("DELETE FROM assembly_votes WHERE attendee_id = ?")->execute([$aid]); } catch (Throwable $e) {}
        // Les pouvoirs qu'il portait redeviennent sans mandataire (à réattribuer).
        if (ag_has_proxy_holder($pdo)) {
            $pdo->prepare("UPDATE assembly_attendees SET proxy_attendee_id = NULL WHERE assembly_id = ? AND proxy_attendee_id = ?")->execute([$id, $aid]);
        }
        $pdo->prepare("DELETE FROM assembly_attendees WHERE id = ? AND assembly_id = ?")->execute([$aid, $id]);
    }
    header('Location: /assemblee/' . $id); exit;
}

// SET ATTENDEE STATUS : l'admin corrige une présence, une excuse, une absence ou un pouvoir
if ($action === 'set_attendee_status') {
    $aid = (int)($_POST['attendee_id'] ?? 0);
    $new = (string)($_POST['status'] ?? '');
    $labels = ['invited' => 'Invité', 'present' => 'Présent', 'excused' => 'Excusé', 'absent' => 'Absent', 'proxy' => 'Pouvoir'];
    if (!isset($labels[$new])) ag_back($id, 'Statut inconnu.');
    $st = $pdo->prepare("SELECT * FROM assembly_attendees WHERE id = ? AND assembly_id = ?");
    $st->execute([$aid, $id]);
    $a = $st->fetch();
    if (!$a) ag_back($id, 'Participant introuvable.');
    if ($new === 'present' && $ag_status !== 'in_progress') {
        ag_back($id, 'La présence se constate pendant la séance : ouvrez d’abord la séance pour marquer un participant présent. Avant, vous pouvez noter une excuse ou un pouvoir.');
    }
    if (ak_db_columns($pdo, 'assembly_attendees') && !ak_db_accepts($pdo, 'assembly_attendees', 'status', $new)) {
        ag_back($id, 'Le statut « ' . $labels[$new] . ' » n’est pas disponible sur votre espace.');
    }
    $data = ['status' => $new];
    $raw = [];
    // Plus présent : l'émargement électronique n'a plus de valeur (et ne permet plus de voter).
    if ($new !== 'present' && !empty($a['signed_at'])) { $data['signed_at'] = null; $data['signature_data'] = null; }
    // Présence constatée par l'organisateur (participant sans smartphone, émargement papier) :
    // elle est horodatée comme un émargement, sans signature (« pointé » sur la fiche et le PV),
    // et le lien personnel permet ensuite de voter. Une signature « je serai présent·e » faite
    // avant la séance ne vaut pas émargement : elle est écartée.
    if ($new === 'present' && empty($a['signed_at'])) { $raw['signed_at'] = 'NOW()'; $data['signature_data'] = null; }
    // Pouvoir donné : l'ancienne réponse « je viendrai » ne vaut plus (le représenté peut
    // encore répondre ensuite sur son lien, sans perdre son pouvoir).
    if ($new === 'proxy' && ($a['status'] ?? '') !== 'proxy' && ag_has_col($pdo, 'assembly_attendees', 'rsvp')) $data['rsvp'] = null;
    $has_holder = ag_has_proxy_holder($pdo);
    if ($has_holder && $new !== 'proxy') $data['proxy_attendee_id'] = null;
    ak_db_update($pdo, 'assembly_attendees', $aid, $data, $raw);

    $msg = $a['full_name'] . ' : ' . $labels[$new] . '.';
    $kind = 'ok';
    // Plus présent en séance : ses votes sur les résolutions encore ouvertes ne comptent plus
    // (un représenté vote alors par son mandataire). Les résolutions déjà closes restent acquises.
    if ($new !== 'present') {
        try {
            $st = $pdo->prepare("DELETE v FROM assembly_votes v JOIN assembly_resolutions r ON r.id = v.resolution_id
                                  WHERE v.attendee_id = ? AND r.assembly_id = ? AND r.closed_at IS NULL");
            $st->execute([$aid, $id]);
            if ($st->rowCount() > 0) $msg .= ' Ses votes ont été retirés des résultats.';
        } catch (Throwable $e) {
            error_log('[action-assemblee] set_attendee_status votes : ' . $e->getMessage());
        }
    }
    if ($has_holder && $new === 'proxy' && empty($a['proxy_attendee_id'])) {
        $msg .= ' Choisissez maintenant son mandataire (la personne qui votera pour elle ou lui) dans la liste des participants.';
    }
    // Excusé, absent ou représenté : ne peut plus porter les pouvoirs qu'on lui avait confiés.
    if ($has_holder && !ag_can_hold_proxy(['status' => $new])) {
        $st = $pdo->prepare("SELECT full_name FROM assembly_attendees WHERE assembly_id = ? AND status = 'proxy' AND proxy_attendee_id = ?");
        $st->execute([$id, $aid]);
        $orphans = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($orphans) {
            $pdo->prepare("UPDATE assembly_attendees SET proxy_attendee_id = NULL WHERE assembly_id = ? AND proxy_attendee_id = ?")->execute([$id, $aid]);
            $msg .= ' ' . $a['full_name'] . ' portait ' . (count($orphans) > 1 ? 'les pouvoirs de ' : 'le pouvoir de ') . implode(', ', $orphans)
                  . ' : désignez un autre mandataire.';
            $kind = 'warn';
        }
    }
    ag_back($id, $msg, $kind);
}

// SET PROXY HOLDER : mandataire d'un participant représenté (pouvoir / procuration)
if ($action === 'set_proxy_holder') {
    if (!ag_has_proxy_holder($pdo)) {
        ag_back($id, 'L’enregistrement du mandataire n’est pas encore disponible sur votre espace (mise à jour de la base à appliquer : migrations/2026-10-11-L04-agenda.sql).');
    }
    $aid = (int)($_POST['attendee_id'] ?? 0);
    $holder_id = (int)($_POST['proxy_to'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM assembly_attendees WHERE id = ? AND assembly_id = ?");
    $st->execute([$aid, $id]);
    $a = $st->fetch();
    if (!$a) ag_back($id, 'Participant introuvable.');
    if (($a['status'] ?? '') !== 'proxy') ag_back($id, 'Indiquez d’abord « Pouvoir » comme statut de ' . $a['full_name'] . '.');
    if ($holder_id > 0) {
        if ($holder_id === $aid) ag_back($id, 'Un participant ne peut pas être son propre mandataire.');
        $st->execute([$holder_id, $id]);
        $holder = $st->fetch();
        if (!$holder) ag_back($id, 'Mandataire introuvable parmi les participants de cette assemblée.');
        if (!ag_can_hold_proxy($holder)) {
            ag_back($id, $holder['full_name'] . ' est excusé·e, absent·e ou lui-même représenté·e : choisissez un mandataire qui participe à la séance.');
        }
    }
    ak_db_update($pdo, 'assembly_attendees', $aid, ['proxy_attendee_id' => $holder_id > 0 ? $holder_id : null]);
    ag_back($id, $holder_id > 0
        ? $a['full_name'] . ' est représenté·e par ' . $holder['full_name'] . ', qui votera aussi pour elle ou lui.'
        : 'Mandataire retiré : le pouvoir de ' . $a['full_name'] . ' compte pour le quorum mais ne vote pas tant qu’aucun mandataire n’est désigné.', 'ok');
}

// SEND INVITATIONS
if ($action === 'send_invitations') {
    $stmt = $pdo->prepare("SELECT * FROM assembly_attendees WHERE assembly_id = ? AND email IS NOT NULL AND email != ''");
    $stmt->execute([$id]);
    $atts = $stmt->fetchAll();

    // Essai gratuit : 200 destinataires par envoi, 500 par 24 h (anti-relais de spam), et pas
    // de renvoi à un participant déjà convoqué dans les 24 h (sinon « Renvoyer » contournerait
    // le quota, qui compte les convocations par date d'envoi).
    if (ak_trial_limited($pdo, $org_id)) {
        $valid = fn($list) => count(array_filter($list, fn($a) => filter_var($a['email'], FILTER_VALIDATE_EMAIL)));
        $n_all = $valid($atts);
        try {
            $st = $pdo->prepare("SELECT * FROM assembly_attendees WHERE assembly_id = ? AND email IS NOT NULL AND email != ''
                                   AND (invitation_sent_at IS NULL OR invitation_sent_at < NOW() - INTERVAL 1 DAY)");
            $st->execute([$id]);
            $atts = $st->fetchAll();
        } catch (Throwable $e) {}
        $n = $valid($atts);
        $trial_block = ($n_all > 0 && $n === 0)
            ? 'Pendant l’essai gratuit, une convocation ne peut être renvoyée qu’après 24 h : tous les participants ont déjà reçu la leur. Passez à une formule Assokit pour lever la limite.'
            : ak_trial_send_block($pdo, $org_id, $n);
        if ($trial_block) {
            $_SESSION['flash_assemblee'] = $trial_block;
            header('Location: /assemblee/' . $id); exit;
        }
    }

    // Une convocation porte l'ordre du jour : présentation + résolutions, et le lien visio.
    $agenda_html = ag_agenda_html($ag, ag_load_resolutions($pdo, $id));
    $visio = ag_safe_url($ag['location_url'] ?? null);
    $org_name = '';
    try { $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $st->execute([$org_id]); $org_name = trim((string)$st->fetchColumn()); } catch (Throwable $e) {}
    $mail_opts = ['tag' => 'ag_convocation'] + ($org_name !== '' ? ['from_name' => $org_name] : []);

    $sent = 0; $failed = 0; $invalid = 0;
    foreach ($atts as $a) {
        if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
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
            . ($visio ? '<br><span style="font-size:13px;color:#374151;">🎥 <a href="' . htmlspecialchars($visio) . '" style="color:#065F46;">' . htmlspecialchars($visio) . '</a></span>' : '')
            . '</div>'
            . $agenda_html
            . '<p style="color:#4b5563;font-size:14px;">Cliquez sur le lien ci-dessous pour indiquer si vous serez présent·e. Le jour de la séance, ce même lien vous permettra d’émarger puis de voter :</p>'
            . '<a href="' . $url . '" style="display:inline-block;background:#10B981;color:#fff;padding:14px 26px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin:10px 0;">Accéder à mon espace personnel →</a>'
            . '<p style="font-size:11px;color:#6b7280;margin-top:18px;">Ce lien est strictement personnel et ne doit pas être partagé.</p>'
            . '</div></div>';
        // Même service que les autres e-mails (Resend) ; seule une convocation réellement partie est comptée.
        $res = ['success' => false];
        try { $res = send_transactional_email($a['email'], $subject, $html, $mail_opts); } catch (Throwable $e) { error_log('[action-assemblee] convocation : ' . $e->getMessage()); }
        if (!empty($res['success'])) {
            $pdo->prepare("UPDATE assembly_attendees SET invitation_sent_at = NOW() WHERE id = ?")->execute([(int)$a['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }

    $no_email = 0;
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM assembly_attendees WHERE assembly_id = ? AND (email IS NULL OR email = '')"); $st->execute([$id]); $no_email = (int)$st->fetchColumn(); } catch (Throwable $e) {}
    $unreachable = $invalid + $no_email;
    $pl = fn($n, $s, $p) => $n . ' ' . ($n > 1 ? $p : $s);

    $notes = [];
    if ($failed > 0) $notes[] = $pl($failed, 'envoi en échec', 'envois en échec') . ' (réessayez avec « Renvoyer convocations »)';
    if ($unreachable > 0) $notes[] = $pl($unreachable, 'participant', 'participants') . ' sans adresse e-mail valide, à convoquer autrement (courrier, remise en main propre)';

    if ($sent > 0) {
        // L'AG n'est « convoquée » que si quelqu'un a vraiment reçu sa convocation.
        $pdo->prepare("UPDATE assemblies SET convocation_sent_at = NOW(),
                              status = CASE WHEN status IS NULL OR status = '' OR status = 'draft' THEN 'sent' ELSE status END
                        WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
        ag_back($id, $pl($sent, 'convocation envoyée', 'convocations envoyées') . '.' . ($notes ? ' ' . implode('. ', $notes) . '.' : ''), $notes ? 'warn' : 'ok');
    }
    ag_back($id, 'Aucune convocation n’a été envoyée' . ($notes ? ' : ' . implode('. ', $notes) . '.' : ' : ajoutez d’abord des participants.'));
}

// OPEN
if ($action === 'open') {
    if (!in_array($ag_status, ['draft', 'sent'], true)) ag_back($id, 'La séance est déjà ouverte.');
    $pdo->prepare("UPDATE assemblies SET status = 'in_progress', opened_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    // Les résolutions s'ouvrent au vote avec la séance (sans dépendre d'une valeur par défaut en base).
    $pdo->prepare("UPDATE assembly_resolutions SET is_open = 1, result = 'pending' WHERE assembly_id = ? AND closed_at IS NULL")->execute([$id]);
    // Reprise de l'ancienne version : « je serai présent·e » signé AVANT la séance n'était
    // qu'une réponse à la convocation. La présence se constate en séance (quorum, PV).
    try {
        $has_rsvp = ag_has_col($pdo, 'assembly_attendees', 'rsvp');
        $pdo->prepare("UPDATE assembly_attendees SET status = 'invited', signed_at = NULL"
                    . ($has_rsvp ? ", rsvp = 'yes'" : "")
                    . " WHERE assembly_id = ? AND status = 'present'")->execute([$id]);
    } catch (Throwable $e) { error_log('[action-assemblee] open : ' . $e->getMessage()); }
    header('Location: /assemblee/' . $id); exit;
}

// CLOSE + compute results
if ($action === 'close') {
    if ($ag_status !== 'in_progress') ag_back($id, 'Ouvrez d’abord la séance : seule une séance en cours peut être clôturée.');
    // Sans quorum, l'assemblée ne délibère pas valablement : aucune résolution n'est adoptée.
    $quorum = ag_quorum_status($ag, ag_load_attendees($pdo, $id));
    foreach (ag_load_resolutions($pdo, $id) as $r) {
        $result = $quorum['ok'] ? ag_compute_result((string)$r['vote_type'], ag_resolution_stats($pdo, (int)$r['id'])) : 'rejected';
        $pdo->prepare("UPDATE assembly_resolutions SET is_open = 0, closed_at = NOW(), result = ? WHERE id = ?")->execute([$result, (int)$r['id']]);
    }
    $pdo->prepare("UPDATE assemblies SET status = 'closed', closed_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    if (!$quorum['ok']) {
        ag_back($id, 'Séance clôturée sans quorum (' . $quorum['present'] . ' présent·e·s ou représenté·e·s pour ' . $quorum['required']
            . ' requis) : aucune résolution n’est adoptée, le procès-verbal les déclare non délibérées. Une nouvelle assemblée devra être convoquée.', 'warn');
    }
    ag_back($id, 'Séance clôturée : le procès-verbal est prêt.', 'ok');
}

http_response_code(400); die('Action inconnue.');
