<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-grants.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;

$grant_id = (int)($_GET['id'] ?? 0);
$grant = gr_load($pdo, $grant_id, $org_id);
if (!$grant) { http_response_code(404); die('Subvention introuvable.'); }

$steps = gr_load_steps($pdo, $grant_id);
$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$progress = $total_steps > 0 ? round(($done_steps / $total_steps) * 100) : 0;

$logs = [];
try {
    $stmt = $pdo->prepare("SELECT l.*, u.first_name, u.last_name FROM grant_activity_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.grant_id = ? ORDER BY l.created_at DESC LIMIT 30");
    $stmt->execute([$grant_id]);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {}

$m = gr_status_meta($grant['status']);

// Calculs
$days_to_apply = $grant['deadline_apply'] ? (int)((strtotime($grant['deadline_apply']) - time()) / 86400) : null;
$days_to_report = $grant['deadline_report'] ? (int)((strtotime($grant['deadline_report']) - time()) / 86400) : null;

render_head($grant['name']);
?>
<?= render_sidebar('subventions') ?>
<main class="main">
  <div class="gr-page" style="max-width:1100px;">
    <a href="/subventions" class="gr-back">← Subventions</a>

    <div class="gr-detail-hero">
      <div class="gr-detail-info">
        <span class="gr-status" style="background:<?= $m[2] ?>;color:<?= $m[1] ?>;"><?= ak_dot($m[1]) ?> <?= h($m[0]) ?></span>
        <h1 class="gr-pg-title"><?= h($grant['name']) ?></h1>
        <div class="gr-detail-meta">
          <?= h(gr_funder_label($grant['funder_type'])) ?> · <strong><?= h($grant['funder']) ?></strong>
          <?php if ($grant['project_name']): ?>
            · 🎯 <a href="/projet/<?= (int)$grant['project_id'] ?>"><?= h($grant['project_name']) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($can_manage): ?>
      <div class="gr-detail-actions">
        <?php if ($is_admin): ?>
          <a href="/supprimer-subvention/<?= (int)$grant['id'] ?>" class="gr-btn-danger" title="Supprimer définitivement (confirmation requise)"><?= ak_icon('trash', 14) ?> Supprimer</a>
        <?php endif; ?>
        <?php if ($grant['contact_email']):
          // Template mailto auto selon statut
          $st = $grant['status'];
          $subject_tpl = "Suivi dossier {$grant['name']}";
          $body_tpl = "Bonjour " . ($grant['contact_name'] ?: '') . ",\n\n";
          if ($st === 'submitted' || $st === 'in_review') {
              $subject_tpl = "Demande de point d'étape — {$grant['name']}";
              $body_tpl .= "Je me permets de revenir vers vous concernant notre dossier de subvention \"{$grant['name']}\"";
              if ($grant['reference']) $body_tpl .= " (référence : {$grant['reference']})";
              if ($grant['submitted_at']) $body_tpl .= ", déposé le " . date('d/m/Y', strtotime($grant['submitted_at']));
              $body_tpl .= ".\n\nPourriez-vous m'indiquer où en est l'instruction et le calendrier prévisionnel de décision ?\n\nMerci par avance.\n\nBien cordialement,\n";
          } elseif ($st === 'granted' && !$grant['reported_at']) {
              $subject_tpl = "Bilan dossier {$grant['name']}";
              $body_tpl .= "Je vous adresse / vous adresserai prochainement le bilan d'utilisation de la subvention \"{$grant['name']}\".\n\nN'hésitez pas à me préciser les pièces et le format attendus si besoin.\n\nBien cordialement,\n";
          } else {
              $body_tpl .= "Je reviens vers vous au sujet du dossier \"{$grant['name']}\".\n\nBien cordialement,\n";
          }
          $mailto = 'mailto:' . rawurlencode($grant['contact_email']) . '?subject=' . rawurlencode($subject_tpl) . '&body=' . rawurlencode($body_tpl);
        ?>
          <a href="<?= h($mailto) ?>" class="gr-btn-relance" onclick="document.getElementById('gr-mark-relance').style.display='inline-flex';">📧 Relancer le financeur</a>
          <form method="POST" action="/action-subvention" id="gr-mark-relance" style="display:<?= $grant['last_relance_at'] ? 'inline-flex' : 'none' ?>;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="log_relance">
            <input type="hidden" name="id" value="<?= (int)$grant['id'] ?>">
            <button type="submit" class="gr-btn-ghost" title="Confirme que la relance a été envoyée (log + horodatage)">✓ Marquer relance envoyée</button>
          </form>
        <?php endif; ?>
        <a href="/subvention-form?id=<?= (int)$grant['id'] ?>" class="gr-btn-ghost"><?= ak_icon('edit', 14) ?> Modifier</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- KPI -->
    <div class="gr-kpis">
      <div class="gr-kpi"><div class="gr-kpi-lbl">Demandé</div><div class="gr-kpi-val"><?= $grant['amount_requested'] ? number_format((float)$grant['amount_requested'], 0, ',', ' ').' €' : '—' ?></div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">Accordé</div><div class="gr-kpi-val gr-green"><?= $grant['amount_granted'] ? number_format((float)$grant['amount_granted'], 0, ',', ' ').' €' : '—' ?></div></div>
      <div class="gr-kpi"><div class="gr-kpi-lbl">Étapes</div><div class="gr-kpi-val"><?= $done_steps ?>/<?= $total_steps ?></div><div class="gr-kpi-sub"><?= $progress ?>%</div></div>
      <?php if ($days_to_apply !== null && in_array($grant['status'], ['draft','submitted','in_review'], true)): ?>
        <div class="gr-kpi"><div class="gr-kpi-lbl">Deadline dépôt</div><div class="gr-kpi-val <?= $days_to_apply < 0 ? 'gr-red' : ($days_to_apply <= 7 ? 'gr-amber' : '') ?>"><?= $days_to_apply < 0 ? abs($days_to_apply).'j retard' : 'J-'.$days_to_apply ?></div><div class="gr-kpi-sub"><?= date('d/m/Y', strtotime($grant['deadline_apply'])) ?></div></div>
      <?php elseif ($days_to_report !== null && $grant['status'] === 'granted' && !$grant['reported_at']): ?>
        <div class="gr-kpi"><div class="gr-kpi-lbl">Bilan dû</div><div class="gr-kpi-val <?= $days_to_report < 0 ? 'gr-red' : ($days_to_report <= 30 ? 'gr-amber' : '') ?>"><?= $days_to_report < 0 ? abs($days_to_report).'j retard' : 'J-'.$days_to_report ?></div><div class="gr-kpi-sub"><?= date('d/m/Y', strtotime($grant['deadline_report'])) ?></div></div>
      <?php endif; ?>
    </div>

    <div class="gr-cols">
      <div class="gr-col-main">
        <?php if ($grant['description']): ?>
        <div class="gr-card">
          <h2><?= ak_icon('edit', 16) ?> Description</h2>
          <p style="white-space:pre-line;"><?= h($grant['description']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Étapes / checklist -->
        <div class="gr-card">
          <h2><?= ak_icon('check-circle', 16) ?> Étapes (<?= $done_steps ?>/<?= $total_steps ?>)</h2>
          <?php if (empty($steps)): ?>
            <p class="gr-muted">Pas d'étapes définies.</p>
          <?php else: ?>
            <div class="gr-steps-list">
              <?php foreach ($steps as $s): ?>
              <div class="gr-step-row <?= $s['is_completed'] ? 'is-done' : '' ?>">
                <?php if ($can_manage): ?>
                <form method="POST" action="/action-subvention" class="gr-step-form">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="toggle_step">
                  <input type="hidden" name="grant_id" value="<?= (int)$grant['id'] ?>">
                  <input type="hidden" name="step_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="gr-step-check <?= $s['is_completed'] ? 'is-done' : '' ?>" title="<?= $s['is_completed'] ? 'Décocher' : 'Marquer fait' ?>"><?= $s['is_completed'] ? '✓' : '' ?></button>
                </form>
                <?php else: ?>
                <span class="gr-step-check <?= $s['is_completed'] ? 'is-done' : '' ?>"><?= $s['is_completed'] ? '✓' : '' ?></span>
                <?php endif; ?>
                <span class="gr-step-title"><?= h($s['title']) ?></span>
                <?php if ($s['is_completed'] && $s['completed_at']): ?>
                  <span class="gr-step-date"><?= date('d/m', strtotime($s['completed_at'])) ?></span>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($grant['notes']): ?>
        <div class="gr-card">
          <h2><?= ak_icon('pin', 16) ?> Notes internes</h2>
          <p style="white-space:pre-line;"><?= h($grant['notes']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Activity log -->
        <?php if (!empty($logs)): ?>
        <div class="gr-card">
          <h2>📜 Historique</h2>
          <div class="gr-logs">
            <?php foreach ($logs as $l): ?>
            <div class="gr-log">
              <span class="gr-log-date"><?= date('d/m H:i', strtotime($l['created_at'])) ?></span>
              <span class="gr-log-user"><?= h(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: 'Système') ?></span>
              <span class="gr-log-label"><?= h($l['action_label']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="gr-col-side">
        <div class="gr-card">
          <h2><?= ak_icon('calendar', 16) ?> Calendrier</h2>
          <?php
          $dates = [
            ['Deadline dépôt', $grant['deadline_apply']],
            ['Date de dépôt', $grant['submitted_at']],
            ['Décision', $grant['decision_at']],
            ['Deadline bilan', $grant['deadline_report']],
            ['Bilan rendu', $grant['reported_at']],
            ['Dernière relance', $grant['last_relance_at']],
          ];
          ?>
          <ul class="gr-dates">
            <?php foreach ($dates as $d): if (!$d[1]) continue; ?>
              <li><span class="gr-date-lbl"><?= h($d[0]) ?></span><strong><?= date('d/m/Y', strtotime($d[1])) ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if ($grant['contact_name'] || $grant['contact_email'] || $grant['contact_phone']): ?>
        <div class="gr-card">
          <h2><?= ak_icon('phone', 16) ?> Contact</h2>
          <?php if ($grant['contact_name']): ?><div><strong><?= h($grant['contact_name']) ?></strong></div><?php endif; ?>
          <?php if ($grant['contact_email']): ?><div><a href="mailto:<?= h($grant['contact_email']) ?>"><?= h($grant['contact_email']) ?></a></div><?php endif; ?>
          <?php if ($grant['contact_phone']): ?><div><a href="tel:<?= h($grant['contact_phone']) ?>"><?= h($grant['contact_phone']) ?></a></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
/* Helper plateforme */
if (!function_exists('gr_platform_label')) {
  function gr_platform_label($code) {
    $map = [
      'dauphin'=>['🏛️','Dauphin','Politique de la Ville · État'],
      'subventia'=>['🛡️','Subventia','FIPD · Ministère Intérieur'],
      'elan'=>['👨‍👩‍👧','Elan','Parentalité · CAF'],
      'lecompteasso'=>['🌍','Le Compte Asso','CNDS · FDVA · JEP'],
      'demarches_simplifiees'=>['📋','Démarches Simplifiées','État généraliste'],
      'ddva'=>['🤝','FDVA','Vie Associative'],
      'region'=>['🗺️','Portail Région',''],
      'departement'=>['🏞️','Portail Département',''],
      'commune'=>['🏘️','Portail Commune / EPCI',''],
      'google_form'=>['📝','Google Form',''],
      'email'=>['📧','Par email',''],
      'papier'=>['📄','Dossier papier',''],
      'autre'=>['🔗','Autre',''],
    ];
    return $map[$code] ?? ['🔗', ucfirst($code), ''];
  }
}
?>
<?php if (!empty($grant['platform']) || !empty($grant['platform_url'])):
  [$pf_ico, $pf_name, $pf_sub] = gr_platform_label($grant['platform'] ?? 'autre');
?>
  <div class="gr-detail-block" style="margin-top:14px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:8px;">📂 Plateforme de dépôt</div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div style="font-size:28px;line-height:1;"><?= $pf_ico ?></div>
      <div style="flex:1;min-width:160px;">
        <div style="font-weight:600;color:#111827;font-size:15px;"><?= h($pf_name) ?></div>
        <?php if ($pf_sub): ?><div style="font-size:12.5px;color:#6b7280;"><?= h($pf_sub) ?></div><?php endif; ?>
      </div>
      <?php if (!empty($grant['platform_url'])): ?>
        <a href="<?= h($grant['platform_url']) ?>" target="_blank" rel="noopener" style="background:#10B981;color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
          Ouvrir le dossier ↗
        </a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($grant['cerfa_number'] || $grant['reference']): ?>
        <div class="gr-card">
          <h2><?= ak_icon('clipboard', 16) ?> Références</h2>
          <?php if ($grant['cerfa_number']): ?><div>CERFA : <code><?= h($grant['cerfa_number']) ?></code></div><?php endif; ?>
          <?php if ($grant['reference']): ?><div>Réf. : <code><?= h($grant['reference']) ?></code></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<style>

.gr-btn-danger { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #FEF2F2; color: #DC2626; border: 1px solid #FCA5A5; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; font-family: inherit; }
.gr-btn-danger:hover { background: #FEE2E2; border-color: #DC2626; }
.gr-detail-hero { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 18px; flex-wrap: wrap; }
.gr-detail-info { flex: 1; min-width: 0; }
.gr-detail-info .gr-status { display: inline-block; margin-bottom: 8px; }
.gr-detail-info h1 { margin: 0 0 6px; }
.gr-detail-meta { color: #6b7280; font-size: 13.5px; }
.gr-detail-meta a { color: #10B981; text-decoration: none; }
.gr-detail-meta a:hover { text-decoration: underline; }
.gr-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.gr-btn-relance { display: inline-flex; align-items: center; padding: 9px 16px; background: #6366F1; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; }
.gr-btn-relance:hover { background: #4F46E5; }
.gr-cols { display: grid; grid-template-columns: 1fr 320px; gap: 16px; }
.gr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 20px; margin-bottom: 14px; word-break: break-word; overflow-wrap: anywhere; }
.gr-card code { overflow-wrap: anywhere; word-break: break-word; }
.gr-card h2 { display: flex; align-items: center; gap: 7px; font-size: 13px; margin: 0 0 12px; color: #065F46; padding-bottom: 6px; border-bottom: 1px solid #f3f4f6; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.gr-card h2 svg { flex: none; }
.gr-muted { color: #9ca3af; font-size: 13px; }
.gr-steps-list { display: flex; flex-direction: column; gap: 6px; }
.gr-step-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; }
.gr-step-form { display: inline-flex; }
.gr-step-check { width: 20px; height: 20px; border: 2px solid #d1d5db; background: #fff; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; padding: 0; flex-shrink: 0; }
.gr-step-check.is-done { background: #10B981; border-color: #10B981; }
.gr-step-title { flex: 1; font-size: 13.5px; color: #111827; }
.gr-step-row.is-done .gr-step-title { color: #9ca3af; text-decoration: line-through; }
.gr-step-date { font-size: 11px; color: #6b7280; }
.gr-logs { display: flex; flex-direction: column; gap: 8px; }
.gr-log { display: flex; gap: 10px; font-size: 12.5px; padding: 6px 0; border-bottom: 1px dashed #f3f4f6; }
.gr-log:last-child { border-bottom: 0; }
.gr-log-date { color: #9ca3af; min-width: 70px; flex-shrink: 0; font-variant-numeric: tabular-nums; }
.gr-log-user { color: #4b5563; font-weight: 600; min-width: 90px; flex-shrink: 0; }
.gr-log-label { color: #111827; }
.gr-dates { list-style: none; padding: 0; margin: 0; }
.gr-dates li { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #f3f4f6; font-size: 13px; }
.gr-dates li:last-child { border-bottom: 0; }
.gr-date-lbl { color: #6b7280; }
.gr-kpi-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
@media (max-width: 720px) { .gr-cols { grid-template-columns: 1fr; } }

/* === FIX 2026-05 : KPI cards manquantes sur page detail === */
.gr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
.gr-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
.gr-kpi-lbl { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 6px; }
.gr-kpi-val { font-size: 22px; font-weight: 700; color: #111827; line-height: 1.1; }
.gr-green { color: #10B981 !important; }
.gr-red { color: #DC2626 !important; }
.gr-amber { color: #F59E0B !important; }

/* === FIX 2026-05 : Boutons + badges manquants === */
.gr-back { display: inline-flex; align-items: center; gap: 4px; color: #6b7280; text-decoration: none; font-size: 13px; font-weight: 500; margin-bottom: 8px; transition: color 0.15s; }
.gr-back:hover { color: #059669; }

.gr-status { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1.4; margin-bottom: 8px; }

.gr-pg-title { font-size: 28px; font-weight: 700; margin: 4px 0 8px; color: #0A0A0B; letter-spacing: -0.02em; line-height: 1.2; }

.gr-btn-ghost { display: inline-flex; align-items: center; gap: 5px; padding: 8px 14px; background: #fff; color: #3F3F46; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer; transition: all 0.15s; line-height: 1.2; }
.gr-btn-ghost:hover { background: #FAFAF9; border-color: #d1d5db; color: #111827; }

.gr-btn-danger { display: inline-flex; align-items: center; gap: 5px; padding: 8px 14px; background: #DC2626; color: #fff; border: 0; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; line-height: 1.2; }
.gr-btn-danger:hover { background: #B91C1C; }
</style>
<?= render_foot() ?>
