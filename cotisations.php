<?php
/**
 * /cotisations - Dashboard des campagnes de cotisation
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;

// Charger campagnes (actives + archivées)
$stmt = $pdo->prepare("SELECT c.*,
    (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status='paid') AS count_paid,
    (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status='pending') AS count_pending,
    (SELECT COALESCE(SUM(amount),0) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status='paid') AS total_paid
    FROM cotisation_campaigns c
    WHERE c.org_id = ? AND c.archived_at IS NULL
    ORDER BY c.is_active DESC, c.year DESC, c.created_at DESC");
$stmt->execute([$org_id]);
$campaigns = $stmt->fetchAll();

// Stats globales année en cours
$current_year = (int)date('Y');
$year_stats = ['paid' => 0, 'pending' => 0, 'count_payers' => 0];
try {
    // Exercice : « encaissé » rattaché à la date de paiement (paid_at) — cohérent avec le FEC.
    // « En attente » (non payé) rattaché à la date de création (pas de paid_at).
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN p.status='paid' AND YEAR(p.paid_at) = ? THEN p.amount ELSE 0 END), 0) AS paid,
        COALESCE(SUM(CASE WHEN p.status='pending' AND YEAR(p.created_at) = ? THEN p.amount ELSE 0 END), 0) AS pending,
        COUNT(DISTINCT CASE WHEN p.status='paid' AND YEAR(p.paid_at) = ? THEN COALESCE(p.adherent_id, CONCAT('e:', p.payer_email)) END) AS payers
        FROM cotisation_payments p
        WHERE p.org_id = ?");
    $stmt->execute([$current_year, $current_year, $current_year, $org_id]);
    if ($r = $stmt->fetch()) {
        $year_stats['paid'] = (float)$r['paid'];
        $year_stats['pending'] = (float)$r['pending'];
        $year_stats['count_payers'] = (int)$r['payers'];
    }
} catch (Throwable $e) {}

render_head('Cotisations');
?>
<?= render_sidebar('cotisations') ?>
<main class="main">
  <div class="ck-page">
    <div class="ck-pg-head">
      <div>
        <h1 class="ck-pg-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('euro','#059669',36) ?><span>Cotisations</span></h1>
        <p class="ck-pg-sub">Suis les paiements des adhérents et lance des campagnes annuelles.</p>
      </div>
      <?php if ($can_manage): ?>
      <a href="/cotisation-form" class="ck-btn-primary">+ Nouvelle campagne</a>
      <?php endif; ?>
    </div>

    <!-- KPI globaux année -->
    <div class="ck-kpis">
      <div class="ck-kpi"><div class="ck-kpi-lbl">Encaissé <?= $current_year ?></div><div class="ck-kpi-val ck-green"><?= number_format($year_stats['paid'], 0, ',', ' ') ?> €</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">En attente</div><div class="ck-kpi-val ck-amber"><?= number_format($year_stats['pending'], 0, ',', ' ') ?> €</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Payeurs <?= $current_year ?></div><div class="ck-kpi-val"><?= $year_stats['count_payers'] ?></div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Campagnes actives</div><div class="ck-kpi-val"><?= count(array_filter($campaigns, fn($c) => $c['is_active'])) ?></div></div>
    </div>

    <?php if (empty($campaigns)): ?>
    <div class="ck-empty">
      <div class="ck-empty-emoji" style="color:#64748B;"><?= ak_icon('euro',44,'1.5') ?></div>
      <h2>Aucune campagne pour le moment</h2>
      <p>Crée ta première campagne (ex : "Adhésion 2025-2026") avec un ou plusieurs tarifs (membre, étudiant, famille…).</p>
      <?php if ($can_manage): ?><a href="/cotisation-form" class="ck-btn-primary">Créer une campagne</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ck-camp-list">
      <?php foreach ($campaigns as $c):
        $total = $c['count_paid'] + $c['count_pending'];
      ?>
      <a href="/cotisation/<?= (int)$c['id'] ?>" class="ck-camp">
        <div class="ck-camp-head">
          <div>
            <div class="ck-camp-name"><?= h($c['name']) ?></div>
            <?php if ($c['year']): ?><div class="ck-camp-year"><?= (int)$c['year'] ?></div><?php endif; ?>
          </div>
          <span class="ck-camp-status <?= $c['is_active'] ? 'active' : 'paused' ?>"><?= $c['is_active'] ? '● Active' : '○ Pause' ?></span>
        </div>
        <div class="ck-camp-stats">
          <span><strong><?= (int)$c['count_paid'] ?></strong> payés</span>
          <?php if ($c['count_pending'] > 0): ?>
            <span class="ck-amber">·  <strong><?= (int)$c['count_pending'] ?></strong> en attente</span>
          <?php endif; ?>
          <span class="ck-camp-total"><?= number_format((float)$c['total_paid'], 0, ',', ' ') ?> €</span>
        </div>
        <?php if ($c['closes_at']): ?>
        <div class="ck-camp-deadline">Clôture : <?= date('d/m/Y', strtotime($c['closes_at'])) ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<style>
.ck-page { max-width: 1100px; margin: 0 auto; padding: 24px 22px; }
.ck-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.ck-pg-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.ck-pg-sub { color: #6b7280; margin: 0; font-size: 14px; }
.ck-btn-primary { display: inline-flex; align-items: center; padding: 9px 16px; background: #10B981; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; transition: background 0.15s; }
.ck-btn-primary:hover { background: #059669; }
.ck-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
.ck-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
.ck-kpi-lbl { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.ck-kpi-val { font-size: 22px; font-weight: 700; color: #111827; }
.ck-green { color: #10B981; } .ck-amber { color: #F59E0B; }
.ck-empty { text-align: center; padding: 50px 20px; background: #fff; border: 2px dashed #e5e7eb; border-radius: 14px; }
.ck-empty-emoji { font-size: 48px; margin-bottom: 12px; }
.ck-empty h2 { font-size: 18px; margin: 0 0 8px; color: #111827; }
.ck-empty p { color: #6b7280; max-width: 460px; margin: 0 auto 18px; font-size: 14px; }
.ck-camp-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.ck-camp { display: block; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; text-decoration: none; color: inherit; transition: all 0.18s; }
.ck-camp:hover { border-color: #10B981; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
.ck-camp-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; gap: 10px; }
.ck-camp-name { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.3; }
.ck-camp-year { font-size: 12px; color: #6b7280; margin-top: 2px; }
.ck-camp-status { font-size: 10.5px; padding: 3px 8px; border-radius: 999px; font-weight: 600; flex-shrink: 0; }
.ck-camp-status.active { background: #ECFDF5; color: #065F46; }
.ck-camp-status.paused { background: #F3F4F6; color: #6B7280; }
.ck-camp-stats { font-size: 12.5px; color: #6b7280; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.ck-camp-stats strong { color: #111827; }
.ck-camp-total { margin-left: auto; font-weight: 700; color: #10B981; font-size: 14px; }
.ck-camp-deadline { font-size: 11.5px; color: #6b7280; margin-top: 8px; padding-top: 8px; border-top: 1px solid #f3f4f6; }
@media (max-width: 540px) { .ck-camp-list { grid-template-columns: 1fr; } }
</style>
<?= render_foot() ?>
