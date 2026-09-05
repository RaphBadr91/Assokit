<?php
/**
 * relances.php — RELANCES INTELLIGENTES (cockpit)
 * ------------------------------------------------------------------
 * Vue unifiée : factures impayées + cotisations expirées, avec le stade
 * de relance recommandé, l'envoi en 1 clic (modèle ou message reformulé
 * par l'IA), et l'automatisation optionnelle via le cron.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/relances-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = 'Les relances sont réservées aux rôles de gestion financière.';
    header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');

$invoices = []; $members = []; $ready = true;
$prefs = ['auto_invoices'=>0, 'auto_memberships'=>0, 'max_stage'=>2];
try {
    $invoices = ak_rel_invoice_targets($pdo, $org_id);
    $members  = ak_rel_membership_targets($pdo, $org_id);
    try {
        $ps = $pdo->prepare("SELECT * FROM org_relance_prefs WHERE org_id=?");
        $ps->execute([$org_id]);
        if ($pr = $ps->fetch()) { foreach ($prefs as $k=>$v) if (isset($pr[$k])) $prefs[$k] = (int)$pr[$k]; }
    } catch (Throwable $e) {}
} catch (Throwable $e) { $ready = false; error_log('[relances] '.$e->getMessage()); }

$inv_due   = array_filter($invoices, fn($t)=>$t['due_now']);
$mem_due   = array_filter($members,  fn($t)=>$t['due_now']);
$inv_total = array_sum(array_map(fn($t)=>$t['amount_cents'], $invoices));
$due_count = count($inv_due) + count($mem_due);

render_head('Relances intelligentes');
echo render_sidebar('relances');
?>
<main class="main">
  <div class="rel-page">
    <div class="rel-head">
      <div>
        <h1 class="rel-title"><?= ak_icon_badge('megaphone', '#059669', 36) ?><span>Relances intelligentes</span></h1>
        <p class="rel-sub">On repère qui relancer et à quel stade — factures impayées et cotisations expirées — et vous envoyez au bon moment, avec le bon ton.</p>
      </div>
      <?php if ($due_count > 0): ?>
      <button type="button" class="rel-btn primary" id="relBatch"><?= ak_icon('bolt',15) ?> Tout relancer (<?= $due_count ?> recommandé<?= $due_count>1?'s':'' ?>)</button>
      <?php endif; ?>
    </div>

<?php if (!$ready): ?>
    <div class="rel-empty"><div class="rel-empty-emoji"><?= ak_icon('wrench',44,'1.5') ?></div><h2>Initialisation requise</h2>
      <p>Exécutez la migration <code>2026-08-19-relances.sql</code> pour activer les relances.</p></div>
<?php else: ?>

    <div class="rel-kpis">
      <div class="rel-kpi"><div class="rel-kpi-lbl">À relancer maintenant</div><div class="rel-kpi-val <?= $due_count>0?'rel-amber':'' ?>"><?= $due_count ?></div></div>
      <div class="rel-kpi"><div class="rel-kpi-lbl">Factures impayées</div><div class="rel-kpi-val"><?= count($invoices) ?></div></div>
      <div class="rel-kpi"><div class="rel-kpi-lbl">Montant impayé</div><div class="rel-kpi-val rel-red"><?= ak_rel_eur($inv_total) ?></div></div>
      <div class="rel-kpi"><div class="rel-kpi-lbl">Cotisations à renouveler</div><div class="rel-kpi-val"><?= count($members) ?></div></div>
    </div>

    <!-- Automatisation -->
    <details class="rel-auto">
      <summary><span class="rel-sum-lbl"><?= ak_icon('robot',16) ?> Automatiser les relances</span><span class="rel-auto-hint"><?= ($prefs['auto_invoices']||$prefs['auto_memberships']) ? 'Auto activé' : 'Manuel (recommandé au démarrage)' ?></span></summary>
      <div class="rel-auto-body">
        <p class="rel-note">Quand c'est activé, le cron quotidien envoie automatiquement les relances dues, dans la limite du stade choisi. Vous gardez la main : désactivez à tout moment.</p>
        <label class="rel-check"><input type="checkbox" id="autoInv" <?= $prefs['auto_invoices']?'checked':'' ?>> Factures : relances automatiques</label>
        <label class="rel-check"><input type="checkbox" id="autoMem" <?= $prefs['auto_memberships']?'checked':'' ?>> Cotisations : relances automatiques</label>
        <label class="rel-check">Stade maximal en automatique :
          <select id="maxStage">
            <option value="1" <?= $prefs['max_stage']==1?'selected':'' ?>>1 — Rappel courtois</option>
            <option value="2" <?= $prefs['max_stage']==2?'selected':'' ?>>2 — Relance ferme</option>
            <option value="3" <?= $prefs['max_stage']==3?'selected':'' ?>>3 — Mise en demeure</option>
          </select>
        </label>
        <div><button type="button" class="rel-btn ghost sm" id="relSavePrefs">Enregistrer</button> <span id="relPrefsOk" style="display:none;color:#059669;font-size:12.5px;">✓ Enregistré</span></div>
      </div>
    </details>

    <!-- FACTURES -->
    <h2 class="rel-section"><?= ak_icon('receipt',18) ?> Factures impayées <span class="rel-count"><?= count($invoices) ?></span></h2>
    <?php if (!$invoices): ?>
      <div class="rel-empty small"><p>Aucune facture impayée échue.</p></div>
    <?php else: ?>
      <div class="rel-list">
        <?php foreach ($invoices as $t): $sm = ak_rel_stage_meta($t['stage']); ?>
        <div class="rel-row" data-type="invoice" data-id="<?= (int)$t['id'] ?>" data-stage="<?= (int)$t['stage'] ?>" data-name="<?= h($t['client_name']) ?>">
          <div class="rel-row-main">
            <div class="rel-row-name"><?= h($t['number']) ?> · <?= h($t['client_name']) ?></div>
            <div class="rel-row-meta">
              <?= ak_rel_eur($t['amount_cents']) ?> · échéance <?= h(date('d/m/Y', strtotime($t['due_date']))) ?>
              · <strong class="rel-late">retard <?= (int)$t['days_overdue'] ?> j</strong>
              <?php if ($t['relances_sent']>0): ?> · <?= (int)$t['relances_sent'] ?> relance<?= $t['relances_sent']>1?'s':'' ?> envoyée<?= $t['relances_sent']>1?'s':'' ?><?php endif; ?>
            </div>
          </div>
          <span class="rel-stage" style="background:<?= $sm[3] ?>;color:<?= $sm[2] ?>;"><?= h($sm[0]) ?></span>
          <?php if (!$t['has_email']): ?>
            <span class="rel-noemail" title="Pas d'email client"><?= ak_icon('mail',12) ?> absent</span>
          <?php else: ?>
            <div class="rel-row-actions">
              <button type="button" class="rel-btn ghost sm js-ai" title="Rédiger avec l'IA"><?= ak_icon('sparkle',13) ?> IA</button>
              <button type="button" class="rel-btn primary sm js-send"><?= $t['due_now'] ? 'Relancer' : 'Relancer quand même' ?></button>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- COTISATIONS -->
    <h2 class="rel-section"><?= ak_icon('idcard',18) ?> Cotisations à renouveler <span class="rel-count"><?= count($members) ?></span></h2>
    <?php if (!$members): ?>
      <div class="rel-empty small"><p>Aucune cotisation à relancer.</p></div>
    <?php else: ?>
      <div class="rel-list">
        <?php foreach ($members as $t): $sm = ak_rel_stage_meta($t['stage']); ?>
        <div class="rel-row" data-type="membership" data-id="<?= (int)$t['id'] ?>" data-stage="<?= (int)$t['stage'] ?>" data-name="<?= h($t['name']) ?>">
          <div class="rel-row-main">
            <div class="rel-row-name"><?= h($t['name'] ?: '—') ?></div>
            <div class="rel-row-meta">
              <?= $t['expired'] ? 'expirée depuis '.(int)$t['days_overdue'].' j' : 'expire bientôt' ?>
              <?php if ($t['fin']): ?> · échéance <?= h(date('d/m/Y', strtotime($t['fin']))) ?><?php endif; ?>
              <?php if ($t['relances_sent']>0): ?> · <?= (int)$t['relances_sent'] ?> rappel<?= $t['relances_sent']>1?'s':'' ?><?php endif; ?>
            </div>
          </div>
          <span class="rel-stage" style="background:<?= $sm[3] ?>;color:<?= $sm[2] ?>;"><?= h($sm[0]) ?></span>
          <?php if (!$t['has_email']): ?>
            <span class="rel-noemail" title="Pas d'email"><?= ak_icon('mail',12) ?> absent</span>
          <?php else: ?>
            <div class="rel-row-actions">
              <button type="button" class="rel-btn ghost sm js-ai" title="Rédiger avec l'IA"><?= ak_icon('sparkle',13) ?> IA</button>
              <button type="button" class="rel-btn primary sm js-send"><?= $t['due_now'] ? 'Relancer' : 'Relancer quand même' ?></button>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="rel-disclaimer"><?= ak_icon('info',13) ?> Cadence recommandée : rappel courtois dès l'échéance, relance ferme à J+15, mise en demeure à J+45 — avec un délai minimal entre deux envois. Vous validez chaque relance (ou l'automatisez).</p>

<?php endif; ?>
  </div>

  <!-- Modal IA / édition -->
  <div id="relModal" class="rel-modal" style="display:none;">
    <div class="rel-modal-card">
      <div class="rel-modal-head"><span id="relModalTitle">Rédiger la relance</span><button type="button" class="rel-modal-x" id="relModalClose">✕</button></div>
      <div class="rel-modal-body">
        <div class="rel-tones">
          Ton : <button type="button" class="rel-tone" data-tone="chaleureux">Chaleureux</button>
          <button type="button" class="rel-tone on" data-tone="neutre">Neutre</button>
          <button type="button" class="rel-tone" data-tone="ferme">Ferme</button>
          <button type="button" class="rel-btn ghost sm" id="relRegen" style="margin-left:auto;"><?= ak_icon('sparkle',13) ?> Générer avec l'IA</button>
        </div>
        <input type="text" id="relSubject" class="rel-input" placeholder="Objet de l'email">
        <textarea id="relBody" class="rel-textarea" rows="10" placeholder="Le message… (rédigez librement ou laissez l'IA proposer)"></textarea>
        <p class="rel-note" id="relAiState"></p>
      </div>
      <div class="rel-modal-foot">
        <button type="button" class="rel-btn ghost" id="relModalCancel">Annuler</button>
        <button type="button" class="rel-btn primary" id="relModalSend">Envoyer la relance</button>
      </div>
    </div>
  </div>
</main>

<style>
.rel-page { max-width:1000px; margin:0 auto; padding:24px 22px; }
.rel-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
.rel-title { font-size:24px; margin:0 0 4px; color:#0F172A; display:flex; align-items:center; gap:11px; }
.rel-sub { color:#64748B; margin:0; font-size:14px; max-width:640px; }
.rel-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:9px; font-size:13px; font-weight:650; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; }
.rel-btn.sm { padding:6px 11px; font-size:12.5px; }
.rel-btn.primary { background:linear-gradient(135deg,#10B981,#059669); color:#fff; }
.rel-btn.primary:hover { filter:brightness(1.05); }
.rel-btn.ghost { background:#fff; border-color:#E2E8F0; color:#334155; }
.rel-btn.ghost:hover { background:#F8FAFC; }
.rel-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:16px; }
.rel-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.rel-kpi-lbl { font-size:10.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-bottom:4px; }
.rel-kpi-val { font-size:20px; font-weight:750; color:#0F172A; }
.rel-amber { color:#F59E0B !important; } .rel-red { color:#EF4444 !important; }
.rel-auto { background:#fff; border:1px solid #E5E7EB; border-radius:14px; margin-bottom:18px; overflow:hidden; }
.rel-auto > summary { list-style:none; cursor:pointer; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; font-weight:700; color:#0F172A; }
.rel-auto > summary::-webkit-details-marker { display:none; }
.rel-auto-hint { font-size:12px; color:#64748B; font-weight:500; }
.rel-auto-body { padding:6px 18px 18px; border-top:1px solid #F1F5F9; display:flex; flex-direction:column; gap:10px; }
.rel-note { font-size:12.5px; color:#64748B; margin:6px 0; line-height:1.5; }
.rel-check { display:inline-flex; align-items:center; gap:8px; font-size:13.5px; color:#334155; cursor:pointer; }
.rel-check select { margin-left:6px; padding:5px 8px; border:1px solid #E2E8F0; border-radius:7px; font-family:inherit; }
.rel-section { font-size:16px; color:#0F172A; margin:22px 0 10px; display:flex; align-items:center; gap:8px; }
.rel-count { font-size:12px; background:#F1F5F9; color:#64748B; border-radius:999px; padding:2px 9px; font-weight:700; }
.rel-list { display:flex; flex-direction:column; gap:8px; }
.rel-row { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #E5E7EB; border-radius:11px; padding:12px 15px; }
.rel-row:hover { border-color:#CBD5E1; }
.rel-row-main { flex:1; min-width:0; }
.rel-row-name { font-size:14px; font-weight:700; color:#0F172A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rel-row-meta { font-size:12px; color:#64748B; margin-top:3px; }
.rel-late { color:#B45309; }
.rel-stage { font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; white-space:nowrap; }
.rel-row-actions { display:flex; gap:6px; }
.rel-noemail { font-size:11.5px; color:#64748B; background:#F8FAFC; border:1px solid #E2E8F0; padding:5px 9px; border-radius:8px; }
.rel-empty { text-align:center; padding:40px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; }
.rel-empty.small { padding:22px; }
.rel-empty-emoji { font-size:42px; margin-bottom:10px; color:#64748B; }
.rel-sum-lbl { display:inline-flex; align-items:center; gap:7px; }
.rel-noemail { display:inline-flex; align-items:center; gap:5px; }
.rel-btn svg, .rel-section svg, .rel-disclaimer svg { flex:none; }
.rel-disclaimer svg { vertical-align:-2px; margin-right:4px; }
.rel-empty h2 { font-size:18px; margin:0 0 8px; color:#0F172A; }
.rel-empty p { color:#64748B; margin:0; font-size:13.5px; }
.rel-empty code { background:#F1F5F9; padding:1px 6px; border-radius:5px; }
.rel-disclaimer { font-size:12px; color:#64748B; margin-top:18px; line-height:1.5; }
.rel-modal { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px; }
.rel-modal-card { background:#fff; border-radius:16px; width:100%; max-width:560px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(9,30,22,.3); }
.rel-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #F1F5F9; font-weight:700; color:#0F172A; }
.rel-modal-x { border:0; background:none; font-size:18px; cursor:pointer; color:#64748B; }
.rel-modal-body { padding:16px 20px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; }
.rel-tones { display:flex; align-items:center; gap:6px; font-size:12.5px; color:#64748B; flex-wrap:wrap; }
.rel-tone { border:1px solid #E2E8F0; background:#fff; padding:5px 11px; border-radius:999px; font-size:12.5px; cursor:pointer; color:#475569; font-family:inherit; }
.rel-tone.on { background:#0F172A; color:#fff; border-color:#0F172A; }
.rel-input { border:1px solid #E2E8F0; border-radius:9px; padding:9px 12px; font-size:14px; font-family:inherit; }
.rel-textarea { border:1px solid #E2E8F0; border-radius:9px; padding:10px 12px; font-size:13.5px; font-family:inherit; line-height:1.5; resize:vertical; }
.rel-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid #F1F5F9; }
@media (max-width:560px){ .rel-row{ flex-wrap:wrap; } .rel-row-actions{ width:100%; } }
</style>

<script>
(function(){
  var CSRF = "<?= $csrf ?>";
  function post(p){ return fetch('/api/relance-action.php',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body:JSON.stringify(Object.assign({csrf_token:CSRF},p))}).then(function(r){return r.json();}); }

  // Envoi direct (modèle)
  function directSend(row, btn){
    var type=row.getAttribute('data-type'), id=parseInt(row.getAttribute('data-id'),10), stage=parseInt(row.getAttribute('data-stage'),10);
    btn.disabled=true; var old=btn.textContent; btn.textContent='Envoi…';
    post({action: type==='invoice'?'send-invoice':'send-membership', invoice_id:id, user_id:id, stage:stage}).then(function(d){
      if(d&&d.ok){ row.style.transition='opacity .2s'; row.style.opacity='.45'; btn.textContent='✓ Envoyée'; }
      else { btn.disabled=false; btn.textContent=old; alert((d&&d.message)||'Échec de l\'envoi.'); }
    }).catch(function(){ btn.disabled=false; btn.textContent=old; });
  }

  document.querySelectorAll('.rel-row').forEach(function(row){
    var send=row.querySelector('.js-send'), ai=row.querySelector('.js-ai');
    if(send) send.addEventListener('click', function(){ directSend(row, send); });
    if(ai) ai.addEventListener('click', function(){ openModal(row); });
  });

  // Batch
  var batch=document.getElementById('relBatch');
  if(batch) batch.addEventListener('click', function(){
    if(!confirm('Envoyer toutes les relances recommandées maintenant ?')) return;
    batch.disabled=true; batch.textContent='Envoi en cours…';
    post({action:'batch'}).then(function(d){
      if(d&&d.ok){ alert(d.sent+' relance(s) envoyée(s)'+(d.failed?', '+d.failed+' échec(s)':'')+'.'); location.reload(); }
      else { batch.disabled=false; batch.textContent='Tout relancer'; alert('Erreur.'); }
    }).catch(function(){ batch.disabled=false; });
  });

  // Prefs auto
  var savePrefs=document.getElementById('relSavePrefs');
  if(savePrefs) savePrefs.addEventListener('click', function(){
    savePrefs.disabled=true;
    post({action:'save-prefs', auto_invoices:document.getElementById('autoInv').checked?1:0,
      auto_memberships:document.getElementById('autoMem').checked?1:0,
      max_stage:parseInt(document.getElementById('maxStage').value,10)}).then(function(d){
      savePrefs.disabled=false;
      if(d&&d.ok){ var ok=document.getElementById('relPrefsOk'); ok.style.display='inline'; setTimeout(function(){ok.style.display='none';},2500); }
    }).catch(function(){ savePrefs.disabled=false; });
  });

  // Modal IA
  var modal=document.getElementById('relModal'), curRow=null, curTone='neutre';
  var mSubject=document.getElementById('relSubject'), mBody=document.getElementById('relBody'), mState=document.getElementById('relAiState');
  function openModal(row){
    curRow=row; curTone='neutre';
    document.querySelectorAll('.rel-tone').forEach(function(b){ b.classList.toggle('on', b.getAttribute('data-tone')==='neutre'); });
    mSubject.value=''; mBody.value=''; mState.textContent='';
    document.getElementById('relModalTitle').textContent='Relance — '+row.getAttribute('data-name');
    modal.style.display='flex';
  }
  function closeModal(){ modal.style.display='none'; curRow=null; }
  document.getElementById('relModalClose').addEventListener('click', closeModal);
  document.getElementById('relModalCancel').addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  document.querySelectorAll('.rel-tone').forEach(function(b){ b.addEventListener('click', function(){
    curTone=b.getAttribute('data-tone');
    document.querySelectorAll('.rel-tone').forEach(function(x){ x.classList.remove('on'); }); b.classList.add('on');
  }); });

  var regen=document.getElementById('relRegen');
  regen.addEventListener('click', function(){
    if(!curRow) return;
    regen.disabled=true; mState.textContent='Génération en cours…';
    post({action:'ai-draft', target:curRow.getAttribute('data-type'), id:parseInt(curRow.getAttribute('data-id'),10),
      stage:parseInt(curRow.getAttribute('data-stage'),10), tone:curTone}).then(function(d){
      regen.disabled=false;
      if(d&&d.ok){ mSubject.value=d.subject||''; mBody.value=d.body||''; mState.textContent='Proposition générée — relisez et ajustez avant l\'envoi.'; }
      else { mState.textContent='IA indisponible ('+((d&&d.error)||'erreur')+'). Rédigez le message à la main.'; }
    }).catch(function(){ regen.disabled=false; mState.textContent='Erreur réseau.'; });
  });

  document.getElementById('relModalSend').addEventListener('click', function(){
    if(!curRow) return;
    var subj=mSubject.value.trim(), body=mBody.value.trim();
    if(subj.length<2 || body.length<10){ alert('Complétez l\'objet et le message.'); return; }
    var type=curRow.getAttribute('data-type'), id=parseInt(curRow.getAttribute('data-id'),10), stage=parseInt(curRow.getAttribute('data-stage'),10);
    var btn=this; btn.disabled=true; btn.textContent='Envoi…';
    post({action: type==='invoice'?'send-invoice-custom':'send-membership-custom', invoice_id:id, user_id:id, stage:stage, subject:subj, body:body}).then(function(d){
      btn.disabled=false; btn.textContent='Envoyer la relance';
      if(d&&d.ok){ closeModal(); curRow.style.opacity='.45'; var s=curRow.querySelector('.js-send'); if(s){ s.textContent='✓ Envoyée'; s.disabled=true; } }
      else alert((d&&d.message)||'Échec de l\'envoi.');
    }).catch(function(){ btn.disabled=false; btn.textContent='Envoyer la relance'; });
  });
})();
</script>
<?php render_foot(); ?>
