<?php
/**
 * encaissement-en-ligne.php — /encaissement-en-ligne
 * --------------------------------------------------------------
 * « Paiements en ligne » de l'association : relier SON compte Stripe (Stripe Connect, compte Standard),
 * reprendre l'inscription demandée par Stripe, voir l'état du compte (paiements, versements, informations
 * demandées), choisir ce qui se paie en ligne (factures, cotisations, dons, prélèvement SEPA), se déconnecter,
 * et voir les derniers paiements reçus.
 *
 * Droits : administrateur ou trésorier (droit « Gérer les finances ») pour agir ; coordinateur : lecture.
 * Application mobile : lecture seule (la configuration et les paiements se font dans le navigateur).
 * Nom de page choisi hors de « mon-asso-paiement… », que l'application bloque (abonnement Assokit).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-permissions.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/stripe-connect-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /dashboard'); exit; }
if (!array_key_exists('can_manage_finances', $user)) {
    try {
        $q = $pdo->prepare("SELECT can_manage_finances FROM users WHERE id = ?");
        $q->execute([(int)$user['id']]);
        $user['can_manage_finances'] = (int)$q->fetchColumn();
    } catch (Throwable $e) { $user['can_manage_finances'] = 0; }
}
require_billing_access('paiements-en-ligne', 'les paiements en ligne');

$in_app = function_exists('ak_is_mobile_app') && ak_is_mobile_app();
$can_manage = ak_connect_can_manage($user) && !$in_app;
$platform = ak_connect_platform_state($pdo);
$mode = ak_stripe_get_mode($pdo);
$test_blocked = ($mode === 'test' && !in_array($org_id, ak_stripe_test_org_ids($pdo), true)) || ak_connect_demo_blocked($pdo, $org_id);
$open = $platform['ok'] && !$test_blocked;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$flash = $_SESSION['flash_encaissement'] ?? null;
unset($_SESSION['flash_encaissement']);
$redirect = function (?array $f = null, string $to = '/encaissement-en-ligne') {
    if ($f) $_SESSION['flash_encaissement'] = $f;
    header('Location: ' . $to, true, 303);
    exit;
};
$log = function (string $action, array $meta = []) {
    if (function_exists('activity_log_action')) activity_log_action($action, $meta);
};

// Retour de l'inscription Stripe : état relu tout de suite (le webhook account.updated suit)
if ($open && isset($_GET['retour']) && ($acc0 = ak_connect_account($pdo, $org_id))) {
    $acc0 = ak_connect_refresh_account($pdo, (string)$acc0['stripe_account_id']);
    $redirect(!empty($acc0['charges_enabled'])
        ? ['type' => 'success', 'message' => 'Votre compte Stripe est relié : les paiements en ligne sont actifs.']
        : ['type' => 'info', 'message' => 'Merci ! Stripe vérifie vos informations ; il peut en demander d’autres ci-dessous. Cette page se met à jour automatiquement.']);
}
// Lien d'inscription expiré (Stripe renvoie ici) : un nouveau lien, tout de suite
if ($open && $can_manage && isset($_GET['relancer']) && ($acc0 = ak_connect_account($pdo, $org_id))) {
    $l = ak_connect_onboarding_link($pdo, (string)$acc0['stripe_account_id']);
    if ($l['ok']) $redirect(null, $l['url']);
    $redirect(['type' => 'error', 'message' => 'Stripe ne répond pas : réessayez dans quelques minutes.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) $redirect(['type' => 'error', 'message' => 'Session expirée : rechargez la page puis recommencez.']);
    if (!$can_manage) $redirect(['type' => 'error', 'message' => $in_app ? 'Configurez les paiements en ligne depuis assokit.fr, dans votre navigateur.' : 'Réservé aux administrateurs et au trésorier de l’association.']);
    $action = (string)($_POST['action'] ?? '');
    if (!$open && $action !== 'save_settings') $redirect(['type' => 'error', 'message' => 'Le paiement en ligne ouvre très bientôt sur Assokit.']);
    switch ($action) {
        case 'connect':
        case 'resume':
            $acc = ak_connect_account($pdo, $org_id);
            if (!$acc || $action === 'connect') {
                $c = ak_connect_create_account($pdo, $org_id, $user);
                if (!$c['ok']) $redirect(['type' => 'error', 'message' => $c['error']]);
                $log('stripe_connect_account', ['acct' => $c['acct']]);
                $acc = ak_connect_account($pdo, $org_id);
                if ($acc && !empty($acc['charges_enabled'])) $redirect(['type' => 'success', 'message' => 'Votre compte Stripe est de nouveau relié : les paiements en ligne sont actifs.']);
            }
            $l = ak_connect_onboarding_link($pdo, (string)$acc['stripe_account_id']);
            if (!$l['ok']) $redirect(['type' => 'error', 'message' => 'Stripe n’a pas pu ouvrir la page d’inscription : ' . $l['error'] . '. Réessayez dans quelques minutes.']);
            $redirect(null, $l['url']);
        case 'refresh':
            if ($acc = ak_connect_account($pdo, $org_id)) ak_connect_refresh_account($pdo, (string)$acc['stripe_account_id']);
            $redirect(['type' => 'success', 'message' => 'État du compte relu chez Stripe.']);
        case 'save_settings':
            $s = ['invoices_enabled' => !empty($_POST['invoices_enabled']), 'cotisations_enabled' => !empty($_POST['cotisations_enabled']),
                  'dons_enabled' => !empty($_POST['dons_enabled']), 'sepa_enabled' => !empty($_POST['sepa_enabled'])];
            if (!ak_connect_save_settings($pdo, $org_id, $s, (int)$user['id'])) $redirect(['type' => 'error', 'message' => 'Réglages non enregistrés (base de données).']);
            $log('stripe_connect_settings', $s);
            $redirect(['type' => 'success', 'message' => 'Réglages enregistrés.']);
        case 'disconnect':
            $acc = ak_connect_account($pdo, $org_id);
            if ($acc) {
                ak_connect_mark_disconnected($pdo, (string)$acc['stripe_account_id'], 'déconnecté depuis Assokit');
                $log('stripe_connect_disconnect', ['acct' => $acc['stripe_account_id']]);
            }
            $redirect(['type' => 'success', 'message' => 'Paiements en ligne désactivés : les boutons « Payer en ligne » ont disparu. Votre compte Stripe et son argent restent à vous ; vous pouvez le relier de nouveau à tout moment.']);
        default:
            $redirect(['type' => 'error', 'message' => 'Action inconnue.']);
    }
}

$acc = $open ? ak_connect_account($pdo, $org_id, null, true) : null;
$disconnected = $acc && !empty($acc['disconnected_at']);
$ready = $acc && !$disconnected && !empty($acc['charges_enabled']) && ($acc['card_capability'] ?? '') === 'active';
$settings = ak_connect_settings($pdo, $org_id);
$reqs = $acc ? ak_connect_requirements($acc) : [];
$payments = [];
try {
    if (!ak_connect_schema_missing($pdo)) {
        $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE org_id = ? AND mode = ? AND status NOT IN ('open', 'expired') ORDER BY id DESC LIMIT 25");
        $q->execute([$org_id, $mode]);
        $payments = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}
$status_lbl = ['paid' => ['Payé', '#065F46', '#ECFDF5'], 'processing' => ['SEPA en cours', '#92400E', '#FEF3C7'], 'failed' => ['Rejeté', '#991B1B', '#FEE2E2'],
               'refunded' => ['Remboursé', '#475569', '#F1F5F9'], 'partially_refunded' => ['Remboursé en partie', '#475569', '#F1F5F9'], 'disputed' => ['Contesté', '#991B1B', '#FEE2E2']];
$kind_lbl = ['invoice' => 'Facture', 'cotisation' => 'Cotisation', 'don' => 'Don'];
$dash_url = 'https://dashboard.stripe.com/' . ($mode === 'test' ? 'test/' : '') . 'payments';
$csrf = h($_SESSION['csrf_token']);

render_head('Paiements en ligne');
render_sidebar('paiements-en-ligne');
?>
<main class="main">
  <div class="pel-page">
    <nav class="crumbs"><a href="/dashboard">Tableau de bord</a><span class="sep">›</span><span class="current">Paiements en ligne</span></nav>
    <h1 class="pel-title">💳 Paiements en ligne</h1>
    <p class="pel-sub">Vos adhérents, donateurs et clients paient par carte bancaire, Apple Pay, Google Pay ou prélèvement SEPA. L’argent arrive directement sur le compte bancaire de l’association.</p>

    <?php if ($flash): ?>
      <div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success' ?>" style="margin:0 0 16px;"><div><?= h($flash['message'] ?? '') ?></div></div>
    <?php endif; ?>

    <?php if ($in_app): ?>
      <div class="pel-note">📱 Dans l’application, cette page est en lecture seule : la configuration des paiements en ligne se fait sur assokit.fr, dans votre navigateur.</div>
    <?php endif; ?>

    <?php if (!$open): ?>
      <section class="pel-card">
        <h2>⏳ Bientôt disponible</h2>
        <p>Le paiement en ligne des factures, cotisations et dons ouvre très bientôt sur Assokit. En attendant, enregistrez les paiements reçus (chèque, virement, espèces) depuis vos campagnes et vos factures.</p>
      </section>

    <?php elseif (!$acc || $disconnected): ?>
      <section class="pel-card pel-hero">
        <h2><?= $disconnected ? 'Paiements en ligne déconnectés' : 'Recevez les paiements de vos adhérents, donateurs et clients directement sur votre compte bancaire' ?></h2>
        <ul class="pel-list">
          <li>✓ Un bouton « Payer en ligne » sur vos factures, vos campagnes de cotisation et votre page de dons, et dans vos e-mails et relances.</li>
          <li>✓ Le paiement est enregistré automatiquement dans Assokit : facture payée, cotisation à jour, reçu envoyé au payeur.</li>
          <li>✓ Votre propre compte Stripe : vous voyez vos paiements, vos versements et vos remboursements dans votre tableau de bord Stripe.</li>
          <li>✓ Frais Stripe à la charge de l’association (à titre indicatif : 1,5 % + 0,25 € par carte européenne) ; aucune commission Assokit.</li>
        </ul>
        <?php if ($can_manage): ?>
          <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Ouverture de Stripe…';">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="connect">
            <button type="submit" class="pel-btn"><?= $disconnected ? '🔗 Relier de nouveau mon compte Stripe' : '🔗 Connecter mon compte Stripe' ?></button>
          </form>
          <p class="pel-help">Stripe vous demande, une seule fois : l’identité de l’association (nom, numéro RNA ou SIREN), celle de son représentant légal et l’IBAN où verser les paiements. Comptez 10 minutes. Vous acceptez au passage les conditions de service de Stripe : l’association devient le vendeur des paiements qu’elle reçoit.</p>
        <?php elseif (!$in_app): ?>
          <p class="pel-help">Un administrateur ou le trésorier de l’association peut relier le compte Stripe.</p>
        <?php endif; ?>
      </section>

    <?php elseif (!$ready): ?>
      <section class="pel-card pel-warn">
        <h2><?= !empty($acc['details_submitted']) && !$reqs ? '⏳ Stripe vérifie vos informations' : '⚠ Stripe attend encore des informations' ?></h2>
        <?php if ($reqs): ?>
          <p>À compléter sur Stripe :</p>
          <ul class="pel-list"><?php foreach ($reqs as $r): ?><li>• <?= h($r) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
          <p>La vérification prend en général quelques minutes, parfois un ou deux jours ouvrés. Vous recevrez un e-mail de Stripe ; cette page se met à jour automatiquement.</p>
        <?php endif; ?>
        <?php if ($can_manage): ?>
          <div class="pel-actions">
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="resume"><button type="submit" class="pel-btn">Reprendre la configuration sur Stripe</button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="refresh"><button type="submit" class="pel-btn-ghost">↻ Actualiser l’état</button></form>
          </div>
        <?php endif; ?>
      </section>

    <?php else: ?>
      <section class="pel-card pel-ok">
        <h2>✓ Paiements en ligne actifs</h2>
        <div class="pel-grid">
          <div><div class="pel-lbl">Compte Stripe</div><div class="pel-val"><?= h($acc['display_name'] ?: '—') ?></div><div class="pel-mono"><?= h($acc['stripe_account_id']) ?><?= $mode === 'test' ? ' · mode test' : '' ?></div></div>
          <div><div class="pel-lbl">Paiements</div><div class="pel-val">✓ Acceptés</div></div>
          <div><div class="pel-lbl">Versements sur votre compte bancaire</div><div class="pel-val"><?= !empty($acc['payouts_enabled']) ? '✓ Actifs' : '⏳ En attente de Stripe' ?></div></div>
          <div><div class="pel-lbl">Prélèvement SEPA</div><div class="pel-val"><?= ($acc['sepa_capability'] ?? '') === 'active' ? '✓ Disponible' : '— Non activé sur Stripe' ?></div></div>
        </div>
        <?php if ($reqs): ?><p class="pel-help">⚠ Stripe demandera bientôt : <?= h(implode(', ', $reqs)) ?>. <?php if ($can_manage): ?>Complétez depuis « Reprendre la configuration ».<?php endif; ?></p><?php endif; ?>
        <p class="pel-help">Frais Stripe à la charge de l’association, aucune commission Assokit. Les fonds sont versés par Stripe sur votre compte bancaire ; remboursements et litiges se gèrent dans votre tableau de bord Stripe, et Assokit se met à jour tout seul.</p>
        <div class="pel-actions">
          <?php if (!$in_app): ?><a class="pel-btn-ghost" href="<?= h($dash_url) ?>" target="_blank" rel="noopener">Ouvrir mon tableau de bord Stripe ↗</a><?php endif; ?>
          <?php if ($can_manage): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="resume"><button type="submit" class="pel-btn-ghost">Modifier mes informations sur Stripe</button></form>
            <form method="post" onsubmit="return confirm(<?= h(json_encode('Désactiver les paiements en ligne ? Les boutons « Payer en ligne » disparaissent de vos factures, campagnes et e-mails. Votre compte Stripe reste à vous.', JSON_UNESCAPED_UNICODE)) ?>);"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="disconnect"><button type="submit" class="pel-btn-danger">Déconnecter</button></form>
          <?php endif; ?>
        </div>
      </section>

      <section class="pel-card">
        <h2>Ce qui se paie en ligne</h2>
        <form method="post" class="pel-form">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="save_settings">
          <label class="pel-check"><input type="checkbox" name="invoices_enabled" value="1" <?= $settings['invoices_enabled'] ? 'checked' : '' ?> <?= $can_manage ? '' : 'disabled' ?>> <span><strong>Factures</strong> — bouton « Payer en ligne » sur la page de la facture, lien dans l’e-mail, les relances et le PDF.</span></label>
          <label class="pel-check"><input type="checkbox" name="cotisations_enabled" value="1" <?= $settings['cotisations_enabled'] ? 'checked' : '' ?> <?= $can_manage ? '' : 'disabled' ?>> <span><strong>Cotisations</strong> — lien de paiement de chaque campagne (et lien personnel de chaque adhérent dans les rappels de cotisation).</span></label>
          <label class="pel-check"><input type="checkbox" name="dons_enabled" value="1" <?= $settings['dons_enabled'] ? 'checked' : '' ?> <?= $can_manage ? '' : 'disabled' ?>> <span><strong>Dons</strong> — page de dons à montant libre pour les campagnes de type « Don ». Assokit envoie un reçu de paiement, pas un reçu fiscal.</span></label>
          <label class="pel-check"><input type="checkbox" name="sepa_enabled" value="1" <?= $settings['sepa_enabled'] ? 'checked' : '' ?> <?= $can_manage ? '' : 'disabled' ?>> <span><strong>Prélèvement SEPA</strong> en plus de la carte (paiements en euros) — confirmé par la banque sous quelques jours ouvrés ; frais Stripe souvent plus bas pour les gros montants.<?= ($acc['sepa_capability'] ?? '') === 'active' ? '' : ' <em>À activer d’abord dans votre tableau de bord Stripe (Paramètres › Moyens de paiement).</em>' ?></span></label>
          <?php if ($can_manage): ?><button type="submit" class="pel-btn">Enregistrer</button><?php endif; ?>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($payments): ?>
      <section class="pel-card">
        <h2>Derniers paiements en ligne</h2>
        <div style="overflow-x:auto;">
        <table class="pel-tbl">
          <thead><tr><th>Date</th><th>Objet</th><th>Payeur</th><th class="num">Montant</th><th class="num">Frais Stripe</th><th>État</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): $b = $status_lbl[$p['status']] ?? [$p['status'], '#475569', '#F1F5F9']; $cx = ak_connect_op_context($pdo, $p); ?>
            <tr>
              <td><?= h(date('d/m/Y', strtotime((string)($p['paid_at'] ?: $p['created_at'])))) ?></td>
              <td><a href="<?= h($cx['link']) ?>"><?= h(ucfirst($cx['object'])) ?></a></td>
              <td><?= h($p['payer_name'] ?: ($p['payer_email'] ?: '—')) ?></td>
              <td class="num"><?= h(ak_connect_money((int)$p['amount_cents'])) ?></td>
              <td class="num"><?= $p['fee_cents'] !== null ? h(ak_connect_money((int)$p['fee_cents'])) : '—' ?></td>
              <td><span class="pel-badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>;"><?= h($b[0]) ?></span><?= !empty($p['overpaid']) ? ' <span class="pel-badge" style="color:#991B1B;background:#FEE2E2;">À rembourser</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>
    <?php endif; ?>

    <details class="pel-card">
      <summary><strong>Comment ça marche ?</strong></summary>
      <ol class="pel-steps">
        <li>Vous reliez le compte Stripe de l’association (ci-dessus). Assokit ne voit jamais votre IBAN ni les cartes de vos payeurs.</li>
        <li>Vos factures, campagnes de cotisation et pages de dons affichent un bouton « Payer en ligne » ; vos e-mails et relances contiennent le lien.</li>
        <li>Le payeur règle sur la page sécurisée de Stripe. L’association est le vendeur : le paiement est versé sur son compte Stripe, puis sur son compte bancaire.</li>
        <li>Assokit enregistre le paiement tout seul (comme un paiement saisi à la main), envoie un reçu au payeur et vous prévient.</li>
        <li>Un remboursement se fait depuis votre tableau de bord Stripe : Assokit le reporte (cotisation « remboursée », facture de nouveau « à payer »).</li>
      </ol>
      <p class="pel-help">Assokit est un intermédiaire technique : il ne détient jamais les fonds. Les dons reçus en ligne ne génèrent pas de reçu fiscal automatique.</p>
    </details>
  </div>
</main>
<style>
.pel-page { max-width: 980px; margin: 0 auto; padding: 24px 22px; }
.pel-title { font-size: 24px; margin: 6px 0 4px; color: #111827; }
.pel-sub { color: #6b7280; margin: 0 0 18px; font-size: 14px; line-height: 1.55; }
.pel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 22px; margin-bottom: 18px; }
.pel-card h2 { font-size: 17px; margin: 0 0 10px; color: #111827; line-height: 1.35; }
.pel-card p { font-size: 14px; color: #374151; line-height: 1.6; margin: 0 0 10px; }
.pel-hero { border: 2px solid #A7F3D0; background: linear-gradient(135deg, #fff, #F0FDF4); }
.pel-ok { border-color: #A7F3D0; }
.pel-warn { border-color: #FCD34D; background: #FFFBEB; }
.pel-note { background: #EFF6FF; border: 1px solid #BFDBFE; color: #1E40AF; border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-bottom: 16px; }
.pel-list { list-style: none; margin: 0 0 14px; padding: 0; display: grid; gap: 6px; font-size: 14px; color: #374151; line-height: 1.5; }
.pel-btn { background: #059669; color: #fff; border: 0; border-radius: 10px; padding: 11px 20px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; }
.pel-btn:hover { background: #047857; } .pel-btn:disabled { opacity: .6; cursor: wait; }
.pel-btn-ghost { display: inline-flex; align-items: center; background: #fff; color: #374151; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 16px; font-weight: 600; font-size: 13.5px; cursor: pointer; text-decoration: none; font-family: inherit; }
.pel-btn-danger { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; border-radius: 10px; padding: 10px 16px; font-weight: 600; font-size: 13.5px; cursor: pointer; font-family: inherit; }
.pel-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.pel-actions form { margin: 0; }
.pel-help { font-size: 12.5px !important; color: #6b7280 !important; margin-top: 10px !important; }
.pel-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 6px 0 10px; }
.pel-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 700; }
.pel-val { font-size: 15px; font-weight: 700; color: #111827; margin-top: 3px; overflow-wrap: anywhere; }
.pel-mono { font-family: monospace; font-size: 12px; color: #6b7280; overflow-wrap: anywhere; }
.pel-form { display: grid; gap: 12px; }
.pel-check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; color: #374151; line-height: 1.5; }
.pel-check input { margin-top: 4px; flex-shrink: 0; }
.pel-form .pel-btn { justify-self: start; }
.pel-tbl { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 620px; }
.pel-tbl th { text-align: left; font-size: 10.5px; text-transform: uppercase; color: #6b7280; padding: 8px 10px; background: #fafbfc; }
.pel-tbl td { padding: 9px 10px; border-top: 1px solid #f3f4f6; }
.pel-tbl .num { text-align: right; white-space: nowrap; }
.pel-tbl a { color: #059669; text-decoration: none; }
.pel-badge { font-size: 11px; padding: 3px 8px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
.pel-steps { margin: 12px 0 8px; padding-left: 20px; font-size: 14px; color: #374151; line-height: 1.6; display: grid; gap: 6px; }
details.pel-card summary { cursor: pointer; font-size: 15px; }
@media (max-width: 540px) { .pel-page { padding: 16px; } .pel-card { padding: 16px; } }
</style>
<?php render_foot(); ?>
