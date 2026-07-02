<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($user['role'] !== 'admin') { http_response_code(403); die('Réservé aux admins.'); }

$id = (int)($_GET['id'] ?? 0);
$ag = null; $resos = [];
if ($id > 0) {
    $ag = ag_load($pdo, $id, $org_id);
    if (!$ag) { http_response_code(404); die('AG introuvable.'); }
    $resos = ag_load_resolutions($pdo, $id);
}
$d = $ag ?: ['type'=>'ag_ord','title'=>'','description'=>'','scheduled_at'=>'','location'=>'','location_url'=>'','quorum_required'=>'','notes_internal'=>''];
if (empty($resos)) {
    $resos = [
        ['id'=>0,'title'=>'Approbation du PV de l\'AG précédente','description'=>'','vote_type'=>'simple'],
        ['id'=>0,'title'=>'Rapport moral du président','description'=>'','vote_type'=>'simple'],
        ['id'=>0,'title'=>'Rapport financier du trésorier','description'=>'','vote_type'=>'simple'],
        ['id'=>0,'title'=>'Quitus au bureau','description'=>'','vote_type'=>'simple'],
        ['id'=>0,'title'=>'Élection du nouveau bureau','description'=>'','vote_type'=>'simple'],
    ];
}

render_head($ag ? 'Modifier AG' : 'Nouvelle AG');
?>
<?= render_sidebar('assemblees') ?>
<main class="main">
  <div class="ag-page" style="max-width:780px;">
    <a href="/assemblees" class="ag-back">← Assemblées</a>
    <h1 class="ag-pg-title"><?= $ag ? '✏️ Modifier' : '+ Nouvelle' ?> assemblée</h1>

    <form method="POST" action="/action-assemblee" class="ag-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="<?= $ag ? 'update' : 'create' ?>">
      <?php if ($ag): ?><input type="hidden" name="id" value="<?= (int)$ag['id'] ?>"><?php endif; ?>

      <h2 class="ag-section">📌 Identité</h2>
      <div class="ag-row">
        <div class="ag-fld"><label>Type *</label>
          <select name="type">
            <?php foreach (['ag_ord','ag_ext','ca','bureau'] as $t): ?>
              <option value="<?= h($t) ?>" <?= $d['type']==$t?'selected':'' ?>><?= h(ag_type_label($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ag-fld"><label>Titre *</label><input type="text" name="title" required maxlength="200" value="<?= h($d['title']) ?>" placeholder="Ex : AG Ordinaire 2026"></div>
      </div>
      <div class="ag-row">
        <div class="ag-fld"><label>Date & heure *</label><input type="datetime-local" name="scheduled_at" required value="<?= $d['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($d['scheduled_at'])) : '' ?>"></div>
        <div class="ag-fld"><label>Quorum (nb min)</label><input type="number" name="quorum_required" min="0" value="<?= h($d['quorum_required']) ?>" placeholder="0 = pas de quorum"></div>
      </div>
      <div class="ag-fld"><label>Lieu</label><input type="text" name="location" maxlength="255" value="<?= h($d['location']) ?>" placeholder="Salle, adresse, ou « visioconférence »"></div>
      <div class="ag-fld"><label>Lien visio (optionnel)</label><input type="url" name="location_url" maxlength="500" value="<?= h($d['location_url']) ?>" placeholder="https://meet.jit.si/..."></div>
      <div class="ag-fld"><label>Présentation / contexte</label><textarea name="description" rows="3" placeholder="Présentation de la session"><?= h($d['description']) ?></textarea></div>

      <h2 class="ag-section">🗳️ Ordre du jour & résolutions</h2>
      <p class="ag-help">Chaque résolution sera votée séparément. Tu peux les éditer plus tard.</p>
      <div id="ag-resos">
        <?php foreach ($resos as $r): ?>
        <div class="ag-reso">
          <input type="hidden" name="reso_id[]" value="<?= (int)($r['id'] ?? 0) ?>">
          <input type="text" name="reso_title[]" required value="<?= h($r['title']) ?>" placeholder="Intitulé de la résolution">
          <select name="reso_type[]">
            <?php foreach (['simple','qualifie_2_3','qualifie_3_4','unanime','consultatif'] as $vt): ?>
              <option value="<?= h($vt) ?>" <?= ($r['vote_type'] ?? 'simple')==$vt?'selected':'' ?>><?= h(ag_vote_type_label($vt)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="ag-reso-rm" onclick="this.closest('.ag-reso').remove()">×</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="ag-add-reso" class="ag-btn-ghost">+ Ajouter une résolution</button>

      <h2 class="ag-section">📝 Notes internes</h2>
      <div class="ag-fld"><textarea name="notes_internal" rows="2" placeholder="Visible uniquement par les organisateurs"><?= h($d['notes_internal']) ?></textarea></div>

      <div class="ag-actions">
        <a href="/assemblees" class="ag-btn-ghost">Annuler</a>
        <button type="submit" class="ag-btn-primary"><?= $ag ? 'Enregistrer' : 'Créer l\'assemblée' ?></button>
      </div>
    </form>
  </div>
</main>
<style>
.ag-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.ag-back:hover { color: #10B981; }
.ag-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px; }
.ag-section { font-size: 14px; color: #065F46; margin: 22px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; }
.ag-section:first-of-type { margin-top: 0; }
.ag-help { font-size: 12.5px; color: #6b7280; margin: 0 0 10px; }
.ag-fld { margin-bottom: 14px; }
.ag-fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.ag-fld input, .ag-fld select, .ag-fld textarea { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
.ag-fld input:focus, .ag-fld select:focus, .ag-fld textarea:focus { outline: none; border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
.ag-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.ag-reso { display: grid; grid-template-columns: 1fr 200px auto; gap: 6px; margin-bottom: 6px; align-items: center; }
.ag-reso input, .ag-reso select { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; font-family: inherit; }
.ag-reso-rm { background: transparent; border: 1px solid #e5e7eb; padding: 0 12px; height: 36px; border-radius: 7px; cursor: pointer; color: #DC2626; font-size: 18px; }
.ag-reso-rm:hover { background: #FEF2F2; border-color: #DC2626; }
.ag-btn-primary { padding: 10px 18px; background: #10B981; color: #fff; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.ag-btn-primary:hover { background: #059669; }
.ag-btn-ghost { display: inline-flex; padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; text-decoration: none; border-radius: 8px; font-size: 13px; cursor: pointer; font-family: inherit; }
.ag-btn-ghost:hover { background: #f9fafb; }
.ag-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
@media (max-width: 540px) { .ag-reso { grid-template-columns: 1fr auto; } .ag-reso select { grid-column: 1/-1; } }
</style>
<script>
document.getElementById('ag-add-reso').addEventListener('click', function() {
  const c = document.getElementById('ag-resos');
  const div = document.createElement('div');
  div.className = 'ag-reso';
  div.innerHTML = '<input type="hidden" name="reso_id[]" value="0"><input type="text" name="reso_title[]" required placeholder="Intitulé"><select name="reso_type[]"><option value="simple">Majorité simple</option><option value="qualifie_2_3">2/3</option><option value="qualifie_3_4">3/4</option><option value="unanime">Unanimité</option><option value="consultatif">Consultatif</option></select><button type="button" class="ag-reso-rm" onclick="this.closest(\'.ag-reso\').remove()">×</button>';
  c.appendChild(div);
});
</script>
<?= render_foot() ?>
