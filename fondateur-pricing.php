<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/includes-pricing.php';
require_login();
$user = sa_require_super_admin();

$plans = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY display_order ASC")->fetchAll();
$orgs  = $pdo->query("SELECT id, name, legal_form, vat_subject, siret, plan FROM organizations ORDER BY name LIMIT 200")->fetchAll();
$sim_org_id = (int)($_GET['org'] ?? 0);
$sim_cycle  = ($_GET['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$sim_org    = null;
foreach ($orgs as $o) { if ((int)$o['id'] === $sim_org_id) { $sim_org = $o; break; } }

sa_render_head('Tarifs & TVA');
sa_render_sidebar('fondateur-pricing');
?>
<main class="sa-main">
  <div class="sa-page">
    <div class="sa-pg-head">
      <div>
        <h1>💶 Tarifs &amp; facturation</h1>
        <p>Plans publics, prix HT/TTC selon assujettissement TVA, cycle mensuel ou annuel (-15%).</p>
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
            <option value="0">— Aucune (HT brut) —</option>
            <?php foreach ($orgs as $o): $is_co = pricing_org_is_vat_subject($o); ?>
            <option value="<?= (int)$o['id'] ?>" <?= $sim_org_id===(int)$o['id']?'selected':'' ?>>
              <?= htmlspecialchars($o['name']) ?> · <?= $is_co ? 'Entreprise (HT)' : 'Asso/Particulier (TTC)' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Cycle</label>
          <select name="cycle" onchange="this.form.submit()">
            <option value="monthly" <?= $sim_cycle==='monthly'?'selected':'' ?>>Mensuel</option>
            <option value="yearly"  <?= $sim_cycle==='yearly'?'selected':'' ?>>Annuel (-15%)</option>
          </select>
        </div>
      </form>
      <?php if ($sim_org): ?>
      <div class="ap-sim-info">
        <strong><?= htmlspecialchars($sim_org['name']) ?></strong> ·
        <?php if (pricing_org_is_vat_subject($sim_org)): ?>
          <span class="ap-tag ap-tag-co">Entreprise assujettie TVA · facturation HT</span>
          <?php if ($sim_org['siret']): ?> · SIRET <?= htmlspecialchars($sim_org['siret']) ?><?php endif; ?>
        <?php else: ?>
          <span class="ap-tag ap-tag-asso">Non assujettie · facturation TTC (HT × 1,20)</span>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="ap-sim-info ap-muted">Sélectionne une orga pour voir le tarif réel facturé.</div>
      <?php endif; ?>
    </section>

    <section class="ap-plans-grid">
      <?php foreach ($plans as $plan):
        $p = pricing_compute_for_org($plan, $sim_org, $sim_cycle);
        $is_featured = !empty($plan['is_featured']);
      ?>
      <article class="ap-plan <?= $is_featured ? 'is-featured' : '' ?>">
        <header>
          <h3><?= htmlspecialchars($plan['name']) ?></h3>
          <?php if ($plan['tagline']): ?><div class="ap-plan-tag"><?= htmlspecialchars($plan['tagline']) ?></div><?php endif; ?>
        </header>
        <div class="ap-plan-prices">
          <?php if (!empty($plan['is_custom_quote']) && (int)$plan['price_cents'] === 0): ?>
            <div class="ap-plan-amt">Sur devis</div>
          <?php else: ?>
            <div class="ap-plan-amt-billed">
              <?= pricing_format_eur($p['billed_cents']) ?>
              <span class="ap-plan-unit"><?= $p['label_unit'] ?> <?= pricing_label_period($sim_cycle) ?></span>
            </div>
            <div class="ap-plan-detail">
              <?php if ($p['billed_unit'] === 'ttc'): ?>
                = HT <?= pricing_format_eur($p['ht_cents']) ?> + TVA <?= number_format($p['vat_rate'], 0) ?>% (<?= pricing_format_eur($p['vat_cents']) ?>)
              <?php else: ?>
                soit <?= pricing_format_eur($p['ttc_cents']) ?> TTC (TVA <?= number_format($p['vat_rate'], 0) ?>%)
              <?php endif; ?>
            </div>
            <?php if ($sim_cycle === 'yearly'): $monthly_eq = (int)round($p['billed_cents'] / 12); ?>
            <div class="ap-plan-eq">≈ <?= pricing_format_eur($monthly_eq) ?> <?= $p['label_unit'] ?>/mois</div>
            <?php endif; ?>
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
        <thead><tr><th>Type d'organisation</th><th>Critères</th><th>Facturation</th></tr></thead>
        <tbody>
          <tr><td><strong>Entreprise (TPE/PME/SARL/SAS)</strong></td><td>SIRET renseigné OU <code>vat_subject=1</code></td><td><span class="ap-tag ap-tag-co">HT + TVA récupérable</span></td></tr>
          <tr><td><strong>Asso loi 1901 non assujettie</strong></td><td>RNA renseigné, <code>vat_subject=0</code></td><td><span class="ap-tag ap-tag-asso">TTC (HT × 1,20)</span></td></tr>
          <tr><td><strong>Micro-entreprise franchise</strong></td><td>SIRET, <code>vat_subject=0</code></td><td><span class="ap-tag ap-tag-asso">TTC (HT × 1,20)</span></td></tr>
          <tr><td><strong>Particulier / Bénévole</strong></td><td>Aucun SIRET ni RNA</td><td><span class="ap-tag ap-tag-asso">TTC (HT × 1,20)</span></td></tr>
        </tbody>
      </table>
      <p class="ap-foot">Les assos soumises aux impôts commerciaux doivent activer <code>vat_subject=1</code> manuellement.</p>
    </section>
  </div>
</main>
<style>
.sa-page { max-width: 1200px; margin: 0 auto; padding: 24px 22px; color: #fff; }
.sa-pg-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.sa-pg-head h1 { font-size: 24px; margin: 0 0 4px; color: #fff; }
.sa-pg-head p { color: #94A3B8; margin: 0; font-size: 14px; }
.sa-card { background: #1E293B; border: 1px solid #334155; border-radius: 14px; padding: 20px 22px; margin-bottom: 18px; }
.sa-card h2 { font-size: 14px; margin: 0 0 14px; color: #FCD34D; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; padding-bottom: 8px; border-bottom: 1px solid #334155; }
.ap-sim-form { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
.ap-sim-form label { display: block; font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
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
.ap-plan-tag { font-size: 11px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; }
.ap-plan-prices { margin: 16px 0; padding: 14px 0; border-top: 1px solid #334155; border-bottom: 1px solid #334155; }
.ap-plan-amt { font-size: 24px; font-weight: 800; color: #94A3B8; }
.ap-plan-amt-billed { font-size: 28px; font-weight: 800; color: #fff; line-height: 1.1; }
.ap-plan-unit { font-size: 13px; color: #94A3B8; font-weight: 600; margin-left: 4px; }
.ap-plan-detail { font-size: 12px; color: #94A3B8; margin-top: 4px; }
.ap-plan-eq { font-size: 12px; color: #34D399; font-weight: 600; margin-top: 4px; }
.ap-plan-limits ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.ap-plan-limits li { font-size: 13px; color: #CBD5E1; }
.ap-rules { width: 100%; border-collapse: collapse; }
.ap-rules th { text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94A3B8; border-bottom: 1px solid #334155; }
.ap-rules td { padding: 10px; font-size: 13px; border-bottom: 1px solid #334155; color: #E2E8F0; }
.ap-rules code { font-size: 11.5px; background: #0F172A; padding: 2px 6px; border-radius: 4px; color: #FCD34D; }
.ap-foot { margin: 14px 0 0; font-size: 12px; color: #94A3B8; }
@media (max-width: 720px) { .ap-sim-form { grid-template-columns: 1fr; } }
</style>
