<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;
if (!$can_manage) { http_response_code(403); die('Réservé.'); }

$stmt = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM attendance_records WHERE session_id = s.id) AS nb_signed FROM attendance_sessions s WHERE s.org_id = ? AND s.archived_at IS NULL ORDER BY s.starts_at DESC LIMIT 200");
$stmt->execute([$org_id]);
$sessions = $stmt->fetchAll();
$stats = att_org_stats($pdo, $org_id);

render_head('Émargement');
?>
<?= render_sidebar('emargement') ?>
<main class="main">
  <div class="at-page">
    <div class="at-pg-head">
      <div>
        <h1 class="at-pg-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('edit','#059669',36) ?><span>Émargement</span></h1>
        <p class="at-pg-sub">Crée une session, partage un QR code, récupère les signatures.</p>
      </div>
      <a href="/emargement-form" class="at-btn-primary">+ Nouvelle session</a>
    </div>

    <div class="at-kpis">
      <div class="at-kpi"><div class="at-kpi-lbl">Total sessions</div><div class="at-kpi-val"><?= (int)$stats['total'] ?></div></div>
      <div class="at-kpi"><div class="at-kpi-lbl">Ouvertes</div><div class="at-kpi-val at-green"><?= (int)$stats['open'] ?></div></div>
      <div class="at-kpi"><div class="at-kpi-lbl">Aujourd'hui</div><div class="at-kpi-val at-blue"><?= (int)$stats['today'] ?></div></div>
      <div class="at-kpi"><div class="at-kpi-lbl">Signatures</div><div class="at-kpi-val"><?= (int)$stats['records_total'] ?></div></div>
    </div>

    <?php if (empty($sessions)): ?>
    <div class="at-empty">
      <div style="color:#64748B;"><?= ak_icon('edit',44,'1.5') ?></div>
      <h2>Aucune session d'émargement</h2>
      <p>Crée ta première session pour un cours, un entraînement, une formation ou une réunion. Partage le QR code aux participants pour qu'ils signent en 5 secondes.</p>
      <a href="/emargement-form" class="at-btn-primary">Créer une session</a>
    </div>
    <?php else: ?>
    <div class="at-list">
      <?php foreach ($sessions as $s): ?>
      <a href="/emargement/<?= (int)$s['id'] ?>" class="at-row">
        <div class="at-row-date">
          <div class="at-row-day"><?= date('d', strtotime($s['starts_at'])) ?></div>
          <div class="at-row-month"><?= fr_format_date('%b', strtotime($s['starts_at'])) ?></div>
          <div class="at-row-time"><?= date('H:i', strtotime($s['starts_at'])) ?></div>
        </div>
        <div class="at-row-main">
          <div class="at-row-name"><?= h($s['title']) ?></div>
          <div class="at-row-meta"><?= $s['location'] ? ak_icon('pin',13).' '.h($s['location']).' · ' : '' ?><?= ak_icon('edit',13) ?> <?= (int)$s['nb_signed'] ?> signature<?= $s['nb_signed'] > 1 ? 's' : '' ?></div>
        </div>
        <span class="at-status <?= $s['is_open'] ? 'open' : 'closed' ?>">
          <?= $s['is_open'] ? ak_dot('#10B981').' Ouverte' : ak_icon('lock',13).' Fermée' ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<style>
.at-page { max-width: 1100px; margin: 0 auto; padding: 24px 22px; }
.at-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.at-pg-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.at-pg-sub { color: #6b7280; margin: 0; font-size: 14px; }
.at-btn-primary { display: inline-flex; padding: 9px 16px; background: #10B981; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; }
.at-btn-primary:hover { background: #059669; }
.at-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px; }
.at-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
.at-kpi-lbl { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.at-kpi-val { font-size: 22px; font-weight: 700; color: #111827; }
.at-green { color: #10B981 !important; } .at-blue { color: #3B82F6 !important; }
.at-empty { text-align: center; padding: 60px 20px; background: #fff; border: 2px dashed #e5e7eb; border-radius: 14px; }
.at-empty h2 { font-size: 20px; margin: 12px 0 8px; }
.at-empty p { max-width: 460px; margin: 0 auto 18px; color: #6b7280; font-size: 14px; line-height: 1.55; }
.at-list { display: flex; flex-direction: column; gap: 8px; }
.at-row { display: flex; align-items: center; gap: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; text-decoration: none; color: inherit; transition: all 0.15s; }
.at-row:hover { border-color: #10B981; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.at-row-date { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 6px 10px; text-align: center; min-width: 56px; }
.at-row-day { font-size: 18px; font-weight: 700; color: #111827; line-height: 1; }
.at-row-month { font-size: 10px; color: #6b7280; text-transform: uppercase; }
.at-row-time { font-size: 11px; color: #4b5563; margin-top: 2px; }
.at-row-main { flex: 1; min-width: 0; }
.at-row-name { font-size: 15px; font-weight: 700; color: #111827; }
.at-row-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
.at-row-meta svg { vertical-align: -2px; }
.at-status { font-size: 11px; padding: 4px 10px; border-radius: 999px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
.at-status svg { flex: none; }
.at-status.open { background: #ECFDF5; color: #065F46; }
.at-status.closed { background: #f3f4f6; color: #6b7280; }
</style>
<?= render_foot() ?>
