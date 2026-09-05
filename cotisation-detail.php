<?php
/**
 * /cotisation/{id} - Détail d'une campagne avec liste paiements + ajout manuel
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;

$campaign_id = (int)($_GET['id'] ?? 0);
$campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
if (!$campaign) { http_response_code(404); die('Campagne introuvable.'); }

$tiers = ck_load_tiers($pdo, $campaign_id);
$stats = ck_campaign_stats($pdo, $campaign_id);
$payments = ck_load_payments($pdo, $campaign_id);
$pay_settings = ck_load_org_payment($pdo, $org_id);

// Charge adhérents pour le select
$adherents = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE org_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name LIMIT 500");
    $stmt->execute([$org_id]);
    $adherents = $stmt->fetchAll();
} catch (Throwable $e) {}

render_head($campaign['name']);
?>
<?= render_sidebar('cotisations') ?>
<main class="main">
  <div class="ck-page" style="max-width:1100px;">
    <a href="/cotisations" class="ck-back">← Cotisations</a>

    <div class="ck-camp-hero">
      <div>
        <h1 class="ck-pg-title"><?= h($campaign['name']) ?> <span class="ck-camp-status <?= $campaign['is_active'] ? 'active' : 'paused' ?>"><?= $campaign['is_active'] ? '● Active' : '○ Pause' ?></span></h1>
        <?php if ($campaign['description']): ?><p class="ck-pg-sub"><?= nl2br(h($campaign['description'])) ?></p><?php endif; ?>
        <?php if ($campaign['closes_at']): ?><div class="ck-deadline">⏰ Clôture le <?= date('d/m/Y', strtotime($campaign['closes_at'])) ?></div><?php endif; ?>
      </div>
      <?php if ($can_manage): ?>
      <div class="ck-actions-row">
        <a href="/cotisation-form?id=<?= (int)$campaign['id'] ?>" class="ck-btn-ghost">✏️ Modifier</a>
      </div>
      <?php endif; ?>
    </div>

    <div class="ck-kpis">
      <div class="ck-kpi"><div class="ck-kpi-lbl">Encaissé</div><div class="ck-kpi-val ck-green"><?= number_format($stats['amount_paid'], 0, ',', ' ') ?> €</div><div class="ck-kpi-sub"><?= $stats['count_paid'] ?> paiements</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">En attente</div><div class="ck-kpi-val ck-amber"><?= number_format($stats['amount_pending'], 0, ',', ' ') ?> €</div><div class="ck-kpi-sub"><?= $stats['count_pending'] ?> en attente</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Tarifs proposés</div><div class="ck-kpi-val"><?= count($tiers) ?></div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Total enregistré</div><div class="ck-kpi-val"><?= count($payments) ?></div></div>
    </div>

    <!-- Tarifs -->
    <?php if (!empty($tiers)): ?>
    <div class="ck-tiers-row">
      <?php foreach ($tiers as $t): ?>
      <div class="ck-tier-card">
        <div class="ck-tier-name"><?= h($t['name']) ?></div>
        <div class="ck-tier-amount"><?= number_format((float)$t['amount'], 2, ',', ' ') ?> €</div>
        <?php if ($t['description']): ?><div class="ck-tier-desc"><?= h($t['description']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
    <!-- Saisie paiement manuel -->
    <details class="ck-add-pay">
      <summary>➕ Enregistrer un paiement manuel (chèque, virement, espèces…)</summary>
      <form method="POST" action="/action-cotisation-payment" class="ck-pay-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
        <input type="hidden" name="action" value="create">

        <div class="ck-row">
          <div class="ck-fld">
            <label>Adhérent</label>
            <select name="adherent_id" id="ck-adh-select">
              <option value="">— Sans adhérent (saisie libre) —</option>
              <?php foreach ($adherents as $a): ?>
                <option value="<?= (int)$a['id'] ?>" data-name="<?= h(trim($a['first_name'].' '.$a['last_name'])) ?>" data-email="<?= h($a['email']) ?>"><?= h(trim($a['first_name'].' '.$a['last_name'])) ?><?= $a['email'] ? ' · ' . h($a['email']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ck-fld">
            <label>Tarif</label>
            <select name="tier_id" id="ck-tier-select">
              <option value="">— Aucun tarif (montant libre) —</option>
              <?php foreach ($tiers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" data-amount="<?= h($t['amount']) ?>"><?= h($t['name']) ?> — <?= number_format((float)$t['amount'], 2, ',', ' ') ?> €</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Nom du payeur *</label><input type="text" name="payer_name" id="ck-payer-name" required maxlength="150"></div>
          <div class="ck-fld"><label>Email payeur</label><input type="email" name="payer_email" id="ck-payer-email" maxlength="200"></div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Montant *</label><input type="number" name="amount" id="ck-amount" required min="0" step="0.01"></div>
          <div class="ck-fld">
            <label>Méthode</label>
            <select name="payment_method">
              <option value="bank">🏦 Virement</option>
              <option value="check">✉️ Chèque</option>
              <option value="cash">💶 Espèces</option>
              <option value="other">➕ Autre</option>
            </select>
          </div>
          <div class="ck-fld">
            <label>Statut</label>
            <select name="status"><option value="paid" selected>● Payé</option><option value="pending">○ En attente</option></select>
          </div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Référence (n° chèque, virement…)</label><input type="text" name="reference" maxlength="100"></div>
          <div class="ck-fld"><label>Date paiement</label><input type="date" name="paid_at" value="<?= date('Y-m-d') ?>"></div>
        </div>

        <div class="ck-fld"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>

        <button type="submit" class="ck-btn-primary">Enregistrer le paiement</button>
      </form>
    </details>
    <?php endif; ?>

    <!-- Liste paiements -->
    <h2 class="ck-section">📋 Paiements (<?= count($payments) ?>)</h2>
    <?php if (empty($payments)): ?>
    <div class="ck-empty-mini">Aucun paiement pour le moment.</div>
    <?php else: ?>
    <div class="ck-pay-list">
      <table class="ck-pay-tbl">
        <thead><tr><th>Payeur</th><th>Tarif</th><th>Montant</th><th>Méthode</th><th>Statut</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p):
          $b = ck_status_badge($p['status']);
          $name = $p['adherent_id'] ? trim($p['a_first'].' '.$p['a_last']) : $p['payer_name'];
        ?>
        <tr>
          <td>
            <div class="ck-pay-name"><?= h($name) ?></div>
            <?php if ($p['payer_email']): ?><div class="ck-pay-email"><?= h($p['payer_email']) ?></div><?php endif; ?>
          </td>
          <td><?= h($p['tier_name'] ?? '—') ?></td>
          <td class="ck-pay-amount"><?= number_format((float)$p['amount'], 2, ',', ' ') ?> €</td>
          <td><?= ck_method_label($p['payment_method']) ?></td>
          <td><span class="ck-badge" style="background:<?= $b[2] ?>;color:<?= $b[1] ?>;"><?= h($b[0]) ?></span></td>
          <td class="ck-pay-date"><?= $p['paid_at'] ? date('d/m/Y', strtotime($p['paid_at'])) : date('d/m/Y', strtotime($p['created_at'])) ?></td>
          <td>
            <?php if ($can_manage && $p['status'] === 'pending'): ?>
            <form method="POST" action="/action-cotisation-payment" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="ck-tbl-btn">✓ Marquer payé</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Stripe placeholder -->
    <div class="ck-stripe-card">
      <div class="ck-stripe-head">
        <span class="ck-stripe-icon">💳</span>
        <div>
          <div class="ck-stripe-title">Paiement en ligne par carte</div>
          <div class="ck-stripe-sub"><?= $pay_settings['stripe_enabled'] ? '✅ Activé · Mode ' . h($pay_settings['stripe_mode']) : '⏳ Stripe sera activé dès que ta société sera créée' ?></div>
        </div>
        <?php if ($is_admin): ?><a href="/admin-paiements" class="ck-btn-ghost">Configurer</a><?php endif; ?>
      </div>
    </div>
  </div>
</main>
<style>
.ck-camp-hero { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px; flex-wrap: wrap; }
.ck-camp-hero .ck-pg-title { display: inline-flex; align-items: center; gap: 10px; }
.ck-deadline { font-size: 12.5px; color: #92400E; background: #FEF3C7; padding: 4px 10px; border-radius: 6px; display: inline-block; margin-top: 6px; }
.ck-actions-row { display: flex; gap: 8px; }
.ck-tiers-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-bottom: 22px; }
.ck-tier-card { background: linear-gradient(135deg, #fff, #F0FDF4); border: 1px solid #D1FAE5; border-radius: 10px; padding: 14px; text-align: center; }
.ck-tier-name { font-size: 13px; font-weight: 600; color: #065F46; }
.ck-tier-amount { font-size: 22px; font-weight: 700; color: #111827; margin: 4px 0; }
.ck-tier-desc { font-size: 11.5px; color: #6b7280; }
.ck-add-pay { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; margin-bottom: 22px; }
.ck-add-pay summary { cursor: pointer; font-weight: 600; color: #065F46; font-size: 14px; }
.ck-add-pay[open] summary { margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f3f4f6; }
.ck-pay-form { padding-top: 4px; }
.ck-section { font-size: 14px; color: #065F46; margin: 16px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; }
.ck-empty-mini { background: #fff; border: 1px dashed #e5e7eb; border-radius: 10px; padding: 24px; text-align: center; color: #6b7280; font-size: 13px; }
.ck-pay-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: auto; }
.ck-pay-tbl { width: 100%; border-collapse: collapse; }
.ck-pay-tbl th { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; text-align: left; padding: 10px 12px; border-bottom: 1px solid #f3f4f6; background: #fafbfc; font-weight: 700; }
.ck-pay-tbl td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.ck-pay-tbl tr:last-child td { border-bottom: 0; }
.ck-pay-name { font-weight: 600; color: #111827; }
.ck-pay-email { font-size: 11.5px; color: #6b7280; }
.ck-pay-amount { font-weight: 700; color: #10B981; white-space: nowrap; }
.ck-pay-date { color: #6b7280; white-space: nowrap; }
.ck-badge { font-size: 11px; padding: 3px 8px; border-radius: 999px; font-weight: 600; }
.ck-tbl-btn { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.ck-tbl-btn:hover { background: #D1FAE5; }
.ck-stripe-card { background: linear-gradient(135deg, #fafbff, #f0fdf4); border: 1px solid #d1fae5; border-radius: 12px; padding: 16px 20px; margin-top: 22px; }
.ck-stripe-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.ck-stripe-icon { font-size: 28px; }
.ck-stripe-title { font-size: 14px; font-weight: 700; color: #111827; }
.ck-stripe-sub { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
.ck-stripe-head .ck-btn-ghost { margin-left: auto; }
.ck-kpi-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
@media (max-width: 540px) { .ck-pay-tbl thead { display: none; } .ck-pay-tbl, .ck-pay-tbl tbody, .ck-pay-tbl tr, .ck-pay-tbl td { display: block; width: 100%; box-sizing: border-box; } .ck-pay-tbl tr { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; } .ck-pay-tbl td { padding: 3px 0; border: 0; } }
</style>
<script>
// Auto-fill payeur depuis adhérent / tarif
(function() {
  const adh = document.getElementById('ck-adh-select');
  const tier = document.getElementById('ck-tier-select');
  const name = document.getElementById('ck-payer-name');
  const email = document.getElementById('ck-payer-email');
  const amount = document.getElementById('ck-amount');
  if (adh) adh.addEventListener('change', function() {
    const opt = adh.options[adh.selectedIndex];
    if (opt.value && !name.value) name.value = opt.dataset.name || '';
    if (opt.value && !email.value) email.value = opt.dataset.email || '';
  });
  if (tier) tier.addEventListener('change', function() {
    const opt = tier.options[tier.selectedIndex];
    if (opt.value && opt.dataset.amount && !amount.value) amount.value = opt.dataset.amount;
  });
})();
</script>
<?= render_foot() ?>
