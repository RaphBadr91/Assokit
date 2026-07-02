<?php
/**
 * ============================================================
 * ASSOKIT — admin-cron.php (v5)
 * Dashboard cockpit CRON
 * ============================================================
 * Si non connecté → redirige vers /admin-cron-login
 * Sinon affiche le dashboard avec bouton déconnexion + timer 15min
 * ============================================================
 */

define('CRON_ADMIN_UI', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/cron-includes.php';

// ----- Si non connecté → redirige vers login
if (!cron_is_super_admin()) {
    header('Location: /admin-cron-login');
    exit;
}

// ----- User courant du cockpit
$cockpitUser = cron_current_cockpit_user();
$timeLeft = cron_cockpit_time_left();

// ----- Stats rapides
$dashboard = [];
try {
    $dashboard = $pdo->query("SELECT * FROM v_cron_dashboard")->fetch() ?: [];
} catch (Throwable $e) {
    $dashboard = [];
}

// ----- 30 derniers runs
$runs = $pdo->query("
    SELECT r.*, u.first_name, u.last_name
    FROM cron_runs r
    LEFT JOIN users u ON u.id = r.triggered_by_user_id
    ORDER BY r.started_at DESC
    LIMIT 30
")->fetchAll();

// ----- CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ----- Helpers locaux PRÉFIXÉS
if (!function_exists('ak_cron_h')) {
    function ak_cron_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ak_cron_status_badge')) {
    function ak_cron_status_badge(string $s): string {
        $map = [
            'success' => ['#10B981', '✓ Succès'],
            'error'   => ['#EF4444', '✕ Erreur'],
            'running' => ['#F59E0B', '↻ En cours'],
            'locked'  => ['#6B7280', '⏸ Verrouillé'],
            'skipped' => ['#6B7280', '⊘ Passé'],
        ];
        [$color, $label] = $map[$s] ?? ['#6B7280', $s];
        return '<span style="display:inline-block;padding:3px 9px;border-radius:999px;background:' . $color . '22;color:' . $color . ';font-size:11px;font-weight:600;letter-spacing:0.03em;">' . $label . '</span>';
    }
}
if (!function_exists('ak_cron_ms')) {
    function ak_cron_ms($n) { return number_format((int)$n) . ' ms'; }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cockpit SA — CRON · Assokit</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; padding: 0; font-family: 'Geist', -apple-system, system-ui, sans-serif; background: #0F0E1A; color: #E5E7EB; font-size: 14px; line-height: 1.5; }
a { color: #A78BFA; text-decoration: none; }
a:hover { color: #C4B5FD; }
:root {
  --violet: #7F77DD; --violet-hover: #6B63C9;
  --violet-light: rgba(127, 119, 221, 0.15);
  --panel: #1A1828; --panel-2: #22202F;
  --border: rgba(255,255,255,0.08); --muted: #9CA3AF; --ink: #F3F4F6;
}
.bar { background: linear-gradient(90deg, #7F77DD 0%, #A78BFA 100%); padding: 10px 24px; color: #fff; font-size: 12.5px; letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.bar-right { display: flex; align-items: center; gap: 16px; font-size: 11.5px; }
.bar .user-chip { background: rgba(0,0,0,0.25); padding: 4px 10px; border-radius: 99px; text-transform: none; letter-spacing: 0.02em; font-weight: 500; }
.bar .timer { background: rgba(255,255,255,0.15); padding: 4px 10px; border-radius: 99px; font-family: 'SF Mono', Monaco, Consolas, monospace; font-weight: 500; text-transform: none; letter-spacing: 0.02em; }
.bar .logout { color: #fff; opacity: 0.85; text-decoration: underline; font-weight: 500; text-transform: none; letter-spacing: 0.02em; }
.bar .logout:hover { opacity: 1; }

.container { max-width: 1200px; margin: 0 auto; padding: 32px 24px; }
header.page { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; gap: 16px; flex-wrap: wrap; }
header.page h1 { font-size: 28px; font-weight: 600; margin: 0 0 6px; letter-spacing: -0.02em; color: var(--ink); }
header.page p { color: var(--muted); margin: 0; font-size: 14px; }
.back { color: var(--violet); font-size: 13px; }

.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 32px; }
.stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
.stat-label { font-size: 11.5px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; font-weight: 500; }
.stat-value { font-size: 28px; font-weight: 600; color: var(--ink); letter-spacing: -0.02em; line-height: 1; }
.stat-hint { font-size: 12px; color: var(--muted); margin-top: 6px; }
.stat.alert .stat-value { color: #EF4444; }
.stat.warn  .stat-value { color: #F59E0B; }
.stat.good  .stat-value { color: #10B981; }

.actions { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 32px; }
.actions h2 { font-size: 15px; font-weight: 600; margin: 0 0 14px; color: var(--ink); }
.actions-row { display: flex; gap: 10px; flex-wrap: wrap; }
.btn { padding: 10px 18px; border-radius: 10px; background: var(--violet); color: #fff; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: background 0.15s ease; font-family: inherit; }
.btn:hover { background: var(--violet-hover); }
.btn-ghost { background: transparent; color: var(--violet); border: 1px solid var(--violet); }
.btn-ghost:hover { background: var(--violet-light); color: #fff; }

.table-wrap { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.table-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.table-head h2 { margin: 0; font-size: 15px; font-weight: 600; color: var(--ink); }
.table-head span { color: var(--muted); font-size: 12px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 11px 20px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; font-size: 11px; background: var(--panel-2); border-bottom: 1px solid var(--border); }
td { padding: 14px 20px; border-bottom: 1px solid var(--border); color: var(--ink); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(127, 119, 221, 0.04); }
.mono { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12px; color: var(--muted); }
.job-pill { display: inline-block; padding: 3px 8px; border-radius: 6px; background: var(--violet-light); color: var(--violet); font-size: 11.5px; font-weight: 600; letter-spacing: 0.02em; }
.empty { padding: 40px 20px; text-align: center; color: var(--muted); }
.details-link { color: var(--violet); font-size: 12px; }

@media (max-width: 720px) { .container { padding: 20px 16px; } table { font-size: 12px; } th, td { padding: 10px 12px; } .bar { flex-direction: column; gap: 6px; padding: 10px 12px; } .bar-right { flex-wrap: wrap; justify-content: center; } }
</style>
</head>
<body>

<div class="bar">
  <span>🛡 Cockpit Fondateur &amp; Super Admin · CRON automations</span>
  <span class="bar-right">
    <?php if ($cockpitUser): ?>
      <span class="user-chip">👤 <?= ak_cron_h($cockpitUser['first_name']) ?></span>
    <?php endif; ?>
    <span class="timer" id="cockpit-timer" data-seconds="<?= (int)$timeLeft ?>">⏱ <span id="timer-display"></span></span>
    <a href="/admin-cron-logout" class="logout">Déconnexion</a>
  </span>
</div>

<div class="container">

  <header class="page">
    <div>
      <h1>Supervision CRON</h1>
      <p>État des automatisations : relances impayés, fin d'essai, renouvellements.</p>
    </div>
    <a href="/super-admin" class="back">← Retour au tableau de bord</a>
  </header>

  <!-- STATS -->
  <div class="stats">
    <div class="stat">
      <div class="stat-label">Runs (30j)</div>
      <div class="stat-value"><?= (int)($dashboard['runs_30d'] ?? 0) ?></div>
      <div class="stat-hint">exécutions totales</div>
    </div>
    <div class="stat <?= ((int)($dashboard['errors_30d'] ?? 0) > 0) ? 'alert' : 'good' ?>">
      <div class="stat-label">Erreurs (30j)</div>
      <div class="stat-value"><?= (int)($dashboard['errors_30d'] ?? 0) ?></div>
      <div class="stat-hint">à surveiller</div>
    </div>
    <div class="stat good">
      <div class="stat-label">Emails envoyés (30j)</div>
      <div class="stat-value"><?= (int)($dashboard['emails_30d'] ?? 0) ?></div>
      <div class="stat-hint"><?= (int)($dashboard['emails_failed_30d'] ?? 0) ?> échecs</div>
    </div>
    <div class="stat">
      <div class="stat-label">Essais actifs</div>
      <div class="stat-value"><?= (int)($dashboard['trials_active'] ?? 0) ?></div>
      <div class="stat-hint"><?= (int)($dashboard['trials_ending_soon'] ?? 0) ?> se terminent ≤ 7j</div>
    </div>
    <div class="stat good">
      <div class="stat-label">Organisations actives</div>
      <div class="stat-value"><?= (int)($dashboard['orgs_active'] ?? 0) ?></div>
      <div class="stat-hint">en plan payant</div>
    </div>
    <div class="stat <?= ((int)($dashboard['invoices_overdue'] ?? 0) > 0) ? 'warn' : '' ?>">
      <div class="stat-label">Factures impayées</div>
      <div class="stat-value"><?= (int)($dashboard['invoices_pending'] ?? 0) + (int)($dashboard['invoices_overdue'] ?? 0) ?></div>
      <div class="stat-hint"><?= cron_format_euros((int)($dashboard['amount_pending_cents'] ?? 0) + (int)($dashboard['amount_overdue_cents'] ?? 0)) ?> à encaisser</div>
    </div>
  </div>

  <!-- ACTIONS -->
  <div class="actions">
    <h2>Lancer un job manuellement</h2>
    <div class="actions-row">
      <form method="POST" action="/admin-cron-run-manual" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= ak_cron_h($csrf) ?>">
        <input type="hidden" name="job" value="impayes">
        <button type="submit" class="btn">▶ Relances impayés</button>
      </form>
      <form method="POST" action="/admin-cron-run-manual" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= ak_cron_h($csrf) ?>">
        <input type="hidden" name="job" value="essai">
        <button type="submit" class="btn">▶ Fin d'essai</button>
      </form>
      <form method="POST" action="/admin-cron-run-manual" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= ak_cron_h($csrf) ?>">
        <input type="hidden" name="job" value="renouvellements">
        <button type="submit" class="btn">▶ Renouvellements</button>
      </form>
      <form method="POST" action="/admin-cron-run-manual" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= ak_cron_h($csrf) ?>">
        <input type="hidden" name="job" value="all">
        <button type="submit" class="btn btn-ghost">▶ Tous les jobs (séquentiel)</button>
      </form>
    </div>
  </div>

  <!-- DERNIERS RUNS -->
  <div class="table-wrap">
    <div class="table-head">
      <h2>30 derniers runs</h2>
      <span>tri du plus récent au plus ancien</span>
    </div>
    <?php if (empty($runs)): ?>
      <div class="empty">Aucun run pour l'instant. Lancez un job manuellement pour commencer.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Job</th><th>Statut</th>
            <th>Traités</th><th>Emails</th><th>Durée</th><th>Source</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($runs as $r): ?>
            <tr>
              <td class="mono"><?= ak_cron_h(date('d/m H:i:s', strtotime($r['started_at']))) ?></td>
              <td><span class="job-pill"><?= ak_cron_h($r['job_name']) ?></span></td>
              <td><?= ak_cron_status_badge($r['status']) ?></td>
              <td><?= (int)$r['items_processed'] ?> <span class="mono" style="color:#10B98199;">(<?= (int)$r['items_succeeded'] ?>✓ <?= (int)$r['items_failed'] ?>✕)</span></td>
              <td><?= (int)$r['emails_sent'] ?></td>
              <td class="mono"><?= ak_cron_ms($r['duration_ms']) ?></td>
              <td><?= ak_cron_h($r['triggered_by']) ?><?php if ($r['triggered_by'] === 'manual' && $r['first_name']): ?> (<?= ak_cron_h($r['first_name']) ?>)<?php endif; ?></td>
              <td><a href="/admin-cron-detail?id=<?= (int)$r['id'] ?>" class="details-link">Détail →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
// Timer décompte 15 min
(function() {
  var el = document.getElementById('cockpit-timer');
  var display = document.getElementById('timer-display');
  if (!el || !display) return;
  var seconds = parseInt(el.dataset.seconds || 0, 10);

  function tick() {
    if (seconds <= 0) {
      // Expiration → redirection vers login
      window.location.href = '/admin-cron-login?expired=1';
      return;
    }
    var mm = Math.floor(seconds / 60);
    var ss = seconds % 60;
    display.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    seconds--;
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

</body>
</html>
