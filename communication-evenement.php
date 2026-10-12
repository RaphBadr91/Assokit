<?php
/**
 * communication-evenement.php — Fiche d'un evenement (cote admin)
 * ==================================================================
 * URL : /communication-evenement?id=X
 * Affiche : details, lien public, liste des RSVPs, bouton Diffuser.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/trial-limits-helpers.php';
require_login();
require_capability('access_marketing');

$user = current_user();
$org_id = (int) $user['org_id'];
$event_id = (int) ($_GET['id'] ?? 0);

if ($event_id <= 0) {
    header('Location: /communication?tab=evenements');
    exit;
}

// Projet lié : seulement s'il appartient à l'association (un project_id étranger,
// enregistré avant le contrôle de communication-evenement-nouveau, ne s'affiche pas)
$stmt = $pdo->prepare("
    SELECT e.*, u.first_name, u.last_name, p.name AS project_name
    FROM communication_events e
    LEFT JOIN users u ON e.created_by_user_id = u.id
    LEFT JOIN projects p ON p.id = e.project_id
          AND p.folder_id IN (SELECT f.id FROM folders f WHERE f.org_id = e.org_id)
    WHERE e.id = ? AND e.org_id = ?
");
$stmt->execute([$event_id, $org_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: /communication?tab=evenements');
    exit;
}

// Action : diffuser par email
$flash = $_SESSION['flash_communication'] ?? null;
unset($_SESSION['flash_communication']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'diffuser' && check_csrf($_POST['csrf_token'] ?? '')) {
    // Une seule invitation par événement (le bouton n'est masqué qu'à l'affichage)
    if (!empty($event['broadcast_id'])) {
        $_SESSION['flash_communication'] = ['type' => 'warning', 'message' => 'Invitation déjà envoyée (ou en cours d’envoi) : elle ne repart pas une seconde fois.'];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;
    }

    // Charger tous les users de l'asso
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE org_id = ? AND is_active = 1 AND email IS NOT NULL");
    $stmt->execute([$org_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Les personnes désinscrites des envois groupés de l'association ne sont plus invitées
    $optouts = function_exists('ak_optout_emails') ? ak_optout_emails($pdo, $org_id) : [];
    $nb_before = count($users);
    $users = array_values(array_filter($users, fn($u) => !isset($optouts[strtolower(trim((string)$u['email']))])));
    $nb_skipped_optout = $nb_before - count($users);
    if (!$users) {
        $_SESSION['flash_communication'] = ['type' => 'error', 'message' => 'Aucun destinataire : tous les adhérents se sont désinscrits des envois groupés.'];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;
    }

    // Essai gratuit : 200 destinataires par envoi, 500 par 24 h (anti-relais de spam)
    if ($trial_block = ak_trial_send_block($pdo, $org_id, count($users))) {
        $_SESSION['flash_communication'] = ['type' => 'error', 'message' => $trial_block];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;
    }

    if (!function_exists('ak_comm_broadcast_send')) {
        $_SESSION['flash_communication'] = ['type' => 'error', 'message' => 'L’envoi d’e-mails n’est pas disponible pour le moment. Réessayez plus tard.'];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;
    }

    // Texte gardé sur la diffusion (fiche /communication-diffusion). Sans page publique
    // (événement non public), l'invitation part sans lien : on répond par e-mail.
    $has_public_page = !empty($event['is_public']) && ($event['status'] ?? 'published') === 'published';
    $subject = 'Invitation : ' . $event['title'];
    $body = "Nous vous invitons à notre événement :\n\n" .
            "📅 " . date('d/m/Y à H:i', strtotime($event['start_date'])) . "\n" .
            ($event['location'] ? "📍 " . $event['location'] . "\n" : "") . "\n" .
            (trim(html_entity_decode(strip_tags(preg_replace('#<\s*br\s*/?>#i', "\n", (string)($event['description'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . "\n\n") .
            ($has_public_page
                ? ($event['rsvp_enabled'] ? "Pour confirmer votre venue, cliquez sur le lien ci-dessous." : "Tous les détails sur la page de l'événement (lien ci-dessous).")
                : ($event['rsvp_enabled'] ? "Pour nous dire si vous venez, répondez simplement à cet e-mail." : ""));

    try {
        // Diffusion enregistrée hors transaction, puis réservation atomique de l'événement :
        // un double clic ou un renvoi du formulaire trouve l'événement déjà pris et n'envoie rien.
        $stmt = $pdo->prepare("
            INSERT INTO communication_broadcasts
                (org_id, created_by_user_id, subject, body, recipient_type, status, nb_total, sent_at, created_at)
            VALUES (?, ?, ?, ?, 'all', 'sending', ?, NOW(), NOW())
        ");
        $stmt->execute([$org_id, (int) $user['id'], $subject, rtrim($body), count($users)]);
        $broadcast_id = (int) $pdo->lastInsertId();

        $claim = $pdo->prepare("UPDATE communication_events SET broadcast_id = ? WHERE id = ? AND org_id = ? AND (broadcast_id IS NULL OR broadcast_id = 0)");
        $claim->execute([$broadcast_id, $event_id, $org_id]);
        if ($claim->rowCount() !== 1) {
            $pdo->prepare("DELETE FROM communication_broadcasts WHERE id = ? AND org_id = ?")->execute([$broadcast_id, $org_id]);
            $_SESSION['flash_communication'] = ['type' => 'warning', 'message' => 'Invitation déjà envoyée (ou en cours d’envoi) : elle ne repart pas une seconde fois.'];
            header('Location: /communication-evenement?id=' . $event_id);
            exit;
        }
    } catch (Throwable $e) {
        error_log('[communication-evenement] diffusion org ' . $org_id . ' : ' . $e->getMessage());
        $_SESSION['flash_communication'] = ['type' => 'error', 'message' => 'L’invitation n’a pas pu être enregistrée (erreur technique). Aucun e-mail n’est parti : réessayez dans quelques minutes.'];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;
    }

    $res = ak_comm_broadcast_send($pdo, $org_id, $broadcast_id, $users);

    // Rien n'est parti : l'événement est libéré pour un nouvel essai (la diffusion en échec reste visible)
    if ($res['sent'] === 0 && $res['pending'] === 0 && empty($res['locked'])) {
        $pdo->prepare("UPDATE communication_events SET broadcast_id = NULL WHERE id = ? AND org_id = ? AND broadcast_id = ?")
            ->execute([$event_id, $org_id, $broadcast_id]);
    }
    $flash_send = ak_comm_broadcast_flash($res, 'Invitation');
    if ($nb_skipped_optout > 0) {
        $flash_send['message'] = rtrim($flash_send['message'], '.') . " · $nb_skipped_optout personne(s) désinscrite(s) non contactée(s).";
    }
    $_SESSION['flash_communication'] = $flash_send;
    header('Location: /communication-evenement?id=' . $event_id);
    exit;
}

// RSVPs
$stmt = $pdo->prepare("
    SELECT r.*, u.first_name, u.last_name, u.email
    FROM communication_event_rsvps r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.event_id = ?
    ORDER BY r.response ASC, r.responded_at DESC
");
$stmt->execute([$event_id]);
$rsvps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_yes = $nb_no = $nb_maybe = 0;
// Personnes attendues = réponses « oui » + leurs accompagnants : même total que la page
// publique (evenement-public.php), qui s'en sert pour déclarer l'événement complet.
$nb_people = 0;
foreach ($rsvps as $r) {
    if ($r['response'] === 'yes') { $nb_yes++; $nb_people += 1 + max(0, (int)($r['nb_accompanying'] ?? 0)); }
    elseif ($r['response'] === 'no') $nb_no++;
    else $nb_maybe++;
}
$max_attendees = (int)($event['max_attendees'] ?? 0);
$places_left = $max_attendees > 0 ? max(0, $max_attendees - $nb_people) : null;

$public_url = 'https://assokit.fr/evenement-public/' . $event['public_slug'];
$has_public_page = !empty($event['is_public']) && ($event['status'] ?? 'published') === 'published';
$broadcast_running = !empty($event['broadcast_id']) && function_exists('ak_comm_broadcast_running')
    && ak_comm_broadcast_running($pdo, (int)$event['broadcast_id']);
// Envoi coupé en route (processus tué, plus de verrou) : des invités attendent encore l'e-mail,
// à reprendre depuis la fiche de la diffusion
$broadcast_pending = 0;
$broadcast_interrupted = false;
if (!empty($event['broadcast_id']) && !$broadcast_running) {
    try {
        $st = $pdo->prepare("
            SELECT b.status,
                   (SELECT COUNT(*) FROM communication_broadcast_recipients r WHERE r.broadcast_id = b.id AND r.status = 'pending') AS nb_pending
            FROM communication_broadcasts b
            WHERE b.id = ? AND b.org_id = ?
        ");
        $st->execute([(int)$event['broadcast_id'], $org_id]);
        if ($bc = $st->fetch(PDO::FETCH_ASSOC)) {
            $broadcast_pending = (int)$bc['nb_pending'];
            $broadcast_interrupted = $broadcast_pending > 0 || ($bc['status'] ?? '') === 'sending';
        }
    } catch (Throwable $e) {
        error_log('[communication-evenement] suivi diffusion : ' . $e->getMessage());
    }
}
// Soirée qui se termine après minuit : la fin tombe le lendemain du début
$end_next_day = !empty($event['end_date'])
    && date('Y-m-d', strtotime($event['end_date'])) !== date('Y-m-d', strtotime($event['start_date']));

render_head($event['title']);
render_sidebar('communication');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/communication?tab=evenements">Communication</a>
    <span class="sep">›</span>
    <span class="current"><?= h($event['title']) ?></span>
  </nav>

  <?php if ($flash): ?>
    <?php $flash_warn = ($flash['type'] ?? '') === 'warning'; ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"<?= $flash_warn ? ' style="background:#FEF3C7; color:#92400E; border-color:#FCD34D;"' : '' ?>>
      <span><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="main-head">
    <div>
      <h1 class="page-title">🎪 <?= h($event['title']) ?></h1>
      <div class="page-sub">
        📅 <?= date('d/m/Y à H:i', strtotime($event['start_date'])) ?>
        <?php if ($event['location']): ?> · 📍 <?= h($event['location']) ?><?php endif; ?>
      </div>
    </div>
    <div style="display:flex; gap:8px;">
      <?php if ($event['is_public']): ?>
        <a href="<?= h($public_url) ?>" target="_blank" class="btn btn-ghost">🔗 Lien public</a>
      <?php endif; ?>
      <?php if (!$event['broadcast_id']): ?>
        <form method="POST" style="display:inline" onsubmit="if (!confirm('Envoyer l\'invitation par email à tous les adhérents ?')) return false; var b = this.querySelector('button'); b.disabled = true; b.textContent = '⏳ Envoi en cours…'; return true;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="diffuser">
          <button type="submit" class="btn btn-primary">📧 Diffuser par email</button>
        </form>
      <?php else: ?>
        <?php if ($broadcast_interrupted): ?>
          <a href="/communication-diffusion?id=<?= (int)$event['broadcast_id'] ?>" class="btn btn-ghost" style="background:#FEF3C7; color:#92400E; border-color:#FCD34D;"
             title="<?= $broadcast_pending > 0 ? $broadcast_pending . ' invité' . ($broadcast_pending > 1 ? 's' : '') . ' n’' . ($broadcast_pending > 1 ? 'ont' : 'a') . ' pas encore reçu l’e-mail' : 'L’envoi ne s’est pas terminé' ?>"><?= $broadcast_pending > 0 ? '⚠️ Envoi interrompu — Reprendre' : '⚠️ Envoi interrompu — Voir diffusion' ?></a>
        <?php else: ?>
          <a href="/communication-diffusion?id=<?= (int)$event['broadcast_id'] ?>" class="btn btn-ghost"><?= $broadcast_running ? '⏳ Envoi en cours — Voir diffusion' : '✓ Email envoyé — Voir diffusion' ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$event['broadcast_id'] && !$has_public_page): ?>
    <div style="font-size:12.5px; color:var(--ink-3); background:var(--bg-2); border:1px solid var(--border); border-radius:10px; padding:10px 14px; margin-bottom:18px;">
      ℹ️ Cet événement n’a pas de lien public : l’invitation partira sans bouton de réponse<?= $event['rsvp_enabled'] ? ', et les invités répondront par e-mail à l’association' : '' ?>.
    </div>
  <?php endif; ?>

  <!-- Lien public en évidence -->
  <?php if ($event['is_public']): ?>
  <div style="background:rgba(5, 150, 105, 0.05); border:1px solid rgba(5, 150, 105, 0.2); border-radius:10px; padding:14px 16px; margin-bottom:18px;">
    <div style="font-size:11.5px; color:var(--acc-dark); font-weight:500; margin-bottom:6px; letter-spacing:0.03em; text-transform:uppercase;">🔗 Lien public de l'événement</div>
    <div style="display:flex; gap:8px; align-items:center;">
      <input type="text" readonly value="<?= h($public_url) ?>"
             style="flex:1; padding:8px 12px; background:var(--bg); border:1px solid var(--border); border-radius:6px; font-family:monospace; font-size:12.5px; color:var(--ink-2);"
             onclick="this.select()">
      <button type="button" onclick="navigator.clipboard.writeText('<?= h($public_url) ?>').then(() => this.textContent = '✓ Copié !')" class="btn btn-ghost" style="padding:8px 12px; font-size:12px; white-space:nowrap;">📋 Copier</button>
    </div>
    <div style="font-size:11.5px; color:var(--ink-3); margin-top:6px;">
      Partagez ce lien par SMS, WhatsApp, réseaux sociaux... Les invités pourront répondre sans compte Assokit.
    </div>
  </div>
  <?php endif; ?>

  <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">

    <!-- Détails -->
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px;">
      <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">Détails</div>

      <?php if ($event['description']): ?>
        <div style="font-size:13.5px; line-height:1.6; margin-bottom:16px; word-break:break-word;"><?= ak_render_rich_text($event['description']) ?></div>
      <?php endif; ?>

      <div style="display:grid; gap:8px; font-size:13px;">
        <div><strong>Début :</strong> <?= date('d/m/Y à H:i', strtotime($event['start_date'])) ?></div>
        <?php if ($event['end_date']): ?>
          <div><strong>Fin :</strong> <?= date('H:i', strtotime($event['end_date'])) ?><?= $end_next_day ? ' (le lendemain, ' . date('d/m/Y', strtotime($event['end_date'])) . ')' : '' ?></div>
        <?php endif; ?>
        <?php if ($event['location']): ?>
          <div><strong>Lieu :</strong> <?= h($event['location']) ?></div>
        <?php endif; ?>
        <?php if ($event['location_address']): ?>
          <div><strong>Adresse :</strong> <?= h($event['location_address']) ?></div>
        <?php endif; ?>
        <?php if ($max_attendees > 0): ?>
          <div><strong>Places :</strong> <?= $max_attendees ?> max · <?= $nb_people ?> personne<?= $nb_people > 1 ? 's' : '' ?> inscrite<?= $nb_people > 1 ? 's' : '' ?> ·
            <?= $places_left > 0 ? $places_left . ' place' . ($places_left > 1 ? 's' : '') . ' restante' . ($places_left > 1 ? 's' : '') : '<strong style="color:#B91C1C;">complet</strong>' ?></div>
        <?php endif; ?>
        <?php if ($event['project_name']): ?>
          <div><strong>Projet lié :</strong> <?= h($event['project_name']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats RSVP -->
    <div>
      <?php if ($event['rsvp_enabled']): ?>
        <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:12px;">
          <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px;">Réponses (RSVP)</div>
          <div style="display:flex; justify-content:space-around; text-align:center;">
            <div>
              <div style="font-size:26px; font-weight:600; color:var(--acc);"><?= $nb_yes ?></div>
              <div style="font-size:11px; color:var(--ink-3);">✓ Oui</div>
            </div>
            <div>
              <div style="font-size:26px; font-weight:600; color:#F59E0B;"><?= $nb_maybe ?></div>
              <div style="font-size:11px; color:var(--ink-3);">? Peut-être</div>
            </div>
            <div>
              <div style="font-size:26px; font-weight:600; color:var(--ink-4);"><?= $nb_no ?></div>
              <div style="font-size:11px; color:var(--ink-3);">✕ Non</div>
            </div>
          </div>
          <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--border); font-size:12px; color:var(--ink-2); text-align:center;">
            <?= $nb_yes ?> réponse<?= $nb_yes > 1 ? 's' : '' ?> oui · <strong><?= $nb_people ?> personne<?= $nb_people > 1 ? 's' : '' ?></strong> avec les accompagnants
            <?php if ($max_attendees > 0): ?>
              · <?= $places_left > 0 ? $places_left . ' place' . ($places_left > 1 ? 's' : '') . ' restante' . ($places_left > 1 ? 's' : '') : '<strong style="color:#B91C1C;">complet</strong>' ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Liste des RSVPs -->
      <?php if (!empty($rsvps)): ?>
        <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; max-height:340px; overflow-y:auto;">
          <div style="padding:12px 16px; border-bottom:1px solid var(--border); background:var(--bg-2); font-size:12px; font-weight:500;">Qui vient ?</div>
          <?php foreach ($rsvps as $r):
            $name = $r['first_name']
                ? trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))
                : ($r['guest_name'] ?: $r['guest_email']);
            $icon = ['yes' => '✓', 'maybe' => '?', 'no' => '✕'][$r['response']] ?? '?';
            $color = ['yes' => 'var(--acc)', 'maybe' => '#F59E0B', 'no' => 'var(--ink-4)'][$r['response']] ?? 'var(--ink-3)';
          ?>
            <div style="padding:10px 16px; border-bottom:1px solid var(--border); font-size:12.5px; display:flex; justify-content:space-between; align-items:center;">
              <div>
                <div><?= h($name) ?><?php if (($r['nb_accompanying'] ?? 0) > 0): ?> <span style="color:var(--ink-4); font-size:11px;">+<?= (int)$r['nb_accompanying'] ?></span><?php endif; ?></div>
                <?php if (!$r['user_id'] && $r['guest_email']): ?>
                  <div style="color:var(--ink-4); font-size:11px;"><?= h($r['guest_email']) ?></div>
                <?php endif; ?>
              </div>
              <div style="color:<?= $color ?>; font-weight:600; font-size:15px;"><?= $icon ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php render_foot(); ?>
