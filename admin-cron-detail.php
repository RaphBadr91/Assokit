<?php
/**
 * ============================================================
 * ASSOKIT — admin-cron-detail.php (v5)
 * ============================================================
 * Si non connecté cockpit → redirige vers login
 * ============================================================
 */

define('CRON_ADMIN_UI', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/cron-includes.php';

if (!cron_is_super_admin()) {
    header('Location: /admin-cron-login');
    exit;
}

$cockpitUser = cron_current_cockpit_user();
$timeLeft = cron_cockpit_time_left();

$runId = (int)($_GET['id'] ?? 0);
if ($runId <= 0) {
    http_response_code(404);
    exit('Run introuvable.');
}

// Run
$stmt = $pdo->prepare("
    SELECT r.*, u.first_name, u.last_name, u.email AS triggered_email
    FROM cron_runs r
    LEFT JOIN users u ON u.id = r.triggered_by_user_id
    WHERE r.id = :id
");
$stmt->execute([':id' => $runId]);
$run = $stmt->fetch();
if (!$run) {
    http_response_code(404);
    exit('Run introuvable.');
}

// Emails envoyés
$emails = $pdo->prepare("
    SELECT e.*, u.first_name, u.last_name
    FROM cron_email_log e
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.cron_run_id = :id
    ORDER BY e.sent_at ASC
");
$emails->execute([':id' => $runId]);
$emails = $emails->fetchAll();

if (!function_exists('ak_cron_h')) {
    function ak_cron_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ak_cron_status_badge')) {
    function ak_cron_status_badge(string $s): string {
        $map = [
            'success' => ['#10B981', '✓ Succès'],
            'error'   => ['#EF4444', '✕ Erreur'],
            'running' => ['#F59E0B', '↻ En cours'],
            'sent'    => ['#10B981', '✓ Envoyé'],
            'failed'  => ['#EF4444', '✕ Échec'],
            'locked'  => ['#6B7280', '⏸ Verrouillé'],
        ];
        [$color, $label] = $map[$s] ?? ['#6B7280', $s];
        return '<span style="display:inline-block;padding:3px 9px;border-radius:999px;background:' . $color . '22;color:' . $color . ';font-size:11px;font-weight:600;">' . $label . '</span>';
    }
}

$details = null;
if (!empty($run['details'])) {
    $details = json_decode($run['details'], true);
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Run #<?= $runId ?> · Cockpit SA · Assokit</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Geist', system-ui, sans-serif; background: #0F0E1A; color: #E5E7EB; font-size: 14px; line-height: 1.5; }
a { color: #A78BFA; text-decoration: none; }
.bar { background: linear-gradient(90deg, #7F77DD 0%, #A78BFA 100%); padding: 10px 24px; color: #fff; font-size: 12.5px; letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.bar-right { display: flex; align-items: center; gap: 16px; font-size: 11.5px; }
.bar .user-chip { background: rgba(0,0,0,0.25); padding: 4px 10px; border-radius: 99px; text-transform: none; letter-spacing: 0.02em; font-weight: 500; }
.bar .timer { background: rgba(255,255,255,0.15); padding: 4px 10px; border-radius: 99px; font-family: 'SF Mono', Monaco, Consolas, monospace; font-weight: 500; text-transform: none; }
.bar .logout { color: #fff; opacity: 0.85; text-decoration: underline; font-weight: 500; text-transform: none; }
.container { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
header.page h1 { font-size: 26px; font-weight: 600; margin: 0 0 4px; letter-spacing: -0.02em; color: #F3F4F6; }
header.page p { color: #9CA3AF; margin: 0 0 20px; }
.back { color: #A78BFA; font-size: 13px; margin-bottom: 16px; display: inline-block; }
.card { background: #1A1828; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
.card h2 { margin: 0 0 16px; font-size: 15px; font-weight: 600; color: #F3F4F6; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.cell { background: #22202F; border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 14px 16px; }
.cell-label { font-size: 11px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px; font-weight: 500; }
.cell-value { font-size: 20px; font-weight: 600; color: #F3F4F6; letter-spacing: -0.01em; }
.cell-value.sm { font-size: 15px; font-weight: 500; }
.mono { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12.5px; color: #D4D4D8; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 10px 14px; font-weight: 500; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.05em; font-size: 10.5px; background: #22202F; border-bottom: 1px solid rgba(255,255,255,0.08); }
td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.06); }
tr:last-child td { border-bottom: none; }
pre { background: #0A0A10; border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 16px; overflow-x: auto; font-size: 12px; color: #D4D4D8; margin: 0; }
.err { background: #3D1515; border: 1px solid #5F2020; border-radius: 8px; padding: 14px 16px; color: #FCA5A5; font-size: 13px; margin-bottom: 16px; white-space: pre-wrap; word-break: break-word; }
.empty { padding: 30px; text-align: center; color: #6B7280; }
</style>
</head>
<body>

<div class="bar">
  <span>🛡 Cockpit Fondateur &amp; Super Admin · Détail run</span>
  <span class="bar-right">
    <?php if ($cockpitUser): ?>
      <span class="user-chip">👤 <?= ak_cron_h($cockpitUser['first_name']) ?></span>
    <?php endif; ?>
    <span class="timer" id="cockpit-timer" data-seconds="<?= (int)$timeLeft ?>">⏱ <span id="timer-display"></span></span>
    <a href="/admin-cron-logout" class="logout">Déconnexion</a>
  </span>
</div>

<div class="container">
  <a href="/admin-cron" class="back">← Retour au cockpit CRON</a>

  <header class="page">
    <h1>Run #<?= (int)$run['id'] ?> · <span style="color:#A78BFA;"><?= ak_cron_h($run['job_name']) ?></span></h1>
    <p>
      Démarré le <?= ak_cron_h(date('d/m/Y à H:i:s', strtotime($run['started_at']))) ?>
      <?php if ($run['finished_at']): ?>
        · terminé à <?= ak_cron_h(date('H:i:s', strtotime($run['finished_at']))) ?>
      <?php endif; ?>
      · <?= ak_cron_status_badge($run['status']) ?>
    </p>
  </header>

  <?php if (!empty($run['error_message'])): ?>
    <div class="err"><strong>Erreur :</strong><br><?= ak_cron_h($run['error_message']) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Statistiques</h2>
    <div class="grid">
      <div class="cell">
        <div class="cell-label">Traités</div>
        <div class="cell-value"><?= (int)$run['items_processed'] ?></div>
      </div>
      <div class="cell">
        <div class="cell-label">Succès</div>
        <div class="cell-value" style="color:#10B981;"><?= (int)$run['items_succeeded'] ?></div>
      </div>
      <div class="cell">
        <div class="cell-label">Échecs</div>
        <div class="cell-value" style="color:<?= (int)$run['items_failed'] > 0 ? '#EF4444' : '#F3F4F6' ?>;"><?= (int)$run['items_failed'] ?></div>
      </div>
      <div class="cell">
        <div class="cell-label">Emails envoyés</div>
        <div class="cell-value"><?= (int)$run['emails_sent'] ?></div>
      </div>
      <div class="cell">
        <div class="cell-label">Durée</div>
        <div class="cell-value sm"><?= number_format((int)$run['duration_ms']) ?> ms</div>
      </div>
      <div class="cell">
        <div class="cell-label">Déclenché par</div>
        <div class="cell-value sm">
          <?= ak_cron_h($run['triggered_by']) ?>
          <?php if ($run['first_name']): ?>
            <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><?= ak_cron_h($run['first_name'] . ' ' . $run['last_name']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($details)): ?>
    <div class="card">
      <h2>Détails techniques</h2>
      <pre><?= ak_cron_h(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Emails envoyés (<?= count($emails) ?>)</h2>
    <?php if (empty($emails)): ?>
      <div class="empty">Aucun email envoyé pour ce run.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Heure</th><th>Destinataire</th><th>Type</th><th>Sujet</th><th>Statut</th><th>Provider ID</th></tr>
        </thead>
        <tbody>
          <?php foreach ($emails as $e): ?>
            <tr>
              <td class="mono"><?= ak_cron_h(date('H:i:s', strtotime($e['sent_at']))) ?></td>
              <td>
                <?= ak_cron_h($e['email_to']) ?>
                <?php if ($e['first_name']): ?>
                  <div style="font-size:11px;color:#9CA3AF;margin-top:2px;"><?= ak_cron_h($e['first_name'] . ' ' . $e['last_name']) ?></div>
                <?php endif; ?>
              </td>
              <td><span style="display:inline-block;padding:2px 7px;background:rgba(127,119,221,0.15);color:#A78BFA;border-radius:5px;font-size:11px;font-weight:600;"><?= ak_cron_h($e['email_type']) ?></span></td>
              <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= ak_cron_h($e['subject']) ?></td>
              <td><?= ak_cron_status_badge($e['status']) ?></td>
              <td class="mono" style="font-size:11px;color:#6B7280;"><?= ak_cron_h($e['provider_id'] ?? '—') ?></td>
            </tr>
            <?php if (!empty($e['error_message'])): ?>
              <tr>
                <td colspan="6" style="background:#2A1515;color:#FCA5A5;font-size:12px;"><strong>Erreur :</strong> <?= ak_cron_h($e['error_message']) ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
(function() {
  var el = document.getElementById('cockpit-timer');
  var display = document.getElementById('timer-display');
  if (!el || !display) return;
  var seconds = parseInt(el.dataset.seconds || 0, 10);
  function tick() {
    if (seconds <= 0) { window.location.href = '/admin-cron-login?expired=1'; return; }
    var mm = Math.floor(seconds / 60), ss = seconds % 60;
    display.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    seconds--;
  }
  tick(); setInterval(tick, 1000);
})();
</script>

</body>
</html>
