<?php
/**
 * mon-asso-copilote.php
 * --------------------------------------------------------------
 * Copilote IA — page chat « Pose ta question à ton asso ».
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$role = strtolower((string)($user['role'] ?? ''));
$is_priv = in_array($role, ['admin','coordinator','founder'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$is_priv) { $_SESSION['flash_error'] = 'Le copilote est réservé aux rôles de pilotage (admin, coordinateur).'; header('Location: /dashboard'); exit; }

render_head('Copilote IA');
render_sidebar('mon-asso-copilote');
$csrf = h($_SESSION['csrf_token'] ?? '');
?>
<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a><span class="sep">›</span>
    <span class="current">🤖 Copilote IA</span>
  </nav>

  <div style="max-width:820px;margin:14px auto;">
    <div style="text-align:center;margin-bottom:18px;">
      <div style="font-size:44px;">🤖</div>
      <h1 style="margin:6px 0 4px;font-size:24px;color:#0F172A;">Pose ta question à ton asso</h1>
      <p style="color:#64748B;font-size:14.5px;margin:0;">Adhérents, cotisations, factures, trésorerie, projets, événements — demande en langage naturel.</p>
    </div>

    <div id="cop-suggests" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:16px;">
      <?php foreach ([
        "Combien d'adhérents actifs ?",
        "Quels adhérents dois-je relancer ?",
        "Quel est mon chiffre d'affaires cette année ?",
        "Quelles factures sont en retard ?",
        "Combien nous doit-on ?",
        "Quels sont les prochains événements ?",
      ] as $s): ?>
        <button type="button" class="cop-chip" style="background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;padding:8px 14px;font-size:13px;color:#334155;cursor:pointer;"><?= h($s) ?></button>
      <?php endforeach; ?>
    </div>

    <div id="cop-log" style="display:flex;flex-direction:column;gap:14px;margin-bottom:16px;"></div>

    <form id="cop-form" style="display:flex;gap:10px;position:sticky;bottom:12px;background:#fff;padding:8px;border:1px solid #E2E8F0;border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.08);">
      <input id="cop-input" type="text" autocomplete="off" placeholder="Écris ta question…" maxlength="500"
             style="flex:1;border:none;outline:none;font-size:15px;padding:10px 12px;background:transparent;color:#0F172A;">
      <button type="submit" id="cop-send" style="background:linear-gradient(135deg,#0CCB8F,#059669);color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:700;font-size:14px;cursor:pointer;">Demander</button>
    </form>
    <p style="text-align:center;color:#94A3B8;font-size:12px;margin-top:10px;">Les chiffres proviennent directement de tes données Assokit. L'IA ne fait que les mettre en mots.</p>
  </div>
</main>

<script>
(function(){
  var CSRF = "<?= $csrf ?>";
  var logEl = document.getElementById('cop-log');
  var form  = document.getElementById('cop-form');
  var input = document.getElementById('cop-input');
  var send  = document.getElementById('cop-send');

  function esc(s){ return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

  function bubble(html, who){
    var b = document.createElement('div');
    b.style.cssText = who==='me'
      ? 'align-self:flex-end;max-width:80%;background:#059669;color:#fff;padding:10px 14px;border-radius:14px 14px 4px 14px;font-size:14.5px;'
      : 'align-self:flex-start;max-width:92%;background:#F8FAFC;border:1px solid #E2E8F0;color:#0F172A;padding:12px 14px;border-radius:14px 14px 14px 4px;font-size:14.5px;';
    b.innerHTML = html;
    logEl.appendChild(b);
    b.scrollIntoView({behavior:'smooth', block:'end'});
    return b;
  }

  function renderTable(t){
    if(!t || !t.columns) return '';
    var h = '<div style="font-weight:700;margin:8px 0 6px;color:#0F172A;">'+esc(t.title||'')+'</div>';
    h += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
    h += '<tr>'+t.columns.map(function(c){return '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #E2E8F0;color:#64748B;">'+esc(c)+'</th>';}).join('')+'</tr>';
    (t.rows||[]).forEach(function(r){
      h += '<tr>'+r.map(function(cell){return '<td style="padding:6px 8px;border-bottom:1px solid #F1F5F9;">'+esc(cell)+'</td>';}).join('')+'</tr>';
    });
    h += '</table></div>';
    return h;
  }

  function ask(q){
    if(!q || !q.trim()) return;
    bubble(esc(q), 'me');
    input.value=''; send.disabled=true;
    var wait = bubble('<span style="color:#94A3B8;">…</span>', 'bot');
    fetch('/mon-asso-copilote-ask.php', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({ question:q, csrf_token:CSRF })
    }).then(function(r){return r.json();}).then(function(d){
      var html = '';
      if(d && d.ok===false){ html = esc(d.error==='forbidden'?'Accès non autorisé.':'Service momentanément indisponible.'); }
      else {
        html = '<div>'+esc(d.answer||'')+'</div>';
        if(d.table){ html += renderTable(d.table); }
        if(d.action && d.action.route){ html += '<div style="margin-top:10px;"><a href="'+esc(d.action.route)+'" style="display:inline-block;background:#0F172A;color:#fff;text-decoration:none;padding:8px 14px;border-radius:9px;font-size:13px;font-weight:600;">'+esc(d.action.label||'Ouvrir')+' →</a></div>'; }
      }
      wait.innerHTML = html;
    }).catch(function(){ wait.innerHTML = esc('Erreur réseau. Réessayez.'); })
      .finally(function(){ send.disabled=false; input.focus(); });
  }

  form.addEventListener('submit', function(e){ e.preventDefault(); ask(input.value); });
  document.querySelectorAll('.cop-chip').forEach(function(c){
    c.addEventListener('click', function(){ ask(c.textContent); });
  });
})();
</script>
<?php render_foot(); ?>
