<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/includes-pricing.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
@require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/stripe-plan-change-helpers.php';
require_login();
$user = sa_require_super_admin();
// Tarifs, plans et Stripe : Fondateur uniquement (section Pilotage Fondateur)
sa_require_capability('can_manage_billing_config');

// Grille 2026 : formules vendues (visibles), puis formules techniques jamais vendues (offre fondateur,
// essai, Démarrage, lignes Sur-mesure propres à un client), avec le nombre d'abonnés de chacune
$all_plans = $pdo->query("SELECT * FROM asso_plans ORDER BY is_visible DESC, display_order ASC, price_cents ASC, id ASC")->fetchAll();
$plans = array_values(array_filter($all_plans, fn($p) => !empty($p['is_visible'])));
$hidden_plans = array_values(array_filter($all_plans, fn($p) => empty($p['is_visible'])));
$plan_subs = [];
try {
    $plan_subs = $pdo->query("SELECT s.plan_id, COUNT(*) FROM organizations o
        JOIN asso_subscriptions s ON s.id = (SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id WHERE s2.org_id = o.id ORDER BY " . ak_sub_order('s2') . " LIMIT 1)
        WHERE o.deleted_at IS NULL AND COALESCE(o.slug, '') NOT LIKE 'demo%' AND s.status IN ('active', 'trial', 'overdue')
        GROUP BY s.plan_id")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {}
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
        <p>Formules Essentiel, Pro, Équipe et Sur-mesure : prix HT (TVA de la plateforme en sus si elle s’applique), abonnement mensuel sans engagement.</p>
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
        $is_featured = !empty($plan['is_featured']);
        $is_quote = (!empty($plan['is_custom_quote']) || $plan['slug'] === 'sur-mesure') && (int)$plan['price_cents'] === 0;
        $badge = $is_featured && function_exists('ak_plan_badge') ? ak_plan_badge($pdo, (string)$plan['slug']) : null;
        $summary = function_exists('ak_plan_public_summary') ? ak_plan_public_summary($plan) : [];
        $n_subs = (int)($plan_subs[$plan['id']] ?? 0);
      ?>
      <article class="ap-plan <?= $is_featured ? 'is-featured' : '' ?>">
        <header>
          <h3><?= h(ak_plan_label((string)$plan['slug'], $plan['name'] ?? null)) ?><?php if ($badge): ?> <span class="ap-tag ap-tag-badge"><?= h($badge) ?></span><?php endif; ?></h3>
          <?php if (!empty($plan['tagline'])): ?><div class="ap-plan-tag"><?= h($plan['tagline']) ?></div><?php endif; ?>
        </header>
        <div class="ap-plan-prices">
          <?php if ($is_quote): ?>
            <div class="ap-plan-amt"><?= h(defined('AK_PRICE_MASK') ? AK_PRICE_MASK : "****,**\u{00A0}€\u{00A0}HT/mois") ?></div>
            <div class="ap-plan-detail">Sur devis — nous contacter</div>
          <?php elseif ((int)$plan['price_cents'] <= 0): ?>
            <div class="ap-plan-amt">0 €</div>
          <?php else: $p = ak_price_split((int)$plan['price_cents'], $vat_rate); ?>
            <div class="ap-plan-amt-billed"><?= h($p['ht']) ?> <span class="ap-plan-unit">HT<?= pricing_label_period($sim_cycle) ?></span></div>
            <div class="ap-plan-detail">
              <?php if ($p['has_vat']): ?>+ TVA <?= h($p['rate_label']) ?> (<?= h($p['vat']) ?>) = <?= h($p['ttc']) ?> TTC
              <?php elseif ($p['vat_known']): ?><?= h(ak_pc_vat_note(0.0)) ?>
              <?php else: ?>TVA de la plateforme indisponible<?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="ap-plan-limits">
          <ul>
            <?php foreach ($summary as $line): ?><li>✓ <?= h($line) ?></li><?php endforeach; ?>
            <li class="ap-muted-li">🧾 <?= $n_subs ?> association<?= $n_subs > 1 ? 's' : '' ?> sur cette formule (hors démos)</li>
            <?php if (!$is_quote && (int)$plan['price_cents'] > 0): ?>
              <li class="ap-muted-li">💳 Prix Stripe : <?= !empty($plan['stripe_price_id_monthly']) ? '<code>' . h($plan['stripe_price_id_monthly']) . '</code>' : 'pas encore créé (« Configurer Stripe automatiquement »)' ?></li>
            <?php endif; ?>
          </ul>
        </div>
      </article>
      <?php endforeach; ?>
    </section>

    <?php if ($hidden_plans): ?>
    <section class="sa-card">
      <h2>🔒 Formules techniques (jamais vendues, jamais sur la page Tarifs)</h2>
      <table class="ap-rules">
        <thead><tr><th>Formule</th><th>Rôle</th><th>Prix</th><th>Volumes</th><th>Associations</th></tr></thead>
        <tbody>
        <?php foreach ($hidden_plans as $hp):
            $role = $hp['slug'] === 'fondateur' ? 'Clients de la première heure : fonctions actuelles, prix garanti 12 mois (cli-rattacher-fondateur.php). Prélevée au prix Stripe de Pro.'
                : ($hp['slug'] === 'pro-essai' || !empty($hp['is_trial']) ? 'Essai gratuit de 14 jours (fonctions Pro)'
                : ($hp['slug'] === 'demarrage' ? 'Accès limité : fin d’essai non convertie, résiliation, impayé'
                : (!empty($hp['is_custom_quote']) ? 'Sur-mesure propre à un client' : 'Ancienne formule, masquée'))); ?>
          <tr>
            <td><strong><?= h(ak_plan_label((string)$hp['slug'], $hp['name'] ?? null)) ?></strong> <code><?= h($hp['slug']) ?></code></td>
            <td><?= h($role) ?></td>
            <td style="white-space:nowrap;"><?= (int)$hp['price_cents'] > 0 ? h(ak_pc_price_label((int)$hp['price_cents'], $vat_rate)) : '—' ?></td>
            <td><?= h(implode(' · ', function_exists('ak_plan_public_summary') ? ak_plan_public_summary($hp) : [])) ?></td>
            <td><?= (int)($plan_subs[$hp['id']] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="ap-foot">Changement de formule par les associations : montée immédiate au prorata, baisse à la fin du mois payé (stripe-change-plan). Sans Stripe : baisse programmée, montée sur demande au support.</p>
    </section>
    <?php endif; ?>

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
.ap-tag-badge { background: #065F46; color: #D1FAE5; vertical-align: middle; margin-left: 6px; }
.ap-muted-li { color: #94A3B8 !important; font-size: 12px !important; }
.ap-plan-limits code, .ap-rules code { font-size: 11px; background: #0F172A; padding: 1px 5px; border-radius: 4px; color: #FCD34D; overflow-wrap: anywhere; }
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
