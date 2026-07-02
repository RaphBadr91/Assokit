<?php
/**
 * mon-asso-recurrences.php
 * --------------------------------------------------------------
 * Liste des factures récurrentes — Pack PHASE 3
 * v2 — Pattern render_head/render_sidebar/render_foot (cohérent dashboard)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-recurrence-helpers.php';

require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// ============================================================
// [PACK 6.5 - SECURITY STRICT] Contrôle accès finances obligatoire
// ============================================================
// ⚠️ POLITIQUE STRICTE : seuls Admin / Fondateur / Super Admin accèdent.
$can_view_finances = (
    in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

if (!$can_view_finances) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('recurrences');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="font-size:54px;margin-bottom:14px;">🔒</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">Les factures récurrentes sont strictement réservées aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}
// ============================================================


// Filtres
$f_status = $_GET['status']    ?? '';
$f_freq   = $_GET['frequency'] ?? '';
$f_q      = trim($_GET['q']    ?? '');

$where = ['r.org_id = :org_id'];
$params = [':org_id' => $org_id];

if (in_array($f_status, ['active','paused','ended','cancelled'], true)) {
    $where[] = 'r.status = :s';
    $params[':s'] = $f_status;
}
if (in_array($f_freq, ['daily','weekly','monthly','quarterly','yearly'], true)) {
    $where[] = 'r.frequency = :f';
    $params[':f'] = $f_freq;
}
if ($f_q !== '') {
    $where[] = '(r.title LIKE :q OR c.display_name LIKE :q)';
    $params[':q'] = '%' . $f_q . '%';
}

$recurrences = [];
$counts = ['active' => 0, 'paused' => 0, 'ended' => 0, 'cancelled' => 0, 'total' => 0];
$generated_this_month = 0;
$page_error = null;

try {
    $sql = "
        SELECT r.*, c.display_name AS client_name
        FROM asso_invoice_recurrences r
        LEFT JOIN asso_clients c ON c.id = r.client_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
          CASE r.status WHEN 'active' THEN 1 WHEN 'paused' THEN 2 WHEN 'ended' THEN 3 ELSE 4 END,
          r.next_run_date ASC,
          r.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $recurrences = $st->fetchAll(PDO::FETCH_ASSOC);

    $counts = ak_recurrence_count_by_status($pdo, $org_id);
    $generated_this_month = ak_recurrence_count_invoices_this_month($pdo, $org_id);
} catch (Throwable $e) {
    $page_error = $e->getMessage();
    error_log('[mon-asso-recurrences] ' . $page_error);
}

render_head('Factures récurrentes');
render_sidebar('recurrences');
?>

<main class="main">

  <style>
    .rec-page-inner { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }
    .rec-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 22px; }
    .rec-title { display: flex; align-items: center; gap: 12px; }
    .rec-title h1 { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -0.02em; }
    .rec-title .icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 22px; }
    .rec-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .rec-btn { padding: 10px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; transition: all 0.15s; }
    .rec-btn-primary { background: #059669; color: white; }
    .rec-btn-primary:hover { background: #047857; }
    .rec-btn-ghost { background: white; color: #475569; border: 1px solid #E2E8F0; }
    .rec-btn-ghost:hover { background: #F8FAFC; }
    .rec-btn-sm { padding: 6px 10px; font-size: 12px; }
    .rec-btn-danger { background: #FEE2E2; color: #991B1B; }
    .rec-btn-warn   { background: #FEF3C7; color: #92400E; }
    .rec-btn-success{ background: #D1FAE5; color: #065F46; }
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .kpi { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 16px 18px; }
    .kpi .lbl { font-size: 12px; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
    .kpi .val { font-size: 28px; font-weight: 700; color: #0F172A; margin-top: 4px; line-height: 1.1; }
    .kpi.ok .val   { color: #059669; }
    .kpi.warn .val { color: #92400E; }
    .kpi.muted .val { color: #475569; }
    .filters { background: white; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filters input[type=text], .filters select { padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .filters input[type=text] { flex: 1; min-width: 200px; }
    .table-wrap { background: white; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; }
    table.rec { width: 100%; border-collapse: collapse; }
    table.rec th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748B; font-weight: 600; padding: 12px 14px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0; }
    table.rec td { padding: 14px; border-bottom: 1px solid #F1F5F9; font-size: 14px; vertical-align: middle; }
    table.rec tr:last-child td { border-bottom: none; }
    table.rec tr:hover { background: #FAFBFC; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .empty { padding: 60px 20px; text-align: center; color: #64748B; }
    .empty .ico { font-size: 48px; margin-bottom: 12px; }
    .next-date { font-weight: 600; color: #059669; }
    .next-date.late { color: #B91C1C; }
    @media (max-width: 720px) {
      table.rec thead { display: none; }
      table.rec tr { display: block; padding: 12px; border-bottom: 1px solid #F1F5F9; }
      table.rec td { display: block; padding: 4px 0; border: none; }
      table.rec td::before { content: attr(data-lbl); font-size: 11px; text-transform: uppercase; color: #64748B; font-weight: 600; display: block; }
    }
  </style>

  <div class="rec-page-inner">

    <div class="rec-header">
      <div class="rec-title">
        <div class="icon">🔄</div>
        <div>
          <h1>Factures récurrentes</h1>
          <div style="color:#64748B;font-size:13px;margin-top:2px;">Automatisez vos factures régulières (cotisations, abonnements, loyers)</div>
        </div>
      </div>
      <div class="rec-actions">
        <a class="rec-btn rec-btn-ghost" href="/mon-asso-factures">← Factures</a>
        <a class="rec-btn rec-btn-primary" href="/mon-asso-recurrence-new">+ Nouvelle récurrence</a>
      </div>
    </div>

    <?php if ($page_error): ?>
      <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px;margin-bottom:18px;color:#991B1B;font-size:14px;">
        ⚠️ Erreur de chargement : <?= h($page_error) ?>
      </div>
    <?php endif; ?>

    <div class="kpi-grid">
      <div class="kpi ok"><div class="lbl">Actives</div><div class="val"><?= (int)$counts['active'] ?></div></div>
      <div class="kpi warn"><div class="lbl">En pause</div><div class="val"><?= (int)$counts['paused'] ?></div></div>
      <div class="kpi muted"><div class="lbl">Terminées</div><div class="val"><?= (int)$counts['ended'] ?></div></div>
      <div class="kpi"><div class="lbl">Générées ce mois</div><div class="val"><?= (int)$generated_this_month ?></div></div>
    </div>

    <form class="filters" method="get" action="/mon-asso-recurrences">
      <input type="text" name="q" placeholder="🔍 Rechercher par titre ou client…" value="<?= h($f_q) ?>">
      <select name="status">
        <option value="">Tous les statuts</option>
        <option value="active"    <?= $f_status==='active'?'selected':'' ?>>Actives</option>
        <option value="paused"    <?= $f_status==='paused'?'selected':'' ?>>En pause</option>
        <option value="ended"     <?= $f_status==='ended'?'selected':'' ?>>Terminées</option>
        <option value="cancelled" <?= $f_status==='cancelled'?'selected':'' ?>>Annulées</option>
      </select>
      <select name="frequency">
        <option value="">Toutes fréquences</option>
        <option value="daily"     <?= $f_freq==='daily'?'selected':'' ?>>Quotidien</option>
        <option value="weekly"    <?= $f_freq==='weekly'?'selected':'' ?>>Hebdomadaire</option>
        <option value="monthly"   <?= $f_freq==='monthly'?'selected':'' ?>>Mensuel</option>
        <option value="quarterly" <?= $f_freq==='quarterly'?'selected':'' ?>>Trimestriel</option>
        <option value="yearly"    <?= $f_freq==='yearly'?'selected':'' ?>>Annuel</option>
      </select>
      <button class="rec-btn rec-btn-ghost" type="submit">Filtrer</button>
      <?php if ($f_q || $f_status || $f_freq): ?>
        <a class="rec-btn rec-btn-ghost" href="/mon-asso-recurrences">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap">
      <?php if (empty($recurrences) && !$page_error): ?>
        <div class="empty">
          <div class="ico">🔄</div>
          <div style="font-weight:600;font-size:16px;color:#0F172A;margin-bottom:6px;">Aucune récurrence pour le moment</div>
          <div style="margin-bottom:16px;">Créez votre première facture récurrente pour automatiser vos cotisations ou abonnements.</div>
          <a class="rec-btn rec-btn-primary" href="/mon-asso-recurrence-new">+ Créer ma première récurrence</a>
        </div>
      <?php elseif (!empty($recurrences)): ?>
        <table class="rec">
          <thead>
            <tr>
              <th>Titre</th><th>Client</th><th>Fréquence</th><th>Prochaine</th>
              <th>Avancement</th><th>Statut</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recurrences as $r):
              $stat = ak_recurrence_status_label($r['status']);
              $is_late = $r['status'] === 'active' && $r['next_run_date'] < date('Y-m-d');
              $progress = !empty($r['max_occurrences'])
                ? (int)$r['occurrences_count'] . ' / ' . (int)$r['max_occurrences']
                : (int)$r['occurrences_count'] . ' générées';
            ?>
            <tr>
              <td data-lbl="Titre"><strong><?= h($r['title']) ?></strong></td>
              <td data-lbl="Client"><?= h($r['client_name'] ?? '—') ?></td>
              <td data-lbl="Fréquence"><?= h(ak_recurrence_frequency_label($r['frequency'], (int)$r['interval_count'])) ?></td>
              <td data-lbl="Prochaine">
                <?php if ($r['status'] === 'active'): ?>
                  <span class="next-date <?= $is_late ? 'late':'' ?>">
                    <?= h(date('d/m/Y', strtotime($r['next_run_date']))) ?>
                    <?php if ($is_late): ?> ⚠<?php endif; ?>
                  </span>
                <?php else: ?>
                  <span style="color:#94A3B8;">—</span>
                <?php endif; ?>
              </td>
              <td data-lbl="Avancement"><?= h($progress) ?></td>
              <td data-lbl="Statut">
                <span class="pill" style="background:<?= h($stat['bg']) ?>;color:<?= h($stat['color']) ?>;"><?= h($stat['label']) ?></span>
                <?php if ((int)$r['auto_send'] === 1): ?>
                  <span class="pill" style="background:#DBEAFE;color:#1E40AF;margin-left:4px;">Auto-envoi</span>
                <?php endif; ?>
              </td>
              <td data-lbl="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a class="rec-btn rec-btn-sm rec-btn-ghost" href="/mon-asso-recurrence-edit?id=<?= (int)$r['id'] ?>">Modifier</a>
                  <?php if ($r['status'] === 'active'): ?>
                    <form method="post" action="/mon-asso-recurrence-toggle" style="display:inline;">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="pause">
                      <button class="rec-btn rec-btn-sm rec-btn-warn" type="submit">⏸ Pause</button>
                    </form>
                    <form method="post" action="/mon-asso-recurrence-run-now" style="display:inline;" onsubmit="return confirm('Générer une facture maintenant à partir de cette récurrence ?');">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="rec-btn rec-btn-sm rec-btn-success" type="submit">▶ Générer</button>
                    </form>
                  <?php elseif ($r['status'] === 'paused'): ?>
                    <form method="post" action="/mon-asso-recurrence-toggle" style="display:inline;">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="resume">
                      <button class="rec-btn rec-btn-sm rec-btn-success" type="submit">▶ Reprendre</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="/mon-asso-recurrence-delete" style="display:inline;" onsubmit="return confirm('Supprimer définitivement cette récurrence ? Les factures déjà générées sont conservées.');">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="rec-btn rec-btn-sm rec-btn-danger" type="submit">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>

</main>

<?php render_foot(); ?>
