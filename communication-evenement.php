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
require_login();
require_capability('access_marketing');

$user = current_user();
$org_id = (int) $user['org_id'];
$event_id = (int) ($_GET['id'] ?? 0);

if ($event_id <= 0) {
    header('Location: /communication?tab=evenements');
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.*, u.first_name, u.last_name, p.name AS project_name
    FROM communication_events e
    LEFT JOIN users u ON e.created_by_user_id = u.id
    LEFT JOIN projects p ON e.project_id = p.id
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
    // Charger tous les users de l'asso
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE org_id = ? AND is_active = 1 AND email IS NOT NULL");
    $stmt->execute([$org_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Charger nom de l'org
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $stmt->execute([$org_id]);
    $org_name = $stmt->fetchColumn() ?: 'votre association';

    // Créer broadcast lié
    $public_url = 'https://assokit.fr/evenement-public/' . $event['public_slug'];
    $event_date_fr = fr_format_date('%A %d %B %Y à %H:%M', strtotime($event['start_date']));

    $subject = 'Invitation : ' . $event['title'];
    $body = "Nous vous invitons à notre événement :\n\n" .
            "📅 " . date('d/m/Y à H:i', strtotime($event['start_date'])) . "\n" .
            ($event['location'] ? "📍 " . $event['location'] . "\n" : "") . "\n" .
            (trim(html_entity_decode(strip_tags(preg_replace('#<\s*br\s*/?>#i', "\n", (string)($event['description'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . "\n\n") .
            "Pour confirmer votre venue, cliquez sur le lien ci-dessous.";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO communication_broadcasts
                (org_id, created_by_user_id, subject, body, recipient_type, status, nb_total, sent_at, created_at)
            VALUES (?, ?, ?, ?, 'all', 'sending', ?, NOW(), NOW())
        ");
        $stmt->execute([$org_id, (int) $user['id'], $subject, $body, count($users)]);
        $broadcast_id = (int) $pdo->lastInsertId();

        $nb_sent = 0;
        $nb_failed = 0;
        foreach ($users as $u) {
            $content = '<h1 style="font-size:22px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">🎪 ' . h($event['title']) . '</h1>'
                     . '<p>Bonjour ' . h($u['first_name']) . ',</p>'
                     . '<p>Nous vous invitons à notre événement :</p>'
                     . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:13.5px">'
                     . '<tr><td style="padding:4px 8px;color:#78716C;width:30%">📅 Date :</td><td style="padding:4px 8px"><strong>' . date('d/m/Y à H:i', strtotime($event['start_date'])) . '</strong></td></tr>'
                     . ($event['location'] ? '<tr><td style="padding:4px 8px;color:#78716C">📍 Lieu :</td><td style="padding:4px 8px">' . h($event['location']) . '</td></tr>' : '')
                     . '</table>'
                     . ($event['description'] ? '<div style="background:#D1FAE5;border-left:3px solid #059669;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13.5px;color:#065F46;line-height:1.6;">' . ak_render_rich_text($event['description']) . '</div>' : '')
                     . '<p style="font-size:12.5px; color:#78716C;">— L\'équipe de ' . h($org_name) . '</p>';

            $cta_label = $event['rsvp_enabled'] ? 'Confirmer ma venue' : "Voir l'événement";
            $html = function_exists('email_wrap') ? email_wrap($subject, $content, $cta_label, $public_url) : $content;

            $status = 'failed'; $err = null;
            if (function_exists('send_transactional_email')) {
                try {
                    $r = send_transactional_email($u['email'], $subject, $html, ['tag' => 'event_invite']);
                    if (!empty($r['success'])) { $status = 'sent'; $nb_sent++; }
                    else { $nb_failed++; $err = $r['error'] ?? null; }
                } catch (Throwable $e) {
                    $nb_failed++; $err = $e->getMessage();
                }
            }

            try {
                $stmt2 = $pdo->prepare("INSERT INTO communication_broadcast_recipients (broadcast_id, user_id, email, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt2->execute([$broadcast_id, (int)$u['id'], $u['email'], $status, $err, $status === 'sent' ? date('Y-m-d H:i:s') : null]);
            } catch (Throwable $e) {}
        }

        $stmt = $pdo->prepare("UPDATE communication_broadcasts SET nb_sent = ?, nb_failed = ?, status = 'sent' WHERE id = ?");
        $stmt->execute([$nb_sent, $nb_failed, $broadcast_id]);

        // Lier l'event au broadcast
        $stmt = $pdo->prepare("UPDATE communication_events SET broadcast_id = ? WHERE id = ?");
        $stmt->execute([$broadcast_id, $event_id]);

        $pdo->commit();

        $_SESSION['flash_communication'] = [
            'type' => 'success',
            'message' => "Invitation envoyée : $nb_sent email(s) envoyé(s)" . ($nb_failed > 0 ? ", $nb_failed échec(s)" : "") . ".",
        ];
        header('Location: /communication-evenement?id=' . $event_id);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        $flash = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    }
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
foreach ($rsvps as $r) {
    if ($r['response'] === 'yes') $nb_yes++;
    elseif ($r['response'] === 'no') $nb_no++;
    else $nb_maybe++;
}

$public_url = 'https://assokit.fr/evenement-public/' . $event['public_slug'];

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
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
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
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="diffuser">
          <button type="submit" class="btn btn-primary" onclick="return confirm('Envoyer l\'invitation par email à tous les adhérents ?')">📧 Diffuser par email</button>
        </form>
      <?php else: ?>
        <a href="/communication-diffusion?id=<?= (int)$event['broadcast_id'] ?>" class="btn btn-ghost">✓ Email envoyé — Voir diffusion</a>
      <?php endif; ?>
    </div>
  </div>

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
          <div><strong>Fin :</strong> <?= date('H:i', strtotime($event['end_date'])) ?></div>
        <?php endif; ?>
        <?php if ($event['location']): ?>
          <div><strong>Lieu :</strong> <?= h($event['location']) ?></div>
        <?php endif; ?>
        <?php if ($event['location_address']): ?>
          <div><strong>Adresse :</strong> <?= h($event['location_address']) ?></div>
        <?php endif; ?>
        <?php if ($event['max_attendees']): ?>
          <div><strong>Places :</strong> <?= (int)$event['max_attendees'] ?> max</div>
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
