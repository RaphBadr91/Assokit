<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/includes-pricing.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
require_login();
$user = sa_require_super_admin();
// Tarifs, plans et Stripe : Fondateur uniquement (section Pilotage Fondateur)
sa_require_capability('can_manage_billing_config');

$plans = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY display_order ASC")->fetchAll();
$orgs  = $pdo->query("SELECT id, name, legal_form, vat_subject, siret, plan FROM organizations ORDER BY name LIMIT 200")->fetchAll();
$sim_org_id = (int)($_GET['org'] ?? 0);
$sim_cycle  = 'monthly';   // abonnement mensuel uniquement : la formule annuelle (-15 %) n'est plus vendue
$sim_org    = null;
foreach ($orgs as $o) { if ((int)$o['id'] === $sim_org_id) { $sim_org = $o; break; } }
// Plan réel de l'organisation simulée (même vérité que la fiche association : un essai est à 0 €)
$sim_state = $sim_org ? ak_org_plan_state($pdo, (int)$sim_org['id']) : null;
// TVA réellement appliquée : celle de la plateforme (ak_vat_applicable_rate, réglage Stripe), la même
// pour toutes les structures, sur la page de paiement, dans Stripe et sur « Mon abonnement ». Le prix
// d'un plan (asso_plans.price_cents) est HT. Paiement sur facture : TVA de la société émettrice
// (ak_manual_invoice_vat_rate, cron-job-renouvellements.php).
$vat_rate = ak_vat_rate_for_display($pdo);
$invoice_vat_rate = ak_manual_invoice_vat_rate($pdo);
$vat_mismatch = $vat_rate !== null && (($vat_rate > 0) !== ($invoice_vat_rate !== null));
$sim_pay_mode = '';
if ($sim_org) {
    try {
        $pm = $pdo->prepare("SELECT s.payment_mode FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
        $pm->execute([(int)$sim_org['id']]);
        $sim_pay_mode = (string)$pm->fetchColumn();
    } catch (Throwable $e) {}
}

sa_render_head('Tarifs & TVA');
sa_render_sidebar('fondateur-pricing');
?>
<main class="sa-main">
  <div class="sa-page">
    <div class="sa-pg-head">
      <div>
        <h1>💶 Tarifs &amp; facturation</h1>
        <p>Plans publics, prix HT + TVA de la plateforme = TTC, abonnement mensuel sans engagement.</p>
      </div>
      <div style="display:flex;gap:8px;">
        <a href="/fondateur-plans" class="sa-btn sa-btn-ghost">⚙️ Modifier les plans</a>
        <a href="/fondateur-stripe-config" class="sa-btn sa-btn-violet">💳 Stripe</a>
      </div>
    </div>

    <section class="sa-card">
      <h2>🧮 Simulateur de facturation</h2>
      <form method="GET" class="ap-sim-form">
        <div>
          <label>Organisation</label>
          <select name="org" onchange="this.form.submit()">
            <option value="0">— Aucune —</option>
            <?php foreach ($orgs as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= $sim_org_id===(int)$o['id']?'selected':'' ?>>
              <?= htmlspecialchars($o['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <?php if ($sim_org): ?>
      <div class="ap-sim-info">
        <strong><?= htmlspecialchars($sim_org['name']) ?></strong> ·
        Plan actuel : <strong><?= h($sim_state['plan_label']) ?></strong> (<?= h($sim_state['badge']) ?><?= ($sim_state['is_trial'] || $sim_state['trial_expired']) ? ' · 0 €' : '' ?>) ·
        <?php if ($sim_pay_mode === 'stripe'): ?>
          <span class="ap-tag ap-tag-co">Carte bancaire (Stripe)</span>
        <?php elseif ($sim_pay_mode === 'free_grant'): ?>
          <span class="ap-tag ap-tag-asso">Offert</span>
        <?php else: ?>
          <span class="ap-tag ap-tag-asso">Paiement sur facture</span>
        <?php endif; ?>
        <?php if (!($sim_state['is_trial'] || $sim_state['trial_expired']) && (int)$sim_state['price_cents'] > 0 && $sim_pay_mode !== 'free_grant'): ?>
          · facturé <strong><?= h(ak_price_split((int)$sim_state['price_cents'], $sim_pay_mode === 'stripe' ? $vat_rate : ($invoice_vat_rate ?? 0.0))['label']) ?></strong>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="ap-sim-info ap-muted">Sélectionne une orga pour voir son plan et le montant facturé.</div>
      <?php endif; ?>
      <?php if ($vat_mismatch): ?>
      <div class="ap-sim-info" style="border:1px solid #92400E;">⚠️ TVA incohérente : Stripe <?= $vat_rate > 0 ? 'ajoute ' . h(rtrim(rtrim(number_format($vat_rate, 2, ',', ''), '0'), ',')) . ' % de TVA' : 'ne facture pas de TVA' ?>, alors que la société est déclarée <?= $invoice_vat_rate !== null ? 'assujettie' : 'non assujettie' ?> (factures sur facture <?= $invoice_vat_rate !== null ? 'avec TVA' : 'sans TVA' ?>). Alignez <a href="/fondateur-stripe-config" style="color:#FCD34D;">Stripe › TVA</a> et les paramètres de la société.</div>
      <?php endif; ?>
    </section>

    <section class="ap-plans-grid">
      <?php foreach ($plans as $plan):
        $p = ak_price_split((int)$plan['price_cents'], $vat_rate);
        $is_featured = !empty($plan['is_featured']);
      ?>
      <article class="ap-plan <?= $is_featured ? 'is-featured' : '' ?>">
        <header>
          <h3><?= htmlspecialchars($plan['name']) ?></h3>
          <?php if ($plan['tagline']): ?><div class="ap-plan-tag"><?= htmlspecialchars($plan['tagline']) ?></div><?php endif; ?>
        </header>
        <div class="ap-plan-prices">
          <?php if ((!empty($plan['is_custom_quote']) || $plan['slug'] === 'sur-mesure') && (int)$plan['price_cents'] === 0): ?>
            <div class="ap-plan-amt">Sur devis</div>
          <?php elseif ((int)$plan['price_cents'] <= 0): ?>
            <div class="ap-plan-amt">Gratuit</div>
          <?php else: ?>
            <div class="ap-plan-amt-billed">
              <?= h($p['ht']) ?>
              <span class="ap-plan-unit"><?= $p['has_vat'] || !$p['vat_known'] ? 'HT ' : '' ?><?= pricing_label_period($sim_cycle) ?></span>
            </div>
            <div class="ap-plan-detail">
              <?php if ($p['has_vat']): ?>
                + TVA <?= h($p['rate_label']) ?> (<?= h($p['vat']) ?>) = <?= h($p['ttc']) ?> TTC
              <?php elseif ($p['vat_known']): ?>
                TVA non applicable, art. 293 B du CGI
              <?php else: ?>
                TVA de la plateforme indisponible
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="ap-plan-limits">
          <ul>
            <?php if ($plan['limit_adherents']): ?><li>👥 <?= (int)$plan['limit_adherents'] ?> adhérents</li><?php endif; ?>
            <?php if ($plan['limit_users']): ?><li>👤 <?= (int)$plan['limit_users'] ?> utilisateurs</li><?php endif; ?>
            <?php if ($plan['limit_invoices_total']): ?><li>📄 <?= (int)$plan['limit_invoices_total'] ?> factures/mois</li><?php endif; ?>
            <?php if ($plan['limit_ai_text_per_month']): ?><li>✨ <?= (int)$plan['limit_ai_text_per_month'] ?> IA/mois</li><?php endif; ?>
            <?php if ($plan['limit_emails_per_month']): ?><li>📧 <?= number_format((int)$plan['limit_emails_per_month'], 0, ',', ' ') ?> emails/mois</li><?php endif; ?>
            <?php if (!$plan['limit_adherents'] && !$plan['limit_users']): ?><li>♾️ Illimité</li><?php endif; ?>
          </ul>
        </div>
      </article>
      <?php endforeach; ?>
    </section>

    <section class="sa-card">
      <h2>📋 Règles d'application TVA</h2>
      <table class="ap-rules">
        <thead><tr><th>Paiement</th><th>Montant facturé</th><th>TVA</th></tr></thead>
        <tbody>
          <tr><td><strong>Carte bancaire (Stripe)</strong></td><td>Prix HT du plan + TVA = TTC, pour toutes les structures</td><td><span class="ap-tag ap-tag-co"><?= $vat_rate === null ? '—' : ($vat_rate > 0 ? h(rtrim(rtrim(number_format($vat_rate, 2, ',', ''), '0'), ',')) . ' %' : 'non applicable') ?></span> <a href="/fondateur-stripe-config" style="color:#FCD34D;font-size:12px;">Stripe › TVA</a></td></tr>
          <tr><td><strong>Paiement sur facture</strong></td><td>Prix HT du plan + TVA = TTC (renouvellement mensuel automatique)</td><td><span class="ap-tag ap-tag-asso"><?= $invoice_vat_rate !== null ? h(rtrim(rtrim(number_format($invoice_vat_rate, 2, ',', ''), '0'), ',')) . ' %' : 'non applicable (art. 293 B du CGI)' ?></span> société <?= $invoice_vat_rate !== null ? 'assujettie' : 'non assujettie' ?></td></tr>
        </tbody>
      </table>
      <p class="ap-foot">Le prix d’un plan est saisi HT (<a href="/fondateur-plans" style="color:#FCD34D;">Modifier les plans</a>). Le client voit le même montant sur la page Tarifs, la page de paiement et « Mon abonnement ».</p>
    </section>
  </div>
</main>
<style>
.sa-page { max-width: 1200px; margin: 0 auto; padding: 24px 22px; color: #fff; }
.sa-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.sa-pg-head h1 { font-size: 24px; margin: 0 0 4px; color: #fff; }
.sa-pg-head p { color: #64748B; margin: 0; font-size: 14px; }
.sa-card { background: #1E293B; border: 1px solid #334155; border-radius: 14px; padding: 20px 22px; margin-bottom: 18px; }
.sa-card h2 { font-size: 14px; margin: 0 0 14px; color: #FCD34D; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; padding-bottom: 8px; border-bottom: 1px solid #334155; }
.ap-sim-form { display: grid; grid-template-columns: minmax(0, 560px); gap: 12px; }
.ap-sim-form label { display: block; font-size: 11px; color: #64748B; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.ap-sim-form select { width: 100%; padding: 9px 12px; border: 1px solid #475569; background: #0F172A; color: #fff; border-radius: 8px; font-size: 14px; font-family: inherit; }
.ap-sim-info { margin-top: 14px; padding: 10px 14px; background: #0F172A; border-radius: 8px; font-size: 13px; color: #E2E8F0; }
.ap-muted { color: #64748B; }
.ap-tag { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.ap-tag-co { background: #1E40AF; color: #DBEAFE; }
.ap-tag-asso { background: #92400E; color: #FEF3C7; }
.ap-plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-bottom: 18px; }
.ap-plan { background: #1E293B; border: 1px solid #334155; border-radius: 14px; padding: 20px 22px; }
.ap-plan.is-featured { border: 2px solid #FCD34D; box-shadow: 0 8px 24px rgba(252,211,77,0.15); }
.ap-plan h3 { margin: 0 0 4px; font-size: 18px; color: #fff; }
.ap-plan-tag { font-size: 11px; color: #64748B; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; }
.ap-plan-prices { margin: 16px 0; padding: 14px 0; border-top: 1px solid #334155; border-bottom: 1px solid #334155; }
.ap-plan-amt { font-size: 24px; font-weight: 800; color: #64748B; }
.ap-plan-amt-billed { font-size: 28px; font-weight: 800; color: #fff; line-height: 1.1; }
.ap-plan-unit { font-size: 13px; color: #64748B; font-weight: 600; margin-left: 4px; }
.ap-plan-detail { font-size: 12px; color: #64748B; margin-top: 4px; }
.ap-plan-eq { font-size: 12px; color: #34D399; font-weight: 600; margin-top: 4px; }
.ap-plan-limits ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.ap-plan-limits li { font-size: 13px; color: #CBD5E1; }
.ap-rules { width: 100%; border-collapse: collapse; }
.ap-rules th { text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748B; border-bottom: 1px solid #334155; }
.ap-rules td { padding: 10px; font-size: 13px; border-bottom: 1px solid #334155; color: #E2E8F0; }
.ap-rules code { font-size: 11.5px; background: #0F172A; padding: 2px 6px; border-radius: 4px; color: #FCD34D; }
.ap-foot { margin: 14px 0 0; font-size: 12px; color: #64748B; }
@media (max-width: 720px) { .ap-sim-form { grid-template-columns: 1fr; } }
</style>
