<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-coach-ia.php';
require_login();
$user = current_user();
$is_admin = ($user['role'] === 'admin');
if (!$is_admin) { http_response_code(403); die('Réservé aux admins.'); }

$org_id = (int)$user['org_id'];
$report_id = (int)($_GET['id'] ?? 0);

if ($report_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM coach_reports WHERE id = ? AND org_id = ?");
    $stmt->execute([$report_id, $org_id]);
    $report = $stmt->fetch() ?: null;
} else {
    $report = coach_load_latest($pdo, $org_id);
}
$history = coach_load_history($pdo, $org_id, 10);

$hl = $report ? (json_decode($report['highlights_json'] ?? '[]', true) ?: []) : [];
$wn = $report ? (json_decode($report['warnings_json'] ?? '[]', true) ?: []) : [];
$rc = $report ? (json_decode($report['recos_json'] ?? '[]', true) ?: []) : [];

render_head('Coach Assokit');
?>
<?= render_sidebar('coach-ia') ?>
<main class="main">
  <div class="ci-page">
    <div class="ci-head">
      <div>
        <h1 class="ci-title">🤖 Ton coach Assokit</h1>
        <p class="ci-sub">Bilan hebdo + 3 actions prioritaires, généré chaque lundi matin.</p>
      </div>
      <form method="POST" action="/action-coach-ia">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="generate_now">
        <button type="submit" class="ci-btn-primary" onclick="this.disabled=true;this.textContent='⏳ Génération…';this.form.submit();">✨ Générer maintenant</button>
      </form>
    </div>

    <?php if (!$report): ?>
      <div class="ci-empty">
        <div style="font-size:48px;">🤖</div>
        <h2>Aucun rapport encore</h2>
        <p>Le coach analyse l'activité de ton association et propose 3 actions prioritaires chaque semaine. Clique « Générer maintenant » pour ton premier rapport.</p>
      </div>
    <?php else: ?>
      <div class="ci-card ci-main">
        <div class="ci-card-head">
          <span class="ci-week"><?= date('d/m', strtotime($report['week_start'])) ?> → <?= date('d/m/Y', strtotime($report['week_end'])) ?></span>
          <span class="ci-gen-time">Généré <?= date('d/m H:i', strtotime($report['generated_at'])) ?></span>
        </div>
        <p class="ci-summary"><?= nl2br(h($report['summary_md'])) ?></p>

        <?php if (!empty($hl)): ?>
        <div class="ci-block ci-block-hl">
          <h3>✨ Points forts</h3>
          <ul><?php foreach ($hl as $h): ?><li><?= h($h) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($wn)): ?>
        <div class="ci-block ci-block-wn">
          <h3>⚠️ Points de vigilance</h3>
          <ul><?php foreach ($wn as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <h2 class="ci-recos-title">🎯 Tes 3 actions prioritaires</h2>
        <div class="ci-recos">
          <?php foreach ($rc as $i => $r): ?>
          <div class="ci-reco">
            <div class="ci-reco-num"><?= $i + 1 ?></div>
            <div class="ci-reco-body">
              <div class="ci-reco-title"><?= h($r['icon'] ?? '•') ?> <?= h($r['title'] ?? '') ?></div>
              <?php if (!empty($r['why'])): ?><div class="ci-reco-why"><?= h($r['why']) ?></div><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (count($history) > 1): ?>
    <div class="ci-history">
      <h2>📜 Historique</h2>
      <?php foreach ($history as $h): ?>
        <a href="/coach-ia?id=<?= (int)$h['id'] ?>" class="ci-hist-row <?= $report && $h['id']==$report['id']?'is-active':'' ?>">
          <span class="ci-hist-week"><?= date('d/m', strtotime($h['week_start'])) ?> → <?= date('d/m/Y', strtotime($h['week_end'])) ?></span>
          <span class="ci-hist-date"><?= date('d/m H:i', strtotime($h['generated_at'])) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<style>
/* Coach Assokit — Liquid Glass 2.0 */
.ci-page { max-width: 840px; margin: 0 auto; padding: 28px 24px 60px; }
.ci-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
.ci-title { font-size: 28px; font-weight: 800; letter-spacing: -0.03em; margin: 0 0 6px; color: var(--ink); line-height: 1.1; }
.ci-sub { color: var(--ink-2); margin: 0; font-size: 14px; }
.ci-btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(140deg,#10B981,#059669); color: #fff; border: 0; border-radius: 13px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; box-shadow: 0 12px 26px -8px rgba(5,150,105,.6), inset 0 1px 0 rgba(255,255,255,.35); transition: transform .12s ease; }
.ci-btn-primary:hover:not(:disabled) { transform: translateY(-1px); }
.ci-btn-primary:disabled { opacity: 0.6; cursor: wait; }
.ci-empty { text-align: center; padding: 60px 20px; background: var(--glass); backdrop-filter: blur(22px) saturate(1.5); -webkit-backdrop-filter: blur(22px) saturate(1.5); border: 1px solid var(--glass-border); border-radius: var(--radius-lg,18px); box-shadow: var(--shadow-card); }
.ci-empty h2 { font-size: 20px; margin: 12px 0 6px; color: var(--ink); }
.ci-empty p { max-width: 420px; margin: 0 auto; color: var(--ink-3); font-size: 14px; line-height: 1.55; }
.ci-card { background: var(--glass); backdrop-filter: blur(22px) saturate(1.5); -webkit-backdrop-filter: blur(22px) saturate(1.5); border: 1px solid var(--glass-border); border-radius: var(--radius-lg,20px); box-shadow: var(--shadow-card); padding: 26px 28px; margin-bottom: 16px; position: relative; overflow: hidden; }
.ci-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: linear-gradient(90deg,#10B981,#6366F1); }
.ci-card-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding-bottom: 14px; margin-bottom: 16px; border-bottom: 1px solid var(--hairline,rgba(12,40,28,.08)); }
.ci-week { display: inline-block; background: var(--brand-soft,rgba(16,185,129,.1)); color: var(--brand-ink,#065F46); font-size: 12.5px; font-weight: 800; padding: 5px 13px; border-radius: 999px; }
.ci-gen-time { font-size: 11.5px; color: var(--ink-4); }
.ci-summary { font-size: 15px; line-height: 1.7; color: var(--ink-2); margin: 0 0 20px; white-space: pre-line; }
.ci-block { border-radius: 14px; padding: 16px 20px; margin: 14px 0; border-left: 4px solid; }
.ci-block-hl { background: var(--brand-soft,rgba(16,185,129,.08)); border-color: #10B981; }
.ci-block-wn { background: rgba(224,133,12,.10); border-color: #F59E0B; }
.ci-block h3 { font-size: 12px; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
.ci-block-hl h3 { color: var(--brand-ink,#065F46); } .ci-block-wn h3 { color: #92400E; }
.ci-block ul { margin: 0; padding-left: 4px; list-style: none; }
.ci-block li { font-size: 13.5px; padding: 5px 0 5px 22px; color: var(--ink); line-height: 1.5; position: relative; }
.ci-block li::before { content: ""; position: absolute; left: 4px; top: 11px; width: 6px; height: 6px; border-radius: 50%; }
.ci-block-hl li::before { background: #10B981; } .ci-block-wn li::before { background: #F59E0B; }
.ci-recos-title { font-size: 12.5px; color: var(--brand-ink,#065F46); margin: 24px 0 14px; padding-bottom: 8px; border-bottom: 1px solid var(--brand-soft,rgba(16,185,129,.2)); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
.ci-recos { display: flex; flex-direction: column; gap: 12px; }
.ci-reco { display: flex; gap: 15px; background: var(--bg-2); border: 1px solid var(--hairline,rgba(12,40,28,.06)); border-radius: 15px; padding: 16px 18px; transition: transform .16s ease, box-shadow .16s ease; }
.ci-reco:hover { transform: translateY(-2px); box-shadow: var(--shadow-card); }
.ci-reco-num { width: 32px; height: 32px; background: linear-gradient(140deg,#34D399,#059669); color: #fff; border-radius: 50%; display: grid; place-items: center; font-weight: 800; flex-shrink: 0; box-shadow: 0 6px 14px -4px rgba(5,150,105,.5); }
.ci-reco-body { flex: 1; min-width: 0; }
.ci-reco-title { font-size: 15px; font-weight: 700; color: var(--ink); letter-spacing: -0.01em; }
.ci-reco-why { font-size: 13px; color: var(--ink-3); margin-top: 5px; line-height: 1.5; }
.ci-history { background: var(--glass); backdrop-filter: blur(20px) saturate(1.5); -webkit-backdrop-filter: blur(20px) saturate(1.5); border: 1px solid var(--glass-border); border-radius: var(--radius-lg,16px); box-shadow: var(--shadow-card); padding: 20px 22px; }
.ci-history h2 { font-size: 12px; color: var(--brand-ink,#065F46); margin: 0 0 12px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
.ci-hist-row { display: flex; justify-content: space-between; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: var(--ink-2); font-size: 13px; transition: background 0.15s; }
.ci-hist-row:hover { background: var(--bg-2); }
.ci-hist-row.is-active { background: var(--brand-soft,rgba(16,185,129,.1)); color: var(--brand-ink,#065F46); font-weight: 650; }
.ci-hist-date { color: var(--ink-4); font-size: 11.5px; font-variant-numeric: tabular-nums; }
@media (max-width: 540px) { .ci-card { padding: 20px 16px; } }
</style>
<?= render_foot() ?>
