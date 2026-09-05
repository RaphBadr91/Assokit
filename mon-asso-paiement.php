<?php
/**
 * mon-asso-paiement.php
 * --------------------------------------------------------------
 * Page de paiement intégrée avec Stripe Payment Element.
 *
 * Fonctionnement :
 *   1. Affiche le récap du plan choisi (Assokit 49,99€/mois)
 *   2. Affiche option "+ Domaine personnalisé +10€/mois"
 *   3. Affiche Stripe Payment Element (CB / Apple Pay / Google Pay / SEPA / Klarna)
 *   4. Au clic "Payer", appel AJAX /stripe-create-payment-intent.php
 *   5. Confirmation côté Stripe via Stripe.js
 *   6. Redirection vers /mon-asso-paiement-success
 *
 * SI Stripe pas encore configuré → affiche un message d'attente
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'le paiement de l\'abonnement');

// Récupération paramètres
$target_plan_slug = (string)($_GET['plan'] ?? 'assokit');
$with_addon_domain = !empty($_GET['addon_domain']);

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

// Détection si Stripe est configuré
$stripe_ready = ak_stripe_is_configured($pdo);
$stripe_pk = $stripe_ready ? ak_stripe_get_publishable_key($pdo) : null;
$addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);

// Calculs prix
$plan_cents = (int)($plan['price_cents'] ?? 0);
$addon_cents = $with_addon_domain ? 1000 : 0; // +10€/mois
$total_cents = $plan_cents + $addon_cents;
$total_str = ak_format_price_cents($total_cents);

render_head('Paiement — ' . $plan['name']);
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

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💳 Finaliser votre abonnement</h1>
      <p style="color:#64748B;margin:0;">Plan <strong><?= htmlspecialchars($plan['name']) ?></strong> — paiement sécurisé par Stripe</p>
    </div>
  </div>

  <?php if (!$stripe_ready): ?>

    <!-- ============================================ -->
    <!-- MODE 1 : Stripe non configuré (placeholder)  -->
    <!-- ============================================ -->
    <div style="background:linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%); border:1px solid #A7F3D0; border-radius:14px; padding:36px 32px; text-align:center;">
      <div style="font-size:54px; margin-bottom:14px;">🚧</div>
      <h2 style="margin:0 0 12px; font-size:22px; color:#047857;">Paiement par carte bientôt disponible</h2>
      <p style="color:#475569; line-height:1.65; max-width:540px; margin:0 auto 22px; font-size:15px;">
        Notre système de paiement automatique sera activé très prochainement (Apple Pay, Google Pay, CB, SEPA, Klarna). En attendant, contactez-nous pour activer votre abonnement par virement.
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
    <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:24px; align-items:start;">

      <!-- COLONNE GAUCHE : Stripe Payment Element -->
      <div style="background:white; border:1px solid #E2E8F0; border-radius:14px; padding:28px;">
        <h2 style="margin:0 0 6px; font-size:18px;">🔐 Paiement sécurisé</h2>
        <p style="color:#64748B; font-size:13.5px; margin:0 0 20px;">Toutes les méthodes de paiement disponibles selon votre appareil.</p>

        <form id="payment-form">
          <!-- Container du Payment Element (rempli par Stripe.js) -->
          <div id="payment-element" style="margin-bottom:18px;"></div>

          <!-- Container des messages d'erreur -->
          <div id="payment-message" style="display:none; padding:12px 14px; background:#FEE2E2; border:1px solid #FECACA; border-radius:8px; color:#991B1B; font-size:13px; margin-bottom:14px;"></div>

          <button id="submit-button" type="submit" style="width:100%; background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:14px 24px; border:none; border-radius:12px; font-weight:600; font-size:15px; cursor:pointer; box-shadow:0 4px 14px rgba(5,150,105,0.30); display:flex; align-items:center; justify-content:center; gap:10px;">
            <span id="button-spinner" style="display:none; width:18px; height:18px; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span>
            <span id="button-text">💳 Payer <?= htmlspecialchars($total_str) ?>/mois</span>
          </button>
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
            <div style="font-weight:600; font-size:14px;">Plan <?= htmlspecialchars($plan['name']) ?></div>
            <div style="color:#64748B; font-size:12px; margin-top:2px;"><?= htmlspecialchars($plan['tagline'] ?? 'Abonnement mensuel') ?></div>
          </div>
          <div style="font-weight:700; font-size:14px; white-space:nowrap;"><?= ak_format_price_cents($plan_cents) ?>/mois</div>
        </div>

        <!-- Add-on domaine (option) -->
        <?php if ($with_addon_domain): ?>
        <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:10px 0; border-bottom:1px solid #F1F5F9;">
          <div style="flex:1;">
            <div style="font-weight:600; font-size:14px; color:#1E40AF;">🏆 Add-on Domaine perso</div>
            <div style="color:#64748B; font-size:12px; margin-top:2px;">adherents.tonasso.fr + emails</div>
          </div>
          <div style="font-weight:700; font-size:14px; color:#1E40AF; white-space:nowrap;">+10,00€/mois</div>
        </div>
        <?php else: ?>
        <!-- Bouton ajouter add-on -->
        <a href="?plan=<?= htmlspecialchars($target_plan_slug) ?>&addon_domain=1" style="display:block; padding:14px; background:#EFF6FF; border:1px dashed #BFDBFE; border-radius:10px; margin:10px 0; text-decoration:none; color:#1E40AF; text-align:center;">
          <div style="font-weight:700; font-size:13.5px; margin-bottom:3px;">🏆 + Ajouter un domaine personnalisé</div>
          <div style="font-size:12px; opacity:0.85;">+10€/mois · adherents.tonasso.fr + emails à votre nom</div>
        </a>
        <?php endif; ?>

        <!-- Total -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0 6px; margin-top:6px; border-top:2px solid #0F172A;">
          <div style="font-weight:700; font-size:16px;">Total mensuel</div>
          <div style="font-weight:800; font-size:22px; color:#059669;"><?= htmlspecialchars($total_str) ?></div>
        </div>

        <?php if (!ak_vat_is_enabled($pdo)): ?>
        <div style="font-size:11px; color:#64748B; margin-top:6px;">
          TVA non applicable · art. 293 B du CGI
        </div>
        <?php else: ?>
        <div style="font-size:11px; color:#64748B; margin-top:6px;">
          Dont TVA (<?= ak_vat_get_rate($pdo) ?>%) : <?= ak_format_price_cents((int)round($total_cents * (ak_vat_get_rate($pdo) / (100 + ak_vat_get_rate($pdo))))) ?>
        </div>
        <?php endif; ?>

        <!-- Garanties -->
        <div style="background:#F0FDF4; border:1px solid #A7F3D0; border-radius:8px; padding:12px 14px; margin-top:18px; font-size:12px; color:#047857; line-height:1.55;">
          ✅ <strong>Sans engagement</strong> — Annulez quand vous voulez<br>
          ✅ <strong>Renouvellement automatique</strong> chaque mois<br>
          ✅ <strong>Garantie 30 jours satisfait ou remboursé</strong>
        </div>

        <!-- Lien retour -->
        <a href="/mon-asso-plan" style="display:block; text-align:center; margin-top:14px; color:#64748B; font-size:12.5px; text-decoration:none;">← Retour au choix du plan</a>
      </div>
    </div>

    <!-- =========== STRIPE.JS =========== -->
    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function() {
      const stripe = Stripe(<?= json_encode($stripe_pk) ?>);
      const elements = stripe.elements({
        mode: 'subscription',
        amount: <?= (int)$total_cents ?>,
        currency: 'eur',
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
            email: <?= json_encode($user['email'] ?? '') ?>,
            name: <?= json_encode($user['first_name'] ?? '' . ' ' . ($user['last_name'] ?? '')) ?>
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
          buttonText.textContent = 'Traitement en cours...';
          buttonSpinner.style.display = 'inline-block';
        } else {
          submitBtn.disabled = false;
          buttonText.textContent = '💳 Payer <?= addslashes($total_str) ?>/mois';
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
          const { clientSecret, error: serverError } = await response.json();

          if (serverError) {
            showMessage(serverError);
            setLoading(false);
            return;
          }

          // 3. Confirmation du paiement chez Stripe
          const { error: confirmError } = await stripe.confirmPayment({
            elements,
            clientSecret,
            confirmParams: {
              return_url: window.location.origin + '/mon-asso-paiement-success'
            }
          });

          if (confirmError) {
            showMessage(confirmError.message || 'Erreur lors du paiement');
            setLoading(false);
          }
          // Si pas d'erreur, Stripe redirige automatiquement
        } catch (e) {
          showMessage('Erreur réseau : ' + e.message);
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

</main>

<?php render_foot(); ?>
