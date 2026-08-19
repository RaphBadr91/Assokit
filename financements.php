<?php
/**
 * financements.php — RADAR DE SUBVENTIONS
 * ------------------------------------------------------------------
 * Détecte automatiquement les dispositifs de financement pertinents
 * pour l'association / la TPE, à partir de son profil, et priorise
 * par éligibilité + échéance. Complète le suivi des candidatures
 * (/subventions) par la phase amont : « qu'ai-je le droit de demander ? ».
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/financements-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));
$can_manage = in_array($role, ['admin','founder'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
if ($org_id <= 0 || !$can_manage) {
    $_SESSION['flash_error'] = 'Le radar de subventions est réservé aux administrateurs.';
    header('Location: /dashboard'); exit;
}

$csrf = h($_SESSION['csrf_token'] ?? '');
$schema_ready = true;
$catalog_count = 0;
$prof = []; $stats = []; $matches = [];
$prefs = ['notify_new_match'=>1, 'min_match_score'=>60, 'notify_deadlines'=>1, 'channel_email'=>1, 'channel_app'=>1];

$filter = $_GET['f'] ?? '';
$valid_f = ['eligible','probable','a_verifier','saved'];

try {
    $catalog_count = (int)$pdo->query("SELECT COUNT(*) FROM grant_catalog WHERE status='active' AND org_id IS NULL")->fetchColumn();
    $prof = fin_build_profile($pdo, $org_id);

    // Premier passage : si aucune correspondance calculée, on calcule.
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM grant_matches WHERE org_id = ?");
    $cnt->execute([$org_id]);
    if ((int)$cnt->fetchColumn() === 0 && $catalog_count > 0) {
        fin_compute_matches($pdo, $org_id);
    }

    $stats = fin_stats($pdo, $org_id);

    // Préférences d'alerte (défauts si non configuré)
    $prefs = ['notify_new_match'=>1, 'min_match_score'=>60, 'notify_deadlines'=>1, 'channel_email'=>1, 'channel_app'=>1];
    try {
        $ps = $pdo->prepare("SELECT * FROM grant_alert_prefs WHERE org_id = ?");
        $ps->execute([$org_id]);
        if ($pr = $ps->fetch()) { foreach ($prefs as $k=>$v) if (isset($pr[$k]) && $pr[$k] !== null) $prefs[$k] = (int)$pr[$k]; }
    } catch (Throwable $e) {}

    $opts = [];
    if ($filter === 'saved') $opts['saved_only'] = true;
    elseif (in_array($filter, ['eligible','probable','a_verifier'], true)) $opts['eligibility'] = $filter;
    $matches = fin_list_matches($pdo, $org_id, $opts);
} catch (Throwable $e) {
    $schema_ready = false;
    error_log('[financements] '.$e->getMessage());
}

$sectors_ref = fin_sectors_catalog();

render_head('Radar de subventions');
echo render_sidebar('financements');
?>
<main class="main">
  <div class="fin-page">
    <div class="fin-head">
      <div>
        <h1 class="fin-title">🎯 Radar de subventions</h1>
        <p class="fin-sub">On détecte automatiquement les financements que votre structure a le droit de demander — priorisés par éligibilité et par échéance.</p>
      </div>
      <div class="fin-head-actions">
        <button type="button" class="fin-btn ghost" id="finRefresh">↻ Recalculer</button>
        <a href="/subventions" class="fin-btn ghost">📁 Mes candidatures</a>
      </div>
    </div>

<?php if (!$schema_ready): ?>
    <div class="fin-empty">
      <div class="fin-empty-emoji">🛠️</div>
      <h2>Base de subventions à initialiser</h2>
      <p>Exécutez la migration <code>2026-08-18-subventions-catalogue.sql</code> puis le script <code>api/seed-grants-catalog.php</code> pour amorcer le catalogue de dispositifs.</p>
    </div>
<?php elseif ($catalog_count === 0): ?>
    <div class="fin-empty">
      <div class="fin-empty-emoji">📥</div>
      <h2>Catalogue vide</h2>
      <p>Lancez <code>api/seed-grants-catalog.php</code> (en tant que fondateur) pour charger les dispositifs nationaux (FDVA, FONJEP, ANS, CAF, fondations…).</p>
    </div>
<?php else: ?>

    <!-- ===== KPIs ===== -->
    <div class="fin-kpis">
      <div class="fin-kpi"><div class="fin-kpi-lbl">Pistes détectées</div><div class="fin-kpi-val"><?= (int)($stats['total'] ?? 0) ?></div></div>
      <div class="fin-kpi"><div class="fin-kpi-lbl">Éligibles</div><div class="fin-kpi-val fin-green"><?= (int)($stats['eligible'] ?? 0) ?></div></div>
      <div class="fin-kpi"><div class="fin-kpi-lbl">Probables</div><div class="fin-kpi-val fin-amber"><?= (int)($stats['probable'] ?? 0) ?></div></div>
      <div class="fin-kpi"><div class="fin-kpi-lbl">Échéances 30j</div><div class="fin-kpi-val <?= ($stats['deadline_30'] ?? 0) > 0 ? 'fin-red' : '' ?>"><?= (int)($stats['deadline_30'] ?? 0) ?></div></div>
      <div class="fin-kpi"><div class="fin-kpi-lbl">Potentiel indicatif</div><div class="fin-kpi-val">≈ <?= number_format((float)($stats['potential_max'] ?? 0), 0, ',', ' ') ?> €</div></div>
    </div>

    <!-- ===== Profil d'éligibilité ===== -->
    <details class="fin-profile" <?= empty($prof['sectors']) ? 'open' : '' ?>>
      <summary>
        <span>⚙️ Affiner mon profil d'éligibilité</span>
        <span class="fin-profile-hint"><?= empty($prof['sectors']) ? 'Renseignez vos secteurs pour des résultats plus précis' : 'Secteurs : '.h(implode(', ', $prof['sectors'])) ?></span>
      </summary>
      <div class="fin-profile-body">
        <p class="fin-field-lbl">Secteurs d'activité de votre structure</p>
        <div class="fin-sectors">
          <?php foreach ($sectors_ref as $k => $label): $on = in_array($k, $prof['sectors'], true); ?>
            <label class="fin-tag <?= $on ? 'on' : '' ?>">
              <input type="checkbox" name="sectors" value="<?= h($k) ?>" <?= $on ? 'checked' : '' ?> hidden>
              <?= h($label) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="fin-tri-grid">
          <div class="fin-tri">
            <span class="fin-field-lbl">Action en quartier prioritaire (QPV) ?</span>
            <?php $q = $prof['is_qpv']; ?>
            <div class="fin-seg" data-field="is_qpv">
              <button type="button" data-v="1" class="<?= $q===1?'on':'' ?>">Oui</button>
              <button type="button" data-v="0" class="<?= $q===0?'on':'' ?>">Non</button>
              <button type="button" data-v="null" class="<?= $q===null?'on':'' ?>">Je ne sais pas</button>
            </div>
          </div>
          <div class="fin-tri">
            <span class="fin-field-lbl">Association d'intérêt général (reçus fiscaux) ?</span>
            <?php $g = $prof['is_interet_general']; ?>
            <div class="fin-seg" data-field="is_interet_general">
              <button type="button" data-v="1" class="<?= $g===1?'on':'' ?>">Oui</button>
              <button type="button" data-v="0" class="<?= $g===0?'on':'' ?>">Non</button>
              <button type="button" data-v="null" class="<?= $g===null?'on':'' ?>">Je ne sais pas</button>
            </div>
          </div>
        </div>

        <div class="fin-profile-foot">
          <div class="fin-geo-note">
            📍 Territoire déduit :
            <?php if ($prof['dept_code'] || $prof['region_code']): ?>
              <strong>dépt <?= h($prof['dept_code'] ?? '?') ?><?= $prof['region_code'] ? ' · région '.h($prof['region_code']) : '' ?></strong>
              <?php if ($prof['city']): ?> (<?= h($prof['city']) ?>)<?php endif; ?>
            <?php else: ?>
              <em>inconnu — renseignez l'adresse de votre structure dans Paramètres pour cibler les aides locales.</em>
            <?php endif; ?>
          </div>
          <button type="button" class="fin-btn primary" id="finSaveProfile">Enregistrer &amp; recalculer</button>
        </div>

        <div class="fin-alerts">
          <p class="fin-field-lbl" style="margin-top:20px;">🔔 Alertes automatiques (email + notification)</p>
          <div class="fin-alerts-grid">
            <label class="fin-check"><input type="checkbox" id="alNew" <?= $prefs['notify_new_match']?'checked':'' ?>> Nouvelles pistes détectées</label>
            <label class="fin-check"><input type="checkbox" id="alDl" <?= $prefs['notify_deadlines']?'checked':'' ?>> Échéances (J-30 &amp; J-7)</label>
            <label class="fin-check"><input type="checkbox" id="alEmail" <?= $prefs['channel_email']?'checked':'' ?>> Par email</label>
            <label class="fin-check"><input type="checkbox" id="alApp" <?= $prefs['channel_app']?'checked':'' ?>> Dans l'app</label>
            <label class="fin-check fin-score-pref">Score minimum : <input type="number" id="alScore" min="0" max="100" step="5" value="<?= (int)$prefs['min_match_score'] ?>"> %</label>
          </div>
          <button type="button" class="fin-btn ghost sm" id="finSavePrefs" style="margin-top:10px;">Enregistrer mes alertes</button>
          <span id="finPrefsOk" style="display:none;color:#059669;font-size:12.5px;margin-left:8px;">✓ Enregistré</span>
        </div>
      </div>
    </details>

    <!-- ===== Filtres ===== -->
    <div class="fin-filters">
      <a href="/financements" class="fin-chip <?= $filter===''?'is-active':'' ?>">Toutes</a>
      <a href="/financements?f=eligible" class="fin-chip <?= $filter==='eligible'?'is-active':'' ?>">✅ Éligibles</a>
      <a href="/financements?f=probable" class="fin-chip <?= $filter==='probable'?'is-active':'' ?>">🟡 Probables</a>
      <a href="/financements?f=a_verifier" class="fin-chip <?= $filter==='a_verifier'?'is-active':'' ?>">🔎 À vérifier</a>
      <a href="/financements?f=saved" class="fin-chip <?= $filter==='saved'?'is-active':'' ?>">⭐ Suivies</a>
    </div>

    <!-- ===== Liste ===== -->
    <?php if (empty($matches)): ?>
      <div class="fin-empty">
        <div class="fin-empty-emoji">🔍</div>
        <h2><?= $filter ? 'Aucune piste dans ce filtre' : 'Aucune piste pour ce profil' ?></h2>
        <p>Complétez votre profil ci-dessus (secteurs, territoire) puis recalculez.</p>
      </div>
    <?php else: ?>
      <div class="fin-list" id="finList">
        <?php foreach ($matches as $m):
          $em = fin_eligibility_meta($m['eligibility']);
          $reasons = json_decode($m['reasons'] ?? '[]', true) ?: [];
          $dl = $m['deadline_apply'];
          $days = $dl ? (int)floor((strtotime($dl) - time())/86400) : null;
          $amount = '';
          if ($m['amount_min'] && $m['amount_max']) $amount = number_format((float)$m['amount_min'],0,',',' ').' – '.number_format((float)$m['amount_max'],0,',',' ').' €';
          elseif ($m['amount_max']) $amount = 'jusqu\'à '.number_format((float)$m['amount_max'],0,',',' ').' €';
        ?>
        <article class="fin-card" data-cat="<?= (int)$m['catalog_id'] ?>" data-saved="<?= (int)$m['saved'] ?>">
          <div class="fin-card-top">
            <span class="fin-elig" style="background:<?= $em[2] ?>;color:<?= $em[1] ?>;"><?= $em[3] ?> <?= h($em[0]) ?> · <?= (int)$m['score'] ?>%</span>
            <?php if ($days !== null): ?>
              <span class="fin-deadline <?= $days<0?'over':($days<=15?'urgent':'') ?>">
                <?= $days<0 ? 'Clôturé' : 'échéance J-'.$days ?> · <?= h(date('d/m/Y', strtotime($dl))) ?>
              </span>
            <?php else: ?>
              <span class="fin-deadline soft"><?= h(ucfirst($m['recurrence'] ?? 'annuel')) ?></span>
            <?php endif; ?>
          </div>

          <h3 class="fin-card-title"><?= h($m['title']) ?></h3>
          <div class="fin-card-funder"><?= h($m['funder_name']) ?><?php if ($amount): ?> · <span class="fin-amount"><?= h($amount) ?></span><?php endif; ?></div>
          <p class="fin-card-summary"><?= h($m['summary'] ?? '') ?></p>

          <?php if ($reasons): ?>
          <ul class="fin-reasons">
            <?php foreach (array_slice($reasons,0,4) as $r): ?><li><?= h($r) ?></li><?php endforeach; ?>
          </ul>
          <?php endif; ?>

          <div class="fin-card-actions">
            <?php if (!empty($m['apply_url'])): ?>
              <a href="<?= h($m['apply_url']) ?>" target="_blank" rel="noopener noreferrer" class="fin-btn primary sm">Déposer une demande ↗</a>
            <?php elseif (!empty($m['source_url'])): ?>
              <a href="<?= h($m['source_url']) ?>" target="_blank" rel="noopener noreferrer" class="fin-btn primary sm">En savoir plus ↗</a>
            <?php endif; ?>
            <a href="/subvention-form" class="fin-btn ghost sm">+ Suivre en dossier</a>
            <button type="button" class="fin-icon-btn js-save" title="Suivre cette piste"><?= (int)$m['saved'] ? '⭐' : '☆' ?></button>
            <button type="button" class="fin-icon-btn js-dismiss" title="Masquer">✕</button>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="fin-disclaimer">ℹ️ Le radar signale des pistes à partir de critères publics : il ne garantit pas l'octroi. Vérifiez toujours les conditions exactes sur le site officiel du financeur (lien fourni). Montants indicatifs.</p>

<?php endif; ?>
  </div>
</main>

<style>
.fin-page { max-width: 1080px; margin: 0 auto; padding: 24px 22px; }
.fin-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
.fin-title { font-size:24px; margin:0 0 4px; color:#0F172A; }
.fin-sub { color:#64748B; margin:0; font-size:14px; max-width:640px; }
.fin-head-actions { display:flex; gap:8px; flex-wrap:wrap; }
.fin-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:9px; font-size:13px; font-weight:650; text-decoration:none; cursor:pointer; border:1px solid transparent; font-family:inherit; }
.fin-btn.sm { padding:7px 12px; font-size:12.5px; }
.fin-btn.primary { background:linear-gradient(135deg,#10B981,#059669); color:#fff; }
.fin-btn.primary:hover { filter:brightness(1.05); }
.fin-btn.ghost { background:#fff; border-color:#E2E8F0; color:#334155; }
.fin-btn.ghost:hover { background:#F8FAFC; }
.fin-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:16px; }
.fin-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.fin-kpi-lbl { font-size:10.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-bottom:4px; }
.fin-kpi-val { font-size:20px; font-weight:750; color:#0F172A; }
.fin-green { color:#10B981 !important; } .fin-amber { color:#F59E0B !important; } .fin-red { color:#EF4444 !important; }
.fin-profile { background:#fff; border:1px solid #E5E7EB; border-radius:14px; margin-bottom:16px; overflow:hidden; }
.fin-profile > summary { list-style:none; cursor:pointer; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; gap:12px; font-weight:700; color:#0F172A; }
.fin-profile > summary::-webkit-details-marker { display:none; }
.fin-profile-hint { font-size:12px; color:#94A3B8; font-weight:500; text-align:right; }
.fin-profile-body { padding:4px 18px 18px; border-top:1px solid #F1F5F9; }
.fin-field-lbl { font-size:12.5px; font-weight:700; color:#475569; margin:14px 0 8px; }
.fin-sectors { display:flex; flex-wrap:wrap; gap:8px; }
.fin-tag { display:inline-flex; align-items:center; padding:7px 13px; border-radius:999px; border:1px solid #E2E8F0; background:#F8FAFC; font-size:13px; color:#475569; cursor:pointer; user-select:none; transition:all .12s; }
.fin-tag.on { background:#ECFDF5; border-color:#10B981; color:#065F46; font-weight:650; }
.fin-tri-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; }
.fin-seg { display:inline-flex; border:1px solid #E2E8F0; border-radius:9px; overflow:hidden; }
.fin-seg button { border:0; background:#fff; padding:7px 12px; font-size:12.5px; color:#475569; cursor:pointer; font-family:inherit; border-right:1px solid #E2E8F0; }
.fin-seg button:last-child { border-right:0; }
.fin-seg button.on { background:#0F172A; color:#fff; font-weight:650; }
.fin-profile-foot { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-top:18px; }
.fin-geo-note { font-size:12.5px; color:#64748B; }
.fin-alerts { border-top:1px solid #F1F5F9; margin-top:6px; }
.fin-alerts-grid { display:flex; flex-wrap:wrap; gap:10px 18px; align-items:center; }
.fin-check { display:inline-flex; align-items:center; gap:7px; font-size:13px; color:#475569; cursor:pointer; }
.fin-check input[type=number] { width:58px; padding:4px 6px; border:1px solid #E2E8F0; border-radius:7px; font-family:inherit; font-size:13px; }
.fin-score-pref { gap:6px; }
.fin-filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.fin-chip { padding:6px 13px; background:#fff; border:1px solid #E5E7EB; border-radius:999px; font-size:12.5px; text-decoration:none; color:#475569; }
.fin-chip:hover { background:#F8FAFC; }
.fin-chip.is-active { background:#ECFDF5; color:#065F46; border-color:#10B981; font-weight:650; }
.fin-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:12px; }
.fin-card { background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:16px; display:flex; flex-direction:column; transition:box-shadow .15s, transform .15s; }
.fin-card:hover { box-shadow:0 8px 24px -12px rgba(9,30,22,.22); transform:translateY(-1px); }
.fin-card-top { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
.fin-elig { font-size:11.5px; font-weight:700; padding:4px 10px; border-radius:999px; white-space:nowrap; }
.fin-deadline { font-size:11px; font-weight:700; padding:3px 9px; border-radius:6px; background:#F1F5F9; color:#64748B; white-space:nowrap; }
.fin-deadline.soft { background:#F8FAFC; color:#94A3B8; font-weight:600; }
.fin-deadline.urgent { background:#FEF3C7; color:#92400E; }
.fin-deadline.over { background:#FEE2E2; color:#991B1B; }
.fin-card-title { font-size:15.5px; margin:0 0 3px; color:#0F172A; line-height:1.3; }
.fin-card-funder { font-size:12.5px; color:#64748B; margin-bottom:8px; }
.fin-amount { color:#059669; font-weight:700; }
.fin-card-summary { font-size:13px; color:#475569; line-height:1.5; margin:0 0 10px; }
.fin-reasons { list-style:none; padding:0; margin:0 0 12px; display:flex; flex-direction:column; gap:4px; }
.fin-reasons li { font-size:12px; color:#475569; padding-left:16px; position:relative; }
.fin-reasons li::before { content:"›"; position:absolute; left:4px; color:#10B981; font-weight:700; }
.fin-card-actions { display:flex; align-items:center; gap:8px; margin-top:auto; flex-wrap:wrap; }
.fin-icon-btn { margin-left:auto; width:32px; height:32px; border-radius:8px; border:1px solid #E2E8F0; background:#fff; cursor:pointer; font-size:15px; line-height:1; }
.fin-icon-btn.js-dismiss { margin-left:0; color:#94A3B8; }
.fin-icon-btn:hover { background:#F8FAFC; }
.fin-empty { text-align:center; padding:48px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; }
.fin-empty-emoji { font-size:46px; margin-bottom:10px; }
.fin-empty h2 { font-size:18px; margin:0 0 8px; color:#0F172A; }
.fin-empty p { color:#64748B; max-width:480px; margin:0 auto; font-size:13.5px; }
.fin-empty code { background:#F1F5F9; padding:1px 6px; border-radius:5px; font-size:12px; }
.fin-disclaimer { font-size:12px; color:#94A3B8; margin-top:18px; line-height:1.5; }
@media (max-width:560px){ .fin-list{ grid-template-columns:1fr; } }
</style>

<script>
(function(){
  var CSRF = "<?= $csrf ?>";
  function post(payload){
    return fetch('/api/financements-action.php', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(Object.assign({csrf_token:CSRF}, payload))
    }).then(function(r){ return r.json(); });
  }

  // Toggle secteurs
  document.querySelectorAll('.fin-tag').forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      var cb = t.querySelector('input'); cb.checked = !cb.checked;
      t.classList.toggle('on', cb.checked);
    });
  });
  // Segments tri-état
  document.querySelectorAll('.fin-seg').forEach(function(seg){
    seg.addEventListener('click', function(e){
      var b = e.target.closest('button'); if(!b) return;
      seg.querySelectorAll('button').forEach(function(x){ x.classList.remove('on'); });
      b.classList.add('on');
    });
  });
  function segVal(field){
    var on = document.querySelector('.fin-seg[data-field="'+field+'"] button.on');
    return on ? on.getAttribute('data-v') : 'null';
  }

  var saveBtn = document.getElementById('finSaveProfile');
  if (saveBtn) saveBtn.addEventListener('click', function(){
    var sectors = Array.prototype.map.call(document.querySelectorAll('.fin-tag input:checked'), function(c){ return c.value; });
    saveBtn.disabled = true; saveBtn.textContent = 'Calcul…';
    post({action:'save-profile', sectors:sectors, is_qpv:segVal('is_qpv'), is_interet_general:segVal('is_interet_general')})
      .then(function(d){ if(d && d.ok){ location.reload(); } else { saveBtn.disabled=false; saveBtn.textContent='Enregistrer & recalculer'; alert('Erreur, réessayez.'); } })
      .catch(function(){ saveBtn.disabled=false; saveBtn.textContent='Enregistrer & recalculer'; });
  });

  var savePrefs = document.getElementById('finSavePrefs');
  if (savePrefs) savePrefs.addEventListener('click', function(){
    savePrefs.disabled = true;
    post({action:'save-prefs',
      notify_new_match: document.getElementById('alNew').checked ? 1 : 0,
      notify_deadlines: document.getElementById('alDl').checked ? 1 : 0,
      channel_email: document.getElementById('alEmail').checked ? 1 : 0,
      channel_app: document.getElementById('alApp').checked ? 1 : 0,
      min_match_score: parseInt(document.getElementById('alScore').value,10) || 0
    }).then(function(d){
      savePrefs.disabled = false;
      if(d && d.ok){ var ok=document.getElementById('finPrefsOk'); ok.style.display='inline'; setTimeout(function(){ ok.style.display='none'; }, 2500); }
    }).catch(function(){ savePrefs.disabled = false; });
  });

  var refresh = document.getElementById('finRefresh');
  if (refresh) refresh.addEventListener('click', function(){
    refresh.disabled = true; refresh.textContent='↻ …';
    post({action:'refresh'}).then(function(){ location.reload(); }).catch(function(){ refresh.disabled=false; refresh.textContent='↻ Recalculer'; });
  });

  // Save / dismiss par carte
  document.querySelectorAll('.fin-card').forEach(function(card){
    var cat = parseInt(card.getAttribute('data-cat'),10);
    var saveB = card.querySelector('.js-save');
    var dismB = card.querySelector('.js-dismiss');
    if (saveB) saveB.addEventListener('click', function(){
      var isSaved = card.getAttribute('data-saved') === '1';
      post({action: isSaved ? 'unsave' : 'save', catalog_id:cat}).then(function(d){
        if(d && d.ok){ card.setAttribute('data-saved', isSaved?'0':'1'); saveB.textContent = isSaved ? '☆' : '⭐'; }
      });
    });
    if (dismB) dismB.addEventListener('click', function(){
      post({action:'dismiss', catalog_id:cat}).then(function(d){
        if(d && d.ok){ card.style.transition='opacity .2s, transform .2s'; card.style.opacity='0'; card.style.transform='scale(.96)'; setTimeout(function(){ card.remove(); }, 200); }
      });
    });
  });
})();
</script>
<?php render_foot(); ?>
