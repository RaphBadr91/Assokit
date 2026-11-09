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
 *   - Choix de la formule (grille 2026) : Essentiel, Pro (mis en avant, présélectionné), Équipe ;
 *     Sur-mesure : « Nous contacter », sans prix. Souscription (essai, fin d'essai, accès limité) par
 *     /mon-asso-paiement ; changement en cours d'abonnement par /stripe-change-plan (montée immédiate
 *     au prorata, baisse à l'échéance, programmée et annulable)
 *   - Offre fondateur : « Prix et fonctions garantis jusqu'au … » (asso_subscriptions.price_locked_until)
 *
 * Prix toujours « HT/mois » (TVA non applicable, art. 293 B du CGI, tant que le taux vaut 0). Démarrage
 * n'est pas une offre : c'est la formule technique d'un essai terminé ou d'un abonnement arrêté (accès
 * limité). Option domaine personnel : plus vendue tant que la fonction n'est pas livrée (ak_addon_domain_offer).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
@require_once __DIR__ . '/stripe-sync-helpers.php';
require_once __DIR__ . '/stripe-plan-change-helpers.php';
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
// « 29,99 € HT/mois (35,99 € TTC) » si une TVA s'applique, sinon « 29,99 € HT/mois » (HT = prix payé)
$fmt_month = function (int $cents) use ($sub_vat_rate, $invoice_vat_rate): string {
    return ak_pc_price_label($cents, $sub_vat_rate ?? $invoice_vat_rate ?? 0.0);
};
// Changement de formule : situation de l'association (souscription, carte, facture, support, bloqué)
$pc = ak_pc_context($pdo, $org_id);
$pending = $pc['pending'];
$locked_until_fr = $pc['locked_until_fr'];
$offer_vat = $pc['mode'] === 'manual' ? ak_manual_invoice_vat_rate_safe($pdo) : $vat_rate;

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
// Premier prélèvement SEPA en cours de vérification par la banque : ni « Choisir une formule », ni second paiement
$sepa_pending_until = function_exists('ak_stripe_payment_pending_until') ? ak_stripe_payment_pending_until($pdo, $org_id) : null;
// Abonnement souscrit pendant l'essai (carte ou mandat SEPA enregistré) : l'essai continue, le premier mois est
// prélevé automatiquement à sa fin. Ni « Choisir une formule », ni second abonnement ; « Annuler » reste possible sans frais.
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
      <p style="color:#64748B;margin:0;">Gérez votre formule, vos factures et votre moyen de paiement</p>
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
  if ($sepa_pending_until) { $plan_title = 'Paiement en cours'; $bg_color = '#FEF3C7'; $border_color = '#FCD34D'; $accent_color = '#92400E'; $emoji = '⏳'; }
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
      <div style="font-size:11px;color:<?= $accent_color ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Formule actuelle</div>
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
          🔒 Abonnement arrêté — vos données sont conservées · choisissez une formule pour continuer
        <?php elseif ($is_active && ($is_free_plan || ($stripe_on_free && $stripe_on_free_plan))): ?>
          🔒 Accès limité : vos données restent consultables et exportables
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
          🎁 Essai gratuit<?= $trial_end_fr !== '' ? ' jusqu’au ' . $trial_end_fr : '' ?> · ✅ abonnement souscrit<?= $trial_card['charging'] ? ' · premier prélèvement en cours' : ($trial_card['charge_fr'] !== '' ? ' · premier prélèvement le ' . htmlspecialchars($trial_card['charge_fr']) : '') ?>
        <?php elseif ($is_trial): ?>
          🎁 Essai gratuit<?= $trial_end_fr !== '' ? ' jusqu’au ' . $trial_end_fr . ' · ' . ($trial_days_left === 0 ? 'dernier jour' : $trial_days_left . ' jour' . ($trial_days_left > 1 ? 's' : '') . ' restant' . ($trial_days_left > 1 ? 's' : '')) : '' ?> · toutes les fonctions de la formule Pro
        <?php else: ?>
          🔒 Accès limité : vos données restent consultables et exportables
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($is_grace || $is_pending || $is_overdue): ?>
        <a href="<?= htmlspecialchars($regularize_url) ?>" style="display:inline-flex;align-items:center;gap:7px;background:#EA580C;color:white;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('credit-card',14) ?>Régulariser</a>
      <?php endif; ?>
      <?php if (!$sepa_pending_until && !$trial_card && ($st === 'none' || $is_trial || $trial_over || ($is_free_plan && !$stripe_on_free && !$is_cancel_scheduled))): ?>
        <a href="#formules" style="display:inline-flex;align-items:center;gap:7px;background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('star-fill',14) ?><?= $after_paid ? 'Reprendre une formule' : 'Choisir une formule' ?></a>
      <?php endif; ?>
      <?php if ($trial_over): ?>
        <a href="/contact?plan=sur-mesure" style="display:inline-flex;align-items:center;gap:7px;background:white;color:#0F172A;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #E2E8F0;"><?= ak_icon('phone',14) ?>Sur-mesure : être rappelé</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($cur_slug === 'fondateur'): ?>
  <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#1E3A8A;font-size:14px;line-height:1.55;">
    🤝 <strong>Offre fondateur</strong> — toutes vos fonctions actuelles, sans changement<?= $locked_until_fr !== '' ? '. Prix et fonctions garantis jusqu’au <strong>' . htmlspecialchars($locked_until_fr) . '</strong>' : '' ?>.
  </div>
  <?php endif; ?>

  <?php if ($cur_slug === 'ak-equipe' && !$is_trial): ?>
  <div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#065F46;font-size:14px;line-height:1.55;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <div style="flex:1;min-width:220px;">🎓 <strong>Réservez votre heure de prise en main</strong> : votre formule Équipe inclut une séance d’une heure en visio et la reprise de votre fichier d’adhérents par nos soins (tableur CSV ou Excel, une fois, à demander dans les 3 mois suivant la souscription).</div>
    <a href="/contact?plan=ak-equipe" style="background:#059669;color:white;padding:8px 14px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px;">Prendre rendez-vous</a>
  </div>
  <?php endif; ?>

  <?php if ($pending): ?>
  <div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin:-8px 0 24px;color:#92400E;font-size:14px;line-height:1.55;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <div style="flex:1;min-width:220px;">🔁 <strong>Changement de formule prévu</strong> : passage à <strong><?= htmlspecialchars($pending['label']) ?></strong><?= $pending['at_fr'] !== '' ? ' le <strong>' . htmlspecialchars($pending['at_fr']) . '</strong>' : ' à la fin de la période payée' ?>. D’ici là, vous gardez votre formule actuelle.</div>
    <form method="post" action="/stripe-change-plan" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '')) ?>">
      <input type="hidden" name="action" value="cancel_pending">
      <button type="submit" style="background:white;color:#92400E;border:1px solid #FCD34D;padding:8px 14px;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;">Annuler ce changement</button>
    </form>
  </div>
  <?php endif; ?>

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

<?php
// ======= CHOIX DE LA FORMULE (grille 2026) =======
// Essentiel, Pro (mis en avant, présélectionné), Équipe ; Sur-mesure « Nous contacter », sans prix.
// Ordre : ordinateur Essentiel · Pro · Équipe ; téléphone Pro · Équipe · Essentiel (propriété CSS order).
$offers = ak_pc_offer_plans($pdo);
$choose_first = in_array($pc['mode'], ['subscribe'], true) && !$sepa_pending_until;
$render_offers = function () use ($pdo, $org_id, $offers, $pc, $pending, $offer_vat, $trial_card, $is_trial, $trial_over, $trial_paused, $after_paid, $sepa_pending_until): string {
    if (!$offers['paid'] && !$offers['quote'] && empty($offers['pending_pro'])) return '';
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $csrf = function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '');
    $subscribe = $pc['mode'] === 'subscribe';
    $title = $trial_card ? 'Votre formule' : (($subscribe || $is_trial || $trial_over || $trial_paused || $after_paid) ? 'Choisissez votre formule' : 'Changer de formule');
    $intro = $trial_card ? 'Abonnement souscrit pendant l’essai : il commence à la fin de votre essai, sans engagement. Vous pouvez encore changer de formule.'
        : ($trial_over ? 'Votre essai est terminé : vos données sont conservées, choisissez la formule qui vous convient pour continuer.'
        : ($after_paid ? 'Votre abonnement est arrêté : vos données sont conservées, choisissez une formule pour continuer.'
        : ($subscribe ? 'Sans engagement, résiliable à tout moment.' . ($pc['stripe_ready'] && $is_trial ? ' Pendant l’essai, vous gardez tous vos jours gratuits : le premier mois n’est prélevé qu’à la fin de l’essai.' : '')
        : ($pc['mode'] === 'support' ? 'Choisissez votre nouvelle formule : nous faisons le changement pour vous et ajustons votre facture.'
        : ($pc['mode'] === 'manual' ? 'Abonnement réglé sur facture : une baisse prend effet à la fin du mois payé ; une montée se fait sur demande, nous l’appliquons pour vous.'
        : 'Une montée de formule prend effet tout de suite, au prorata ; une baisse prend effet à la fin du mois payé.')))));
    $order = ['assokit' => 1, 'ak-equipe' => 2, 'ak-essentiel' => 3];
    ob_start(); ?>
      <div id="formules" class="akp-offers">
        <h3 style="margin:0 0 6px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('chart',18) ?><?= $h($title) ?></h3>
        <p style="color:#64748B;font-size:13px;margin:0 0 16px;line-height:1.55;"><?= $h($intro) ?></p>
        <?php if ($pc['mode'] === 'blocked'): ?>
          <p style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;color:#92400E;font-size:13px;margin:0 0 14px;"><?= $h($pc['reason']) ?></p>
        <?php endif; ?>
        <div class="akp-grid">
        <?php foreach ($offers['paid'] as $p):
            $slug = (string)$p['slug'];
            $is_current = (int)$p['id'] === (int)$pc['cur_plan_id'] && !$subscribe;
            $is_pending = $pending && (int)$pending['plan_id'] === (int)$p['id'];
            $featured = !empty($p['is_featured']);
            $badge = function_exists('ak_plan_badge') && $featured ? ak_plan_badge($pdo, $slug) : null;
            $kind = ak_pc_kind((int)$pc['cur_price'], $p);
            $blockers = (($kind === 'downgrade' && !$subscribe) || $subscribe) && !$is_current ? ak_pc_usage_blockers($pdo, $org_id, $p) : [];
            $summary = function_exists('ak_plan_public_summary') ? ak_plan_public_summary($p) : [];
            $css_order = $order[$slug] ?? (int)($p['display_order'] ?? 5) + 3;
        ?>
          <div class="akp-card akp-s-<?= $h(preg_replace('/[^a-z0-9-]/', '', $slug)) ?><?= $featured ? ' is-featured' : '' ?><?= $is_current ? ' is-current' : '' ?>" style="order:<?= (int)$css_order ?>;">
            <?php if ($badge): ?><div class="akp-badge"><?= $h($badge) ?></div><?php endif; ?>
            <?php if ($slug === 'ak-equipe'): ?><div class="akp-tag">Le plus complet à prix affiché</div><?php endif; ?>
            <div class="akp-name"><?= $h($p['label']) ?></div>
            <?php if (!empty($p['tagline'])): ?><div class="akp-tagline"><?= $h($p['tagline']) ?></div><?php endif; ?>
            <div class="akp-price"><?= $h(ak_pc_price_label((int)$p['price_cents'], $offer_vat)) ?></div>
            <?php if ($summary): ?><ul class="akp-sum"><?php foreach ($summary as $line): ?><li><?= $h($line) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <div class="akp-cta">
            <?php if ($is_current): ?>
              <div class="akp-state akp-state-ok"><?= $trial_card ? '✓ Formule choisie' : '✓ Formule actuelle' ?></div>
            <?php elseif ($is_pending): ?>
              <div class="akp-state akp-state-wait">Prévue<?= $pending['at_fr'] !== '' ? ' le ' . $h($pending['at_fr']) : '' ?></div>
            <?php elseif ($blockers): ?>
              <div class="akp-state akp-state-no"><?= $h(implode(' ', $blockers)) ?></div>
            <?php elseif ($pc['mode'] === 'blocked' || $sepa_pending_until): ?>
              <div class="akp-state akp-state-wait">Indisponible pour l’instant</div>
            <?php elseif ($subscribe): ?>
              <a href="/mon-asso-paiement?plan=<?= $h(rawurlencode($slug)) ?>" class="<?= $featured ? 'akp-btn akp-btn-main' : ($slug === 'ak-essentiel' ? 'akp-link' : 'akp-btn') ?>">Choisir <?= $h($p['label']) ?></a>
              <?php if ($slug === 'ak-equipe' && ($is_trial || $trial_paused)): ?><div class="akp-note">L’essai porte sur les fonctions Pro ; les fonctions et les volumes Équipe s’activent à l’abonnement.</div><?php endif; ?>
            <?php else: ?>
              <a href="/stripe-change-plan?plan=<?= $h(rawurlencode($slug)) ?>" class="<?= $kind === 'upgrade' ? 'akp-btn akp-btn-main' : 'akp-btn' ?>">Passer à <?= $h($p['label']) ?></a>
              <div class="akp-note"><?= $pc['mode'] === 'trial_card' ? 'À la fin de l’essai, sans frais d’ici là'
                  : ($pc['mode'] === 'support' || ($kind === 'upgrade' && $pc['mode'] !== 'stripe') ? 'Sur demande : nous faisons le changement'
                  : ($kind === 'upgrade' ? 'Tout de suite, au prorata' : 'À la fin du mois payé')) ?></div>
            <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($offers['pending_pro'])): ?>
          <div class="akp-card akp-s-assokit is-featured" style="order:1;">
            <div class="akp-name">Pro</div>
            <div class="akp-tagline">Associations actives et TPE</div>
            <p class="akp-quote-txt" style="color:#334155;">Pro : souscription disponible très prochainement, contactez-nous.</p>
            <div class="akp-cta"><a href="/contact?plan=assokit" class="akp-btn akp-btn-main"><?= ak_icon('phone',14) ?> Nous contacter</a></div>
          </div>
        <?php endif; ?>
        <?php if ($offers['quote']): ?>
          <div class="akp-card akp-quote" style="order:9;">
            <div class="akp-name">Sur-mesure</div>
            <div class="akp-tagline"><?= $h($offers['quote']['tagline'] ?? 'Réseaux, fédérations, collectivités') ?></div>
            <div class="akp-price">Sur devis</div>
            <p class="akp-quote-txt">Plus de 2 000 membres, plus de 25 gestionnaires, ou plusieurs structures (réseau, fédération, collectivité) ? Parlons de votre projet.</p>
            <div class="akp-cta"><a href="/contact?plan=sur-mesure" class="akp-btn"><?= ak_icon('phone',14) ?> Nous contacter</a></div>
          </div>
        <?php endif; ?>
        </div>
        <p style="font-size:12px;color:#64748B;margin:14px 0 0;line-height:1.55;">
          Sans engagement : résiliation à tout moment, effet à la fin du mois payé. <?= $h(ak_pc_vat_note($offer_vat)) ?>
          1 crédit IA = 1 texte rédigé, 1 réponse du copilote ou 1 justificatif lu.
        </p>
      </div>
    <?php return (string)ob_get_clean();
};
?>
<style>
.akp-offers { background:white; border:1px solid #E2E8F0; border-radius:14px; padding:22px 24px; }
#formules { scroll-margin-top: 80px; }
.akp-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; align-items:stretch; }
.akp-card { position:relative; background:#FAF8F5; border:1px solid #E2E8F0; border-radius:12px; padding:18px 16px 16px; display:flex; flex-direction:column; min-width:0; }
.akp-card.is-featured { background:#F0FDF4; border:2px solid #059669; box-shadow:0 8px 22px rgba(5,150,105,0.14); }
.akp-card.is-current { outline:2px solid #0F172A; outline-offset:-2px; }
.akp-badge { position:absolute; top:-11px; left:50%; transform:translateX(-50%); background:#059669; color:white; font-size:11px; font-weight:700; padding:3px 12px; border-radius:999px; white-space:nowrap; }
.akp-tag { font-size:11px; font-weight:700; color:#1E40AF; text-transform:uppercase; letter-spacing:.03em; margin-bottom:4px; }
.akp-name { font-weight:800; font-size:17px; color:#0F172A; }
.akp-tagline { font-size:12px; color:#64748B; margin:2px 0 8px; }
.akp-price { font-weight:800; font-size:16px; color:#047857; margin:2px 0 10px; }
.akp-sum { list-style:none; padding:0; margin:0 0 12px; font-size:12.5px; color:#334155; line-height:1.55; }
.akp-sum li::before { content:"✓ "; color:#059669; font-weight:700; }
.akp-cta { margin-top:auto; }
.akp-btn { display:flex; align-items:center; justify-content:center; gap:6px; background:white; color:#0F172A; border:1px solid #CBD5E1; padding:9px 10px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; text-align:center; }
.akp-btn-main { background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; border:none; }
.akp-link { display:block; text-align:center; color:#047857; font-size:13px; font-weight:700; text-decoration:underline; padding:8px 0; }
.akp-note { font-size:11.5px; color:#64748B; margin-top:6px; text-align:center; line-height:1.45; }
.akp-state { padding:8px 10px; border-radius:8px; font-size:12px; font-weight:700; text-align:center; line-height:1.4; }
.akp-state-ok { background:#0F172A; color:white; }
.akp-state-wait { background:#FEF3C7; color:#92400E; }
.akp-state-no { background:#FEF2F2; color:#991B1B; font-weight:600; }
.akp-quote { background:#0F172A; border-color:#0F172A; }
.akp-quote .akp-name, .akp-quote .akp-price { color:white; }
.akp-quote .akp-tagline, .akp-quote-txt { color:#CBD5E1; }
.akp-quote-txt { font-size:12.5px; line-height:1.5; margin:0 0 12px; }
@media (min-width: 921px) {
  .akp-s-ak-essentiel { order:1 !important; }
  .akp-s-assokit { order:2 !important; }
  .akp-s-ak-equipe { order:3 !important; }
  .akp-card.is-featured { transform:translateY(-4px); }
}
</style>
<?php if ($choose_first): ?>
  <div style="margin-bottom:24px;"><?= $render_offers() ?></div>
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
        $has_detail_vat = $detail_vat_rate !== null && $detail_vat_rate > 0;
        $ht_suffix = "\u{00A0}HT";   // prix HT partout (grille 2026), même sans TVA
        $total_split = ak_price_split((int)$pricing['total_cents'], $detail_vat_rate ?? 0.0);
        ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div>Formule <?= htmlspecialchars($subscription_status['plan_name'] ?? '') ?></div>
          <div style="font-weight:600;"><?= htmlspecialchars(ak_price_eur((int)$pricing['plan_cents']) . $ht_suffix) ?>/mois</div>
        </div>
        <?php foreach ($pricing['addons'] as $addon): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div style="color:#1E40AF;">🏆 <?= htmlspecialchars($addon['addon_name']) ?></div>
          <div style="font-weight:600;color:#1E40AF;">+<?= htmlspecialchars(ak_price_eur((int)$addon['price_cents_monthly']) . $ht_suffix) ?>/mois</div>
        </div>
        <?php endforeach; ?>
        <?php if ($has_detail_vat): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:13px;color:#475569;">
          <div>TVA <?= htmlspecialchars($total_split['rate_label']) ?></div>
          <div><?= htmlspecialchars($total_split['vat']) ?></div>
        </div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;padding:12px 0 0;border-top:2px solid #0F172A;margin-top:6px;font-size:15px;font-weight:700;">
          <div>Total mensuel<?= $has_detail_vat ? ' TTC' : '' ?></div>
          <div style="color:#059669;font-size:18px;"><?= htmlspecialchars($total_split['ttc'] . ($has_detail_vat ? '' : "\u{00A0}HT")) ?></div>
        </div>
        <?php if (!$has_detail_vat && $vat_rate <= 0): ?>
        <div style="font-size:11.5px;color:#64748B;margin-top:4px;"><?= htmlspecialchars(ak_pc_vat_note(0.0)) ?></div>
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

      <?php if (!$choose_first) echo $render_offers(); ?>

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
            <?= $after_paid ? 'Aucun abonnement en cours : aucun prélèvement.' : 'Aucune formule payante : aucun prélèvement.' ?>
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

      <!-- Option domaine personnel : proposée seulement une fois la fonction livrée (ak_addon_domain_offer :
           jamais sur Essentiel, comprise dans les formules qui l'incluent). Aujourd'hui : jamais affichée. -->
      <?php
      $has_addon = function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id);
      $domain_offer = ak_addon_domain_offer($live_stripe ? ak_stripe_plan_row($pdo, (int)$live_stripe['plan_id']) : $cur_plan);
      if ($domain_offer === 'addon' && $is_active && !$has_addon && $payment_mode === 'stripe' && !$stripe_on_free && $has_stripe_subscription && $stripe_ready):
      ?>
      <div style="background:linear-gradient(135deg, #EFF6FF 0%, white 100%);border:1px solid #BFDBFE;border-radius:14px;padding:22px;">
        <div style="font-size:32px;margin-bottom:8px;">🏆</div>
        <h3 style="margin:0 0 6px;font-size:15px;color:#1E40AF;">Domaine personnel</h3>
        <p style="font-size:12.5px;color:#475569;margin:0 0 14px;line-height:1.5;">Ajoutez votre propre domaine (adherents.tonasso.fr) : <strong>+<?= htmlspecialchars($fmt_month(AK_ADDON_DOMAIN_HT_CENTS)) ?></strong>.</p>
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
