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
.ci-page { max-width: 880px; margin: 0 auto; padding: 24px 22px; }
.ci-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
.ci-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.ci-sub { color: #6b7280; margin: 0; font-size: 14px; }
.ci-btn-primary { padding: 10px 18px; background: #10B981; color: #fff; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.ci-btn-primary:hover:not(:disabled) { background: #059669; }
.ci-btn-primary:disabled { opacity: 0.6; cursor: wait; }
.ci-empty { text-align: center; padding: 60px 20px; background: #fff; border: 2px dashed #e5e7eb; border-radius: 14px; }
.ci-empty h2 { font-size: 20px; margin: 12px 0 6px; }
.ci-empty p { max-width: 420px; margin: 0 auto; color: #6b7280; font-size: 14px; line-height: 1.55; }
.ci-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 24px 26px; margin-bottom: 16px; }
.ci-card-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding-bottom: 12px; margin-bottom: 14px; border-bottom: 1px solid #f3f4f6; }
.ci-week { display: inline-block; background: #ECFDF5; color: #065F46; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; }
.ci-gen-time { font-size: 11.5px; color: #9ca3af; }
.ci-summary { font-size: 14.5px; line-height: 1.65; color: #1f2937; margin: 0 0 18px; white-space: pre-line; }
.ci-block { border-radius: 10px; padding: 14px 18px; margin: 14px 0; border-left: 3px solid; }
.ci-block-hl { background: #ECFDF5; border-color: #10B981; }
.ci-block-wn { background: #FEF3C7; border-color: #F59E0B; }
.ci-block h3 { font-size: 12px; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.04em; }
.ci-block-hl h3 { color: #065F46; } .ci-block-wn h3 { color: #92400E; }
.ci-block ul { margin: 0; padding-left: 18px; }
.ci-block li { font-size: 13px; padding: 3px 0; color: #1f2937; }
.ci-recos-title { font-size: 14px; color: #065F46; margin: 22px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; text-transform: uppercase; letter-spacing: 0.04em; }
.ci-recos { display: flex; flex-direction: column; gap: 10px; }
.ci-reco { display: flex; gap: 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; }
.ci-reco-num { width: 30px; height: 30px; background: #10B981; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
.ci-reco-body { flex: 1; min-width: 0; }
.ci-reco-title { font-size: 14.5px; font-weight: 600; color: #111827; }
.ci-reco-why { font-size: 12.5px; color: #6b7280; margin-top: 4px; }
.ci-history { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 22px; }
.ci-history h2 { font-size: 13px; color: #065F46; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.04em; }
.ci-hist-row { display: flex; justify-content: space-between; padding: 9px 12px; border-radius: 8px; text-decoration: none; color: #1f2937; font-size: 13px; transition: background 0.15s; }
.ci-hist-row:hover { background: #f9fafb; }
.ci-hist-row.is-active { background: #ECFDF5; color: #065F46; font-weight: 600; }
.ci-hist-date { color: #9ca3af; font-size: 11.5px; font-variant-numeric: tabular-nums; }
@media (max-width: 540px) { .ci-card { padding: 18px 16px; } }
</style>
<?= render_foot() ?>
