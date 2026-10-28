<?php
/**
 * mon-asso-paiement-success.php
 * --------------------------------------------------------------
 * Page de succès affichée après un paiement Stripe réussi.
 *
 * Stripe redirige ici avec :
 *   ?payment_intent=pi_xxx
 *   &payment_intent_client_secret=pi_xxx_secret_yyy
 *   &redirect_status=succeeded|requires_action|...
 *
 * Cette page :
 *   1. Vérifie le statut du paiement côté Stripe (PaymentIntent de CETTE
 *      association) : redirect_status seul ne prouve rien
 *   2. Affiche un message adapté (succès / en attente / échec), avec la
 *      formule réellement payée
 *   3. Paiement réussi : l'abonnement est relu chez Stripe et appliqué tout de
 *      suite (ak_stripe_sync_subscription, stripe-sync-helpers.php : même code
 *      que le webhook, sans doublon), sans attendre le webhook. Si Stripe ne
 *      répond pas : « activation en cours », la page se recharge, le webhook
 *      fera l'activation
 *   4. Prélèvement SEPA en cours de vérification : noté sur l'association
 *      (pas de fin d'essai ni de second paiement pendant ce temps)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';

require_login();
// Apple 3.1.1 : aucun tunnel d'achat ne doit s'ouvrir dans l'app.
ak_block_billing_page();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// Paiement de l'abonnement : même accès que la page de paiement (montants, formule)
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'le paiement de l\'abonnement');

$payment_intent_id = (string)($_GET['payment_intent'] ?? '');
if (!preg_match('/^pi_[A-Za-z0-9_]{1,250}$/', $payment_intent_id)) $payment_intent_id = '';
$redirect_status = (string)($_GET['redirect_status'] ?? '');

$status = 'unknown';
$amount_str = '';
$intent_data = null;
$paid_sub_id = '';
$is_sepa = false;

if ($payment_intent_id && ak_stripe_is_configured($pdo)) {
    $resp = ak_stripe_api_call($pdo, 'GET', '/payment_intents/' . $payment_intent_id, ['expand' => ['invoice', 'payment_method']]);
    // Seul un paiement du client Stripe de CETTE association est lu (jamais celui d'une autre)
    $org_customer = function_exists('ak_get_stripe_customer_id') ? (string)ak_get_stripe_customer_id($pdo, $org_id) : '';
    if ($resp['ok'] && $org_customer !== '' && (string)($resp['data']['customer'] ?? '') === $org_customer) {
        $intent_data = $resp['data'];
        $status = $intent_data['status'] ?? 'unknown';
        $amount = (int)($intent_data['amount'] ?? 0);
        $amount_str = ak_format_price_cents($amount);
        $inv = $intent_data['invoice'] ?? null;
        $paid_sub_id = is_array($inv) ? (string)(ak_stripe_invoice_sub_id($inv) ?? '') : '';
        $pm = $intent_data['payment_method'] ?? null;
        $is_sepa = (is_array($pm) && ($pm['type'] ?? '') === 'sepa_debit')
            || (($intent_data['payment_method_types'] ?? []) === ['sepa_debit']);
        // L'abonnement est appliqué tout de suite (paiement réussi : actif, fin de l'essai ; prélèvement SEPA
        // en cours : noté), sans attendre le webhook. Stripe injoignable : le webhook s'en chargera.
        if ($paid_sub_id !== '' && in_array($status, ['succeeded', 'processing'], true)) {
            try {
                ak_stripe_sync_subscription($pdo, $paid_sub_id);
            } catch (Throwable $e) {
                error_log('[mon-asso-paiement-success] org #' . $org_id . ' : ' . $e->getMessage());
            }
        }
    }
}

// Succès = PaymentIntent lu chez Stripe « succeeded ». redirect_status (paramètre d'URL) ne sert qu'à
// dire « en cours de vérification » quand le paiement n'a pas pu être lu.
$is_success = ($status === 'succeeded');
$is_processing = !$is_success && ($status === 'processing' || ($status === 'unknown' && in_array($redirect_status, ['succeeded', 'pending', 'processing'], true)));
$is_failed = (!$is_success && !$is_processing && $intent_data !== null);

// Formule réellement payée et activation : la ligne d'abonnement de ce paiement (écrite par le webhook)
$paid_plan_label = '';
$is_activated = false;
if ($is_success) {
    try {
        $row = null;
        if ($paid_sub_id !== '') {
            $q = $pdo->prepare("SELECT s.status, p.slug, p.name FROM asso_subscriptions s LEFT JOIN asso_plans p ON p.id = s.plan_id
                                WHERE s.org_id = ? AND s.stripe_subscription_id = ? LIMIT 1");
            $q->execute([$org_id, $paid_sub_id]);
            $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($row) {
            $is_activated = ($row['status'] ?? '') === 'active';
            $paid_plan_label = function_exists('ak_plan_label') ? ak_plan_label($row['slug'] ?? '', $row['name'] ?? null) : (string)($row['name'] ?? '');
        }
        if ($paid_plan_label === '' && is_array($intent_data['invoice'] ?? null)) {
            $slug = (string)($intent_data['invoice']['subscription_details']['metadata']['plan_slug'] ?? '');
            if ($slug !== '') {
                $q = $pdo->prepare("SELECT slug, name FROM asso_plans WHERE slug = ? LIMIT 1");
                $q->execute([$slug]);
                $p = $q->fetch(PDO::FETCH_ASSOC);
                if ($p) $paid_plan_label = function_exists('ak_plan_label') ? ak_plan_label($p['slug'], $p['name']) : (string)$p['name'];
            }
        }
    } catch (Throwable $e) {
        error_log('[mon-asso-paiement-success] ' . get_class($e));
    }
}

// Pas de bandeau « Essai gratuit · S'abonner » au-dessus d'un paiement qui vient d'aboutir
// (lu par render_trial_banner, includes-layout.php)
$GLOBALS['ak_hide_trial_banner'] = true;
render_head($is_success ? ($is_activated ? 'Paiement confirmé' : 'Paiement reçu') : 'Paiement en cours');
render_sidebar('mon-asso-paiement');
?>

<main class="main">

  <div style="max-width:680px; margin:40px auto; padding:0 20px;">

    <?php if ($is_success): ?>

      <!-- ============== SUCCÈS ============== -->
      <div style="background:linear-gradient(135deg, #F0FDF4 0%, #FAF8F5 100%); border:2px solid #A7F3D0; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:72px; margin-bottom:16px; line-height:1;">🎉</div>
        <h1 style="margin:0 0 14px; font-size:28px; color:#047857;"><?= $is_activated ? 'Paiement confirmé !' : 'Paiement reçu !' ?></h1>
        <p style="color:#475569; line-height:1.65; font-size:16px; margin:0 0 28px;">
          <?php if ($is_activated): ?>
            Bienvenue dans votre <?= $paid_plan_label !== '' ? 'plan <strong>' . htmlspecialchars($paid_plan_label) . '</strong>' : 'nouvelle formule' ?>.<br>
            Votre abonnement est <strong>actif</strong>.
          <?php else: ?>
            Votre paiement<?= $paid_plan_label !== '' ? ' pour le plan <strong>' . htmlspecialchars($paid_plan_label) . '</strong>' : '' ?> est bien reçu.<br>
            <strong>Activation en cours</strong> : cela prend quelques secondes. Cette page se met à jour toute seule.
          <?php endif; ?>
          <?php if ($amount_str): ?>
          <br><span style="color:#64748B; font-size:14px;">Montant débité : <strong><?= htmlspecialchars($amount_str) ?></strong></span>
          <?php endif; ?>
        </p>

        <div style="background:white; border:1px solid #E2E8F0; border-radius:12px; padding:20px; margin:0 0 28px; text-align:left;">
          <div style="font-weight:700; font-size:14px; color:#0F172A; margin-bottom:12px;">📋 Prochaines étapes</div>
          <ul style="margin:0; padding-left:22px; color:#475569; line-height:1.85; font-size:13.5px;">
            <li>✅ Votre <strong>facture</strong> est disponible dans <em>Mon abonnement → Mes factures</em> dès sa réception de Stripe</li>
            <li>✅ Le <strong>renouvellement automatique</strong> est activé chaque mois</li>
            <li>✅ Vous pouvez <strong>annuler à tout moment</strong> depuis <em>Mon abonnement</em></li>
          </ul>
        </div>

        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
          <a href="/dashboard" style="background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:13px 28px; border-radius:11px; text-decoration:none; font-weight:600; font-size:14px;">→ Accéder au dashboard</a>
          <a href="/mon-asso-plan" style="background:white; color:#475569; padding:13px 24px; border-radius:11px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid #E2E8F0;">📋 Voir mon abonnement</a>
        </div>
      </div>
      <?php if (!$is_activated): ?>
      <script>
      // Activation par le webhook Stripe : la page se recharge quelques fois (5 s) jusqu'à l'activation
      (function () {
        var u = new URL(window.location.href), n = parseInt(u.searchParams.get('w') || '0', 10);
        if (n >= 12) return;
        u.searchParams.set('w', String(n + 1));
        setTimeout(function () { window.location.replace(u.toString()); }, 5000);
      })();
      </script>
      <?php endif; ?>

    <?php elseif ($is_processing): ?>

      <!-- ============== EN COURS ============== -->
      <div style="background:linear-gradient(135deg, #FEF3C7 0%, #FAF8F5 100%); border:2px solid #FCD34D; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:64px; margin-bottom:16px;">⏳</div>
        <h1 style="margin:0 0 14px; font-size:24px; color:#92400E;"><?= $is_sepa ? 'Prélèvement SEPA en cours de vérification' : 'Paiement en cours de vérification' ?></h1>
        <p style="color:#78350F; line-height:1.65; font-size:15px; margin:0 0 24px;">
          <?php if ($is_sepa): ?>
            Votre mandat de prélèvement est enregistré. Votre banque confirme le prélèvement sous quelques jours ouvrés (jusqu’à une semaine).<br>
            Vous gardez l’accès à Assokit d’ici là, et votre abonnement s’active automatiquement dès la confirmation. Inutile de payer une seconde fois.
          <?php else: ?>
            Votre paiement est en cours de validation. Cela peut prendre quelques minutes.<br>
            Votre abonnement sera activé dès sa confirmation : suivez son état sur la page « Mon abonnement ».
          <?php endif; ?>
        </p>
        <a href="/mon-asso-plan" style="background:#92400E; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">Voir mon abonnement</a>
      </div>

    <?php elseif ($is_failed): ?>

      <!-- ============== ÉCHEC ============== -->
      <div style="background:#FEE2E2; border:2px solid #FECACA; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:64px; margin-bottom:16px;">❌</div>
        <h1 style="margin:0 0 14px; font-size:24px; color:#991B1B;">Paiement non finalisé</h1>
        <p style="color:#7F1D1D; line-height:1.65; font-size:15px; margin:0 0 24px;">
          Le paiement n’a pas pu être confirmé<?= ($status === 'requires_action' || $redirect_status === 'failed') ? ' (validation 3-D Secure non effectuée ou refusée par votre banque)' : '' ?>. Aucun montant n’a été débité.<br>
          Vous pouvez réessayer avec la même carte ou une autre, ou nous contacter si le problème persiste.
        </p>
        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
          <a href="/mon-asso-paiement?plan=assokit" style="background:#DC2626; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">↻ Réessayer</a>
          <a href="/contact?subject=billing" style="background:white; color:#475569; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid #E2E8F0;">📞 Nous contacter</a>
        </div>
      </div>

    <?php else: ?>

      <!-- ============== ÉTAT INCONNU (pas de payment_intent) ============== -->
      <div style="background:white; border:1px solid #E2E8F0; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:54px; margin-bottom:16px;">🤔</div>
        <h1 style="margin:0 0 14px; font-size:22px; color:#0F172A;">État inconnu</h1>
        <p style="color:#64748B; line-height:1.65; font-size:14.5px; margin:0 0 24px;">
          Nous n'avons pas pu retrouver votre paiement. Vérifiez votre abonnement ou contactez-nous.
        </p>
        <a href="/mon-asso-plan" style="background:#0F172A; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">Mon abonnement</a>
      </div>

    <?php endif; ?>

  </div>

</main>

<?php render_foot(); ?>
