<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail : réglages (administrateurs)
 * ============================================================
 * Compte relié, synchronisation, conservation, signature, tri par IA,
 * catégories (nom, couleur, mots-clés, qui les voit), déconnexion.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/mail-helpers.php';
require_once __DIR__ . '/demo-guard.php';
require_login();

$user = current_user();
$org = (int)$user['org_id'];
if (!mail_can_manage($user) || !mail_schema_ready($pdo)) { header('Location: /boite-mail'); exit; }
$acc = mail_get_account($pdo, $org);
$cats = mail_categories($pdo, $org);
$csrf = $_SESSION['csrf_token'] ?? '';
$demo = ak_demo_session();

$counts = [];
$s = $pdo->prepare("SELECT category_id, COUNT(*) FROM mail_threads WHERE org_id = ? GROUP BY category_id");
$s->execute([$org]);
foreach ($s->fetchAll(PDO::FETCH_NUM) as [$cid, $n]) $counts[(int)$cid] = (int)$n;

render_head('Réglages de la boîte mail');
render_sidebar('boite-mail');
?>
<main class="main">
<div class="bp-page">
  <a class="bp-back" href="/boite-mail">← Boîte mail</a>
  <h1 class="bp-title"><?= ak_icon_badge('gear', '#059669', 34) ?><span>Réglages de la boîte mail</span></h1>

  <section class="bp-card">
    <h2>Compte relié</h2>
    <?php if (!$acc): ?>
      <p class="bp-muted">Aucune boîte reliée. <a href="/boite-mail">Relier la boîte Gmail →</a></p>
    <?php else: ?>
      <div class="bp-acc">
        <div><strong><?= h($acc['email']) ?></strong><?= $acc['provider'] === 'demo' ? ' <span class="bp-pill">Démonstration</span>' : '' ?>
          <div class="bp-muted">État : <?= $acc['status'] === 'active' ? 'connectée' : h($acc['last_error'] ?: $acc['status']) ?><?= $acc['last_sync_at'] ? ' · dernière synchronisation le ' . h(date('d/m/Y à H:i', strtotime($acc['last_sync_at']))) : '' ?></div></div>
        <?php if (!$demo): ?>
          <div class="bp-acc-btns">
            <?php if ($acc['provider'] === 'gmail'): ?><a class="bp-btn ghost" href="/mail-google-connect?email=<?= urlencode($acc['email']) ?>">Reconnecter</a><?php endif; ?>
            <button type="button" class="bp-btn danger" id="bpDisconnect">Déconnecter et effacer la copie locale</button>
          </div>
        <?php endif; ?>
      </div>

      <form id="bpSettings" class="bp-form">
        <label>Nom d’expéditeur des réponses<input name="display_name" maxlength="190" value="<?= h($acc['display_name'] ?? '') ?>" placeholder="Nom de l’association"></label>
        <label>Signature ajoutée aux réponses<textarea name="signature" rows="4" maxlength="2000" placeholder="Prénom Nom&#10;Fonction — Association&#10;Téléphone"><?= h($acc['signature'] ?? '') ?></textarea></label>
        <div class="bp-row">
          <label>Première synchronisation<select name="sync_days">
            <?php foreach ([7 => '7 derniers jours', 30 => '30 derniers jours', 90 => '3 derniers mois', 180 => '6 derniers mois'] as $v => $l): ?><option value="<?= $v ?>"<?= (int)$acc['sync_days'] === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></label>
          <label>Conservation dans Assokit<select name="retention_months">
            <?php foreach ([6 => '6 mois', 12 => '1 an', 24 => '2 ans', 36 => '3 ans', 60 => '5 ans'] as $v => $l): ?><option value="<?= $v ?>"<?= (int)$acc['retention_months'] === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <label class="bp-check"><input type="checkbox" name="ai_sort" value="1"<?= (int)$acc['ai_sort'] ? ' checked' : '' ?>> Ranger par l’IA les e-mails qu’aucune règle ne reconnaît (objet, expéditeur et début du message envoyés à l’IA)</label>
        <p class="bp-muted">La copie locale des conversations plus anciennes que la durée de conservation est effacée chaque nuit ; les e-mails restent dans Gmail.</p>
        <div><button class="bp-btn primary" type="submit">Enregistrer</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="bp-card">
    <div class="bp-card-head">
      <div><h2>Catégories</h2>
      <p class="bp-muted">Un e-mail va dans la première catégorie (dans cet ordre) dont un mot-clé apparaît dans l’objet. Un mot-clé avec @ vise l’expéditeur : <code>@opco.fr</code> (tout le domaine) ou <code>nom@exemple.fr</code>. Sans règle, l’IA choisit ; sinon « Autres ».</p></div>
      <?php if ($acc): ?><button type="button" class="bp-btn ghost" id="bpReclassify">Re-trier toute la boîte</button><?php endif; ?>
    </div>
    <div class="bp-cats" id="bpCats">
      <?php foreach ($cats as $c): $roles = array_filter(explode(',', (string)$c['roles'])); ?>
      <form class="bp-cat" data-id="<?= (int)$c['id'] ?>">
        <div class="bp-cat-top">
          <span class="bp-handle" title="Ordre"><?php if ($c['slug'] !== 'autre'): ?><button type="button" data-move="-1">↑</button><button type="button" data-move="1">↓</button><?php endif; ?></span>
          <input type="color" name="color" value="<?= h($c['color']) ?>">
          <input name="label" value="<?= h($c['label']) ?>" maxlength="80" required>
          <span class="bp-muted"><?= $counts[(int)$c['id']] ?? 0 ?> conversation(s)</span>
        </div>
        <?php if ($c['slug'] !== 'autre'): ?>
        <textarea name="keywords" rows="2" placeholder="mots-clés séparés par des virgules"><?= h($c['keywords']) ?></textarea>
        <?php else: ?><input type="hidden" name="keywords" value=""><p class="bp-muted">Tout ce qui n’entre dans aucune autre catégorie.</p><?php endif; ?>
        <div class="bp-cat-foot">
          <span class="bp-muted">Visible par :</span>
          <label class="bp-check"><input type="checkbox" name="roles" value="admin" checked disabled> Administrateurs</label>
          <label class="bp-check"><input type="checkbox" name="roles" value="coordinator"<?= !$roles || in_array('coordinator', $roles, true) ? ' checked' : '' ?>> Coordinateurs</label>
          <span class="bp-spacer"></span>
          <?php if (!(int)$c['is_system']): ?><button type="button" class="bp-btn ghost sm" data-del>Supprimer</button><?php endif; ?>
          <button type="submit" class="bp-btn primary sm">Enregistrer</button>
        </div>
      </form>
      <?php endforeach; ?>
    </div>
    <form class="bp-cat new" data-id="0">
      <div class="bp-cat-top"><input type="color" name="color" value="#0EA5E9"><input name="label" maxlength="80" placeholder="Nouvelle catégorie (ex. Mairie)" required></div>
      <textarea name="keywords" rows="2" placeholder="mots-clés séparés par des virgules"></textarea>
      <div class="bp-cat-foot"><label class="bp-check"><input type="checkbox" name="roles" value="coordinator" checked> Visible par les coordinateurs</label><span class="bp-spacer"></span><button type="submit" class="bp-btn primary sm">Ajouter</button></div>
    </form>
  </section>
</div>
</main>
<style>
.bp-page { max-width: 900px; margin: 0 auto; }
.bp-back { font-size: 13px; color: #059669; font-weight: 600; text-decoration: none; }
.bp-title { display: flex; align-items: center; gap: 12px; font-size: 22px; color: #0F172A; margin: 10px 0 18px; }
.bp-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 16px; padding: 22px; margin-bottom: 16px; }
.bp-card h2 { font-size: 16px; color: #0F172A; margin: 0 0 10px; }
.bp-card-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.bp-muted { color: #64748B; font-size: 12.5px; line-height: 1.5; }
.bp-muted code { background: #F1F5F9; border-radius: 4px; padding: 0 4px; }
.bp-pill { font-size: 11px; font-weight: 600; background: #EEF2FF; color: #4338CA; border-radius: 999px; padding: 2px 8px; }
.bp-acc { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: center; padding-bottom: 16px; border-bottom: 1px solid #F1F5F9; margin-bottom: 16px; }
.bp-acc-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.bp-form { display: grid; gap: 12px; }
.bp-form label { display: grid; gap: 5px; font-size: 12.5px; font-weight: 600; color: #334155; }
.bp-form input, .bp-form textarea, .bp-form select, .bp-cat input[name=label], .bp-cat textarea { border: 1px solid #E2E8F0; border-radius: 9px; padding: 8px 10px; font-size: 13.5px; font-family: inherit; font-weight: 400; }
.bp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bp-check { display: flex !important; grid-template-columns: none !important; gap: 7px; align-items: center; font-weight: 400 !important; font-size: 13px; color: #334155; }
.bp-btn { display: inline-flex; align-items: center; gap: 6px; border-radius: 10px; padding: 9px 14px; font-size: 13.5px; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; font-family: inherit; }
.bp-btn.sm { padding: 6px 11px; font-size: 12.5px; }
.bp-btn.primary { background: #059669; color: #fff; } .bp-btn.ghost { background: #fff; border-color: #E2E8F0; color: #334155; }
.bp-btn.danger { background: #fff; border-color: #FECACA; color: #B91C1C; }
.bp-cats { display: grid; gap: 10px; margin: 14px 0; }
.bp-cat { border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px; display: grid; gap: 8px; }
.bp-cat.new { border-style: dashed; }
.bp-cat-top { display: flex; gap: 8px; align-items: center; }
.bp-cat-top input[name=label] { flex: 1; font-weight: 600; }
.bp-cat input[type=color] { width: 34px; height: 34px; border: 1px solid #E2E8F0; border-radius: 8px; padding: 2px; background: #fff; }
.bp-handle button { border: 1px solid #E2E8F0; background: #fff; border-radius: 6px; width: 24px; height: 24px; cursor: pointer; color: #64748B; }
.bp-cat-foot { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.bp-spacer { flex: 1; }
.bm-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #0F172A; color: #fff; padding: 10px 16px; border-radius: 10px; font-size: 13.5px; z-index: 3100; }
@media (max-width: 640px) { .bp-row { grid-template-columns: 1fr; } .bp-card-head { flex-direction: column; } }
</style>
<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;
  function call(d) {
    d.csrf_token = csrf;
    return fetch('/boite-mail-action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Erreur ' + r.status }; }); });
  }
  function toast(m) { var t = document.createElement('div'); t.className = 'bm-toast'; t.textContent = m; document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500); }

  var st = document.getElementById('bpSettings');
  if (st) st.addEventListener('submit', function (e) {
    e.preventDefault();
    call({ action: 'settings', display_name: st.display_name.value, signature: st.signature.value, sync_days: st.sync_days.value,
           retention_months: st.retention_months.value, ai_sort: st.ai_sort.checked ? 1 : 0 })
      .then(function (r) { toast(r.ok ? 'Réglages enregistrés' : (r.error || 'Erreur')); });
  });

  document.querySelectorAll('.bp-cat').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var roles = ['admin'];
      f.querySelectorAll('input[name=roles][value=coordinator]').forEach(function (c) { if (c.checked) roles.push('coordinator'); });
      // Les deux cochés = visible par tous ceux qui ont la boîte (aucune restriction)
      call({ action: 'category_save', id: +f.getAttribute('data-id'), label: f.label.value, color: f.color.value,
             keywords: f.keywords ? f.keywords.value : '', roles: roles.length === 2 ? [] : roles })
        .then(function (r) { if (!r.ok) return toast(r.error || 'Erreur'); toast('Catégorie enregistrée'); if (!+f.getAttribute('data-id')) location.reload(); });
    });
    var del = f.querySelector('[data-del]');
    if (del) del.addEventListener('click', function () {
      if (!confirm('Supprimer cette catégorie ? Ses conversations iront dans « Autres ».')) return;
      call({ action: 'category_delete', id: +f.getAttribute('data-id') }).then(function (r) { r.ok ? location.reload() : toast(r.error || 'Erreur'); });
    });
    f.querySelectorAll('[data-move]').forEach(function (b) {
      b.addEventListener('click', function () {
        var box = document.getElementById('bpCats'), items = [].slice.call(box.children), i = items.indexOf(f), j = i + (+b.getAttribute('data-move'));
        if (j < 0 || j >= items.length || items[j].querySelector('[data-move]') === null) return;
        if (j < i) box.insertBefore(f, items[j]); else box.insertBefore(items[j], f);
        call({ action: 'category_order', ids: [].slice.call(box.children).map(function (x) { return +x.getAttribute('data-id'); }) });
      });
    });
  });

  var rc = document.getElementById('bpReclassify');
  if (rc) rc.addEventListener('click', function () {
    if (!confirm('Re-trier toutes les conversations avec les règles actuelles (les rangements faits à la main sont conservés) ?')) return;
    rc.disabled = true; rc.textContent = 'Tri en cours…';
    call({ action: 'reclassify' }).then(function (r) { rc.disabled = false; rc.textContent = 'Re-trier toute la boîte'; toast(r.ok ? r.count + ' conversation(s) re-triée(s)' : (r.error || 'Erreur')); });
  });

  var dc = document.getElementById('bpDisconnect');
  if (dc) dc.addEventListener('click', function () {
    if (!confirm('Déconnecter la boîte ? L’accès Google est retiré et la copie des e-mails dans Assokit est effacée (rien n’est supprimé dans Gmail).')) return;
    call({ action: 'disconnect' }).then(function (r) { r.ok ? (location.href = '/boite-mail') : toast(r.error || 'Erreur'); });
  });
})();
</script>
<?php render_foot(); ?>
