<?php
/**
 * mon-asso-annuler-abonnement.php
 * --------------------------------------------------------------
 * Annulation d'abonnement depuis le dashboard client.
 *
 * Flow :
 *   1. Page de confirmation avec récap (date fin, conséquences)
 *   2. POST → annulation Stripe (cancel_at_period_end = true)
 *   3. Webhook customer.subscription.updated met à jour BDD
 *   4. Le client garde l'accès jusqu'à fin de période payée
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'l\'annulation d\'abonnement');

$active_sub = ak_get_org_active_subscription($pdo, $org_id);

if (!$active_sub) {
    $_SESSION['flash_error'] = 'Aucun abonnement actif à annuler.';
    header('Location: /mon-asso-plan');
    exit;
}

$flash_msg = null;
$flash_type = 'success';

// Traitement annulation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée, veuillez réessayer.';
        header('Location: /mon-asso-plan'); exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if ($action === 'cancel_subscription' && $confirm === 'CONFIRMER') {
        $stripe_sub_id = $active_sub['stripe_subscription_id'] ?? null;
        $payment_mode = $active_sub['payment_mode'] ?? 'manual';

        try {
            if ($payment_mode === 'stripe' && $stripe_sub_id && ak_stripe_is_configured($pdo)) {
                // Annulation via API Stripe (à fin de période)
                $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . $stripe_sub_id, [
                    'cancel_at_period_end' => 'true',
                ]);
                if (!$resp['ok']) {
                    throw new Exception('Erreur Stripe : ' . $resp['error']);
                }
                // Le webhook fera la mise à jour BDD
                // Mais on met aussi à jour localement par sécurité
                $upd = $pdo->prepare("UPDATE asso_subscriptions SET cancel_at_period_end = 1, cancelled_at = NOW(), updated_at = NOW() WHERE id = :id");
                $upd->execute([':id' => (int)$active_sub['id']]);

                $flash_msg = '✅ Annulation confirmée. Vous gardez l\'accès jusqu\'à la fin de la période payée.';
            } else {
                // Annulation manuelle (mode manual / wire_transfer / free_grant)
                $upd = $pdo->prepare("
                    UPDATE asso_subscriptions
                    SET status = 'cancelled', cancelled_at = NOW(), cancel_at_period_end = 1, updated_at = NOW()
                    WHERE id = :id
                ");
                $upd->execute([':id' => (int)$active_sub['id']]);
                $flash_msg = '✅ Abonnement annulé. Pour toute question, contactez-nous.';
            }

            $_SESSION['flash_success'] = $flash_msg;
            header('Location: /mon-asso-plan');
            exit;

        } catch (Throwable $e) {
            $flash_msg = 'Erreur : ' . $e->getMessage();
            $flash_type = 'error';
        }
    } else {
        $flash_msg = 'Veuillez taper exactement "CONFIRMER" pour valider l\'annulation.';
        $flash_type = 'error';
    }
}

$is_already_cancelled = !empty($active_sub['cancel_at_period_end']);
$period_end = $active_sub['current_period_end'] ?? null;

render_head('Annuler mon abonnement');
render_sidebar('mon-asso-plan');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/mon-asso-plan">Mon abonnement</a>
    <span class="sep">›</span>
    <span class="current">🛑 Annuler</span>
  </nav>

  <div style="max-width:680px;margin:20px auto;">

    <?php if ($flash_msg): ?>
      <div style="padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;<?= $flash_type === 'error' ? 'background:#FEE2E2;border:1px solid #FECACA;color:#991B1B;' : 'background:#D1FAE5;border:1px solid #A7F3D0;color:#065F46;' ?>">
        <?= htmlspecialchars($flash_msg) ?>
      </div>
    <?php endif; ?>

    <?php if ($is_already_cancelled): ?>

      <!-- Déjà annulé -->
      <div style="background:#FEF3C7;border:2px solid #FCD34D;border-radius:18px;padding:36px 32px;text-align:center;">
        <div style="font-size:54px;margin-bottom:14px;">✓</div>
        <h1 style="margin:0 0 12px;font-size:22px;color:#92400E;">Annulation déjà en cours</h1>
        <p style="color:#78350F;line-height:1.65;font-size:14.5px;margin:0 0 22px;">
          Votre abonnement <strong><?= htmlspecialchars($active_sub['plan_name']) ?></strong> a déjà été annulé.<br>
          <?php if ($period_end): ?>
            Vous gardez l'accès jusqu'au <strong><?= htmlspecialchars(date('d/m/Y', strtotime($period_end))) ?></strong>.
          <?php endif; ?>
        </p>
        <a href="/mon-asso-plan" style="background:#92400E;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour</a>
      </div>

    <?php else: ?>

      <!-- Formulaire annulation -->
      <div style="background:white;border:2px solid #FECACA;border-radius:18px;padding:36px 32px;">
        <div style="text-align:center;margin-bottom:22px;">
          <div style="font-size:54px;margin-bottom:10px;">😢</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#0F172A;">Annuler mon abonnement</h1>
          <p style="color:#64748B;font-size:14px;margin:0;">Plan actuel : <strong><?= htmlspecialchars($active_sub['plan_name']) ?></strong></p>
        </div>

        <!-- Conséquences -->
        <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#92400E;margin-bottom:10px;font-size:14px;">⚠️ Avant de partir, voici ce que vous perdrez :</div>
          <ul style="margin:0;padding-left:20px;color:#78350F;line-height:1.85;font-size:13.5px;">
            <li>L'accès au plan <strong><?= htmlspecialchars($active_sub['plan_name']) ?></strong></li>
            <li>La diffusion email aux adhérents</li>
            <li>Les factures récurrentes automatiques</li>
            <li>Les statistiques avancées</li>
            <li>Le support prioritaire</li>
            <?php if (function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id)): ?>
            <li>Votre domaine personnalisé (add-on +10€/mois)</li>
            <?php endif; ?>
          </ul>
        </div>

        <!-- Garanties -->
        <div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#047857;margin-bottom:10px;font-size:14px;">✅ Vos garanties :</div>
          <ul style="margin:0;padding-left:20px;color:#065F46;line-height:1.85;font-size:13.5px;">
            <li><strong>Vous gardez l'accès</strong> jusqu'à la fin de la période payée<?php if ($period_end): ?> (<?= htmlspecialchars(date('d/m/Y', strtotime($period_end))) ?>)<?php endif; ?></li>
            <li><strong>Vos données sont conservées 30 jours</strong> après expiration (vous pouvez les exporter)</li>
            <li><strong>Aucun frais d'annulation</strong></li>
            <li><strong>Vous pouvez vous réabonner</strong> à tout moment, vos données seront restaurées</li>
          </ul>
        </div>

        <!-- Avant tout : alternatives -->
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#1E40AF;margin-bottom:10px;font-size:14px;">💡 Avant d'annuler, avez-vous pensé à :</div>
          <div style="display:flex;gap:8px;flex-direction:column;font-size:13px;color:#1E3A8A;">
            <a href="/contact?subject=billing" style="color:#1E40AF;text-decoration:none;padding:6px 0;">→ Nous parler de votre situation (peut-être qu'on peut vous aider)</a>
            <a href="/mon-asso-plan" style="color:#1E40AF;text-decoration:none;padding:6px 0;">→ Passer à un plan moins cher (downgrade)</a>
            <a href="/contact?subject=support" style="color:#1E40AF;text-decoration:none;padding:6px 0;">→ Une difficulté technique ? On vous aide gratuitement</a>
          </div>
        </div>

        <!-- Confirmation -->
        <form method="post" action="" style="border-top:1px solid #E2E8F0;padding-top:22px;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="cancel_subscription">

          <label style="display:block;font-weight:600;font-size:14px;color:#0F172A;margin-bottom:8px;">
            Pour confirmer, tapez <code style="background:#FEE2E2;color:#991B1B;padding:2px 8px;border-radius:4px;font-family:monospace;">CONFIRMER</code> ci-dessous :
          </label>
          <input type="text" name="confirm" placeholder="Tapez CONFIRMER" required style="width:100%;padding:12px 14px;border:2px solid #FECACA;border-radius:10px;font-family:monospace;font-size:14px;margin-bottom:18px;text-transform:uppercase;">

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="/mon-asso-plan" style="background:#0F172A;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;flex:1;text-align:center;">← Garder mon abonnement</a>
            <button type="submit" style="background:#DC2626;color:white;padding:12px 24px;border:none;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;">🛑 Confirmer l'annulation</button>
          </div>
        </form>
      </div>

    <?php endif; ?>

  </div>

</main>

<?php render_foot(); ?>
