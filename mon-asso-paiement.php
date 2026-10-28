<?php
/**
 * mon-asso-paiement.php
 * --------------------------------------------------------------
 * Page de paiement intégrée avec Stripe Payment Element.
 *
 * Fonctionnement :
 *   1. Affiche le récap du plan choisi (Assokit : prix HT + TVA de la plateforme = TTC, includes-pricing.php)
 *   2. Affiche option "+ Domaine personnalisé +10 € HT/mois"
 *   3. Affiche Stripe Payment Element : carte bancaire (avec Apple Pay / Google Pay) et, s'il est
 *      activé sur le compte Stripe, prélèvement SEPA — exactement les moyens acceptés par l'abonnement
 *      (ak_stripe_payment_method_types, la même liste que stripe-create-payment-intent.php)
 *   4. Au clic "Payer", appel AJAX /stripe-create-payment-intent.php
 *   5. Confirmation côté Stripe via Stripe.js (3-D Secure, mandat SEPA)
 *   6. Redirection vers /mon-asso-paiement-success (qui active l'abonnement sans attendre le webhook)
 *
 * Prélèvement SEPA déjà en cours de vérification (premier paiement) : aucun nouveau paiement proposé.
 * Paiement impossible pour cette association (ak_stripe_payment_refusal, même règle que
 * stripe-create-payment-intent.php : suspension par le support, abonnement réglé sur facture, mode test
 * hors des associations de test) : le message remplace le formulaire. Mode test : bandeau « MODE TEST ».
 *
 * Association déjà abonnée par carte (abonnement Stripe actif ou en retard) :
 * JAMAIS de second abonnement (double facturation). À la place :
 *   - ?action=update_card → portail client Stripe (changer la carte, payer
 *     une facture en attente), retour sur /mon-asso-plan ;
 *   - ?addon_domain=1 → confirmation, puis POST action=add_domain qui ajoute
 *     l'add-on domaine à l'abonnement existant (POST /v1/subscription_items) ;
 *   - sinon → rappel de l'abonnement en place (changement de formule : contact).
 *
 * SI Stripe pas encore configuré → affiche un message d'attente
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';
require_once __DIR__ . '/includes-pricing.php';

require_login();
// Apple 3.1.1 : aucun tunnel d'achat ne doit s'ouvrir dans l'app.
ak_block_billing_page();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'le paiement de l\'abonnement');

// Récupération paramètres
$target_plan_slug = (string)($_GET['plan'] ?? 'assokit');
$with_addon_domain = !empty($_GET['addon_domain']);
$page_action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));

// Abonnement par carte déjà en vigueur : aucun nouvel abonnement ne doit naître de cette page
$live_stripe = function_exists('ak_org_live_stripe_subscription') ? ak_org_live_stripe_subscription($pdo, $org_id) : null;
$has_addon = function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id);
$addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);
// Add-on ajouté à l'abonnement par carte en place : le montant tel qu'il sera prélevé (même règle que
// « Mon abonnement ») : « 10,00 € HT/mois, soit 12,00 € TTC » si l'abonnement porte la TVA, sinon « 10,00 €/mois »
$live_vat_rate = function_exists('ak_live_sub_vat_rate') ? ak_live_sub_vat_rate($pdo, $org_id, $live_stripe) : null;
$addon_live = ak_price_split(AK_ADDON_DOMAIN_HT_CENTS, $live_vat_rate ?? 0.0);
$addon_live_label = $live_vat_rate === null ? ak_price_eur(AK_ADDON_DOMAIN_HT_CENTS) . '/mois'
    : $addon_live['per_month'] . ', soit ' . $addon_live['ttc'] . ' TTC';

// « Changer ma CB » : portail client Stripe (carte, factures en attente), jamais un nouvel abonnement
if ($page_action === 'update_card') {
    $customer_id = function_exists('ak_get_stripe_customer_id') ? ak_get_stripe_customer_id($pdo, $org_id) : null;
    if (!ak_stripe_is_configured($pdo) || !$customer_id) {
        $_SESSION['flash_error'] = 'Aucune carte bancaire n’est enregistrée pour votre association. Pour toute question, contactez-nous.';
        header('Location: /mon-asso-plan');
        exit;
    }
    // Configuration du portail créée par « Configurer Stripe automatiquement » (changer de carte, factures,
    // résiliation en fin de période), en français. Sans elle, Stripe prend la configuration par défaut du compte.
    $portal = [
        'customer'   => $customer_id,
        'return_url' => ak_stripe_site_base() . '/mon-asso-plan',
        'locale'     => 'fr',
    ];
    $bpc = (string)ak_stripe_config_get($pdo, 'billing_portal_configuration_id_' . ak_stripe_get_mode($pdo), '');
    if ($bpc !== '') $portal['configuration'] = $bpc;
    $resp = ak_stripe_api_call($pdo, 'POST', '/billing_portal/sessions', $portal);
    $portal_url = (string)($resp['data']['url'] ?? '');
    if (!empty($resp['ok']) && preg_match('#^https://[a-z0-9.-]*stripe\.com/#i', $portal_url)) {
        header('Location: ' . $portal_url);
        exit;
    }
    error_log('[mon-asso-paiement] portail Stripe org #' . $org_id . ' : ' . ($resp['error'] ?? 'réponse invalide'));
    $_SESSION['flash_error'] = 'La gestion de la carte bancaire est momentanément indisponible. Contactez-nous : nous la mettons à jour avec vous.';
    header('Location: /mon-asso-plan');
    exit;
}

// Add-on domaine sur un abonnement par carte déjà actif : ajouté à CET abonnement (prorata sur la
// prochaine facture), jamais un second abonnement qui refacturerait le plan
$addon_possible = $live_stripe && $live_stripe['status'] === 'active' && empty($live_stripe['cancel_at_period_end'])
    && !$has_addon && !empty($addon_price_id) && ak_stripe_is_configured($pdo);
if ($live_stripe && $_SERVER['REQUEST_METHOD'] === 'POST' && $page_action === 'add_domain') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée, veuillez réessayer.';
    } elseif (!$addon_possible) {
        $_SESSION['flash_error'] = $has_addon ? 'Le domaine personnalisé est déjà inclus dans votre abonnement.'
            : 'L’ajout du domaine personnalisé n’est pas possible sur votre abonnement actuel. Contactez-nous.';
    } else {
        // Clé d'idempotence : un double envoi du formulaire n'ajoute jamais l'option deux fois
        $resp = ak_stripe_api_call($pdo, 'POST', '/subscription_items', [
            'subscription'       => $live_stripe['stripe_subscription_id'],
            'price'              => $addon_price_id,
            'proration_behavior' => 'create_prorations',
            'metadata[org_id]'   => (string)$org_id,
        ], 'ak-addon-' . $live_stripe['stripe_subscription_id'] . '-' . $addon_price_id);
        if (!empty($resp['ok'])) {
            // Le webhook customer.subscription.updated l'enregistre aussi (même ligne, sans doublon)
            try {
                ak_db_insert($pdo, 'asso_subscription_addons', [
                    'subscription_id' => (int)$live_stripe['id'], 'org_id' => $org_id, 'addon_type' => 'custom_domain',
                    'addon_name' => 'Domaine personnalisé', 'price_cents_monthly' => AK_ADDON_DOMAIN_HT_CENTS, 'stripe_price_id' => $addon_price_id,
                    'stripe_subscription_item_id' => $resp['data']['id'] ?? null, 'status' => 'active',
                ], ['created_at' => 'NOW()']);
            } catch (Throwable $e) {
                error_log('[mon-asso-paiement] add-on local org #' . $org_id . ' : ' . get_class($e));
            }
            $_SESSION['flash_success'] = 'Domaine personnalisé ajouté à votre abonnement : +' . $addon_live_label . '. La période en cours est facturée au prorata sur la prochaine facture. Nous configurons votre domaine et vous écrivons dès qu’il est prêt.';
            if (function_exists('ak_stripe_notify_founder')) {
                ak_stripe_notify_founder($pdo, '🏆 Domaine personnalisé à mettre en place',
                    '<p>« ' . ak_stripe_h((string)($user['org_name'] ?? ('Association n°' . $org_id))) . ' » a ajouté l’option Domaine personnalisé à son abonnement.</p><p>Configurez son domaine dans le cockpit (Domaines).</p>', $org_id);
            }
        } else {
            error_log('[mon-asso-paiement] add-on Stripe org #' . $org_id . ' : ' . ($resp['error'] ?? '?'));
            $_SESSION['flash_error'] = 'L’ajout du domaine personnalisé a échoué. Aucun montant n’a été prélevé. Réessayez ou contactez-nous.';
        }
    }
    header('Location: /mon-asso-plan');
    exit;
}

// Récupération du plan cible
$plan = null;
try {
    $st = $pdo->prepare("SELECT * FROM asso_plans WHERE slug = :s AND is_visible = 1 LIMIT 1");
    $st->execute([':s' => $target_plan_slug]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* skip */ }

if (!$plan) {
    $_SESSION['flash_error'] = 'Plan introuvable.';
    header('Location: /mon-asso-plan');
    exit;
}

// Seules les formules payantes se règlent ici : Démarrage n'est plus proposée, l'essai ne s'achète pas,
// Sur-mesure est sur devis (stripe-create-payment-intent.php refuse aussi ces formules)
if (!$live_stripe && ((int)($plan['price_cents'] ?? 0) <= 0 || ($plan['slug'] ?? '') === 'sur-mesure'
    || !empty($plan['is_custom_quote']) || !empty($plan['is_trial']) || ($plan['slug'] ?? '') === 'pro-essai')) {
    if (($plan['slug'] ?? '') === 'sur-mesure' || !empty($plan['is_custom_quote'])) {
        header('Location: /contact?plan=sur-mesure');
        exit;
    }
    $_SESSION['flash_error'] = 'Cette formule ne se souscrit pas en ligne : choisissez Assokit pour continuer.';
    header('Location: /mon-asso-plan');
    exit;
}

// Détection si Stripe est configuré
$stripe_ready = ak_stripe_is_configured($pdo);
$stripe_pk = $stripe_ready ? ak_stripe_get_publishable_key($pdo) : null;
// Moyens de paiement : exactement ceux que l'abonnement acceptera (stripe-create-payment-intent.php)
$pm_types = function_exists('ak_stripe_payment_method_types') ? ak_stripe_payment_method_types($pdo) : ['card'];
// Premier prélèvement SEPA déjà en cours de vérification : pas de second paiement
$pending_until = function_exists('ak_stripe_payment_pending_until') ? ak_stripe_payment_pending_until($pdo, $org_id) : null;
// Paiement impossible pour cette association (le formulaire n'est pas montré). Paiements pas encore
// ouverts : le bloc « bientôt disponible » plus bas suffit.
$refusal = ak_stripe_payment_refusal($pdo, $org_id);
if ($refusal && $refusal['kind'] === 'test_mode' && !$stripe_ready) $refusal = null;
$test_mode = $stripe_ready && ak_stripe_get_mode($pdo) === 'test';

// Calculs prix : formule et add-on HT, TVA de la plateforme (celle que Stripe ajoute), total TTC prélevé
$plan_cents = (int)($plan['price_cents'] ?? 0);
$addon_cents = $with_addon_domain ? AK_ADDON_DOMAIN_HT_CENTS : 0; // +10 € HT/mois
$vat_rate = function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0;
$price = ak_price_split($plan_cents + $addon_cents, $vat_rate);
// Stripe calcule la TVA ligne par ligne (formule, puis option), arrondie au centime sur chaque ligne :
// même calcul ici, pour que le montant affiché (et celui de la feuille Apple Pay / Google Pay) soit
// exactement celui de la première facture
if ($addon_cents > 0 && $vat_rate > 0) {
    $line_vat = ak_price_split($plan_cents, $vat_rate)['vat_cents'] + ak_price_split($addon_cents, $vat_rate)['vat_cents'];
    if ($line_vat !== $price['vat_cents']) {
        $price['vat_cents'] = $line_vat;
        $price['ttc_cents'] = $plan_cents + $addon_cents + $line_vat;
        $price['vat'] = ak_price_eur($line_vat);
        $price['ttc'] = ak_price_eur($price['ttc_cents']);
    }
}
$total_cents = $price['ttc_cents'];
$total_str = $price['ttc'];

// Essai en cours / terminé : rappelé au-dessus du paiement (même texte que « Mon abonnement »)
$trial_info = function_exists('ak_plan_trial_info') ? ak_plan_trial_info($pdo, $org_id) : ['in_trial' => false, 'trial_expired' => false];
$plan_label = function_exists('ak_plan_display_name') ? ak_plan_display_name($plan) : (string)$plan['name'];

render_head('Paiement — ' . $plan_label);
render_sidebar('mon-asso-paiement');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/mon-asso-plan">Mon abonnement</a>
    <span class="sep">›</span>
    <span class="current">💳 Paiement</span>
  </nav>

  <?php if ($live_stripe):
    $live_label = function_exists('ak_plan_label') ? ak_plan_label($live_stripe['plan_slug'] ?? '', $live_stripe['plan_name'] ?? null) : (string)($live_stripe['plan_name'] ?? '');
    $live_end_fr = !empty($live_stripe['current_period_end']) && strtotime((string)$live_stripe['current_period_end']) ? date('d/m/Y', strtotime((string)$live_stripe['current_period_end'])) : '';
    $btn = 'display:inline-flex;align-items:center;gap:7px;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;';
  ?>

  <!-- ============================================ -->
  <!-- Abonnement par carte déjà en place : pas de second abonnement -->
  <!-- ============================================ -->
  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💳 Mon abonnement par carte</h1>
      <p style="color:#64748B;margin:0;">Plan <strong><?= htmlspecialchars($live_label) ?></strong> — déjà réglé par carte via Stripe</p>
    </div>
  </div>

  <?php if ($with_addon_domain && $addon_possible): ?>
    <div style="background:linear-gradient(135deg, #EFF6FF 0%, white 100%);border:1px solid #BFDBFE;border-radius:14px;padding:28px;max-width:640px;">
      <div style="font-size:32px;margin-bottom:8px;">🏆</div>
      <h2 style="margin:0 0 8px;font-size:18px;color:#1E40AF;">Ajouter un domaine personnalisé</h2>
      <p style="font-size:14px;color:#475569;margin:0 0 18px;line-height:1.6;">
        <strong>+<?= htmlspecialchars($addon_live_label) ?></strong> ajoutés à votre abonnement <?= htmlspecialchars($live_label) ?> en cours, avec la même carte.
        La période déjà entamée est facturée au prorata sur votre prochaine facture. Aucun nouvel abonnement, votre plan n’est pas refacturé.
      </p>
      <form method="post" action="/mon-asso-paiement" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '')) ?>">
        <input type="hidden" name="action" value="add_domain">
        <button type="submit" style="<?= $btn ?>background:#1E40AF;color:white;border:none;cursor:pointer;">Confirmer l’ajout (+<?= htmlspecialchars($addon_live_label) ?>)</button>
        <a href="/mon-asso-plan" style="<?= $btn ?>background:white;color:#475569;border:1px solid #E2E8F0;">← Retour</a>
      </form>
    </div>
  <?php else: ?>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:28px;max-width:640px;">
      <?php if ($live_stripe['status'] === 'overdue'): ?>
        <p style="font-size:14px;color:#991B1B;margin:0 0 16px;line-height:1.6;">⚠️ Le dernier paiement de votre abonnement n’a pas abouti. Mettez à jour votre carte bancaire : le paiement est relancé, sans nouvel abonnement.</p>
      <?php elseif (!empty($live_stripe['cancel_at_period_end'])): ?>
        <p style="font-size:14px;color:#92400E;margin:0 0 16px;line-height:1.6;">🛑 Résiliation programmée<?= $live_end_fr !== '' ? ' le ' . htmlspecialchars($live_end_fr) : '' ?>. Pour reprendre votre abonnement ou changer de formule, contactez-nous.</p>
      <?php else: ?>
        <p style="font-size:14px;color:#475569;margin:0 0 16px;line-height:1.6;">
          Votre association a déjà un abonnement par carte en cours : il n’y a rien à payer ici.
          <?php if ($with_addon_domain && $has_addon): ?>Le domaine personnalisé est déjà inclus.<?php endif; ?>
          Pour changer de formule, contactez-nous : nous ajustons votre abonnement sans double prélèvement.
        </p>
      <?php endif; ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($stripe_ready && empty($live_stripe['cancel_at_period_end'])): ?>
          <a href="/mon-asso-paiement?action=update_card" style="<?= $btn ?>background:#059669;color:white;">💳 Changer ma carte bancaire</a>
        <?php endif; ?>
        <a href="/contact?subject=billing" style="<?= $btn ?>background:white;color:#475569;border:1px solid #E2E8F0;">📞 Nous contacter</a>
        <a href="/mon-asso-plan" style="<?= $btn ?>background:white;color:#475569;border:1px solid #E2E8F0;">← Mon abonnement</a>
      </div>
    </div>
  <?php endif; ?>

  <?php else: ?>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💳 Finaliser votre abonnement</h1>
      <p style="color:#64748B;margin:0;">Plan <strong><?= htmlspecialchars($plan_label) ?></strong> — paiement sécurisé par Stripe</p>
    </div>
  </div>

  <?php if (!empty($trial_info['in_trial']) || !empty($trial_info['trial_expired'])): ?>
    <?php $trial_over = !empty($trial_info['trial_expired']); ?>
    <div style="background:<?= $trial_over ? '#FEF2F2' : '#FFFBEB' ?>;border:1px solid <?= $trial_over ? '#FECACA' : '#FCD34D' ?>;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:<?= $trial_over ? '#991B1B' : '#92400E' ?>;font-size:14px;line-height:1.5;">
      <span style="font-size:22px;line-height:1;"><?= $trial_over ? '⏳' : '🎁' ?></span>
      <div style="flex:1;min-width:220px;">
        <strong><?= htmlspecialchars($trial_info['label']) ?></strong><br>
        <?php if ($trial_over): ?>
          Vos données sont conservées : une fois le paiement confirmé, votre association passe au plan <?= htmlspecialchars($plan_label) ?>.
        <?php else: ?>
          Votre abonnement commence dès le paiement : le premier mois est prélevé aujourd’hui et l’essai gratuit s’arrête (les jours d’essai restants ne sont pas reportés). Vos données et réglages sont conservés.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($pending_until): ?>

    <!-- ============================================ -->
    <!-- Prélèvement SEPA en cours de vérification     -->
    <!-- ============================================ -->
    <div style="background:linear-gradient(135deg, #FEF3C7 0%, #FAF8F5 100%); border:1px solid #FCD34D; border-radius:14px; padding:32px; text-align:center;">
      <div style="font-size:48px; margin-bottom:12px;">⏳</div>
      <h2 style="margin:0 0 12px; font-size:21px; color:#92400E;">Prélèvement SEPA en cours de vérification</h2>
      <p style="color:#78350F; line-height:1.65; max-width:560px; margin:0 auto 20px; font-size:15px;">
        Votre banque confirme le prélèvement de votre abonnement : cela prend quelques jours ouvrés. Inutile de payer une seconde fois :
        votre abonnement s’active automatiquement dès la confirmation, et vous gardez l’accès à Assokit d’ici là.
      </p>
      <a href="/mon-asso-plan" style="background:#92400E; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">← Mon abonnement</a>
    </div>

  <?php elseif ($refusal): ?>

    <!-- ============================================ -->
    <!-- Paiement impossible pour cette association   -->
    <!-- ============================================ -->
    <div style="background:<?= $refusal['kind'] === 'test_mode' ? 'linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%)' : '#FFFBEB' ?>; border:1px solid <?= $refusal['kind'] === 'test_mode' ? '#A7F3D0' : '#FCD34D' ?>; border-radius:14px; padding:32px; text-align:center;">
      <div style="font-size:48px; margin-bottom:12px;"><?= $refusal['kind'] === 'test_mode' ? '🚧' : '📞' ?></div>
      <h2 style="margin:0 0 12px; font-size:21px; color:<?= $refusal['kind'] === 'test_mode' ? '#047857' : '#92400E' ?>;"><?= $refusal['kind'] === 'test_mode' ? 'Paiement en ligne bientôt disponible' : 'Paiement en ligne indisponible pour votre espace' ?></h2>
      <p style="color:#475569; line-height:1.65; max-width:560px; margin:0 auto 20px; font-size:15px;"><?= htmlspecialchars($refusal['message']) ?></p>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a href="/contact?subject=billing" style="background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">📞 Nous contacter</a>
        <a href="/mon-asso-plan" style="background:white; color:#475569; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid #E2E8F0;">← Mon abonnement</a>
      </div>
    </div>

  <?php elseif (!$stripe_ready): ?>

    <!-- ============================================ -->
    <!-- MODE 1 : Stripe non configuré (placeholder)  -->
    <!-- ============================================ -->
    <div style="background:linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%); border:1px solid #A7F3D0; border-radius:14px; padding:36px 32px; text-align:center;">
      <div style="font-size:54px; margin-bottom:14px;">🚧</div>
      <h2 style="margin:0 0 12px; font-size:22px; color:#047857;">Paiement par carte bientôt disponible</h2>
      <p style="color:#475569; line-height:1.65; max-width:540px; margin:0 auto 22px; font-size:15px;">
        Le paiement en ligne (carte bancaire, Apple Pay, Google Pay, prélèvement SEPA) sera activé très prochainement. En attendant, contactez-nous pour activer votre abonnement par virement.
      </p>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a href="/contact?subject=billing" style="background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">📞 Nous contacter</a>
        <a href="/mon-asso-plan" style="background:white; color:#475569; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid #E2E8F0;">← Retour</a>
      </div>
    </div>

  <?php else: ?>

    <!-- ============================================ -->
    <!-- MODE 2 : Stripe configuré → formulaire actif -->
    <!-- ============================================ -->
    <?php if ($test_mode): ?>
    <div style="background:#FEF3C7;border:2px dashed #D97706;border-radius:12px;padding:12px 16px;margin-bottom:18px;color:#92400E;font-size:14px;line-height:1.5;">
      🧪 <strong>MODE TEST — aucun vrai prélèvement.</strong> Payez avec une carte de test de Stripe (4242 4242 4242 4242, date future, n’importe quel code) : une vraie carte est refusée.
    </div>
    <?php endif; ?>
    <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:24px; align-items:start;">

      <!-- COLONNE GAUCHE : Stripe Payment Element -->
      <div style="background:white; border:1px solid #E2E8F0; border-radius:14px; padding:28px;">
        <h2 style="margin:0 0 6px; font-size:18px;">🔐 Paiement sécurisé</h2>
        <p style="color:#64748B; font-size:13.5px; margin:0 0 20px;"><?= in_array('sepa_debit', $pm_types, true) ? 'Carte bancaire, Apple Pay ou Google Pay (selon votre appareil), ou prélèvement SEPA.' : 'Carte bancaire, Apple Pay ou Google Pay (selon votre appareil).' ?></p>

        <form id="payment-form">
          <!-- Container du Payment Element (rempli par Stripe.js) -->
          <div id="payment-element" style="margin-bottom:18px;"></div>

          <!-- Container des messages d'erreur -->
          <div id="payment-message" style="display:none; padding:12px 14px; background:#FEE2E2; border:1px solid #FECACA; border-radius:8px; color:#991B1B; font-size:13px; margin-bottom:14px;"></div>

          <button id="submit-button" type="submit" style="width:100%; background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:14px 24px; border:none; border-radius:12px; font-weight:600; font-size:15px; cursor:pointer; box-shadow:0 4px 14px rgba(5,150,105,0.30); display:flex; align-items:center; justify-content:center; gap:10px;">
            <span id="button-spinner" style="display:none; width:18px; height:18px; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span>
            <span id="button-text">💳 Payer <?= htmlspecialchars($total_str) ?><?= $price['has_vat'] ? ' TTC' : '' ?>/mois</span>
          </button>
          <p style="font-size:12px; color:#64748B; line-height:1.55; margin:12px 0 0; text-align:center;">
            En confirmant, vous acceptez les <a href="/cgu#paiement" target="_blank" rel="noopener" style="color:#059669;">conditions générales</a>.
            Premier mois prélevé aujourd’hui, puis renouvellement le <?= (int)date('j') ?> de chaque mois<?= (int)date('j') > 28 ? ' (ou le dernier jour du mois)' : '' ?> ;
            résiliation à tout moment depuis « Mon abonnement », effet à la fin du mois payé ; aucun remboursement au prorata.
          </p>
        </form>

        <!-- Logos confiance -->
        <div style="display:flex; align-items:center; justify-content:center; gap:16px; margin-top:18px; padding-top:18px; border-top:1px solid #F1F5F9; flex-wrap:wrap;">
          <div style="font-size:11px; color:#64748B; font-weight:600; letter-spacing:0.05em;">PAIEMENT SÉCURISÉ PAR</div>
          <div style="font-weight:700; color:#635BFF; font-size:16px;">stripe</div>
          <div style="font-size:11px; color:#64748B;">🔒 SSL 256-bit · PCI-DSS · 3D Secure</div>
        </div>
      </div>

      <!-- COLONNE DROITE : Récap commande -->
      <div style="background:linear-gradient(135deg, #FAF8F5 0%, white 100%); border:1px solid #E2E8F0; border-radius:14px; padding:28px; position:sticky; top:24px;">
        <h2 style="margin:0 0 18px; font-size:17px;">📋 Récapitulatif</h2>

        <!-- Plan -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:10px 0; border-bottom:1px solid #F1F5F9;">
          <div style="flex:1;">
            <div style="font-weight:600; font-size:14px;">Plan <?= htmlspecialchars($plan_label) ?></div>
            <div style="color:#64748B; font-size:12px; margin-top:2px;"><?= htmlspecialchars($plan['tagline'] ?? 'Abonnement mensuel') ?></div>
          </div>
          <div style="font-weight:700; font-size:14px; white-space:nowrap;"><?= htmlspecialchars(ak_price_eur($plan_cents)) ?><?= $price['has_vat'] ? ' HT' : '' ?>/mois</div>
        </div>

        <!-- Add-on domaine (option) -->
        <?php if ($with_addon_domain): ?>
        <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:10px 0; border-bottom:1px solid #F1F5F9;">
          <div style="flex:1;">
            <div style="font-weight:600; font-size:14px; color:#1E40AF;">🏆 Add-on Domaine perso</div>
            <div style="color:#64748B; font-size:12px; margin-top:2px;">adherents.tonasso.fr + emails</div>
          </div>
          <div style="font-weight:700; font-size:14px; color:#1E40AF; white-space:nowrap;">+<?= htmlspecialchars(ak_price_eur($addon_cents)) ?><?= $price['has_vat'] ? ' HT' : '' ?>/mois</div>
        </div>
        <?php else: ?>
        <!-- Bouton ajouter add-on -->
        <a href="?plan=<?= htmlspecialchars($target_plan_slug) ?>&addon_domain=1" style="display:block; padding:14px; background:#EFF6FF; border:1px dashed #BFDBFE; border-radius:10px; margin:10px 0; text-decoration:none; color:#1E40AF; text-align:center;">
          <div style="font-weight:700; font-size:13.5px; margin-bottom:3px;">🏆 + Ajouter un domaine personnalisé</div>
          <div style="font-size:12px; opacity:0.85;">+<?= htmlspecialchars(ak_price_eur(AK_ADDON_DOMAIN_HT_CENTS)) ?><?= $price['has_vat'] ? ' HT' : '' ?>/mois · adherents.tonasso.fr + emails à votre nom</div>
        </a>
        <?php endif; ?>

        <!-- Total : HT + TVA = TTC prélevé chaque mois -->
        <?php if ($price['has_vat']): ?>
        <div style="padding:12px 0 0; margin-top:6px; border-top:2px solid #0F172A; font-size:13px; color:#475569; line-height:1.7;">
          <div style="display:flex; justify-content:space-between;"><span>Total HT</span><span style="white-space:nowrap;"><?= htmlspecialchars($price['ht']) ?></span></div>
          <div style="display:flex; justify-content:space-between;"><span>TVA <?= htmlspecialchars($price['rate_label']) ?></span><span style="white-space:nowrap;"><?= htmlspecialchars($price['vat']) ?></span></div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0 6px;">
          <div style="font-weight:700; font-size:16px;">Total TTC / mois</div>
          <div style="font-weight:800; font-size:22px; color:#059669; white-space:nowrap;"><?= htmlspecialchars($price['ttc']) ?></div>
        </div>
        <div style="font-size:11.5px; color:#64748B; margin-top:4px;">
          <?= htmlspecialchars($price['ht']) ?> HT + TVA <?= htmlspecialchars($price['rate_label']) ?> (<?= htmlspecialchars($price['vat']) ?>) = <?= htmlspecialchars($price['ttc']) ?> TTC / mois
        </div>
        <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0 6px; margin-top:6px; border-top:2px solid #0F172A;">
          <div style="font-weight:700; font-size:16px;">Total mensuel</div>
          <div style="font-weight:800; font-size:22px; color:#059669; white-space:nowrap;"><?= htmlspecialchars($total_str) ?></div>
        </div>
        <div style="font-size:11px; color:#64748B; margin-top:6px;">
          TVA non applicable · art. 293 B du CGI
        </div>
        <?php endif; ?>

        <!-- Garanties -->
        <div style="background:#F0FDF4; border:1px solid #A7F3D0; border-radius:8px; padding:12px 14px; margin-top:18px; font-size:12px; color:#047857; line-height:1.55;">
          ✅ <strong>Sans engagement</strong> — résiliable à tout moment, effet en fin de période en cours<br>
          ✅ <strong>Renouvellement automatique</strong> chaque mois<br>
          ✅ <strong>Vos données et réglages</strong> sont conservés
        </div>

        <!-- Lien retour -->
        <a href="/mon-asso-plan" style="display:block; text-align:center; margin-top:14px; color:#64748B; font-size:12.5px; text-decoration:none;">← Retour au choix du plan</a>
      </div>
    </div>

    <!-- =========== STRIPE.JS =========== -->
    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function() {
      const stripe = Stripe(<?= json_encode($stripe_pk) ?>, { locale: 'fr' });
      // Mode « abonnement » sans intention préalable : l'abonnement est créé au clic sur « Payer ».
      // paymentMethodTypes = exactement les moyens acceptés par l'abonnement (sinon confirmPayment échoue).
      const elements = stripe.elements({
        mode: 'subscription',
        amount: <?= (int)$total_cents ?>,
        currency: 'eur',
        paymentMethodTypes: <?= json_encode(array_values($pm_types)) ?>,
        appearance: {
          theme: 'stripe',
          variables: {
            colorPrimary: '#059669',
            colorBackground: '#ffffff',
            colorText: '#0F172A',
            colorDanger: '#DC2626',
            fontFamily: 'system-ui, sans-serif',
            spacingUnit: '4px',
            borderRadius: '8px'
          }
        }
      });

      const paymentElement = elements.create('payment', {
        layout: 'tabs',
        defaultValues: {
          billingDetails: {
            email: <?= json_encode($user['email'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            name: <?= json_encode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')), JSON_HEX_TAG | JSON_HEX_AMP) ?>
          }
        }
      });
      paymentElement.mount('#payment-element');

      const form = document.getElementById('payment-form');
      const submitBtn = document.getElementById('submit-button');
      const buttonText = document.getElementById('button-text');
      const buttonSpinner = document.getElementById('button-spinner');
      const messageDiv = document.getElementById('payment-message');

      function setLoading(isLoading) {
        if (isLoading) {
          submitBtn.disabled = true;
          buttonText.textContent = 'Traitement en cours…';
          buttonSpinner.style.display = 'inline-block';
        } else {
          submitBtn.disabled = false;
          buttonText.textContent = <?= json_encode('💳 Payer ' . $total_str . ($price['has_vat'] ? ' TTC' : '') . '/mois', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
          buttonSpinner.style.display = 'none';
        }
      }

      function showMessage(msg) {
        messageDiv.textContent = msg;
        messageDiv.style.display = 'block';
      }

      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        setLoading(true);
        messageDiv.style.display = 'none';

        try {
          // 1. Submit elements (validation côté client)
          const { error: submitError } = await elements.submit();
          if (submitError) {
            showMessage(submitError.message || 'Erreur de validation');
            setLoading(false);
            return;
          }

          // 2. Création du PaymentIntent côté serveur
          const response = await fetch('/stripe-create-payment-intent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
              plan_slug: <?= json_encode($target_plan_slug) ?>,
              with_addon_domain: <?= $with_addon_domain ? 'true' : 'false' ?>
            })
          });
          let data = {};
          try { data = await response.json(); } catch (e) { data = { error: 'Réponse inattendue du serveur. Aucun montant n’a été prélevé.' }; }
          const { clientSecret, redirect } = data;
          // Session refusée (compte désactivé, espace fermé…) : la réponse porte le code dans « error » et le texte dans « message »
          const serverError = (data.ok === false && data.message) ? data.message : data.error;

          if (serverError || !clientSecret) {
            showMessage(serverError || 'Le paiement n’a pas pu être préparé. Aucun montant n’a été prélevé.');
            setLoading(false);
            // Abonnement déjà en place ou prélèvement en cours : retour à « Mon abonnement »
            if (redirect) setTimeout(function () { window.location.href = redirect; }, 3500);
            return;
          }

          // 3. Confirmation du paiement chez Stripe (3-D Secure et mandat SEPA gérés par Stripe.js)
          const { error: confirmError } = await stripe.confirmPayment({
            elements,
            clientSecret,
            confirmParams: {
              return_url: window.location.origin + '/mon-asso-paiement-success'
            }
          });

          if (confirmError) {
            // Carte refusée, 3-D Secure non validé… : rien n'est prélevé, on peut réessayer (même abonnement)
            showMessage((confirmError.message || 'Le paiement n’a pas abouti.') + ' Aucun montant n’a été prélevé.');
            setLoading(false);
          }
          // Si pas d'erreur, Stripe redirige automatiquement
        } catch (e) {
          showMessage('Erreur réseau : ' + e.message + '. Vérifiez votre connexion puis réessayez.');
          setLoading(false);
        }
      });
    })();
    </script>

    <style>
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 920px) {
      main.main > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
      }
    }
    </style>

  <?php endif; ?>

  <?php endif; /* $live_stripe */ ?>

</main>

<?php render_foot(); ?>
