<?php
/**
 * mon-asso-annuler-abonnement.php
 * --------------------------------------------------------------
 * Annulation d'abonnement depuis le dashboard client.
 *
 * Flow :
 *   1. Page de confirmation avec récap (date fin, conséquences)
 *   2. POST → annulation Stripe (cancel_at_period_end = true), ou hors
 *      Stripe : ligne « cancelled » avec sa date de fin (ak_sub_cancel_manual)
 *   3. Webhook customer.subscription.updated met à jour BDD
 *   4. Le client garde l'accès jusqu'à fin de période payée, puis passe
 *      en accès limité, formule Démarrage (ak_sub_expire_cancelled)
 *   Paiement en retard (« Arrêter mon abonnement » de l'écran bloquant
 *   et des relances) : arrêt immédiat de l'abonnement impayé et Démarrage.
 *   Démarrage n'est plus une offre : les textes parlent d'accès limité.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
// Confirmation de la résiliation par e-mail (ak_stripe_notify_resiliation / ak_stripe_notify_stop)
require_once __DIR__ . '/stripe-sync-helpers.php';

require_login();
// Apple 3.1.1 : aucun tunnel d'achat ne doit s'ouvrir dans l'app.
ak_block_billing_page();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'l\'annulation d\'abonnement');

// Formule et essai : même vérité que « Mon abonnement » et le cockpit fondateur (ak_org_plan_state).
// Lu AVANT la ligne à annuler : une résiliation arrivée à échéance passe d'abord à Démarrage.
require_once __DIR__ . '/plan-helpers.php';
$trial_info = ak_plan_trial_info($pdo, $org_id);

$active_sub = ak_get_org_active_subscription($pdo, $org_id);

if (!$active_sub) {
    $_SESSION['flash_error'] = 'Aucun abonnement actif à annuler.';
    header('Location: /mon-asso-plan');
    exit;
}

$plan_label = function_exists('ak_plan_label')
    ? ak_plan_label($active_sub['plan_slug'] ?? '', $active_sub['plan_name'] ?? null)
    : (string)($active_sub['plan_name'] ?? '');
// Essai (en cours, ou terminé sans formule payante) : rien à annuler. Annuler la ligne d'essai
// la sortait de la bascule de fin d'essai (elle gardait le plan d'essai après la date de fin).
// Un abonnement Stripe pris pendant l'essai (paiement en cours) reste, lui, annulable.
$is_trial_sub = ak_trial_blocks_cancel($pdo, $org_id, $active_sub, $trial_info);

$sub_status = (string)($active_sub['status'] ?? '');
$stripe_sub_id = trim((string)($active_sub['stripe_subscription_id'] ?? ''));
$payment_mode = (string)($active_sub['payment_mode'] ?? 'manual');
$is_stripe_sub = $payment_mode === 'stripe' && $stripe_sub_id !== '';
// Déjà résilié : hors Stripe (« cancelled ») ou résiliation programmée chez Stripe
$is_already_cancelled = $sub_status === 'cancelled' || !empty($active_sub['cancel_at_period_end']);
// Paiement en retard : « Arrêter mon abonnement » (lien de l'écran bloquant et des e-mails de relance)
// arrête l'abonnement impayé et rétrograde TOUT DE SUITE, sans attendre une fin de période non payée
$is_overdue = $sub_status === 'overdue';
// Formule gratuite sans prélèvement par carte en cours : rien à annuler
$is_free_sub = !$is_trial_sub && !$is_stripe_sub && (int)($active_sub['price_cents_monthly'] ?? 0) <= 0
    && ($active_sub['plan_slug'] ?? '') !== 'sur-mesure' && empty($active_sub['plan_is_custom_quote']);
// Date jusqu'à laquelle la formule reste acquise (annoncée avant la confirmation, puis appliquée)
if ($is_already_cancelled) {
    $access_until = ak_sub_row_access_end($pdo, $org_id, $active_sub);
} elseif ($is_stripe_sub) {
    $access_until = ak_sub_access_end($active_sub['current_period_end'] ?? null, null);
} else {
    $access_until = ak_sub_cancel_end_date($pdo, $org_id, $active_sub['current_period_end'] ?? null);
}
$access_until_fr = $access_until ? date('d/m/Y', strtotime($access_until)) : '';

$flash_msg = null;
$flash_type = 'success';

// Traitement annulation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($is_trial_sub || $is_free_sub || $is_already_cancelled)) {
    if ($is_trial_sub) {
        $_SESSION['flash_error'] = !empty($trial_info['trial_expired'])
            ? 'Votre essai est terminé : il n’y a pas d’abonnement payant à annuler.'
            : 'Votre association est en essai : il n’y a pas d’abonnement à annuler.';
    } elseif ($is_free_sub) {
        $_SESSION['flash_error'] = 'Votre association est sur le plan gratuit Démarrage : il n’y a pas d’abonnement à annuler.';
    } else {
        $_SESSION['flash_error'] = 'Votre abonnement est déjà résilié' . ($access_until_fr !== '' ? ' : vous gardez votre formule jusqu’au ' . $access_until_fr . '.' : '.');
    }
    header('Location: /mon-asso-plan');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée, veuillez réessayer.';
        header('Location: /mon-asso-plan'); exit;
    }
    $action = (string)($_POST['action'] ?? '');
    // Le champ s'affiche en majuscules (text-transform) : « confirmer » tapé en minuscules est accepté
    $confirm = mb_strtoupper(trim((string)($_POST['confirm'] ?? '')), 'UTF-8');

    if ($action === 'cancel_subscription' && $confirm === 'CONFIRMER') {
        try {
            if ($is_stripe_sub && !ak_stripe_is_configured($pdo)) {
                // Ne jamais marquer « résilié » ici un abonnement que Stripe continuerait de prélever
                throw new Exception('Votre abonnement par carte ne peut pas être arrêté d’ici pour le moment. Contactez-nous : nous l’arrêtons pour vous.');
            }
            if ($is_overdue) {
                // Paiement en retard : arrêt immédiat de l'abonnement impayé, puis accès limité (Démarrage)
                $stripe_ended = false;
                if ($is_stripe_sub) {
                    $resp = ak_stripe_api_call($pdo, 'DELETE', '/subscriptions/' . rawurlencode($stripe_sub_id));
                    // Déjà arrêté chez Stripe (introuvable) : rien de plus à faire là-bas
                    if (empty($resp['ok']) && (int)($resp['http_code'] ?? 0) !== 404) {
                        throw new Exception('Erreur Stripe : ' . ($resp['error'] ?? 'arrêt refusé'));
                    }
                    $stripe_ended = true;
                }
                ak_db_update($pdo, 'asso_subscriptions', (int)$active_sub['id'], ['status' => 'cancelled'], ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
                ak_sub_downgrade_free($pdo, (int)$active_sub['id'], $stripe_ended);
                // Confirmation sur un support durable (le webhook ne la renvoie pas : la ligne est déjà arrêtée)
                ak_stripe_notify_stop($pdo, $org_id, $is_stripe_sub ? $stripe_sub_id : 'manuel-' . (int)$active_sub['id'], null, false);
                $flash_msg = 'L’abonnement impayé est arrêté : plus aucun prélèvement. Votre association passe en accès limité (Démarrage) et vos données sont conservées. Vous pouvez reprendre Assokit à tout moment.';
            } elseif ($is_stripe_sub) {
                // Annulation via API Stripe (à fin de période)
                $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . rawurlencode($stripe_sub_id), [
                    'cancel_at_period_end' => 'true',
                ]);
                if (!$resp['ok']) {
                    throw new Exception('Erreur Stripe : ' . $resp['error']);
                }
                // Le webhook fera la mise à jour BDD
                // Mais on met aussi à jour localement par sécurité
                // (échéance donnée par Stripe : la même date partout)
                $stripe_end = !empty($resp['data']['current_period_end']) ? date('Y-m-d H:i:s', (int)$resp['data']['current_period_end']) : null;
                ak_db_update($pdo, 'asso_subscriptions', (int)$active_sub['id'],
                    ['cancel_at_period_end' => 1] + ($stripe_end ? ['current_period_end' => $stripe_end] : []),
                    ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
                $end = $stripe_end ? date('d/m/Y', strtotime($stripe_end)) : $access_until_fr;
                // Confirmation par e-mail tout de suite (le webhook customer.subscription.updated, qui arrive
                // ensuite, ne la renvoie pas : même clé, même échéance)
                ak_stripe_notify_resiliation($pdo, $org_id, $stripe_sub_id, $stripe_end ?: ($active_sub['current_period_end'] ?? null));
                $flash_msg = 'Résiliation enregistrée. Vous gardez votre formule ' . $plan_label . ($end !== '' ? ' jusqu’au ' . $end : ' jusqu’à la fin de la période payée')
                           . ', puis votre association passe en accès limité (Démarrage). Aucun nouveau prélèvement.';
            } else {
                // Annulation manuelle (mode manual / wire_transfer / free_grant) : la formule reste jusqu'à
                // la fin de la période, puis Démarrage (ak_sub_expire_cancelled)
                $end = ak_sub_cancel_manual($pdo, $active_sub, $org_id);
                ak_stripe_notify_resiliation($pdo, $org_id, 'manuel-' . (int)$active_sub['id'], $end);
                $flash_msg = 'Résiliation enregistrée. Vous gardez votre formule ' . $plan_label . ' jusqu’au ' . date('d/m/Y', strtotime($end))
                           . ', puis votre association passe en accès limité (Démarrage).';
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

$regularize_url = $is_overdue && function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/contact?subject=billing';

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

    <?php if ($is_trial_sub): ?>

      <!-- Essai : rien à annuler -->
      <?php $trial_over = !empty($trial_info['trial_expired']); ?>
      <div style="background:<?= $trial_over ? '#FEF2F2' : '#FFFBEB' ?>;border:2px solid <?= $trial_over ? '#FECACA' : '#FCD34D' ?>;border-radius:18px;padding:36px 32px;text-align:center;">
        <div style="font-size:54px;margin-bottom:14px;"><?= $trial_over ? '⏳' : '🎁' ?></div>
        <h1 style="margin:0 0 8px;font-size:22px;color:<?= $trial_over ? '#991B1B' : '#92400E' ?>;"><?= h($trial_info['label'] !== '' ? $trial_info['label'] : 'Essai PRO') ?></h1>
        <p style="color:<?= $trial_over ? '#7F1D1D' : '#78350F' ?>;line-height:1.65;font-size:14.5px;margin:0 0 22px;">
          <?php if ($trial_over): ?>
            Votre essai est terminé<?= $trial_info['end_fr'] !== '' ? ' depuis le <strong>' . h($trial_info['end_fr']) . '</strong>' : '' ?> et aucune formule n’a encore été choisie : il n’y a pas d’abonnement payant à annuler.<br>
            Vos données sont conservées. Choisissez une formule pour retrouver toutes les fonctionnalités.
          <?php else: ?>
            Votre association est en <strong>essai gratuit</strong><?= $trial_info['end_fr'] !== '' ? ' jusqu’au <strong>' . h($trial_info['end_fr']) . '</strong>' : '' ?> : il n’y a pas d’abonnement à annuler et rien ne vous sera facturé.<br>
            Sans formule choisie d’ici là, l’essai s’arrête de lui-même. Vos données sont conservées.
          <?php endif; ?>
        </p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
          <a href="/mon-asso-plan" style="background:#059669;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">Choisir une formule</a>
          <a href="/contact?subject=billing" style="background:white;color:#475569;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #E2E8F0;">Nous contacter</a>
        </div>
      </div>

    <?php elseif ($is_already_cancelled): ?>

      <!-- Déjà annulé -->
      <div style="background:#FEF3C7;border:2px solid #FCD34D;border-radius:18px;padding:36px 32px;text-align:center;">
        <div style="font-size:54px;margin-bottom:14px;">✓</div>
        <h1 style="margin:0 0 12px;font-size:22px;color:#92400E;">Annulation déjà en cours</h1>
        <p style="color:#78350F;line-height:1.65;font-size:14.5px;margin:0 0 22px;">
          Votre abonnement <strong><?= h($plan_label) ?></strong> a déjà été annulé.<br>
          <?php if ($access_until_fr !== ''): ?>
            Vous gardez l'accès jusqu'au <strong><?= h($access_until_fr) ?></strong>, puis votre association passe en accès limité (Démarrage).
          <?php else: ?>
            Votre association passera ensuite en accès limité (Démarrage).
          <?php endif; ?>
        </p>
        <a href="/mon-asso-plan" style="background:#92400E;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour</a>
      </div>

    <?php elseif ($is_free_sub): ?>

      <!-- Formule gratuite : rien à annuler -->
      <div style="background:#F8FAFC;border:2px solid #E2E8F0;border-radius:18px;padding:36px 32px;text-align:center;">
        <div style="font-size:54px;margin-bottom:14px;">📋</div>
        <h1 style="margin:0 0 12px;font-size:22px;color:#0F172A;">Plan gratuit <?= h($plan_label) ?></h1>
        <p style="color:#475569;line-height:1.65;font-size:14.5px;margin:0 0 22px;">
          Votre association est sur le plan gratuit : il n’y a pas d’abonnement à annuler et rien ne vous est facturé.
        </p>
        <a href="/mon-asso-plan" style="background:#0F172A;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour</a>
      </div>

    <?php elseif ($is_overdue): ?>

      <!-- Paiement en retard : arrêt immédiat de l'abonnement impayé -->
      <div style="background:white;border:2px solid #FECACA;border-radius:18px;padding:36px 32px;">
        <div style="text-align:center;margin-bottom:22px;">
          <div style="font-size:54px;margin-bottom:10px;">↓</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#0F172A;">Arrêter mon abonnement</h1>
          <p style="color:#64748B;font-size:14px;margin:0;">Plan actuel : <strong><?= h($plan_label) ?></strong> · paiement en retard</p>
        </div>

        <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;padding:18px 20px;margin-bottom:22px;color:#78350F;line-height:1.7;font-size:13.5px;">
          Le dernier paiement de votre abonnement <strong><?= h($plan_label) ?></strong> n’a pas abouti. En confirmant :
          <ul style="margin:8px 0 0;padding-left:20px;">
            <li>l’abonnement est <strong>arrêté tout de suite</strong> : plus aucun prélèvement ;</li>
            <li>votre association passe en <strong>accès limité</strong> (formule Démarrage : sans diffusion email, factures récurrentes ni statistiques avancées) ;</li>
            <li><strong>vos données sont conservées</strong> et vous pouvez reprendre une formule à tout moment.</li>
          </ul>
        </div>

        <div style="text-align:center;margin-bottom:22px;">
          <a href="<?= h($regularize_url) ?>" style="display:inline-block;background:#059669;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">💳 Régulariser plutôt mon paiement</a>
        </div>

        <form method="post" action="" style="border-top:1px solid #E2E8F0;padding-top:22px;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="cancel_subscription">

          <label style="display:block;font-weight:600;font-size:14px;color:#0F172A;margin-bottom:8px;">
            Pour confirmer, tapez <code style="background:#FEE2E2;color:#991B1B;padding:2px 8px;border-radius:4px;font-family:monospace;">CONFIRMER</code> ci-dessous :
          </label>
          <input type="text" name="confirm" placeholder="Tapez CONFIRMER" required autocomplete="off" style="width:100%;padding:12px 14px;border:2px solid #FECACA;border-radius:10px;font-family:monospace;font-size:14px;margin-bottom:18px;text-transform:uppercase;">

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="/mon-asso-plan" style="background:#0F172A;color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;flex:1;text-align:center;">← Garder mon abonnement</a>
            <button type="submit" style="background:#DC2626;color:white;padding:12px 24px;border:none;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;">↓ Arrêter mon abonnement</button>
          </div>
        </form>
      </div>

    <?php else: ?>

      <!-- Formulaire annulation -->
      <div style="background:white;border:2px solid #FECACA;border-radius:18px;padding:36px 32px;">
        <div style="text-align:center;margin-bottom:22px;">
          <div style="font-size:54px;margin-bottom:10px;">😢</div>
          <h1 style="margin:0 0 8px;font-size:22px;color:#0F172A;">Annuler mon abonnement</h1>
          <p style="color:#64748B;font-size:14px;margin:0;">Plan actuel : <strong><?= h($plan_label) ?></strong></p>
        </div>

        <!-- Conséquences -->
        <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#92400E;margin-bottom:10px;font-size:14px;">⚠️ Avant de partir, voici ce que vous perdrez :</div>
          <ul style="margin:0;padding-left:20px;color:#78350F;line-height:1.85;font-size:13.5px;">
            <li>L'accès au plan <strong><?= h($plan_label) ?></strong></li>
            <li>La diffusion email aux adhérents</li>
            <li>Les factures récurrentes automatiques</li>
            <li>Les statistiques avancées</li>
            <li>Le support prioritaire</li>
            <?php if (function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id)): ?>
            <li>Votre domaine personnalisé (add-on)</li>
            <?php endif; ?>
          </ul>
        </div>

        <!-- Garanties -->
        <div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#047857;margin-bottom:10px;font-size:14px;">✅ Vos garanties :</div>
          <ul style="margin:0;padding-left:20px;color:#065F46;line-height:1.85;font-size:13.5px;">
            <li><strong>Vous gardez l'accès</strong> jusqu'à la fin de la période payée<?php if ($access_until_fr !== ''): ?> (<?= h($access_until_fr) ?>)<?php endif; ?>, puis votre association passe en accès limité (Démarrage)</li>
            <li><strong>Vos données sont conservées</strong> (vous pouvez les exporter à tout moment)</li>
            <li><strong>Aucun frais d'annulation</strong></li>
            <li><strong>Vous pouvez vous réabonner</strong> à tout moment, vos données seront restaurées</li>
          </ul>
        </div>

        <!-- Avant tout : alternatives -->
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:18px 20px;margin-bottom:22px;">
          <div style="font-weight:700;color:#1E40AF;margin-bottom:10px;font-size:14px;">💡 Avant d'annuler, avez-vous pensé à :</div>
          <div style="display:flex;gap:8px;flex-direction:column;font-size:13px;color:#1E3A8A;">
            <a href="/contact?subject=billing" style="color:#1E40AF;text-decoration:none;padding:6px 0;">→ Nous parler de votre situation (peut-être qu'on peut vous aider)</a>
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
          <input type="text" name="confirm" placeholder="Tapez CONFIRMER" required autocomplete="off" style="width:100%;padding:12px 14px;border:2px solid #FECACA;border-radius:10px;font-family:monospace;font-size:14px;margin-bottom:18px;text-transform:uppercase;">

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
