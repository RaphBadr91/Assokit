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
 *   1. Vérifie le statut du paiement côté Stripe
 *   2. Affiche un message adapté (succès / en attente / échec)
 *   3. Le webhook (en parallèle) met à jour la BDD automatiquement
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);

$payment_intent_id = (string)($_GET['payment_intent'] ?? '');
$redirect_status = (string)($_GET['redirect_status'] ?? '');

$status = 'unknown';
$amount_str = '';
$intent_data = null;

if ($payment_intent_id && ak_stripe_is_configured($pdo)) {
    $resp = ak_stripe_api_call($pdo, 'GET', '/payment_intents/' . $payment_intent_id);
    if ($resp['ok']) {
        $intent_data = $resp['data'];
        $status = $intent_data['status'] ?? 'unknown';
        $amount = (int)($intent_data['amount'] ?? 0);
        $amount_str = ak_format_price_cents($amount);
    }
}

$is_success = ($status === 'succeeded' || $redirect_status === 'succeeded');
$is_processing = ($status === 'processing' || $redirect_status === 'pending');
$is_failed = (!$is_success && !$is_processing && $payment_intent_id);

render_head($is_success ? 'Paiement confirmé' : 'Paiement en cours');
render_sidebar('mon-asso-paiement');
?>

<main class="main">

  <div style="max-width:680px; margin:40px auto; padding:0 20px;">

    <?php if ($is_success): ?>

      <!-- ============== SUCCÈS ============== -->
      <div style="background:linear-gradient(135deg, #F0FDF4 0%, #FAF8F5 100%); border:2px solid #A7F3D0; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:72px; margin-bottom:16px; line-height:1;">🎉</div>
        <h1 style="margin:0 0 14px; font-size:28px; color:#047857;">Paiement confirmé !</h1>
        <p style="color:#475569; line-height:1.65; font-size:16px; margin:0 0 28px;">
          Bienvenue dans votre nouveau plan <strong>Assokit</strong>.<br>
          Votre abonnement est <strong>actif immédiatement</strong>.
          <?php if ($amount_str): ?>
          <br><span style="color:#64748B; font-size:14px;">Montant débité : <strong><?= htmlspecialchars($amount_str) ?></strong></span>
          <?php endif; ?>
        </p>

        <div style="background:white; border:1px solid #E2E8F0; border-radius:12px; padding:20px; margin:0 0 28px; text-align:left;">
          <div style="font-weight:700; font-size:14px; color:#0F172A; margin-bottom:12px;">📋 Prochaines étapes</div>
          <ul style="margin:0; padding-left:22px; color:#475569; line-height:1.85; font-size:13.5px;">
            <li>✅ Votre <strong>facture</strong> a été générée et est disponible dans <em>Mon abonnement → Mes factures</em></li>
            <li>✅ Un <strong>email de confirmation</strong> vous a été envoyé</li>
            <li>✅ Le <strong>renouvellement automatique</strong> est activé chaque mois</li>
            <li>✅ Vous pouvez <strong>annuler à tout moment</strong> depuis <em>Mon abonnement</em></li>
          </ul>
        </div>

        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
          <a href="/dashboard" style="background:linear-gradient(180deg,#059669 0%,#047857 100%); color:white; padding:13px 28px; border-radius:11px; text-decoration:none; font-weight:600; font-size:14px;">→ Accéder au dashboard</a>
          <a href="/mon-asso-plan" style="background:white; color:#475569; padding:13px 24px; border-radius:11px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid #E2E8F0;">📋 Voir mon abonnement</a>
        </div>
      </div>

    <?php elseif ($is_processing): ?>

      <!-- ============== EN COURS ============== -->
      <div style="background:linear-gradient(135deg, #FEF3C7 0%, #FAF8F5 100%); border:2px solid #FCD34D; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:64px; margin-bottom:16px;">⏳</div>
        <h1 style="margin:0 0 14px; font-size:24px; color:#92400E;">Paiement en cours de traitement</h1>
        <p style="color:#78350F; line-height:1.65; font-size:15px; margin:0 0 24px;">
          Votre paiement est en cours de validation par votre banque. Cela peut prendre quelques minutes.<br>
          Vous recevrez un email dès la confirmation.
        </p>
        <a href="/mon-asso-plan" style="background:#92400E; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">Voir mon abonnement</a>
      </div>

    <?php elseif ($is_failed): ?>

      <!-- ============== ÉCHEC ============== -->
      <div style="background:#FEE2E2; border:2px solid #FECACA; border-radius:18px; padding:48px 32px; text-align:center;">
        <div style="font-size:64px; margin-bottom:16px;">❌</div>
        <h1 style="margin:0 0 14px; font-size:24px; color:#991B1B;">Paiement non finalisé</h1>
        <p style="color:#7F1D1D; line-height:1.65; font-size:15px; margin:0 0 24px;">
          Le paiement n'a pas pu être confirmé. Aucun montant n'a été débité.<br>
          Vous pouvez réessayer ou nous contacter si le problème persiste.
        </p>
        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
          <a href="/mon-asso-paiement" style="background:#DC2626; color:white; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px;">↻ Réessayer</a>
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
