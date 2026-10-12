<?php
/**
 * communication-diffusion.php — Vue detail d'une diffusion
 * ============================================================
 * URL : /communication-diffusion?id=X
 * Affiche :
 *   - Recap (sujet, date, destinataires, stats)
 *   - Liste des destinataires avec statut individuel
 *   - Preview du message envoye
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();
require_capability('access_marketing');

$user = current_user();
$org_id = (int) $user['org_id'];
$broadcast_id = (int) ($_GET['id'] ?? 0);

if ($broadcast_id <= 0) {
    header('Location: /communication?tab=diffuser');
    exit;
}

$flash = $_SESSION['flash_communication'] ?? null;
unset($_SESSION['flash_communication']);

// Reprise d'un envoi interrompu : seuls les destinataires restés « pending » reçoivent l'e-mail
// (ak_comm_broadcast_send prend un verrou : une reprise lancée deux fois n'envoie rien en double).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reprendre'
    && check_csrf($_POST['csrf_token'] ?? '') && function_exists('ak_comm_broadcast_send')) {
    $res = ak_comm_broadcast_send($pdo, $org_id, $broadcast_id);
    if ($res['status'] !== 'missing') {
        $_SESSION['flash_communication'] = ak_comm_broadcast_flash($res, 'Diffusion');
    }
    header('Location: /communication-diffusion?id=' . $broadcast_id);
    exit;
}

$stmt = $pdo->prepare("
    SELECT b.*, u.first_name, u.last_name
    FROM communication_broadcasts b
    LEFT JOIN users u ON b.created_by_user_id = u.id
    WHERE b.id = ? AND b.org_id = ?
");
$stmt->execute([$broadcast_id, $org_id]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: /communication?tab=diffuser');
    exit;
}

// Destinataires
$stmt = $pdo->prepare("
    SELECT r.*, u.first_name, u.last_name
    FROM communication_broadcast_recipients r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.broadcast_id = ?
    ORDER BY r.status ASC, u.first_name ASC
");
$stmt->execute([$broadcast_id]);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_pending = count(array_filter($recipients, fn($r) => ($r['status'] ?? '') === 'pending'));
$running = $nb_pending > 0 && function_exists('ak_comm_broadcast_running') && ak_comm_broadcast_running($pdo, $broadcast_id);
$status_label = [
    'sent'    => ['Envoyée', 'var(--acc)'],
    'partial' => ['Envoyée en partie', '#B45309'],
    'failed'  => ['Échec : aucun e-mail envoyé', '#B91C1C'],
    'sending' => [$running ? 'Envoi en cours' : 'Envoi interrompu', '#B45309'],
    'draft'   => ['Brouillon', 'var(--ink-3)'],
][$b['status'] ?? ''] ?? null;

render_head('Diffusion #' . $broadcast_id);
render_sidebar('communication');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/communication?tab=diffuser">Communication</a>
    <span class="sep">›</span>
    <span class="current">Diffusion #<?= $broadcast_id ?></span>
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
      <h1 class="page-title" style="font-size:22px;"><?= h($b['subject']) ?></h1>
      <div class="page-sub">
        Envoyé par <strong><?= h($b['first_name'] . ' ' . $b['last_name']) ?></strong>
        le <?= $b['sent_at'] ? date('d/m/Y à H:i', strtotime($b['sent_at'])) : '—' ?>
        <?php if ($status_label): ?>
          · <strong style="color:<?= $status_label[1] ?>;"><?= h($status_label[0]) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($nb_pending > 0): ?>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:13px;">
      <div>
        <?php if ($running): ?>
          ⏳ Envoi en cours : <?= $nb_pending ?> destinataire<?= $nb_pending > 1 ? 's' : '' ?> restant<?= $nb_pending > 1 ? 's' : '' ?>. Rechargez la page dans quelques minutes.
        <?php else: ?>
          ⚠️ L’envoi s’est interrompu : <?= $nb_pending ?> destinataire<?= $nb_pending > 1 ? 's' : '' ?> n’<?= $nb_pending > 1 ? 'ont' : 'a' ?> pas encore reçu l’e-mail.
          Ceux qui l’ont reçu ne le recevront pas une seconde fois.
        <?php endif; ?>
      </div>
      <?php if (!$running): ?>
        <form method="POST" style="margin:0;" onsubmit="var b = this.querySelector('button'); b.disabled = true; b.textContent = '⏳ Envoi en cours…'; return true;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="reprendre">
          <button type="submit" class="btn btn-primary" style="padding:6px 14px; font-size:12.5px;">📧 Reprendre l’envoi</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:20px;">
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px;">
      <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Total</div>
      <div style="font-size:22px; font-weight:600; margin-top:4px;"><?= (int)$b['nb_total'] ?></div>
    </div>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px;">
      <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Envoyés</div>
      <div style="font-size:22px; font-weight:600; color:var(--acc); margin-top:4px;"><?= (int)$b['nb_sent'] ?></div>
    </div>
    <?php if ($b['nb_failed'] > 0): ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px;">
      <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Échecs</div>
      <div style="font-size:22px; font-weight:600; color:#EF4444; margin-top:4px;"><?= (int)$b['nb_failed'] ?></div>
    </div>
    <?php endif; ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px;">
      <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Taux succès</div>
      <div style="font-size:22px; font-weight:600; margin-top:4px;">
        <?= $b['nb_total'] > 0 ? round(($b['nb_sent'] / $b['nb_total']) * 100) : 0 ?>%
      </div>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">

    <!-- Preview message -->
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px;">
      <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">Contenu du message</div>
      <div style="font-size:15px; font-weight:500; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--border);">
        <?= h($b['subject']) ?>
      </div>
      <div style="font-size:13.5px; line-height:1.6; color:var(--ink-2); white-space:pre-wrap;"><?= h($b['body']) ?></div>
    </div>

    <!-- Destinataires -->
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:0; overflow:hidden; max-height:500px; overflow-y:auto;">
      <div style="padding:14px 16px; border-bottom:1px solid var(--border); background:var(--bg-2);">
        <div style="font-size:13px; font-weight:500;">Destinataires (<?= count($recipients) ?>)</div>
      </div>
      <?php foreach ($recipients as $r): ?>
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 16px; border-bottom:1px solid var(--border); font-size:12.5px;">
          <div>
            <div><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></div>
            <div style="color:var(--ink-4); font-size:11px;"><?= h($r['email']) ?></div>
          </div>
          <div>
            <?php if ($r['status'] === 'sent'): ?>
              <span style="color:var(--acc); font-size:11px;">✓ Envoyé</span>
            <?php elseif ($r['status'] === 'failed' || $r['status'] === 'bounced'): ?>
              <span style="color:#EF4444; font-size:11px;" title="<?= h($r['error_message'] ?? '') ?>">✕ <?= $r['status'] === 'bounced' ? 'Rejeté' : 'Échec' ?></span>
            <?php else: ?>
              <span style="color:var(--ink-4); font-size:11px;">⏳ <?= h($r['status'] === 'pending' ? 'En attente' : $r['status']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php render_foot(); ?>
