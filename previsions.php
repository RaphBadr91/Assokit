<?php
/**
 * previsions.php — DASHBOARD PRÉDICTIF
 * ------------------------------------------------------------------
 * Trésorerie projetée à 6 mois, saisonnalité recettes/adhésions,
 * alertes de tendance. Projection déterministe, indicative.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/previsions-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = "Le tableau de bord prévisionnel est réservé aux rôles de gestion financière.";
    header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');

// Enregistrement du solde de trésorerie de départ (POST, CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set-balance') {
    if (check_csrf($_POST['csrf_token'] ?? '')) {
        $bal = (float)str_replace([',',' '], ['.',''], (string)($_POST['balance'] ?? '0'));
        $cents = (int)round($bal * 100);
        try {
            $pdo->prepare("INSERT INTO org_forecast_prefs (org_id, start_balance_cents, balance_set_at, updated_at)
                           VALUES (:o,:b,NOW(),NOW())
                           ON DUPLICATE KEY UPDATE start_balance_cents=VALUES(start_balance_cents), balance_set_at=NOW(), updated_at=NOW()")
                ->execute([':o'=>$org_id, ':b'=>$cents]);
            $_SESSION['flash_success'] = 'Solde de trésorerie enregistré.';
        } catch (Throwable $e) { $_SESSION['flash_error'] = "Base non initialisée : exécutez la migration."; }
    }
    header('Location: /previsions'); exit;
}

$ready = true; $start_balance = 0.0; $balance_set = false;
$history = []; $forecast = ['rows'=>[], 'avg_rev'=>0, 'avg_exp'=>0, 'method'=>''];
$season_rev = []; $season_adh = []; $alerts = []; $outstanding = 0.0;

try {
    try {
        $bp = $pdo->prepare("SELECT start_balance_cents, balance_set_at FROM org_forecast_prefs WHERE org_id=?");
        $bp->execute([$org_id]);
        if ($b = $bp->fetch()) { $start_balance = ((int)$b['start_balance_cents'])/100; $balance_set = !empty($b['balance_set_at']); }
    } catch (Throwable $e) {}

    $history  = ak_prev_history($pdo, $org_id, 18);
    $forecast = ak_prev_forecast($pdo, $org_id, 6, $balance_set ? $start_balance : 0.0);
    $season_rev = ak_prev_seasonal_index($history);
    $season_adh = ak_prev_membership_seasonality($pdo, $org_id);
    $alerts = ak_prev_trend_alerts($history, $forecast, $balance_set ? $start_balance : 0.0);

    $os = $pdo->prepare("SELECT COALESCE(SUM(amount_ttc_cents),0)/100 v FROM asso_invoices WHERE org_id=? AND status IN ('pending','overdue')");
    $os->execute([$org_id]); $outstanding = (float)$os->fetchColumn();
} catch (Throwable $e) { $ready = false; error_log('[previsions] '.$e->getMessage()); }

// Série combinée pour le graphe (12 derniers mois + 6 projetés).
$hist12 = array_slice($history, -12, 12, true);
$bars = [];
foreach ($hist12 as $ym=>$v) $bars[] = ['label'=>ak_prev_month_label($ym), 'net'=>$v['net'], 'rev'=>$v['rev'], 'exp'=>$v['exp'], 'proj'=>false];
foreach ($forecast['rows'] as $r) $bars[] = ['label'=>$r['label'], 'net'=>$r['net'], 'rev'=>$r['rev'], 'exp'=>$r['exp'], 'proj'=>true, 'cum'=>$r['cum']];

$proj_rev_total = array_sum(array_map(fn($r)=>$r['rev'], $forecast['rows']));
$avg_net = $forecast['avg_rev'] - $forecast['avg_exp'];
$best_mo = null; $worst_mo = null;
if ($season_rev) { arsort($season_rev); $best_mo = array_key_first($season_rev); $worst_mo = array_key_last($season_rev); }
$mo_names = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];

render_head('Prévisions');
echo render_sidebar('previsions');
?>
<main class="main">
  <div class="pv-page">
    <div class="pv-head">
      <div>
        <h1 class="pv-title">📈 Tableau de bord prévisionnel</h1>
        <p class="pv-sub">Trésorerie projetée à 6 mois, saisonnalité et tendances — à partir de vos recettes, dépenses, créances et récurrences.</p>
      </div>
    </div>

<?php if (!$ready): ?>
    <div class="pv-empty"><div class="pv-empty-emoji">🛠️</div><h2>Initialisation requise</h2>
      <p>Exécutez la migration <code>2026-08-19-forecast.sql</code> pour activer l'ancrage de trésorerie.</p></div>
<?php else: ?>

    <?php if (!empty($alerts)): ?>
    <div class="pv-alerts">
      <?php foreach ($alerts as $a):
        $ac = ['danger'=>['#991B1B','#FEE2E2','🔴'],'warn'=>['#92400E','#FEF3C7','🟠'],'good'=>['#065F46','#D1FAE5','🟢']][$a['level']] ?? ['#374151','#F3F4F6','•']; ?>
        <div class="pv-alert" style="background:<?= $ac[1] ?>;color:<?= $ac[0] ?>;"><?= $ac[2] ?> <?= h($a['text']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="pv-kpis">
      <div class="pv-kpi"><div class="pv-kpi-lbl">Recettes moy./mois</div><div class="pv-kpi-val pv-green"><?= ak_prev_eur($forecast['avg_rev']) ?></div></div>
      <div class="pv-kpi"><div class="pv-kpi-lbl">Dépenses moy./mois</div><div class="pv-kpi-val pv-red"><?= ak_prev_eur($forecast['avg_exp']) ?></div></div>
      <div class="pv-kpi"><div class="pv-kpi-lbl">Solde de flux moy.</div><div class="pv-kpi-val <?= $avg_net<0?'pv-red':'pv-green' ?>"><?= ak_prev_eur($avg_net) ?></div></div>
      <div class="pv-kpi"><div class="pv-kpi-lbl">Recettes projetées 6 mois</div><div class="pv-kpi-val"><?= ak_prev_eur($proj_rev_total) ?></div></div>
      <div class="pv-kpi"><div class="pv-kpi-lbl">À encaisser (créances)</div><div class="pv-kpi-val pv-amber"><?= ak_prev_eur($outstanding) ?></div></div>
    </div>

    <!-- GRAPHE -->
    <div class="pv-card">
      <div class="pv-card-h">Recettes − dépenses par mois <span class="pv-legend"><span class="pv-lg pv-lg-real"></span>réalisé <span class="pv-lg pv-lg-proj"></span>projeté<?php if ($balance_set): ?> <span class="pv-lg pv-lg-cum"></span>trésorerie cumulée<?php endif; ?></span></div>
      <div class="pv-chart-scroll">
        <?php
          $H = 180; $W = max(640, count($bars) * 46); $pad = 24; $zero = $H/2;
          $maxAbs = 1;
          foreach ($bars as $b) $maxAbs = max($maxAbs, abs($b['net']));
          $cumMax = 1; $cumMin = 0;
          if ($balance_set) { foreach ($forecast['rows'] as $r) { $cumMax = max($cumMax, $r['cum']); $cumMin = min($cumMin, $r['cum']); } $cumMin = min($cumMin, $start_balance); $cumMax = max($cumMax, $start_balance); }
          $bw = 26; $gap = ($W - $pad*2 - $bw*count($bars)) / max(1,count($bars)-1);
          echo '<svg width="'.$W.'" height="'.($H+34).'" viewBox="0 0 '.$W.' '.($H+34).'" role="img">';
          echo '<line x1="'.$pad.'" y1="'.$zero.'" x2="'.($W-$pad).'" y2="'.$zero.'" stroke="#E2E8F0" stroke-width="1"/>';
          $cumPts = [];
          foreach ($bars as $i=>$b) {
            $x = $pad + $i*($bw+$gap);
            $h = (int)round(abs($b['net'])/$maxAbs * ($H/2 - 8));
            $y = $b['net'] >= 0 ? $zero - $h : $zero;
            $col = $b['net'] >= 0 ? ($b['proj'] ? '#6EE7B7' : '#10B981') : ($b['proj'] ? '#FCA5A5' : '#EF4444');
            $op = $b['proj'] ? '0.85' : '1';
            echo '<rect x="'.$x.'" y="'.$y.'" width="'.$bw.'" height="'.max(1,$h).'" rx="3" fill="'.$col.'" opacity="'.$op.'"'.($b['proj']?' stroke="#059669" stroke-dasharray="2 2" stroke-width="0.6"':'').'/>';
            echo '<text x="'.($x+$bw/2).'" y="'.($H+14).'" font-size="9" fill="#94A3B8" text-anchor="middle">'.htmlspecialchars($b['label']).'</text>';
            if ($balance_set && isset($b['cum'])) {
              $range = max(1, $cumMax - $cumMin);
              $cy = ($H) - (($b['cum'] - $cumMin)/$range * ($H-16)) - 8;
              $cumPts[] = ($x+$bw/2).','.round($cy,1);
            }
          }
          if (count($cumPts) > 1) {
            echo '<polyline points="'.implode(' ', $cumPts).'" fill="none" stroke="#6366F1" stroke-width="2"/>';
            foreach ($cumPts as $p) { [$px,$py]=explode(',',$p); echo '<circle cx="'.$px.'" cy="'.$py.'" r="2.6" fill="#6366F1"/>'; }
          }
          echo '</svg>';
        ?>
      </div>
      <p class="pv-method"><?= h($forecast['method']) ?></p>
    </div>

    <!-- TABLE PRÉVISION -->
    <div class="pv-card">
      <div class="pv-card-h">Projection sur 6 mois</div>
      <div class="pv-table-scroll">
        <table class="pv-table">
          <thead><tr><th>Mois</th><th>Recettes</th><th>Dépenses</th><th>Solde du mois</th><?php if ($balance_set): ?><th>Trésorerie cumulée</th><?php endif; ?><th>dont connu</th></tr></thead>
          <tbody>
          <?php foreach ($forecast['rows'] as $r): ?>
            <tr>
              <td><strong><?= h($r['label']) ?></strong></td>
              <td class="pv-green"><?= ak_prev_eur($r['rev']) ?></td>
              <td class="pv-red"><?= ak_prev_eur($r['exp']) ?></td>
              <td class="<?= $r['net']<0?'pv-red':'pv-green' ?>"><?= ($r['net']>=0?'+':'').ak_prev_eur($r['net']) ?></td>
              <?php if ($balance_set): ?><td class="<?= $r['cum']<0?'pv-red':'' ?>"><strong><?= ak_prev_eur($r['cum']) ?></strong></td><?php endif; ?>
              <td class="pv-muted"><?= $r['known']>0 ? ak_prev_eur($r['known']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="pv-grid2">
      <!-- SAISONNALITÉ -->
      <div class="pv-card">
        <div class="pv-card-h">Saisonnalité des recettes</div>
        <?php if ($best_mo): ?>
          <p class="pv-note">Mois le plus fort : <strong><?= h($mo_names[$best_mo]) ?></strong> · le plus faible : <strong><?= h($mo_names[$worst_mo]) ?></strong>.</p>
        <?php endif; ?>
        <div class="pv-season">
          <?php ksort($season_rev); foreach ($season_rev as $mo=>$idx): $pct = min(100, (int)round($idx*50)); ?>
            <div class="pv-season-col" title="Indice <?= number_format($idx,2,',',' ') ?>">
              <div class="pv-season-bar" style="height:<?= max(4,$pct) ?>px;background:<?= $idx>=1?'#10B981':'#CBD5E1' ?>;"></div>
              <div class="pv-season-lbl"><?= h(substr($mo_names[$mo],0,1)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="pv-method">Indice 1,0 = mois moyen. Basé sur votre historique (jusqu'à 24 mois).</p>
      </div>

      <!-- SOLDE DE DÉPART -->
      <div class="pv-card">
        <div class="pv-card-h">Ancrer la trésorerie</div>
        <p class="pv-note">Saisissez votre solde de trésorerie <strong>actuel</strong> pour projeter la trésorerie cumulée réelle. Sinon, seuls les soldes de flux mensuels sont affichés.</p>
        <form method="POST" action="/previsions" class="pv-bal-form">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="set-balance">
          <input type="text" name="balance" inputmode="decimal" placeholder="ex : 4 500" value="<?= $balance_set ? h(number_format($start_balance,0,',',' ')) : '' ?>" class="pv-bal-input"> €
          <button type="submit" class="pv-btn primary">Enregistrer</button>
        </form>
        <?php if ($balance_set): ?><p class="pv-method">Trésorerie de départ : <?= ak_prev_eur($start_balance) ?>. La projection cumulée en tient compte.</p><?php endif; ?>
      </div>
    </div>

    <p class="pv-disclaimer">ℹ️ Projections <strong>indicatives</strong> calculées sur vos données passées et vos engagements connus. Elles ne constituent ni un conseil comptable ni une garantie. Les recettes réelles dépendront de vos encaissements effectifs.</p>

<?php endif; ?>
  </div>
</main>

<style>
.pv-page { max-width:1040px; margin:0 auto; padding:24px 22px; }
.pv-head { margin-bottom:16px; }
.pv-title { font-size:24px; margin:0 0 4px; color:#0F172A; }
.pv-sub { color:#64748B; margin:0; font-size:14px; max-width:660px; }
.pv-alerts { display:flex; flex-direction:column; gap:8px; margin-bottom:16px; }
.pv-alert { padding:10px 14px; border-radius:10px; font-size:13.5px; font-weight:600; }
.pv-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:16px; }
.pv-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.pv-kpi-lbl { font-size:10.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-bottom:4px; }
.pv-kpi-val { font-size:19px; font-weight:750; color:#0F172A; }
.pv-green { color:#10B981 !important; } .pv-red { color:#EF4444 !important; } .pv-amber { color:#F59E0B !important; } .pv-muted { color:#94A3B8 !important; }
.pv-card { background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:16px 18px; margin-bottom:14px; }
.pv-card-h { font-size:14px; font-weight:750; color:#0F172A; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.pv-legend { font-size:11px; color:#94A3B8; font-weight:500; display:flex; align-items:center; gap:5px; }
.pv-lg { width:11px; height:11px; border-radius:3px; display:inline-block; margin-left:8px; }
.pv-lg-real { background:#10B981; } .pv-lg-proj { background:#6EE7B7; border:1px dashed #059669; } .pv-lg-cum { background:#6366F1; }
.pv-chart-scroll { overflow-x:auto; }
.pv-method { font-size:11.5px; color:#94A3B8; margin:10px 0 0; line-height:1.5; }
.pv-table-scroll { overflow-x:auto; }
.pv-table { width:100%; border-collapse:collapse; font-size:13px; min-width:520px; }
.pv-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #E2E8F0; color:#64748B; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
.pv-table td { padding:9px 10px; border-bottom:1px solid #F1F5F9; }
.pv-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.pv-note { font-size:13px; color:#475569; line-height:1.5; margin:0 0 12px; }
.pv-season { display:flex; align-items:flex-end; gap:6px; height:64px; padding:4px 0; }
.pv-season-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:4px; }
.pv-season-bar { width:100%; max-width:20px; border-radius:4px 4px 0 0; }
.pv-season-lbl { font-size:9.5px; color:#94A3B8; text-transform:uppercase; }
.pv-bal-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.pv-bal-input { width:130px; padding:9px 12px; border:1px solid #E2E8F0; border-radius:9px; font-family:inherit; font-size:14px; }
.pv-btn { padding:9px 15px; border-radius:9px; font-size:13px; font-weight:650; cursor:pointer; border:1px solid transparent; font-family:inherit; }
.pv-btn.primary { background:linear-gradient(135deg,#10B981,#059669); color:#fff; }
.pv-disclaimer { font-size:12px; color:#94A3B8; margin-top:16px; line-height:1.5; }
.pv-empty { text-align:center; padding:46px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; }
.pv-empty-emoji { font-size:46px; margin-bottom:10px; } .pv-empty h2 { font-size:18px; margin:0 0 8px; color:#0F172A; } .pv-empty p { color:#64748B; font-size:13.5px; }
.pv-empty code { background:#F1F5F9; padding:1px 6px; border-radius:5px; }
@media (max-width:640px){ .pv-grid2 { grid-template-columns:1fr; } }
</style>
<?php render_foot(); ?>
