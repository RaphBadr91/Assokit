<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin');
if (!$is_admin) { http_response_code(403); die('Réservé aux admins.'); }

$stmt = $pdo->prepare("SELECT * FROM assemblies WHERE org_id = ? AND archived_at IS NULL ORDER BY scheduled_at DESC");
$stmt->execute([$org_id]);
$ags = $stmt->fetchAll();
$stats = ag_org_stats($pdo, $org_id);

render_head('Assemblées');
?>
<?= render_sidebar('assemblees') ?>
<main class="main">
  <div class="ag-page">
    <div class="ag-pg-head">
      <div>
        <h1 class="ag-pg-title">🏛️ Assemblées & réunions</h1>
        <p class="ag-pg-sub">Convoque, fais voter, signe et génère le PV automatiquement.</p>
      </div>
      <a href="/assemblee-form" class="ag-btn-primary">+ Nouvelle assemblée</a>
    </div>

    <div class="ag-kpis">
      <div class="ag-kpi"><div class="ag-kpi-lbl">Total</div><div class="ag-kpi-val"><?= (int)$stats['total'] ?></div></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">À venir</div><div class="ag-kpi-val ag-blue"><?= (int)$stats['upcoming'] ?></div></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">En cours</div><div class="ag-kpi-val ag-green"><?= (int)$stats['in_progress'] ?></div></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">Clôturées</div><div class="ag-kpi-val"><?= (int)$stats['closed'] ?></div></div>
    </div>

    <?php if (empty($ags)): ?>
    <div class="ag-empty">
      <div style="font-size:48px;">🏛️</div>
      <h2>Aucune assemblée</h2>
      <p>Crée ta première AG : convocation auto, signature électronique, votes en ligne, PV automatique.</p>
      <a href="/assemblee-form" class="ag-btn-primary">Créer une AG</a>
    </div>
    <?php else: ?>
    <div class="ag-list">
      <?php foreach ($ags as $ag): $m = ag_status_meta($ag['status']); ?>
        <a href="/assemblee/<?= (int)$ag['id'] ?>" class="ag-row">
          <div class="ag-row-date">
            <div class="ag-row-day"><?= date('d', strtotime($ag['scheduled_at'])) ?></div>
            <div class="ag-row-month"><?= fr_format_date('%b', strtotime($ag['scheduled_at'])) ?></div>
            <div class="ag-row-time"><?= date('H:i', strtotime($ag['scheduled_at'])) ?></div>
          </div>
          <div class="ag-row-main">
            <div class="ag-row-name"><?= h($ag['title']) ?></div>
            <div class="ag-row-meta">
              <?= h(ag_type_label($ag['type'])) ?>
              <?php if ($ag['location']): ?> · 📍 <?= h($ag['location']) ?><?php endif; ?>
            </div>
          </div>
          <span class="ag-status" style="background:<?= $m[2] ?>;color:<?= $m[1] ?>;"><?= $m[3] ?> <?= h($m[0]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<style>
.ag-page { max-width: 1100px; margin: 0 auto; padding: 24px 22px; }
.ag-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.ag-pg-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.ag-pg-sub { color: #6b7280; margin: 0; font-size: 14px; }
.ag-btn-primary { display: inline-flex; padding: 9px 16px; background: #10B981; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; }
.ag-btn-primary:hover { background: #059669; }
.ag-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px; }
.ag-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
.ag-kpi-lbl { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.ag-kpi-val { font-size: 22px; font-weight: 700; color: #111827; }
.ag-blue { color: #3B82F6 !important; } .ag-green { color: #10B981 !important; }
.ag-empty { text-align: center; padding: 60px 20px; background: #fff; border: 2px dashed #e5e7eb; border-radius: 14px; }
.ag-empty h2 { font-size: 20px; margin: 12px 0 8px; }
.ag-empty p { max-width: 440px; margin: 0 auto 18px; color: #6b7280; font-size: 14px; }
.ag-list { display: flex; flex-direction: column; gap: 8px; }
.ag-row { display: flex; align-items: center; gap: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; text-decoration: none; color: inherit; transition: all 0.15s; }
.ag-row:hover { border-color: #10B981; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.ag-row-date { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 6px 10px; text-align: center; min-width: 56px; }
.ag-row-day { font-size: 18px; font-weight: 700; color: #111827; line-height: 1; }
.ag-row-month { font-size: 10px; color: #6b7280; text-transform: uppercase; }
.ag-row-time { font-size: 11px; color: #4b5563; margin-top: 2px; }
.ag-row-main { flex: 1; min-width: 0; }
.ag-row-name { font-size: 15px; font-weight: 700; color: #111827; }
.ag-row-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
.ag-status { font-size: 11px; padding: 4px 10px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
</style>
<?= render_foot() ?>
