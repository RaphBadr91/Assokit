<?php
/**
 * /cotisation-form?id=N - Créer ou éditer une campagne (avec tarifs)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }

$campaign_id = (int)($_GET['id'] ?? 0);
$campaign = null; $tiers = [];
if ($campaign_id > 0) {
    $campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
    if (!$campaign) { http_response_code(404); die('Campagne introuvable.'); }
    $tiers = ck_load_tiers($pdo, $campaign_id);
}

$d = $campaign ?: ['name'=>'', 'year'=>(int)date('Y'), 'description'=>'', 'currency'=>'EUR', 'opens_at'=>'', 'closes_at'=>'', 'is_active'=>1];
if (empty($tiers)) $tiers = [['id'=>0, 'name'=>'Adhésion', 'amount'=>30, 'description'=>'']];

render_head($campaign ? 'Modifier campagne' : 'Nouvelle campagne');
?>
<?= render_sidebar('cotisations') ?>
<main class="main">
  <div class="ck-page" style="max-width:760px;">
    <a href="/cotisations" class="ck-back">← Cotisations</a>
    <h1 class="ck-pg-title"><?= $campaign ? '✏️ Modifier' : '+ Nouvelle' ?> campagne</h1>

    <form method="POST" action="/action-cotisation" class="ck-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="<?= $campaign ? 'update' : 'create' ?>">
      <?php if ($campaign): ?><input type="hidden" name="id" value="<?= (int)$campaign['id'] ?>"><?php endif; ?>

      <div class="ck-fld">
        <label>Nom de la campagne *</label>
        <input type="text" name="name" required maxlength="120" value="<?= h($d['name']) ?>" placeholder="Ex : Adhésion 2025-2026">
      </div>

      <div class="ck-row">
        <div class="ck-fld"><label>Année</label><input type="number" name="year" min="2020" max="2050" value="<?= h($d['year']) ?>"></div>
        <div class="ck-fld"><label>Devise</label>
          <select name="currency"><option value="EUR" <?= $d['currency']=='EUR'?'selected':'' ?>>EUR (€)</option><option value="USD" <?= $d['currency']=='USD'?'selected':'' ?>>USD ($)</option><option value="CHF" <?= $d['currency']=='CHF'?'selected':'' ?>>CHF</option></select>
        </div>
        <div class="ck-fld"><label>Active</label>
          <select name="is_active"><option value="1" <?= $d['is_active']?'selected':'' ?>>● Oui</option><option value="0" <?= !$d['is_active']?'selected':'' ?>>○ Non</option></select>
        </div>
      </div>

      <div class="ck-row">
        <div class="ck-fld"><label>Ouverture</label><input type="date" name="opens_at" value="<?= h($d['opens_at']) ?>"></div>
        <div class="ck-fld"><label>Clôture</label><input type="date" name="closes_at" value="<?= h($d['closes_at']) ?>"></div>
      </div>

      <div class="ck-fld">
        <label>Description (visible sur la page de paiement)</label>
        <textarea name="description" rows="3" placeholder="Présentation de la campagne, ce que finance la cotisation, etc."><?= h($d['description']) ?></textarea>
      </div>

      <h2 class="ck-section">💰 Tarifs proposés</h2>
      <p class="ck-help">Crée un ou plusieurs tarifs (ex : Membre, Étudiant, Famille…). Au minimum 1 tarif requis.</p>
      <div id="ck-tiers">
        <?php foreach ($tiers as $i => $t): ?>
        <div class="ck-tier" data-idx="<?= $i ?>">
          <input type="hidden" name="tier_id[]" value="<?= (int)($t['id'] ?? 0) ?>">
          <input type="text" name="tier_name[]" placeholder="Nom (ex : Adulte)" required value="<?= h($t['name']) ?>">
          <input type="number" name="tier_amount[]" min="0" step="0.01" required value="<?= h($t['amount']) ?>" placeholder="30">
          <input type="text" name="tier_desc[]" placeholder="Description courte" value="<?= h($t['description'] ?? '') ?>">
          <button type="button" class="ck-tier-rm" onclick="this.closest('.ck-tier').remove()">×</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="ck-add-tier" class="ck-btn-ghost">+ Ajouter un tarif</button>

      <div class="ck-actions">
        <a href="/cotisations" class="ck-btn-ghost">Annuler</a>
        <button type="submit" class="ck-btn-primary"><?= $campaign ? 'Enregistrer' : 'Créer la campagne' ?></button>
      </div>
    </form>
  </div>
</main>
<style>
.ck-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.ck-back:hover { color: #10B981; }
.ck-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px; }
.ck-fld { margin-bottom: 16px; }
.ck-fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.ck-fld input, .ck-fld select, .ck-fld textarea { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
.ck-fld input:focus, .ck-fld select:focus, .ck-fld textarea:focus { outline: none; border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
.ck-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
.ck-section { font-size: 14px; color: #065F46; margin: 24px 0 6px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; }
.ck-help { font-size: 12.5px; color: #6b7280; margin: 0 0 12px; }
.ck-tier { display: grid; grid-template-columns: 1.6fr 0.7fr 1.4fr auto; gap: 8px; margin-bottom: 8px; align-items: stretch; }
.ck-tier input { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; font-family: inherit; }
.ck-tier-rm { background: transparent; border: 1px solid #e5e7eb; padding: 0 12px; border-radius: 7px; cursor: pointer; color: #DC2626; font-size: 18px; }
.ck-tier-rm:hover { background: #FEF2F2; border-color: #DC2626; }
.ck-btn-ghost { display: inline-flex; align-items: center; padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; }
.ck-btn-ghost:hover { background: #f9fafb; }
.ck-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
@media (max-width: 540px) { .ck-tier { grid-template-columns: 1fr 1fr; } }
</style>
<script>
document.getElementById('ck-add-tier').addEventListener('click', function() {
  const c = document.getElementById('ck-tiers');
  const div = document.createElement('div');
  div.className = 'ck-tier';
  div.innerHTML = '<input type="hidden" name="tier_id[]" value="0"><input type="text" name="tier_name[]" placeholder="Nom" required><input type="number" name="tier_amount[]" min="0" step="0.01" required placeholder="30"><input type="text" name="tier_desc[]" placeholder="Description"><button type="button" class="ck-tier-rm" onclick="this.closest(\'.ck-tier\').remove()">×</button>';
  c.appendChild(div);
});
</script>
<?= render_foot() ?>
