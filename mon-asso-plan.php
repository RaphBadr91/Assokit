<?php
/**
 * mon-asso-plan.php — VERSION ENRICHIE PACK 6.4 STRIPE
 * --------------------------------------------------------------
 * Dashboard complet de l'abonnement client avec :
 *   - État actuel du plan (active/grace/overdue)
 *   - Méthode de paiement (Stripe ou manuel)
 *   - Bouton Régulariser dynamique (URL Stripe ou contact)
 *   - Aperçu des 3 dernières factures (lien vers liste complète)
 *   - Bouton "Gérer le paiement" / "Annuler l'abonnement"
 *   - Section "Changer de plan" (upgrade/downgrade)
 *
 * Offre : Assokit (prix HT + TVA = TTC, includes-pricing.php) ou Sur-mesure (sur devis). Démarrage
 * n'est plus proposée : elle reste la formule technique d'un essai terminé (« Essai terminé —
 * choisissez votre formule ») et celle des anciens comptes gratuits, qui continuent comme avant.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
@require_once __DIR__ . '/stripe-sync-helpers.php';
require_once __DIR__ . '/includes-pricing.php';

require_login();
// Apple 3.1.1 : aucun tunnel d'achat ne doit s'ouvrir dans l'app.
ak_block_billing_page();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// ============================================================
// [PACK 6.5 - SECURITY STRICT] Contrôle accès finances obligatoire
// ============================================================
// ⚠️ POLITIQUE STRICTE : seuls Admin / Fondateur / Super Admin accèdent.
$can_view_finances = (
    in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

if (!$can_view_finances) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('mon-asso-plan');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="color:#64748B;margin-bottom:14px;">'.ak_icon('lock',44,'1.5').'</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">L\'abonnement et les factures sont strictement réservés aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}
// ============================================================


// Récupération état abonnement
$subscription_status = function_exists('ak_get_subscription_status') ? ak_get_subscription_status($pdo, $org_id) : ['status' => 'none', 'plan_name' => 'Démarrage'];
$active_sub = function_exists('ak_get_org_active_subscription') ? ak_get_org_active_subscription($pdo, $org_id) : null;
$pricing = function_exists('ak_calculate_total_monthly_price') ? ak_calculate_total_monthly_price($pdo, $org_id) : ['plan_cents' => 0, 'addons_cents' => 0, 'total_cents' => 0, 'addons' => []];

// Récupération 3 dernières factures : les mêmes que « Mes factures » (Stripe, manuelles, AK-AAAA-NNNNNN)
$recent_invoices = function_exists('ak_get_org_invoices_all') ? ak_get_org_invoices_all($pdo, $org_id, 3)
    : (function_exists('ak_get_org_invoices_stripe') ? ak_get_org_invoices_stripe($pdo, $org_id, 3) : []);

// Ligne d'abonnement COURANTE (celle des fonctionnalités, ak_get_current_plan). ak_get_org_active_subscription
// la suit (un abonnement par carte en vigueur passe devant) : par sécurité, le prix affiché est celui de
// la formule courante si les deux lignes diffèrent.
$cur_plan = function_exists('ak_get_current_plan') ? ak_get_current_plan($pdo, $org_id) : null;
if ($cur_plan && $active_sub && (int)$active_sub['id'] !== (int)($cur_plan['subscription_id'] ?? 0)) {
    $pricing = ['plan_cents' => (int)($cur_plan['price_cents'] ?? 0), 'addons_cents' => 0, 'total_cents' => (int)($cur_plan['price_cents'] ?? 0), 'addons' => []];
}

// Détection capacités Stripe
$stripe_ready = function_exists('ak_stripe_is_configured') && ak_stripe_is_configured($pdo);
// Abonnement par carte encore en vigueur (actif ou en retard) : « Changer ma CB », add-on, annulation
$live_stripe = function_exists('ak_org_live_stripe_subscription') ? ak_org_live_stripe_subscription($pdo, $org_id) : null;
$has_stripe_subscription = (bool)$live_stripe;
$payment_mode = ($cur_plan && array_key_exists('payment_mode', $cur_plan)) ? ($cur_plan['payment_mode'] ?? 'manual') : ($active_sub['payment_mode'] ?? 'manual');
// Client Stripe passé sur une formule que Stripe ne facture pas (gratuite, Sur-mesure…) : jamais
// « Carte bancaire automatique » ni « Renouvellement automatique », l'abonnement par carte est à arrêter
$stripe_on_free_plan = null;
$stripe_on_free = function_exists('ak_stripe_left_on_free') ? ak_stripe_left_on_free($pdo, $org_id, $stripe_on_free_plan) : null;

// Tous les plans disponibles pour upgrade/downgrade
$plans = [];
try {
    $plans = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$current_plan_id = $cur_plan['plan_id'] ?? ($active_sub['plan_id'] ?? null);

// Prix des formules proposées : HT + TVA = TTC au taux de la plateforme (celui que Stripe ajoute aux
// nouveaux abonnements par carte). Formule en cours : le montant tel qu'il est facturé. Abonnement par
// carte souscrit avec la TVA (dernière facture Stripe avec TVA) : HT + TVA = TTC ; paiement sur facture
// ou virement : HT + TVA de la facture ; abonnement par carte plus ancien, sans TVA : montant seul.
$vat_rate = function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0;
$sub_vat_rate = function_exists('ak_live_sub_vat_rate') ? ak_live_sub_vat_rate($pdo, $org_id, $live_stripe) : null;
// Paiement sur facture ou virement (aucun abonnement par carte) : la facture de renouvellement
// (cron-job-renouvellements.php) porte prix HT + TVA d'ak_manual_invoice_vat_rate = TTC, ou « TVA non
// applicable » (société non assujettie) : le montant affiché est celui de la facture.
$invoice_vat_rate = (!$live_stripe && !in_array((string)$payment_mode, ['stripe', 'free_grant'], true) && function_exists('ak_manual_invoice_vat_rate'))
    ? (ak_manual_invoice_vat_rate($pdo) ?? 0.0) : null;
// « 49,99 € HT/mois (59,99 € TTC) », ou le montant tel qu'il est prélevé (« 49,99 €/mois »)
$fmt_month = function (int $cents) use ($sub_vat_rate, $invoice_vat_rate): string {
    if ($sub_vat_rate !== null) return ak_price_split($cents, $sub_vat_rate)['label'];
    if ($invoice_vat_rate !== null) return ak_price_split($cents, $invoice_vat_rate)['label'];
    return ak_price_eur($cents) . '/mois';
};

// Lignée d'essai (essai jamais converti, ak_org_is_trial) : une association rétrogradée en Démarrage à la
// fin de son essai voit « Essai terminé — choisissez votre formule », jamais « Plan gratuit ». Les anciens
// comptes gratuits (Démarrage sans essai) continuent comme avant, avec une invitation à passer à Assokit.
$trial_lineage = function_exists('ak_org_is_trial') && ak_org_is_trial($pdo, $org_id);
$cur_slug = (string)($cur_plan['slug'] ?? '');
// Abonnement payé puis arrêté (résiliation arrivée à échéance, impayé) : ak_sub_downgrade_free a passé la
// ligne courante en Démarrage en gardant l'ancienne formule (previous_plan_id). Il n'y a plus de formule
// gratuite : « Accès limité », jamais « Plan gratuit actif ». La fin d'essai (même marquage, formule d'essai)
// reste « Essai terminé ».
$after_paid = false;
if ($cur_slug === 'demarrage' && !$trial_lineage && $active_sub && !empty($active_sub['previous_plan_id'])
    && (int)$active_sub['id'] === (int)($cur_plan['subscription_id'] ?? $active_sub['id'])) {
    try {
        $pq = $pdo->prepare("SELECT 1 FROM asso_plans p WHERE p.id = ? AND p.slug <> 'demarrage' AND NOT (" . ak_plan_trial_sql($pdo) . ")");
        $pq->execute([(int)$active_sub['previous_plan_id']]);
        $after_paid = (bool)$pq->fetchColumn();
    } catch (Throwable $e) {}
}

// URL "Régulariser" dynamique
$regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/contact?subject=billing';
// Premier prélèvement SEPA en cours de vérification par la banque : ni « Passer à Assokit », ni second paiement
$sepa_pending_until = function_exists('ak_stripe_payment_pending_until') ? ak_stripe_payment_pending_until($pdo, $org_id) : null;
// Abonnement souscrit pendant l'essai (carte ou mandat SEPA enregistré) : l'essai continue, le premier mois est
// prélevé automatiquement à sa fin. Ni « Passer à Assokit », ni second abonnement ; « Annuler » reste possible sans frais.
$trial_card = function_exists('ak_org_trial_card') ? ak_org_trial_card($pdo, $org_id) : null;
$trial_card_amount = ak_trial_card_amount($pdo, $trial_card);   // « TTC » d'après l'abonnement souscrit, pas l'interrupteur du jour
// Prochain prélèvement d'un abonnement par carte en cours (échéance donnée par Stripe)
$next_charge_fr = ($live_stripe && $live_stripe['status'] === 'active' && empty($live_stripe['cancel_at_period_end'])
    && !empty($live_stripe['current_period_end']) && strtotime((string)$live_stripe['current_period_end']) > time())
    ? date('d/m/Y', strtotime((string)$live_stripe['current_period_end'])) : '';

render_head('Mon abonnement');
render_sidebar('mon-asso-plan');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">💎 Mon abonnement</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💎 Mon abonnement</h1>
      <p style="color:#64748B;margin:0;">Gérez votre plan, vos factures et votre méthode de paiement</p>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background:#D1FAE5;border:1px solid #A7F3D0;border-radius:10px;padding:12px 16px;margin-bottom:18px;color:#065F46;font-size:14px;">
      ✅ <?= htmlspecialchars($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:10px;padding:12px 16px;margin-bottom:18px;color:#991B1B;font-size:14px;">
      ❌ <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- ======= BANDEAU ÉTAT ABONNEMENT ======= -->
  <?php
  $st = $subscription_status['status'] ?? 'none';
  // Essai gratuit : même état que le bandeau et le cockpit (ak_get_subscription_status ← ak_org_plan_state).
  // Essai terminé dès que la date est passée, que le cron l'ait déjà basculé en Démarrage ou non :
  // jamais « Abonnement actif · Renouvellement automatique », jamais « 0 jour restant » à une date passée.
  $trial_over = !empty($subscription_status['trial_expired'])
      || ($trial_lineage && $cur_slug === 'demarrage' && empty($subscription_status['is_trial']) && empty($subscription_status['trial_paused']));
  // Essai suspendu par le Fondateur avant sa fin : ni « essai en cours, toutes les fonctionnalités », ni
  // « abonnement actif » (même état que le badge « Essai suspendu » du cockpit et de l'appli)
  $trial_paused = !empty($subscription_status['trial_paused']) && !$trial_over;
  $is_active = ($st === 'active') && !$trial_over && !$trial_paused;
  $is_grace = !empty($subscription_status['in_grace_period']);
  $is_overdue = !empty($subscription_status['is_overdue']);
  $is_pending = !empty($subscription_status['is_pending']);
  $is_trial = ($st === 'trial' || !empty($subscription_status['is_trial'])) && !$trial_over && !$trial_paused;
  $trial_end_fr = !empty($subscription_status['trial_ends_at']) ? date('d/m/Y', strtotime((string)$subscription_status['trial_ends_at'])) : '';
  $trial_days_left = max(0, (int)($subscription_status['trial_days_left'] ?? 0));
  $bg_color = $is_active ? '#D1FAE5' : ($is_grace || $is_pending ? '#FEF3C7' : ($is_overdue ? '#FEE2E2' : '#F1F5F9'));
  $border_color = $is_active ? '#A7F3D0' : ($is_grace || $is_pending ? '#FCD34D' : ($is_overdue ? '#FECACA' : '#E2E8F0'));
  $accent_color = $is_active ? '#047857' : ($is_grace || $is_pending ? '#92400E' : ($is_overdue ? '#991B1B' : '#475569'));
  $emoji = $is_active ? '✅' : ($is_grace || $is_pending ? '⏳' : ($is_overdue ? '⚠️' : ($is_trial ? '🎁' : '📋')));
  if ($is_trial) { $bg_color = '#ECFDF5'; $border_color = '#A7F3D0'; $accent_color = '#047857'; }
  if ($trial_over) { $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '⏳'; }
  if ($trial_paused) { $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '⏸'; }
  if ($after_paid && !$trial_over) { $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '🔒'; }
  // Essai terminé (cron passé ou non) : aucune formule choisie, Démarrage n'est pas une offre
  $plan_title = $trial_over ? 'Essai terminé' : ($after_paid ? 'Accès limité' : ($subscription_status['plan_name'] ?? 'Démarrage'));
  // Premier prélèvement SEPA en cours de vérification : ni « Essai terminé, choisissez votre formule », ni offre à souscrire
  if ($sepa_pending_until) { $plan_title = 'Assokit · paiement en cours'; $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '⏳'; }
  // Formule gratuite (Démarrage) : rien ne se renouvelle ni ne se prélève (Sur-mesure, facturé à part, n'en est pas une)
  $is_free_plan = $cur_plan && (int)($cur_plan['price_cents'] ?? 0) <= 0 && ($cur_plan['slug'] ?? '') !== 'sur-mesure' && empty($cur_plan['is_custom_quote']);
  // Résiliation en cours (ak_plan_trial_info, même texte que Paramètres) : la formule reste jusqu'à
  // access_until, puis accès limité (Démarrage). Ni « Renouvellement automatique », ni « Annuler », ni « Plan gratuit ».
  $is_cancel_scheduled = !empty($subscription_status['cancel_scheduled']) && !$is_trial && !$trial_over && !$trial_paused;
  $access_until_fr = (string)($subscription_status['access_until_fr'] ?? '');
  $sub_cancelled = $is_cancel_scheduled && $st === 'cancelled';
  if ($is_cancel_scheduled) { $is_active = false; $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '🛑'; }
  ?>

  <div style="background:<?= $bg_color ?>;border:1px solid <?= $border_color ?>;border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
    <div style="font-size:40px;flex-shrink:0;"><?= $emoji ?></div>
    <div style="flex:1;min-width:240px;">
      <div style="font-size:11px;color:<?= $accent_color ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Plan actuel</div>
      <div style="font-size:22px;font-weight:700;color:<?= $accent_color ?>;line-height:1.2;">
        <?= htmlspecialchars($plan_title) ?>
        <?php if ($pricing['total_cents'] > 0 && !$trial_over): ?>
          · <span style="font-size:18px;"><?= htmlspecialchars($fmt_month((int)$pricing['total_cents'])) ?></span>
        <?php endif; ?>
      </div>
      <div style="font-size:13px;color:<?= $accent_color ?>;margin-top:4px;opacity:0.9;">
        <?php if ($sepa_pending_until): ?>
          ⏳ Prélèvement SEPA en cours de vérification par votre banque · l’abonnement s’active dès sa confirmation
        <?php elseif ($is_cancel_scheduled && $sub_cancelled): ?>
          🛑 Résiliée<?= $access_until_fr !== '' ? ' · accès jusqu’au ' . htmlspecialchars($access_until_fr) : '' ?> · puis accès limité (Démarrage), vos données sont conservées
        <?php elseif ($is_cancel_scheduled): ?>
          🛑 Résiliation programmée<?= $access_until_fr !== '' ? ' le ' . htmlspecialchars($access_until_fr) : '' ?> · aucun nouveau prélèvement · puis accès limité (Démarrage)
        <?php elseif ($after_paid && !$trial_over): ?>
          🔒 Abonnement arrêté — vos données sont conservées · choisissez une formule pour retrouver toutes les fonctionnalités
        <?php elseif ($is_active && ($is_free_plan || ($stripe_on_free && $stripe_on_free_plan))): ?>
          ✅ Plan gratuit actif
        <?php elseif ($is_active && $stripe_on_free): ?>
          ✅ Abonnement actif
        <?php elseif ($is_active && $payment_mode === 'stripe'): ?>
          ✅ Abonnement actif · Renouvellement automatique<?= $next_charge_fr !== '' ? ' · Prochain prélèvement le ' . htmlspecialchars($next_charge_fr) : '' ?>
        <?php elseif ($is_active && $payment_mode === 'free_grant'): ?>
          ✅ Abonnement actif · offert
        <?php elseif ($is_active && $payment_mode === 'wire_transfer'): ?>
          ✅ Abonnement actif · paiement par virement
        <?php elseif ($is_active): ?>
          ✅ Abonnement actif · paiement sur facture
        <?php elseif ($trial_over): ?>
          ⏳ Essai terminé<?= $trial_end_fr !== '' ? ' le ' . $trial_end_fr : '' ?> — choisissez votre formule · vos données sont conservées
        <?php elseif ($trial_paused): ?>
          ⏸ Essai suspendu<?= $trial_end_fr !== '' ? ' · fin prévue le ' . $trial_end_fr : '' ?> · contactez-nous pour le réactiver
        <?php elseif ($is_pending): ?>
          ⏳ Paiement en attente · <?= (int)($subscription_status['days_remaining'] ?? 0) ?> jours de tolérance
        <?php elseif ($is_grace): ?>
          ⏳ Période de grâce · Régularisez sous <?= (int)($subscription_status['days_remaining'] ?? 0) ?> jours
        <?php elseif ($is_overdue): ?>
          ⚠️ Paiement en retard · Régularisez pour conserver vos accès
        <?php elseif ($is_trial && $trial_card): ?>
          🎁 Essai gratuit<?= $trial_end_fr !== '' ? ' jusqu’au ' . $trial_end_fr : '' ?> · ✅ abonnement Assokit souscrit<?= $trial_card['charging'] ? ' · premier prélèvement en cours' : ($trial_card['charge_fr'] !== '' ? ' · premier prélèvement le ' . htmlspecialchars($trial_card['charge_fr']) : '') ?>
        <?php elseif ($is_trial): ?>
          🎁 Essai gratuit<?= $trial_end_fr !== '' ? ' jusqu’au ' . $trial_end_fr . ' · ' . ($trial_days_left === 0 ? 'dernier jour' : $trial_days_left . ' jour' . ($trial_days_left > 1 ? 's' : '') . ' restant' . ($trial_days_left > 1 ? 's' : '')) : '' ?> · toutes les fonctionnalités
        <?php else: ?>
          📋 Plan gratuit
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($is_grace || $is_pending || $is_overdue): ?>
        <a href="<?= htmlspecialchars($regularize_url) ?>" style="display:inline-flex;align-items:center;gap:7px;background:#EA580C;color:white;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('credit-card',14) ?>Régulariser</a>
      <?php endif; ?>
      <?php if (!$sepa_pending_until && !$trial_card && ($st === 'none' || $is_trial || $trial_over || ($is_free_plan && !$stripe_on_free && !$is_cancel_scheduled) || ($is_cancel_scheduled && ($cur_plan['slug'] ?? '') !== 'assokit'))): ?>
        <a href="/mon-asso-paiement?plan=assokit" style="display:inline-flex;align-items:center;gap:7px;background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('star-fill',14) ?><?= $trial_over ? 'Choisir Assokit' : ($after_paid ? 'Reprendre Assokit' : 'Passer à Assokit') ?></a>
      <?php endif; ?>
      <?php if ($trial_over): ?>
        <a href="/contact?plan=sur-mesure" style="display:inline-flex;align-items:center;gap:7px;background:white;color:#0F172A;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #E2E8F0;"><?= ak_icon('phone',14) ?>Sur-mesure : être rappelé</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($trial_card && !$sepa_pending_until && $trial_card['charging']): ?>
  <div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#92400E;font-size:14px;line-height:1.55;">
    ⏳ <strong>Premier prélèvement en cours</strong> — votre essai est terminé<?= $trial_card['charge_fr'] !== '' ? ' le <strong>' . htmlspecialchars($trial_card['charge_fr']) . '</strong>' : '' ?>
    et le premier mois<?= $trial_card_amount !== '' ? ' (<strong>' . htmlspecialchars($trial_card_amount) . '</strong>)' : '' ?> est en train d’être prélevé. Votre abonnement s’active dès qu’il aura abouti ;
    vous pourrez ensuite le résilier à tout moment (effet à la fin du mois payé).
  </div>
  <?php elseif ($trial_card && !$sepa_pending_until): ?>
  <div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#065F46;font-size:14px;line-height:1.55;">
    ✅ <strong>Abonnement souscrit</strong> — aucun prélèvement pendant votre essai.
    Premier prélèvement<?= $trial_card_amount !== '' ? ' de <strong>' . htmlspecialchars($trial_card_amount) . '</strong>' : '' ?><?= $trial_card['charge_fr'] !== '' ? ' le <strong>' . htmlspecialchars($trial_card['charge_fr']) . '</strong>' : '' ?>, à la fin de votre essai, puis chaque mois à la même date.
    Vous pouvez annuler avant cette date sans frais : aucun montant ne sera prélevé.
  </div>
  <?php endif; ?>

  <?php if ($sepa_pending_until): ?>
  <div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#92400E;font-size:14px;line-height:1.55;">
    ⏳ <strong>Prélèvement SEPA en cours de vérification</strong> — votre banque confirme le paiement de votre abonnement sous quelques jours ouvrés.
    Inutile de payer une seconde fois : l’abonnement s’active automatiquement dès la confirmation, et vous gardez l’accès à Assokit d’ici là.
  </div>
  <?php endif; ?>

  <!-- ======= GRILLE PRINCIPALE ======= -->
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

    <!-- COLONNE GAUCHE -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Détail prix -->
      <?php if ($pricing['total_cents'] > 0 && !$trial_over): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('euro',18) ?>Détail du tarif</h3>
        <?php
        // TVA de l'abonnement par carte, sinon celle des factures (paiement sur facture ou virement)
        $detail_vat_rate = $sub_vat_rate ?? $invoice_vat_rate;
        $ht_suffix = ($detail_vat_rate !== null && $detail_vat_rate > 0) ? ' HT' : '';
        $total_split = ak_price_split((int)$pricing['total_cents'], $detail_vat_rate ?? 0.0);
        ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div>Plan <?= htmlspecialchars($subscription_status['plan_name'] ?? '') ?></div>
          <div style="font-weight:600;"><?= htmlspecialchars(ak_price_eur((int)$pricing['plan_cents']) . $ht_suffix) ?>/mois</div>
        </div>
        <?php foreach ($pricing['addons'] as $addon): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div style="color:#1E40AF;">🏆 <?= htmlspecialchars($addon['addon_name']) ?></div>
          <div style="font-weight:600;color:#1E40AF;">+<?= htmlspecialchars(ak_price_eur((int)$addon['price_cents_monthly']) . $ht_suffix) ?>/mois</div>
        </div>
        <?php endforeach; ?>
        <?php if ($ht_suffix !== ''): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:13px;color:#475569;">
          <div>TVA <?= htmlspecialchars($total_split['rate_label']) ?></div>
          <div><?= htmlspecialchars($total_split['vat']) ?></div>
        </div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;padding:12px 0 0;border-top:2px solid #0F172A;margin-top:6px;font-size:15px;font-weight:700;">
          <div>Total mensuel<?= $ht_suffix !== '' ? ' TTC' : '' ?></div>
          <div style="color:#059669;font-size:18px;"><?= htmlspecialchars($total_split['ttc']) ?></div>
        </div>
        <?php if ($ht_suffix === '' && $vat_rate <= 0): ?>
        <div style="font-size:11.5px;color:#64748B;margin-top:4px;">TVA non applicable, art. 293 B du CGI</div>
        <?php endif; ?>
        <div style="font-size:12px;color:#64748B;margin-top:12px;line-height:1.55;">
          <?php if ($next_charge_fr !== ''): ?>Prochain prélèvement le <?= htmlspecialchars($next_charge_fr) ?>, puis chaque mois à la même date. <?php endif; ?>
          Sans engagement : résiliation à tout moment, effet à la fin du mois payé. <a href="/cgu#paiement" style="color:#059669;">Conditions générales</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Aperçu factures -->
      <?php if (!empty($recent_invoices)): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
          <h3 style="margin:0;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('file-text',18) ?>Mes dernières factures</h3>
          <a href="/mon-asso-factures" style="font-size:13px;color:#059669;text-decoration:none;font-weight:600;">Voir toutes →</a>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <?php foreach ($recent_invoices as $inv): ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:10px 0;">
              <div style="font-weight:600;"><?= htmlspecialchars(($inv['invoice_number'] ?? '') !== '' ? (string)$inv['invoice_number'] : 'Facture en préparation') ?></div>
              <div style="font-size:12px;color:#64748B;"><?= htmlspecialchars(date('d/m/Y', strtotime($inv['created_at']))) ?></div>
            </td>
            <td style="padding:10px 0;text-align:right;">
              <div style="font-weight:700;"><?= ak_format_price_cents((int)$inv['amount_cents']) ?></div>
              <?php
              $st_color = '#94A3B8'; $st_bg = '#F1F5F9'; $st_label = $inv['status'];
              if ($inv['status'] === 'paid') { $st_color = '#065F46'; $st_bg = '#D1FAE5'; $st_label = 'Payée'; }
              elseif ($inv['status'] === 'open') { $st_color = '#92400E'; $st_bg = '#FEF3C7'; $st_label = 'En attente'; }
              elseif ($inv['status'] === 'void') { $st_color = '#475569'; $st_bg = '#F1F5F9'; $st_label = 'Annulée'; }
              elseif ($inv['status'] === 'uncollectible') { $st_color = '#991B1B'; $st_bg = '#FEE2E2'; $st_label = 'Impayée'; }
              ?>
              <span style="background:<?= $st_bg ?>;color:<?= $st_color ?>;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;"><?= htmlspecialchars($st_label) ?></span>
            </td>
            <td style="padding:10px 0 10px 12px;text-align:right;">
              <?php if (!empty($inv['invoice_pdf_url'])): ?>
                <a href="<?= htmlspecialchars($inv['invoice_pdf_url']) ?>" target="_blank" rel="noopener" style="color:#059669;font-size:13px;text-decoration:none;font-weight:600;">📥 PDF</a>
              <?php elseif (!empty($inv['hosted_invoice_url'])): ?>
                <a href="<?= htmlspecialchars($inv['hosted_invoice_url']) ?>" target="_blank" rel="noopener" style="color:#059669;font-size:13px;text-decoration:none;font-weight:600;">🔗 Voir</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endif; ?>

      <!-- Changer de plan -->
      <?php
      // Formules proposées : Assokit et Sur-mesure. Démarrage n'apparaît que comme formule ACTUELLE d'un
      // ancien compte gratuit (jamais pour un essai terminé) ; aucune autre formule à 0 € n'est proposée.
      $plans = array_values(array_filter($plans, function ($p) use ($current_plan_id, $trial_over, $after_paid) {
          $is_current = $current_plan_id !== null && (int)$p['id'] === (int)$current_plan_id;
          $is_quote = ($p['slug'] === 'sur-mesure' || !empty($p['is_custom_quote']));
          if (!empty($p['is_trial']) || $p['slug'] === 'pro-essai') return false;
          if ((int)$p['price_cents'] <= 0 && !$is_quote) return $is_current && !$trial_over && !$after_paid;
          return true;
      }));
      // Formules payantes d'abord, Sur-mesure (sur devis) en dernier
      usort($plans, fn($a, $b) => (($a['slug'] === 'sur-mesure' || !empty($a['is_custom_quote'])) <=> ($b['slug'] === 'sur-mesure' || !empty($b['is_custom_quote'])))
          ?: ((int)$a['price_cents'] <=> (int)$b['price_cents']));
      ?>
      <?php if (!empty($plans)): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 6px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('chart',18) ?><?= $trial_card ? 'Votre formule' : (($trial_over || $is_trial || $trial_paused || $after_paid) ? 'Choisissez votre formule' : 'Changer de plan') ?></h3>
        <p style="color:#64748B;font-size:13px;margin:0 0 16px;"><?= $trial_card ? 'Abonnement Assokit souscrit : il commence à la fin de votre essai, sans engagement.' : ($trial_over ? 'Votre essai est terminé : vos données sont conservées, choisissez la formule qui vous convient pour continuer.' : ($after_paid ? 'Votre abonnement est arrêté : vos données sont conservées, choisissez une formule pour retrouver toutes les fonctionnalités.' : 'Sans engagement, résiliable à tout moment.')) ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
          <?php
          // Sur-mesure / sur devis : jamais « Gratuit » (prix 0 en base = sur devis, comme le cockpit et /tarifs)
          $cur_is_quote = $cur_plan && (($cur_plan['slug'] ?? '') === 'sur-mesure' || !empty($cur_plan['is_custom_quote']));
          foreach ($plans as $p):
            $is_current = ((int)$p['id'] === (int)$current_plan_id) && !$trial_over;
            $is_quote = ($p['slug'] === 'sur-mesure' || !empty($p['is_custom_quote']));
            // Formule en cours : le montant facturé (comme le bandeau) ; autre formule : prix HT + TTC
            $price_str = $is_quote ? 'Sur devis' : ((int)$p['price_cents'] === 0 ? 'Gratuit'
                : ($is_current ? $fmt_month((int)$p['price_cents']) : ak_price_split((int)$p['price_cents'], $vat_rate)['label']));
          ?>
          <div style="background:<?= $is_current ? '#F0FDF4' : '#FAF8F5' ?>;border:<?= $is_current ? '2px solid #059669' : '1px solid #E2E8F0' ?>;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-weight:700;color:<?= $is_current ? '#047857' : '#0F172A' ?>;font-size:14px;"><?= htmlspecialchars(function_exists('ak_plan_label') ? ak_plan_label($p['slug'], $p['name']) : $p['name']) ?></div>
            <div style="font-size:12px;color:#64748B;margin:4px 0 10px;"><?= htmlspecialchars($price_str) ?></div>
            <?php if ($is_current): ?>
              <div style="background:#059669;color:white;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:600;">✓ PLAN ACTUEL</div>
            <?php elseif ($is_quote): ?>
              <a href="/contact?plan=sur-mesure" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#F1F5F9;color:#475569;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('phone',14) ?>Être recontacté</a>
            <?php elseif ((int)$p['price_cents'] <= 0): ?>
              <?php // Formule à 0 € (filtrée plus haut sauf formule actuelle) : ni achat ni résiliation depuis cette carte ?>
            <?php elseif ($sepa_pending_until): ?>
              <div style="background:#FEF3C7;color:#92400E;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;">⏳ Paiement en cours</div>
            <?php elseif ($trial_card): ?>
              <div style="background:#ECFDF5;color:#047857;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;">✓ Souscrit · à la fin de l’essai</div>
            <?php elseif ($trial_over || ((int)$p['price_cents'] > (int)($current_plan_id ? $pricing['plan_cents'] : 0) && !$cur_is_quote)): ?>
              <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($p['slug']) ?>" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#059669;color:white;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('upload',14) ?><?= ($trial_over || $is_trial || $trial_paused || $after_paid) ? 'Choisir' : 'Upgrade' ?></a>
            <?php elseif ((int)$p['price_cents'] > 0): ?>
              <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($p['slug']) ?>" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#F1F5F9;color:#475569;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('download',14) ?>Passer à ce plan</a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- COLONNE DROITE -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Méthode paiement -->
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('credit-card',18) ?>Mode de paiement</h3>
        <?php if ($stripe_on_free): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">💳</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#92400E;">Prélèvement par carte à arrêter</div>
              <div style="color:#64748B;"><?= $stripe_on_free_plan ? 'Votre formule actuelle est gratuite' : 'Votre formule actuelle n’est pas réglée par carte' ?> : l’abonnement par carte (Stripe) de votre ancienne formule n’a plus lieu d’être. Si votre carte est encore débitée, contactez-nous.</div>
            </div>
          </div>
        <?php elseif ($trial_card && !$sepa_pending_until): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;"><?= $trial_card['pm_type'] === 'sepa_debit' ? '🏦' : '💳' ?></div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#047857;"><?= $trial_card['pm_type'] === 'sepa_debit' ? 'Mandat SEPA enregistré' : 'Carte enregistrée' ?></div>
              <div style="color:#64748B;"><?= $trial_card['charging'] ? 'Premier prélèvement en cours' : 'Premier prélèvement' . ($trial_card['charge_fr'] !== '' ? ' le ' . htmlspecialchars($trial_card['charge_fr']) : '') . ', à la fin de l’essai' ?></div>
            </div>
          </div>
        <?php elseif ($sepa_pending_until): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🏦</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#92400E;">Prélèvement SEPA</div>
              <div style="color:#64748B;">En cours de vérification par votre banque</div>
            </div>
          </div>
        <?php elseif (($is_trial || $trial_over || $trial_paused) && $payment_mode !== 'stripe'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🎁</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#0F172A;"><?= $trial_over ? 'Aucun prélèvement' : 'Essai gratuit' ?></div>
              <div style="color:#64748B;"><?= $trial_over ? 'Aucune formule choisie : vos données sont conservées' : 'Sans carte bancaire, sans engagement' ?></div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'stripe' && $is_cancel_scheduled): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">💳</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#0F172A;">Carte bancaire · prélèvements arrêtés</div>
              <div style="color:#64748B;"><?= $sub_cancelled ? 'Abonnement par carte terminé' : 'Aucun nouveau prélèvement' . ($access_until_fr !== '' ? ' après le ' . htmlspecialchars($access_until_fr) : '') ?></div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'stripe'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">💳</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#047857;">Carte bancaire automatique</div>
              <div style="color:#64748B;">Prélèvement mensuel via Stripe</div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'free_grant'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🎁</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#1E40AF;">Plan offert</div>
              <div style="color:#64748B;">Activation par le fondateur</div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'wire_transfer'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🏦</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#92400E;">Virement bancaire</div>
              <div style="color:#64748B;">Paiement manuel</div>
            </div>
          </div>
        <?php elseif ($is_free_plan): ?>
          <div style="font-size:13px;color:#64748B;line-height:1.5;margin-bottom:14px;">
            <?= $after_paid ? 'Aucun abonnement en cours : aucun prélèvement.' : 'Plan gratuit : aucun paiement.' ?>
          </div>
        <?php elseif ($cur_plan): ?>
          <?php // Paiement hors Stripe (« manual » ou non renseigné) : sur facture, sans prélèvement automatique ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🧾</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#0F172A;">Paiement sur facture</div>
              <div style="color:#64748B;">Sans prélèvement automatique</div>
            </div>
          </div>
        <?php else: ?>
          <div style="font-size:13px;color:#64748B;line-height:1.5;margin-bottom:14px;">
            Aucune méthode de paiement configurée.
          </div>
        <?php endif; ?>

        <?php if (($has_stripe_subscription && !$is_cancel_scheduled) || ($trial_card && $stripe_ready)): ?>
          <a href="/mon-asso-paiement?action=update_card" style="display:flex;align-items:center;justify-content:center;gap:6px;text-align:center;background:#F1F5F9;color:#0F172A;padding:9px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:600;"><?= ak_icon('refresh',14) ?>Changer ma CB</a>
        <?php endif; ?>
      </div>

      <!-- Actions -->
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('gear',18) ?>Actions</h3>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <a href="/mon-asso-factures" style="background:#FAF8F5;color:#0F172A;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #E2E8F0;">
            <?= ak_icon('file-text',16) ?>Mes factures
          </a>
          <a href="/contact?subject=billing" style="background:#FAF8F5;color:#0F172A;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #E2E8F0;">
            <?= ak_icon('phone',16) ?>Nous contacter
          </a>
          <?php if ($trial_card && $trial_card['charging']): ?>
            <div style="background:#FEF3C7;color:#92400E;padding:10px 14px;border-radius:8px;font-size:13.5px;font-weight:600;border:1px solid #FCD34D;margin-top:6px;">
              ⏳ Premier prélèvement en cours
            </div>
          <?php elseif ($trial_card): ?>
            <a href="/mon-asso-annuler-abonnement" style="background:#FEE2E2;color:#991B1B;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #FECACA;margin-top:6px;">
              🛑 Annuler mon abonnement (sans frais)
            </a>
          <?php elseif ($is_cancel_scheduled): ?>
            <div style="background:#FEF3C7;color:#92400E;padding:10px 14px;border-radius:8px;font-size:13.5px;font-weight:600;border:1px solid #FCD34D;margin-top:6px;">
              🛑 <?= $sub_cancelled ? 'Abonnement résilié' : 'Résiliation programmée' ?><?= $access_until_fr !== '' ? ($sub_cancelled ? ' · accès jusqu’au ' : ' le ') . htmlspecialchars($access_until_fr) : '' ?>
            </div>
          <?php elseif ($has_stripe_subscription || (($is_active && !$is_free_plan) || $is_overdue) && $active_sub): ?>
            <a href="/mon-asso-annuler-abonnement" style="background:#FEE2E2;color:#991B1B;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #FECACA;margin-top:6px;">
              🛑 Annuler mon abonnement
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add-on domaine (si pas déjà actif) -->
      <?php
      $has_addon = function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id);
      if ($is_active && !$has_addon && $payment_mode === 'stripe' && !$stripe_on_free && $has_stripe_subscription && $stripe_ready):
      ?>
      <div style="background:linear-gradient(135deg, #EFF6FF 0%, white 100%);border:1px solid #BFDBFE;border-radius:14px;padding:22px;">
        <div style="font-size:32px;margin-bottom:8px;">🏆</div>
        <h3 style="margin:0 0 6px;font-size:15px;color:#1E40AF;">Domaine personnalisé</h3>
        <p style="font-size:12.5px;color:#475569;margin:0 0 14px;line-height:1.5;">Ajoutez votre propre domaine (adherents.tonasso.fr) pour seulement <strong>+<?= htmlspecialchars($fmt_month(AK_ADDON_DOMAIN_HT_CENTS)) ?></strong>.</p>
        <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($subscription_status['plan_slug'] ?? 'assokit') ?>&addon_domain=1" style="display:block;background:#1E40AF;color:white;padding:9px;border-radius:8px;text-align:center;font-size:13px;text-decoration:none;font-weight:600;">+ Ajouter</a>
      </div>
      <?php endif; ?>

    </div>

  </div>

  <style>
  @media (max-width: 920px) {
    main.main > div[style*="grid-template-columns:2fr 1fr"] {
      grid-template-columns: 1fr !important;
    }
  }
  </style>

</main>

<?php render_foot(); ?>
