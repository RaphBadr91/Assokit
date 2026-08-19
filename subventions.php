<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-grants.php';
require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;

$filter = $_GET['status'] ?? '';
$valid_filters = ['draft','submitted','in_review','granted','rejected','reported'];

$where = "g.org_id = ? AND g.archived_at IS NULL";
$params = [$org_id];
if (in_array($filter, $valid_filters, true)) { $where .= " AND g.status = ?"; $params[] = $filter; }

$stmt = $pdo->prepare("SELECT g.*, p.name AS project_name FROM grants g LEFT JOIN projects p ON p.id = g.project_id WHERE $where ORDER BY
    CASE WHEN g.deadline_apply IS NOT NULL AND g.deadline_apply >= CURDATE() THEN 0 ELSE 1 END,
    g.deadline_apply ASC, g.created_at DESC");
$stmt->execute($params);
$grants = $stmt->fetchAll();

$stats = gr_org_stats($pdo, $org_id);

render_head('Subventions');
?>
<?= render_sidebar('subventions') ?>
<main class="main">
  <div class="gr-page">
    <div class="gr-pg-head">
      <div>
        <h1 class="gr-pg-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('euro','#059669',36) ?><span>Subventions</span></h1>
        <p class="gr-pg-sub">Suis tes demandes de subvention de A à Z : dépôt, instruction, accord, bilan.</p>
      </div>
      <?php if ($can_manage): ?><a href="/subvention-form" class="gr-btn-primary">+ Nouvelle demande</a><?php endif; ?>
    </div>

    <div class="gr-kpis">
      <div class="gr-kpi"><div class="gr-kpi-lbl">Total dossiers</div><div class="gr-kpi-val"><?= (int)$stats['count'] ?></div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">Demandé</div><div class="gr-kpi-val"><?= number_format($stats['requested'], 0, ',', ' ') ?> €</div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">Accordé</div><div class="gr-kpi-val gr-green"><?= number_format($stats['granted'], 0, ',', ' ') ?> €</div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">En instruction</div><div class="gr-kpi-val gr-amber"><?= (int)$stats['pending'] ?></div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">Échéances 30j</div><div class="gr-kpi-val <?= $stats['upcoming'] > 0 ? 'gr-red' : '' ?>"><?= (int)$stats['upcoming'] ?></div></div>
    </div>

    <div class="gr-filters">
      <a href="/subventions" class="gr-chip <?= $filter === '' ? 'is-active' : '' ?>">Tous</a>
      <?php foreach ($valid_filters as $f): $m = gr_status_meta($f); ?>
        <a href="/subventions?status=<?= h($f) ?>" class="gr-chip <?= $filter === $f ? 'is-active' : '' ?>" style="<?= $filter === $f ? 'background:'.$m[2].';color:'.$m[1].';border-color:'.$m[1] : '' ?>"><?= $m[3] ?> <?= h($m[0]) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($grants)): ?>
    <div class="gr-empty">
      <div class="gr-empty-emoji" style="color:#94A3B8;"><?= ak_icon('euro',44,'1.5') ?></div>
      <h2>Aucune subvention pour le moment</h2>
      <p>Crée ton premier dossier (CAF, FDVA, mairie, fondation…) pour suivre la deadline et le statut.</p>
      <?php if ($can_manage): ?><a href="/subvention-form" class="gr-btn-primary">Créer une demande</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="gr-list">
      <?php foreach ($grants as $g):
        $m = gr_status_meta($g['status']);
        $days_left = null;
        if ($g['deadline_apply']) {
          $days_left = (int)((strtotime($g['deadline_apply']) - time()) / 86400);
        }
      ?>
      <a href="/subvention/<?= (int)$g['id'] ?>" class="gr-row">
        <div class="gr-row-main">
          <div class="gr-row-name"><?= h($g['name']) ?></div>
          <div class="gr-row-meta">
            <?= h(gr_funder_label($g['funder_type'])) ?> · <?= h($g['funder']) ?>
            <?php if ($g['project_name']): ?> · <span style="display:inline-flex; vertical-align:-2px;"><?= ak_icon('target',13) ?></span> <?= h($g['project_name']) ?><?php endif; ?>
          </div>
        </div>
        <div class="gr-row-amount">
          <?php if ($g['status'] === 'granted' && $g['amount_granted']): ?>
            <strong class="gr-green"><?= number_format((float)$g['amount_granted'], 0, ',', ' ') ?> €</strong>
            <span class="gr-row-amount-sub">accordé</span>
          <?php elseif ($g['amount_requested']): ?>
            <strong><?= number_format((float)$g['amount_requested'], 0, ',', ' ') ?> €</strong>
            <span class="gr-row-amount-sub">demandé</span>
          <?php else: ?>
            <span class="gr-row-amount-sub">—</span>
          <?php endif; ?>
        </div>
        <span class="gr-status" style="background:<?= $m[2] ?>;color:<?= $m[1] ?>;"><?= $m[3] ?> <?= h($m[0]) ?></span>
        <?php if ($days_left !== null && in_array($g['status'], ['draft','submitted','in_review'], true)): ?>
          <span class="gr-deadline <?= $days_left < 0 ? 'gr-overdue' : ($days_left <= 7 ? 'gr-urgent' : '') ?>">
            <?php if ($days_left < 0): ?>Retard <?= abs($days_left) ?>j<?php else: ?>J-<?= $days_left ?><?php endif; ?>
          </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<style>
.gr-page { max-width: 1100px; margin: 0 auto; padding: 24px 22px; }
.gr-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.gr-pg-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.gr-pg-sub { color: #6b7280; margin: 0; font-size: 14px; }
.gr-btn-primary { display: inline-flex; padding: 9px 16px; background: #10B981; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; }
.gr-btn-primary:hover { background: #059669; }
.gr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px; }
.gr-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
.gr-kpi-lbl { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.gr-kpi-val { font-size: 20px; font-weight: 700; color: #111827; }
.gr-green { color: #10B981 !important; } .gr-amber { color: #F59E0B !important; } .gr-red { color: #EF4444 !important; }
.gr-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
.gr-chip { padding: 5px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 999px; font-size: 12px; text-decoration: none; color: #4b5563; transition: all 0.15s; }
.gr-chip:hover { background: #f9fafb; }
.gr-chip.is-active { background: #ECFDF5; color: #065F46; border-color: #10B981; font-weight: 600; }
.gr-empty { text-align: center; padding: 50px 20px; background: #fff; border: 2px dashed #e5e7eb; border-radius: 14px; }
.gr-empty-emoji { font-size: 48px; margin-bottom: 12px; }
.gr-empty h2 { font-size: 18px; margin: 0 0 8px; }
.gr-empty p { color: #6b7280; max-width: 460px; margin: 0 auto 18px; font-size: 14px; }
.gr-list { display: flex; flex-direction: column; gap: 8px; }
.gr-row { display: flex; align-items: center; gap: 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 16px; text-decoration: none; color: inherit; transition: all 0.15s; }
.gr-row:hover { border-color: #10B981; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.gr-row-main { flex: 1; min-width: 0; }
.gr-row-name { font-size: 14.5px; font-weight: 700; color: #111827; }
.gr-row-meta { font-size: 12px; color: #6b7280; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gr-row-amount { text-align: right; min-width: 90px; }
.gr-row-amount strong { display: block; font-size: 14px; color: #111827; }
.gr-row-amount-sub { font-size: 10.5px; color: #9ca3af; }
.gr-status { font-size: 11px; padding: 4px 10px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
.gr-deadline { font-size: 11px; padding: 3px 9px; border-radius: 6px; font-weight: 700; background: #f3f4f6; color: #6b7280; white-space: nowrap; }
.gr-deadline.gr-urgent { background: #FEF3C7; color: #92400E; }
.gr-deadline.gr-overdue { background: #FEE2E2; color: #991B1B; }
@media (max-width: 540px) { .gr-row { flex-wrap: wrap; } .gr-row-main { flex: 1 0 100%; } }
</style>
<?= render_foot() ?>
