<?php
/**
 * stripe-change-plan.php — changement de formule en cours d'abonnement
 * --------------------------------------------------------------
 * GET  /stripe-change-plan?plan=<slug> : récapitulatif à confirmer (montée immédiate au prorata, ou
 *      baisse à l'échéance ; ce qui change ; garantie de l'offre fondateur perdue, le cas échéant).
 * POST action=change (plan) : exécution (ak_pc_execute, stripe-plan-change-helpers.php) ;
 * POST action=cancel_pending : annule la baisse programmée.
 *
 * Avec Stripe : POST /v1/subscriptions/{id} (items[0][id], items[0][price], proration_behavior=
 * create_prorations pour une montée ; none pour une baisse, la formule payée restant jusqu'à l'échéance).
 * La formule locale suit par le webhook (customer.subscription.updated), et tout de suite ici (relecture).
 * Sans Stripe : baisse programmée (cron-job-renouvellements.php), montée sur demande au support.
 * Aucune formule payante en cours (essai, fin d'essai, accès limité) : renvoi vers /mon-asso-paiement.
 *
 * Administrateurs de l'association seulement. Jamais dans l'application mobile (Apple 3.1.1).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/stripe-plan-change-helpers.php';
require_once __DIR__ . '/includes-pricing.php';

require_login();
ak_block_billing_page();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('mon-asso-plan', 'le changement de formule');

$back = '/mon-asso-plan';
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée, veuillez réessayer.';
        header('Location: ' . $back); exit;
    }
    // Une association à la fois (deux administrateurs, double clic) : jamais deux changements croisés
    if (!ak_stripe_lock($pdo, 'change_' . $org_id, 10)) {
        $_SESSION['flash_error'] = 'Un changement de formule est déjà en cours pour votre association. Patientez quelques secondes puis rechargez la page.';
        header('Location: ' . $back); exit;
    }
    try {
        if ($action === 'cancel_pending') {
            $r = ak_pc_cancel_pending($pdo, $org_id);
            $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['message'];
            header('Location: ' . $back); exit;
        }
        if ($action === 'change') {
            $to = ak_pc_plan_by_slug($pdo, (string)($_POST['plan'] ?? ''));
            if (!$to) {
                $_SESSION['flash_error'] = 'Formule introuvable.';
                header('Location: ' . $back); exit;
            }
            $r = ak_pc_execute($pdo, $org_id, $user, $to);
            if ($r['message'] !== '') $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['message'];
            header('Location: ' . $r['redirect']); exit;
        }
    } finally {
        ak_stripe_unlock($pdo, 'change_' . $org_id);
    }
    $_SESSION['flash_error'] = 'Demande inconnue : rien n’a changé.';
    header('Location: ' . $back); exit;
}

// ---------- Récapitulatif à confirmer ----------
$to = ak_pc_plan_by_slug($pdo, (string)($_GET['plan'] ?? ''));
if (!$to) { $_SESSION['flash_error'] = 'Formule introuvable.'; header('Location: ' . $back); exit; }
if (ak_pc_plan_is_quote($to)) { header('Location: /contact?plan=sur-mesure'); exit; }
if (!ak_pc_plan_is_sellable($to)) { $_SESSION['flash_error'] = 'Cette formule ne se souscrit pas en ligne.'; header('Location: ' . $back); exit; }

$ctx = ak_pc_context($pdo, $org_id);
if ($ctx['mode'] === 'subscribe') { header('Location: /mon-asso-paiement?plan=' . rawurlencode((string)$to['slug'])); exit; }
if ($ctx['mode'] === 'blocked') { $_SESSION['flash_error'] = $ctx['reason']; header('Location: ' . $back); exit; }
if ((int)$to['id'] === $ctx['cur_plan_id']) { $_SESSION['flash_error'] = 'C’est déjà votre formule.'; header('Location: ' . $back); exit; }

$vat = $ctx['mode'] === 'manual' ? ak_manual_invoice_vat_rate_safe($pdo) : (function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0);
$kind = ak_pc_kind($ctx['cur_price'], $to);
$blockers = $kind === 'downgrade' ? ak_pc_usage_blockers($pdo, $org_id, $to) : [];
$to_label = (string)$to['label'];
$from_label = (string)$ctx['cur_label'];
$end_fr = $ctx['period_end'] && strtotime($ctx['period_end']) ? date('d/m/Y', strtotime($ctx['period_end'])) : '';
$summary = function_exists('ak_plan_public_summary') ? ak_plan_public_summary($to) : [];
$csrf = function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '');

if ($ctx['mode'] === 'trial_card') {
    $when = 'Votre abonnement commencera avec la formule ' . $to_label . ' à la fin de votre essai'
          . ($ctx['trial_card']['charge_fr'] !== '' ? ', le ' . $ctx['trial_card']['charge_fr'] : '') . '. Rien n’est prélevé d’ici là, et vous pouvez toujours annuler sans frais avant cette date.';
    $btn = 'Confirmer : commencer avec ' . $to_label;
} elseif ($ctx['mode'] === 'stripe' && $kind === 'upgrade') {
    $when = 'Le changement est immédiat : vous profitez tout de suite de la formule ' . $to_label . '. Les jours restants de la période en cours'
          . ($end_fr !== '' ? ' (jusqu’au ' . $end_fr . ')' : '') . ' sont facturés au prorata sur votre prochaine facture ; ensuite, ' . ak_pc_price_label((int)$to['price_cents'], $vat) . ' à chaque échéance.';
    $btn = 'Confirmer le passage à ' . $to_label;
} elseif ($kind === 'downgrade' && in_array($ctx['mode'], ['stripe', 'manual'], true)) {
    $when = 'Vous gardez la formule ' . $from_label . ($end_fr !== '' ? ' jusqu’au ' . $end_fr : ' jusqu’à la fin de la période payée')
          . ', sans aucun remboursement ni frais. Ensuite, vous passez à ' . $to_label . ' : ' . ak_pc_price_label((int)$to['price_cents'], $vat) . ' à partir de cette échéance. Vous pouvez annuler ce changement d’ici là.';
    $btn = 'Programmer le passage à ' . $to_label;
} else {
    $when = 'Votre abonnement est réglé ' . ($ctx['mode'] === 'manual' ? 'sur facture' : 'hors paiement par carte') . ' : nous faisons le changement pour vous. '
          . 'Votre demande est transmise au support, qui applique la formule ' . $to_label . ' et ajuste votre prochaine facture. Rien n’est prélevé d’ici là.';
    $btn = 'Envoyer ma demande';
}

render_head('Changer de formule');
render_sidebar('mon-asso-plan');
?>
<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Tableau de bord</a>
    <span class="sep">›</span>
    <a href="/mon-asso-plan">Mon abonnement</a>
    <span class="sep">›</span>
    <span class="current">Changer de formule</span>
  </nav>

  <div style="max-width:680px;margin:20px auto;background:white;border:1px solid #E2E8F0;border-radius:16px;padding:28px 30px;">
    <h1 style="margin:0 0 6px;font-size:22px;color:#0F172A;">Passer à la formule <?= h($to_label) ?></h1>
    <p style="margin:0 0 18px;color:#64748B;font-size:14px;"><?= $ctx['mode'] === 'trial_card' ? 'Formule choisie à la souscription' : 'Formule actuelle' ?> : <strong><?= h($from_label) ?></strong><?= $ctx['cur_price'] > 0 ? ' · ' . h(ak_pc_price_label($ctx['cur_price'], $vat)) : '' ?></p>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 16px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin-bottom:16px;">
      <div>
        <div style="font-weight:700;font-size:16px;color:#0F172A;"><?= h($to_label) ?></div>
        <?php if (!empty($to['tagline'])): ?><div style="font-size:12.5px;color:#64748B;"><?= h($to['tagline']) ?></div><?php endif; ?>
      </div>
      <div style="font-weight:800;font-size:18px;color:#047857;white-space:nowrap;"><?= h(ak_pc_price_label((int)$to['price_cents'], $vat)) ?></div>
    </div>
    <?php if ($summary): ?>
      <ul style="margin:0 0 16px;padding-left:20px;color:#334155;font-size:13.5px;line-height:1.7;">
        <?php foreach ($summary as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($blockers): ?>
      <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;padding:14px 16px;margin-bottom:16px;color:#991B1B;font-size:13.5px;line-height:1.6;">
        <strong>Ce changement n’est pas possible pour l’instant.</strong><br>
        <?php foreach ($blockers as $b): ?><?= h($b) ?><br><?php endforeach; ?>
        Réduisez-les d’abord, ou gardez votre formule.
      </div>
      <a href="/mon-asso-plan" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Mon abonnement</a>
    <?php else: ?>
      <p style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:14px 16px;margin:0 0 16px;color:#065F46;font-size:13.5px;line-height:1.6;"><?= h($when) ?></p>
      <?php if ($ctx['cur_slug'] === 'fondateur'): ?>
        <p style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:14px 16px;margin:0 0 16px;color:#92400E;font-size:13.5px;line-height:1.6;">
          Vous quittez l’offre fondateur<?= $ctx['locked_until_fr'] !== '' ? ' : la garantie de prix et de fonctions jusqu’au ' . h($ctx['locked_until_fr']) . ' prend fin avec ce changement' : '' ?>. Ce choix est définitif.
        </p>
      <?php endif; ?>
      <?php if ($ctx['pending'] && (int)$ctx['pending']['plan_id'] !== (int)$to['id']): ?>
        <p style="font-size:13px;color:#475569;margin:0 0 16px;">Le passage déjà prévu à <?= h($ctx['pending']['label']) ?> sera remplacé par ce choix.</p>
      <?php endif; ?>
      <form method="post" action="/stripe-change-plan" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="change">
        <input type="hidden" name="plan" value="<?= h((string)$to['slug']) ?>">
        <button type="submit" onclick="this.disabled=true;this.form.submit();" style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:12px 22px;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;"><?= h($btn) ?></button>
        <a href="/mon-asso-plan" style="background:white;color:#475569;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #E2E8F0;">Annuler</a>
      </form>
    <?php endif; ?>
    <p style="font-size:12px;color:#64748B;margin:18px 0 0;line-height:1.55;">Sans engagement : résiliation à tout moment, effet à la fin du mois payé. <?= h(ak_pc_vat_note($vat)) ?></p>
  </div>
</main>
<?php render_foot(); ?>
