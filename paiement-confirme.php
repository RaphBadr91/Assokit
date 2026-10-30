<?php
/**
 * paiement-confirme.php — /paiement-confirme?op={id}.{signature}&cs={session}
 * --------------------------------------------------------------
 * Retour de la page Stripe Checkout (success_url) d'un paiement en ligne reçu par une association.
 * La session est RELUE chez Stripe (au nom du compte de l'association) puis appliquée par la même fonction que
 * le webhook (ak_connect_apply_session) : le paiement est enregistré tout de suite, sans attendre le webhook,
 * et jamais deux fois. Le paramètre op est signé (pas de numéro devinable) et doit correspondre à la session.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';
require_once __DIR__ . '/stripe-connect-helpers.php';
require_once __DIR__ . '/paiement-en-ligne-layout.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$op_id = ak_connect_op_from_ref($pdo, (string)($_GET['op'] ?? ''));
$op = $op_id > 0 ? ak_connect_op($pdo, $op_id) : null;
$cs_id = (string)($_GET['cs'] ?? '');
if (!$op || !preg_match('/^cs_[A-Za-z0-9_]+$/', $cs_id) || (!empty($op['checkout_session_id']) && !hash_equals((string)$op['checkout_session_id'], $cs_id))) {
    http_response_code(404);
    ak_pay_head('Paiement introuvable', 'Assokit');
    echo '<div class="pp-card"><div class="pp-body"><p><strong>Ce lien de confirmation n’est pas valable.</strong></p><p class="pp-muted">Si vous venez de payer, votre reçu arrive par e-mail.</p></div></div>';
    ak_pay_foot('l’association');
    exit;
}

// Session relue chez Stripe, puis appliquée (idempotent : le webhook peut être déjà passé)
if (in_array($op['status'], ['open', 'processing'], true)) {
    $r = ak_stripe_api_call($pdo, 'GET', '/checkout/sessions/' . rawurlencode($cs_id), [], null, (string)$op['stripe_account_id']);
    if (!empty($r['ok']) && is_array($r['data'])) {
        try {
            ak_connect_apply_session($pdo, (string)$op['stripe_account_id'], $r['data'], 'return');
        } catch (Throwable $e) {
            error_log('[paiement-confirme] op #' . $op_id . ' : ' . $e->getMessage());
        }
    }
    $op = ak_connect_op($pdo, $op_id);
}

$ctx = ak_connect_op_context($pdo, $op);
$org_name = $ctx['org_name'];
$back = '';
if ($op['kind'] === 'invoice') {
    $q = $pdo->prepare("SELECT public_uuid FROM asso_invoices WHERE id = ? AND org_id = ?");
    $q->execute([(int)$op['invoice_id'], (int)$op['org_id']]);
    $u = (string)$q->fetchColumn();
    if ($u !== '') $back = '/facture/' . $u;
} else {
    $q = $pdo->prepare("SELECT * FROM cotisation_campaigns WHERE id = ? AND org_id = ?");
    $q->execute([(int)$op['campaign_id'], (int)$op['org_id']]);
    if ($c = $q->fetch(PDO::FETCH_ASSOC)) $back = ak_connect_campaign_url($pdo, $c, (int)($op['adherent_id'] ?? 0));
}
$status = (string)$op['status'];
$amount = ak_connect_money((int)$op['amount_cents']);
$title = in_array($status, ['paid', 'refunded', 'partially_refunded', 'disputed'], true) ? 'Paiement confirmé' : ($status === 'processing' ? 'Prélèvement enregistré' : 'Paiement non abouti');

ak_pay_head($title, $org_name);
?>
<div class="pp-card">
  <div class="pp-head">
    <div class="pp-org"><?= $h($org_name) ?></div>
    <h1><?= $status === 'paid' ? '✅ Paiement confirmé — merci !' : ($status === 'processing' ? '⏳ Prélèvement SEPA enregistré' : ($title === 'Paiement confirmé' ? '✅ Paiement confirmé' : 'Paiement non abouti')) ?></h1>
  </div>
  <div class="pp-body">
    <?php if ($title === 'Paiement confirmé'): ?>
      <p><?= $h($org_name) ?> a bien reçu votre paiement.</p>
    <?php elseif ($status === 'processing'): ?>
      <p class="pp-alert pp-alert-wait">Votre banque confirme le prélèvement sous quelques jours ouvrés. Vous recevrez alors votre reçu par e-mail ; rien d’autre à faire.</p>
    <?php else: ?>
      <p class="pp-alert pp-alert-error">Le paiement n’a pas abouti<?= $status === 'failed' && !empty($op['failure_message']) ? ' (' . $h(mb_strtolower((string)$op['failure_message'])) . ')' : '' ?> : rien n’a été prélevé.</p>
      <p class="pp-muted">Si vous venez de valider le paiement sur Stripe, la confirmation peut prendre quelques secondes : rechargez cette page.</p>
    <?php endif; ?>
    <table class="pp-rows">
      <tr><td>Objet</td><td><?= $h(ucfirst($ctx['object'])) ?></td></tr>
      <tr><td>Montant</td><td><?= $h($amount) ?></td></tr>
      <?php if (!empty($op['paid_at'])): ?><tr><td>Date</td><td><?= $h(date('d/m/Y à H:i', strtotime((string)$op['paid_at']))) ?></td></tr><?php endif; ?>
      <?php if (!empty($op['payer_email']) && in_array($status, ['paid', 'processing'], true)): ?><tr><td>Reçu envoyé à</td><td><?= $h($op['payer_email']) ?></td></tr><?php endif; ?>
    </table>
    <?php if ($back !== ''): ?>
      <p><a class="pp-btn-ghost" href="<?= $h($back) ?>">← <?= $op['kind'] === 'invoice' ? 'Revenir à la facture' : ($title === 'Paiement confirmé' ? 'Revenir à la page' : 'Réessayer') ?></a></p>
    <?php endif; ?>
  </div>
</div>
<?php ak_pay_foot($org_name); ?>
