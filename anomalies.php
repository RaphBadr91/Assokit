<?php
/**
 * anomalies.php — DÉTECTION D'ANOMALIES (audit à la demande)
 * ------------------------------------------------------------------
 * Passe au crible factures et cotisations pour repérer les incohérences
 * (doublons, TVA, numérotation, dates, statuts, double encaissement…).
 * Chaque anomalie peut être ignorée (faux positif) durablement.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/anomalies-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = "L'audit des anomalies est réservé aux rôles de gestion financière.";
    header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');
$show_ignored = isset($_GET['ignored']);

$ready = true; $findings = []; $ignored_count = 0;
try {
    $findings = ak_anom_scan($pdo, $org_id, $show_ignored);
    // compter les ignorées séparément
    $ic = $pdo->prepare("SELECT COUNT(*) FROM anomaly_dismissed WHERE org_id=?"); $ic->execute([$org_id]); $ignored_count = (int)$ic->fetchColumn();
} catch (Throwable $e) { $ready = false; error_log('[anomalies-page] '.$e->getMessage()); }

$c_high = count(array_filter($findings, fn($f)=>$f['severity']==='high' && empty($f['dismissed'])));
$c_med  = count(array_filter($findings, fn($f)=>$f['severity']==='medium' && empty($f['dismissed'])));
$c_low  = count(array_filter($findings, fn($f)=>$f['severity']==='low' && empty($f['dismissed'])));

render_head('Détection d\'anomalies');
echo render_sidebar('anomalies');
?>
<main class="main">
  <div class="an-page">
    <div class="an-head">
      <div>
        <h1 class="an-title">🔍 Détection d'anomalies</h1>
        <p class="an-sub">Un audit automatique de vos factures et cotisations : doublons, TVA incohérente, trous de numérotation, statuts et dates suspects, double encaissement.</p>
      </div>
      <div class="an-head-actions">
        <button type="button" class="an-btn ghost" onclick="location.reload()">↻ Relancer l'audit</button>
      </div>
    </div>

<?php if (!$ready): ?>
    <div class="an-empty"><div class="an-empty-emoji">🛠️</div><h2>Initialisation requise</h2>
      <p>Exécutez la migration <code>2026-08-19-anomalies.sql</code> pour activer la fonction « ignorer ».</p></div>
<?php else: ?>

    <div class="an-kpis">
      <div class="an-kpi"><div class="an-kpi-lbl">🔴 Critiques</div><div class="an-kpi-val <?= $c_high>0?'an-red':'an-green' ?>"><?= $c_high ?></div></div>
      <div class="an-kpi"><div class="an-kpi-lbl">🟠 À vérifier</div><div class="an-kpi-val <?= $c_med>0?'an-amber':'' ?>"><?= $c_med ?></div></div>
      <div class="an-kpi"><div class="an-kpi-lbl">🔵 Infos</div><div class="an-kpi-val"><?= $c_low ?></div></div>
      <div class="an-kpi"><div class="an-kpi-lbl">Ignorées</div><div class="an-kpi-val an-muted"><?= $ignored_count ?></div></div>
    </div>

    <div class="an-toolbar">
      <?php if ($show_ignored): ?>
        <a href="/anomalies" class="an-chip">← Masquer les anomalies ignorées</a>
      <?php elseif ($ignored_count > 0): ?>
        <a href="/anomalies?ignored=1" class="an-chip">Voir les <?= $ignored_count ?> anomalie(s) ignorée(s)</a>
      <?php endif; ?>
    </div>

    <?php
      $visible = array_filter($findings, fn($f)=> $show_ignored ? true : empty($f['dismissed']));
      if (empty($visible)):
    ?>
      <div class="an-empty">
        <div class="an-empty-emoji">✅</div>
        <h2>Aucune anomalie détectée</h2>
        <p>Vos factures et cotisations sont cohérentes. Relancez l'audit après vos prochaines saisies.</p>
      </div>
    <?php else: ?>
      <div class="an-list">
        <?php foreach ($visible as $f): $sm = ak_anom_sev_meta($f['severity']); ?>
        <div class="an-card <?= !empty($f['dismissed'])?'is-ignored':'' ?>" data-hash="<?= h($f['hash']) ?>" data-cat="<?= h($f['category']) ?>">
          <div class="an-card-left">
            <span class="an-sev" style="background:<?= $sm[2] ?>;color:<?= $sm[1] ?>;"><?= $sm[3] ?> <?= h($sm[0]) ?></span>
          </div>
          <div class="an-card-body">
            <div class="an-card-title"><?= h($f['title']) ?></div>
            <div class="an-card-detail"><?= h($f['detail']) ?></div>
          </div>
          <div class="an-card-actions">
            <?php if (!empty($f['route'])): ?><a href="<?= h($f['route']) ?>" class="an-btn ghost sm">Ouvrir</a><?php endif; ?>
            <?php if (!empty($f['dismissed'])): ?>
              <button type="button" class="an-btn ghost sm js-restore">Rétablir</button>
            <?php else: ?>
              <button type="button" class="an-btn ghost sm js-dismiss" title="Faux positif — ignorer">Ignorer</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="an-disclaimer">ℹ️ Cet audit signale des <em>incohérences probables</em> à partir de règles comptables : il ne remplace pas votre expert-comptable. « Ignorer » masque durablement un faux positif (il réapparaîtra si un cas identique se reproduit).</p>

<?php endif; ?>
  </div>
</main>

<style>
.an-page { max-width:960px; margin:0 auto; padding:24px 22px; }
.an-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
.an-title { font-size:24px; margin:0 0 4px; color:#0F172A; }
.an-sub { color:#64748B; margin:0; font-size:14px; max-width:640px; }
.an-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:9px; font-size:13px; font-weight:650; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; }
.an-btn.sm { padding:6px 11px; font-size:12.5px; }
.an-btn.ghost { background:#fff; border-color:#E2E8F0; color:#334155; }
.an-btn.ghost:hover { background:#F8FAFC; }
.an-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:14px; }
.an-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.an-kpi-lbl { font-size:11px; color:#64748B; font-weight:700; margin-bottom:4px; }
.an-kpi-val { font-size:22px; font-weight:750; color:#0F172A; }
.an-red { color:#EF4444 !important; } .an-amber { color:#F59E0B !important; } .an-green { color:#10B981 !important; } .an-muted { color:#94A3B8 !important; }
.an-toolbar { margin-bottom:12px; }
.an-chip { font-size:12.5px; color:#475569; text-decoration:none; background:#fff; border:1px solid #E5E7EB; padding:6px 12px; border-radius:999px; }
.an-chip:hover { background:#F8FAFC; }
.an-list { display:flex; flex-direction:column; gap:8px; }
.an-card { display:flex; gap:14px; background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:14px 16px; align-items:flex-start; }
.an-card.is-ignored { opacity:.6; }
.an-card-left { flex:none; }
.an-sev { font-size:11px; font-weight:700; padding:4px 9px; border-radius:999px; white-space:nowrap; }
.an-card-body { flex:1; min-width:0; }
.an-card-title { font-size:14.5px; font-weight:700; color:#0F172A; margin-bottom:3px; }
.an-card-detail { font-size:13px; color:#475569; line-height:1.5; }
.an-card-actions { display:flex; flex-direction:column; gap:6px; flex:none; align-items:flex-end; }
.an-empty { text-align:center; padding:46px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; }
.an-empty-emoji { font-size:46px; margin-bottom:10px; }
.an-empty h2 { font-size:18px; margin:0 0 8px; color:#0F172A; }
.an-empty p { color:#64748B; max-width:460px; margin:0 auto; font-size:13.5px; }
.an-empty code { background:#F1F5F9; padding:1px 6px; border-radius:5px; }
.an-disclaimer { font-size:12px; color:#94A3B8; margin-top:18px; line-height:1.5; }
@media (max-width:560px){ .an-card{ flex-wrap:wrap; } .an-card-actions{ flex-direction:row; width:100%; } }
</style>

<script>
(function(){
  var CSRF = "<?= $csrf ?>";
  function post(p){ return fetch('/api/anomalie-action.php',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body:JSON.stringify(Object.assign({csrf_token:CSRF},p))}).then(function(r){return r.json();}); }

  document.querySelectorAll('.an-card').forEach(function(card){
    var hash=card.getAttribute('data-hash'), cat=card.getAttribute('data-cat');
    var dis=card.querySelector('.js-dismiss'), res=card.querySelector('.js-restore');
    if(dis) dis.addEventListener('click', function(){
      dis.disabled=true;
      post({action:'dismiss', hash:hash, category:cat}).then(function(d){
        if(d&&d.ok){ card.style.transition='opacity .2s, transform .2s'; card.style.opacity='0'; card.style.transform='scale(.97)'; setTimeout(function(){ card.remove(); }, 200); }
        else dis.disabled=false;
      }).catch(function(){ dis.disabled=false; });
    });
    if(res) res.addEventListener('click', function(){
      res.disabled=true;
      post({action:'restore', hash:hash}).then(function(d){ if(d&&d.ok) location.reload(); else res.disabled=false; }).catch(function(){ res.disabled=false; });
    });
  });
})();
</script>
<?php render_foot(); ?>
