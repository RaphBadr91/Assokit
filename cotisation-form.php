<?php
/**
 * /cotisation-form?id=N - Créer ou éditer une campagne (avec tarifs)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }

$campaign_id = (int)($_GET['id'] ?? 0);
$campaign = null; $tiers = [];
if ($campaign_id > 0) {
    $campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
    if (!$campaign) { http_response_code(404); die('Campagne introuvable.'); }
    $tiers = ck_load_tiers($pdo, $campaign_id);
}
// Tarifs déjà choisis par des paiements : ils ne peuvent pas être retirés (cf. action-cotisation.php).
$tier_uses = [];
if ($campaign_id > 0 && $tiers) {
    $st = $pdo->prepare("SELECT tier_id, COUNT(*) FROM cotisation_payments WHERE campaign_id = ? AND tier_id IS NOT NULL GROUP BY tier_id");
    $st->execute([$campaign_id]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tid, $n]) $tier_uses[(int)$tid] = (int)$n;
}

$d = $campaign ?: ['name'=>'', 'year'=>(int)date('Y'), 'description'=>'', 'currency'=>'EUR', 'opens_at'=>'', 'closes_at'=>'', 'is_active'=>1];
// Choix « ces paiements renouvellent l'adhésion » : proposé une fois la migration L02 passée.
$has_renews = ck_has_renews_setting($pdo);
// Type de campagne (cotisation / don) et paiement en ligne : proposés une fois la migration 2026-10-30 passée
$has_online = ck_has_online_settings($pdo);

// Saisie refusée par action-cotisation.php (nom vide, clôture avant ouverture) :
// on rouvre le même formulaire, prérempli, avec le message.
$form_error = null;
$back = $_SESSION['ck_campaign_form'] ?? null;
unset($_SESSION['ck_campaign_form']);
if (is_array($back) && (int)($back['id'] ?? 0) === $campaign_id) {
    $d = array_merge($d, (array)($back['data'] ?? []));
    $back_tiers = array_values(array_filter((array)($back['tiers'] ?? []), fn($t) => trim((string)($t['name'] ?? '')) !== ''));
    if ($back_tiers) $tiers = $back_tiers;
    $form_error = (string)($back['message'] ?? '');
} elseif (!empty($_GET['error'])) {
    $form_error = ['name' => 'Le nom de la campagne est obligatoire.',
                   'dates' => 'La date de clôture précède la date d’ouverture.'][$_GET['error']] ?? null;
}
if (empty($tiers)) $tiers = [['id'=>0, 'name'=>'Adhésion', 'amount'=>30, 'description'=>'']];

render_head($campaign ? 'Modifier campagne' : 'Nouvelle campagne');
?>
<?= render_sidebar('cotisations') ?>
<main class="main">
  <div class="ck-page" style="max-width:760px;">
    <a href="/cotisations" class="ck-back">← Cotisations</a>
    <h1 class="ck-pg-title"><?= $campaign ? '✏️ Modifier' : '+ Nouvelle' ?> campagne</h1>

    <?php if ($form_error): ?>
    <div class="alert alert-error" style="margin:12px 0;">
      <span><?= ak_icon('alert-tri', 16) ?></span>
      <div><?= h($form_error) ?></div>
    </div>
    <?php endif; ?>

    <form method="POST" action="/action-cotisation" class="ck-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="<?= $campaign ? 'update' : 'create' ?>">
      <?php if ($campaign): ?><input type="hidden" name="id" value="<?= (int)$campaign['id'] ?>"><?php endif; ?>

      <?php if ($has_online): $kd = (string)($d['kind'] ?? ''); ?>
      <div class="ck-fld">
        <label>Type de campagne</label>
        <select name="kind" id="ck-kind">
          <option value=""<?= $kd !== 'don' ? ' selected' : '' ?>>Cotisation (adhésion, participation, billetterie…)</option>
          <option value="don"<?= $kd === 'don' ? ' selected' : '' ?>>Dons (montant libre, ne renouvelle pas l’adhésion)</option>
        </select>
      </div>
      <?php endif; ?>

      <div class="ck-fld">
        <label>Nom de la campagne *</label>
        <input type="text" name="name" required maxlength="120" value="<?= h($d['name']) ?>" placeholder="Ex : Adhésion 2025-2026">
      </div>

      <div class="ck-row">
        <div class="ck-fld"><label>Année</label><input type="number" name="year" min="2020" max="2050" value="<?= h($d['year']) ?>"></div>
        <div class="ck-fld"><label>Devise</label>
          <select name="currency"><option value="EUR" <?= $d['currency']=='EUR'?'selected':'' ?>>EUR (€)</option><option value="USD" <?= $d['currency']=='USD'?'selected':'' ?>>USD ($)</option><option value="CHF" <?= $d['currency']=='CHF'?'selected':'' ?>>CHF</option><option value="GBP" <?= $d['currency']=='GBP'?'selected':'' ?>>GBP (£)</option></select>
        </div>
        <div class="ck-fld"><label>Active</label>
          <select name="is_active"><option value="1" <?= $d['is_active']?'selected':'' ?>>● Oui</option><option value="0" <?= !$d['is_active']?'selected':'' ?>>○ Non</option></select>
        </div>
      </div>

      <div class="ck-row">
        <div class="ck-fld"><label>Ouverture</label><input type="date" name="opens_at" id="ck-opens" value="<?= h($d['opens_at']) ?>"></div>
        <div class="ck-fld"><label>Clôture</label><input type="date" name="closes_at" id="ck-closes" value="<?= h($d['closes_at']) ?>"<?= $d['opens_at'] ? ' min="' . h($d['opens_at']) . '"' : '' ?>></div>
      </div>

      <?php if ($has_renews): $rm = $d['renews_membership'] ?? null; $rm = ($rm === null || $rm === '') ? '' : (string)(int)$rm; ?>
      <div class="ck-fld ck-not-don">
        <label>Ces paiements renouvellent l’adhésion (12 mois)</label>
        <select name="renews_membership">
          <option value=""<?= $rm === '' ? ' selected' : '' ?>>Automatique : oui si le nom contient « adhésion » ou « cotisation »</option>
          <option value="1"<?= $rm === '1' ? ' selected' : '' ?>>Oui : le payeur est à jour de son adhésion</option>
          <option value="0"<?= $rm === '0' ? ' selected' : '' ?>>Non : participation aux frais, billetterie, don…</option>
        </select>
      </div>
      <?php endif; ?>

      <div class="ck-fld">
        <label>Description (usage interne)</label>
        <textarea name="description" rows="3" placeholder="Présentation de la campagne, ce que finance la cotisation, etc."><?= h($d['description']) ?></textarea>
      </div>

      <?php if ($has_online): $op = $d['online_payment'] ?? null; $op = ($op === null || $op === '') ? '' : (string)(int)$op; ?>
      <h2 class="ck-section">💳 Paiement en ligne</h2>
      <p class="ck-help">Le lien de paiement de la campagne fonctionne une fois le compte Stripe de l’association relié (<a href="/encaissement-en-ligne">Paiements en ligne</a>). Le montant est toujours fixé ici : tarif choisi, ou montant libre avec un minimum.</p>
      <div class="ck-row">
        <div class="ck-fld"><label>Paiement en ligne</label>
          <select name="online_payment"><option value=""<?= $op !== '0' ? ' selected' : '' ?>>Proposé (si activé pour l’association)</option><option value="0"<?= $op === '0' ? ' selected' : '' ?>>Non proposé pour cette campagne</option></select>
        </div>
        <div class="ck-fld"><label>Montant minimum (montant libre)</label><input type="text" inputmode="decimal" name="min_amount" value="<?= h($d['min_amount'] ?? '') ?>" placeholder="1,00"></div>
      </div>
      <div class="ck-fld ck-not-don"><label style="text-transform:none;letter-spacing:0;font-weight:500;"><input type="checkbox" name="free_amount" value="1" <?= !empty($d['free_amount']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;"> Proposer aussi un « montant libre » (prix libre, soutien)</label></div>
      <?php endif; ?>

      <div id="ck-tiers-block" class="ck-not-don">
      <h2 class="ck-section">💰 Tarifs proposés</h2>
      <p class="ck-help">Crée un ou plusieurs tarifs (ex : Membre, Étudiant, Famille…). Au minimum 1 tarif requis.</p>
      <div id="ck-tiers">
        <?php foreach ($tiers as $i => $t): ?>
        <div class="ck-tier" data-idx="<?= $i ?>">
          <input type="hidden" name="tier_id[]" value="<?= (int)($t['id'] ?? 0) ?>">
          <input type="text" name="tier_name[]" placeholder="Nom (ex : Adulte)" required value="<?= h($t['name']) ?>">
          <input type="number" name="tier_amount[]" min="0" step="0.01" required value="<?= h($t['amount']) ?>" placeholder="30">
          <input type="text" name="tier_desc[]" placeholder="Description courte" value="<?= h($t['description'] ?? '') ?>">
          <?php if (!empty($tier_uses[(int)($t['id'] ?? 0)])): $n = $tier_uses[(int)$t['id']]; ?>
          <button type="button" class="ck-tier-rm" disabled title="Utilisé par <?= $n ?> paiement<?= $n > 1 ? 's' : '' ?> : ce tarif ne peut pas être retiré">×</button>
          <?php else: ?>
          <button type="button" class="ck-tier-rm" onclick="this.closest('.ck-tier').remove()">×</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="ck-add-tier" class="ck-btn-ghost">+ Ajouter un tarif</button>
      </div>

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
.ck-tier-rm:disabled { color: #D1D5DB; cursor: not-allowed; background: transparent; border-color: #e5e7eb; }
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
// Campagne de dons : ni tarifs ni renouvellement d'adhésion (montant libre sur la page de dons)
(function() {
  const kind = document.getElementById('ck-kind');
  if (!kind) return;
  function sync() {
    const don = kind.value === 'don';
    document.querySelectorAll('.ck-not-don').forEach(function(el) { el.style.display = don ? 'none' : ''; });
    document.querySelectorAll('#ck-tiers input[name="tier_name[]"], #ck-tiers input[name="tier_amount[]"]').forEach(function(i) { i.required = !don; });
  }
  kind.addEventListener('change', sync); sync();
})();
// La clôture ne peut pas précéder l'ouverture (contrôlé aussi côté serveur)
document.getElementById('ck-opens').addEventListener('change', function() {
  document.getElementById('ck-closes').min = this.value || '';
});
</script>
<?= render_foot() ?>
