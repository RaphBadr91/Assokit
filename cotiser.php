<?php
/**
 * cotiser.php — /cotiser/{jeton} (cotisation) et /don/{jeton} (don)
 * --------------------------------------------------------------
 * Page publique de paiement d'une campagne de cotisation ou de dons (cotisation_campaigns.public_token).
 * Le payeur choisit un tarif (ou un montant libre, borné : dons et campagnes « prix libre »), donne son nom et
 * son e-mail (reçu), puis paie sur la page Stripe Checkout de l'association (stripe-connect-helpers.php).
 *
 * Lien personnel d'un adhérent : ?m={id}.{signature} (rappels de cotisation, espace membre) : nom et e-mail
 * préremplis, et le paiement prolonge SON adhésion. Sans signature valide, aucun adhérent n'est rattaché :
 * l'association rapproche le paiement à la main (comme un chèque reçu sans nom d'adhérent).
 * Montant toujours calculé ici (tarif lu en base, montant libre borné), jamais repris tel quel du navigateur.
 * Application mobile : la page s'affiche sans formulaire (le paiement se fait dans le navigateur).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';
require_once __DIR__ . '/includes-cotisations.php';
require_once __DIR__ . '/stripe-connect-helpers.php';
require_once __DIR__ . '/rate-limit-helper.php';
require_once __DIR__ . '/paiement-en-ligne-layout.php';

$token = strtolower((string)($_GET['token'] ?? ''));
$campaign = null;
if (preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    try {
        $q = $pdo->prepare("SELECT * FROM cotisation_campaigns WHERE public_token = ? LIMIT 1");
        $q->execute([$token]);
        $campaign = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}
$org = $campaign ? ak_connect_org_row($pdo, (int)$campaign['org_id']) : null;
if (!$campaign || !$org || !empty($org['deleted_at'])) {
    http_response_code(404);
    ak_pay_head('Page introuvable', 'Assokit');
    echo '<div class="pp-card"><div class="pp-body"><p><strong>Ce lien de paiement n’existe pas ou plus.</strong></p><p class="pp-muted">Vérifiez l’adresse reçue, ou contactez l’association.</p></div></div>';
    ak_pay_foot('l’association');
    exit;
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$is_don = ak_connect_campaign_is_don($campaign);
$org_name = (string)$org['name'];
$tiers = $is_don ? [] : ck_load_tiers($pdo, (int)$campaign['id']);
$tiers = array_values(array_filter($tiers, fn($t) => (float)$t['amount'] > 0));
$free = $is_don || !$tiers || !empty($campaign['free_amount']);
$min_cents = max(AK_CONNECT_MIN_CENTS, (int)round((float)($campaign['min_amount'] ?? 0) * 100));
$in_app = ak_is_mobile_app();
$closed_msg = ak_connect_campaign_open($campaign);
$online = ak_connect_campaign_online($pdo, $campaign);
$self = ($is_don ? '/don/' : '/cotiser/') . $token;

// Lien personnel signé : adhérent de CETTE association seulement
$mref = (string)($_GET['m'] ?? ($_POST['m'] ?? ''));
$member = null;
if ($mref !== '' && ($mid = ak_connect_member_from_ref($pdo, (int)$campaign['id'], $mref)) > 0) {
    try {
        $q = $pdo->prepare("SELECT id, first_name, last_name, email, adhesion_valid_until FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
        $q->execute([$mid, (int)$campaign['org_id']]);
        $member = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}
if (!$member) $mref = '';
$self_q = $self . ($mref !== '' ? '?m=' . rawurlencode($mref) : '');
// Prélèvement SEPA de CET adhérent encore en attente de la banque pour cette campagne : pas de second paiement
$pending_sepa = (!$is_don && $member) ? ak_connect_cotisation_pending_op($pdo, (int)$campaign['id'], (int)$member['id']) : null;

if (empty($_SESSION['csrf_token_public'])) $_SESSION['csrf_token_public'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token_public'];
$error = '';
$val = ['tier' => (string)($_POST['tier_id'] ?? ''), 'amount' => (string)($_POST['amount'] ?? ''),
        'name' => (string)($_POST['payer_name'] ?? ($member ? trim($member['first_name'] . ' ' . $member['last_name']) : '')),
        'email' => (string)($_POST['payer_email'] ?? ($member['email'] ?? ''))];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $amount = 0; $tier_id = 0; $tier_name = '';
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Session expirée : rechargez la page puis recommencez.';
    } elseif ($in_app) {
        $error = 'Le paiement s’ouvre dans votre navigateur, pas dans l’application.';
    } elseif ($closed_msg !== null || !$online['ok']) {
        $error = $closed_msg ?? ak_connect_unavailable_text($org_name);
    } elseif (!ak_rate_limit('pay_campaign', 20, 600)) {
        $error = 'Trop de tentatives en peu de temps : patientez quelques minutes.';
    } else {
        $name = trim(preg_replace('/\s+/', ' ', $val['name']));
        $email = mb_strtolower(trim($val['email']));
        if ($val['tier'] !== '' && $val['tier'] !== 'libre') {
            foreach ($tiers as $t) if ((string)(int)$t['id'] === $val['tier']) { $tier_id = (int)$t['id']; $tier_name = (string)$t['name']; $amount = (int)round((float)$t['amount'] * 100); }
            if (!$tier_id) $error = 'Choisissez un tarif.';
        } elseif ($free) {
            $raw = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', $val['amount']);
            $raw = str_replace(',', '.', $raw);
            if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw)) $error = 'Indiquez un montant, par exemple 20 ou 20,50.';
            else {
                $amount = (int)round((float)$raw * 100);
                if ($amount < $min_cents) $error = 'Le montant minimum est de ' . ak_connect_money($min_cents) . '.';
                elseif ($amount > AK_CONNECT_MAX_CENTS) $error = 'Le montant maximum en ligne est de ' . ak_connect_money(AK_CONNECT_MAX_CENTS) . ' : contactez ' . $org_name . ' pour un montant plus élevé.';
            }
        } else {
            $error = 'Choisissez un tarif.';
        }
        if ($error === '' && (mb_strlen($name) < 2 || mb_strlen($name) > 150)) $error = 'Indiquez votre nom et votre prénom.';
        if ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Indiquez une adresse e-mail valide : votre reçu y sera envoyé.';
        if ($error === '') {
            $label = $is_don ? 'Don à ' . $org_name : 'Cotisation « ' . $campaign['name'] . ' »' . ($tier_name !== '' ? ' — ' . $tier_name : '');
            $r = ak_connect_start_checkout($pdo, [
                'org_id' => (int)$campaign['org_id'], 'kind' => $is_don ? 'don' : 'cotisation', 'campaign_id' => (int)$campaign['id'],
                'tier_id' => $tier_id, 'adherent_id' => $member ? (int)$member['id'] : 0, 'amount_cents' => $amount,
                'currency' => (string)($campaign['currency'] ?: 'EUR'), 'label' => $label,
                'product_description' => $is_don ? (string)$campaign['name'] : $org_name,
                'description' => $label . ' (' . $name . ')', 'payer_name' => $name, 'payer_email' => $email,
                'cancel_url' => ak_stripe_site_base() . $self . '?' . ($mref !== '' ? 'm=' . rawurlencode($mref) . '&' : '') . 'annule=1',
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
            if ($r['ok']) { header('Location: ' . $r['url'], true, 303); exit; }
            $error = $r['error'];
        }
    }
}

$cur = (string)($campaign['currency'] ?: 'EUR');
$title = $is_don ? 'Faire un don' : 'Payer ma cotisation';
ak_pay_head($title, $org_name);
?>
<div class="pp-card">
  <div class="pp-head">
    <div class="pp-org"><?= $h($org_name) ?></div>
    <h1><?= $h($is_don ? ($campaign['name'] ?: 'Faire un don') : $campaign['name']) ?></h1>
  </div>
  <div class="pp-body">
    <?php /* La description de la campagne est « à usage interne » (cotisation-form.php) : jamais affichée ici */ ?>
    <p><?= $is_don ? 'Soutenez ' . $h($org_name) . ' par un don en ligne.' : 'Réglez votre cotisation à ' . $h($org_name) . ' en ligne.' ?></p>
    <?php if (!empty($campaign['closes_at']) && $closed_msg === null): ?><p class="pp-muted">⏰ Jusqu’au <?= $h(date('d/m/Y', strtotime((string)$campaign['closes_at']))) ?></p><?php endif; ?>
    <?php if ($member): ?>
      <p class="pp-alert pp-alert-info">Bonjour <?= $h($member['first_name']) ?> ! Ce lien vous est personnel : votre paiement sera rattaché à votre adhésion<?= !empty($member['adhesion_valid_until']) ? ' (valable jusqu’au ' . $h(date('d/m/Y', strtotime((string)$member['adhesion_valid_until']))) . ')' : '' ?>.</p>
    <?php endif; ?>
    <?php if (isset($_GET['annule'])): ?><p class="pp-alert pp-alert-wait">Paiement annulé : rien n’a été prélevé. Vous pouvez réessayer.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="pp-alert pp-alert-error"><?= $h($error) ?></p><?php endif; ?>

    <?php if ($closed_msg !== null): ?>
      <p class="pp-alert pp-alert-wait"><?= $h($closed_msg) ?> Pour toute question, contactez <?= $h($org_name) ?>.</p>
      <?php if ($tiers): ?><p class="pp-muted">Tarifs : <?= $h(implode(' · ', array_map(fn($t) => $t['name'] . ' ' . ck_money($t['amount'], $cur), $tiers))) ?></p><?php endif; ?>
    <?php elseif (!$online['ok']): ?>
      <p class="pp-alert pp-alert-wait"><?= $h(ak_connect_unavailable_text($org_name)) ?></p>
      <?php if ($tiers): ?><p class="pp-muted">Tarifs : <?= $h(implode(' · ', array_map(fn($t) => $t['name'] . ' ' . ck_money($t['amount'], $cur), $tiers))) ?></p><?php endif; ?>
    <?php elseif ($pending_sepa): ?>
      <p class="pp-alert pp-alert-wait">Un prélèvement SEPA est déjà en cours pour votre cotisation (<?= $h(ak_connect_money((int)$pending_sepa['amount_cents'])) ?>, envoyé le <?= $h(date('d/m/Y', strtotime((string)$pending_sepa['created_at']))) ?>) : la banque le confirme sous quelques jours ouvrés, et vous recevrez alors votre reçu par e-mail. Rien d’autre à faire.</p>
    <?php elseif ($in_app): ?>
      <p class="pp-alert pp-alert-info">Le paiement en ligne s’ouvre dans votre navigateur. Ouvrez ce lien depuis l’e-mail reçu, ou depuis assokit.fr.</p>
      <?php if ($tiers): ?><p class="pp-muted">Tarifs : <?= $h(implode(' · ', array_map(fn($t) => $t['name'] . ' ' . ck_money($t['amount'], $cur), $tiers))) ?></p><?php endif; ?>
    <?php else: ?>
      <form method="POST" action="<?= $h($self) ?>" id="pp-form" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Ouverture de la page de paiement…';">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <?php if ($mref !== ''): ?><input type="hidden" name="m" value="<?= $h($mref) ?>"><?php endif; ?>
        <?php if ($tiers): ?>
          <div class="pp-fld"><label><?= $is_don ? 'Montant' : 'Votre tarif' ?></label></div>
          <div class="pp-tiers">
            <?php foreach ($tiers as $i => $t): ?>
              <label class="pp-tier"><input type="radio" name="tier_id" value="<?= (int)$t['id'] ?>" data-amount="<?= $h(number_format((float)$t['amount'], 2, '.', '')) ?>" <?= ($val['tier'] === (string)(int)$t['id'] || ($val['tier'] === '' && $i === 0 && !$free)) ? 'checked' : '' ?> required>
                <span class="pp-t-name"><?= $h($t['name']) ?><?php if (!empty($t['description'])): ?><span class="pp-t-desc"><?= $h($t['description']) ?></span><?php endif; ?></span>
                <span class="pp-t-amt"><?= $h(ck_money($t['amount'], $cur)) ?></span></label>
            <?php endforeach; ?>
            <?php if ($free): ?>
              <label class="pp-tier"><input type="radio" name="tier_id" value="libre" <?= $val['tier'] === 'libre' ? 'checked' : '' ?>><span class="pp-t-name">Montant libre</span></label>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($free): ?>
          <div class="pp-fld" id="pp-free">
            <label for="pp-amount"><?= $is_don ? 'Montant de votre don (€)' : 'Montant (€)' ?></label>
            <?php if ($is_don): ?><div class="pp-quick"><?php foreach ([10, 20, 50, 100] as $q): if ($q * 100 < $min_cents) continue; ?><button type="button" data-v="<?= $q ?>"><?= $q ?>&nbsp;€</button><?php endforeach; ?></div><?php endif; ?>
            <input type="text" inputmode="decimal" id="pp-amount" name="amount" value="<?= $h($val['amount']) ?>" placeholder="<?= $h(number_format($min_cents / 100, 0, ',', ' ')) ?> € minimum" autocomplete="off">
          </div>
        <?php endif; ?>
        <div class="pp-fld"><label for="pp-name">Nom et prénom</label><input type="text" id="pp-name" name="payer_name" value="<?= $h($val['name']) ?>" maxlength="150" required autocomplete="name"></div>
        <div class="pp-fld"><label for="pp-email">E-mail (pour votre reçu)</label><input type="email" id="pp-email" name="payer_email" value="<?= $h($val['email']) ?>" maxlength="200" required autocomplete="email"></div>
        <button type="submit" class="pp-btn"><?= $is_don ? '💚 Faire un don' : '💳 Payer ma cotisation' ?></button>
        <div class="pp-lock">🔒 Paiement par carte bancaire, Apple Pay ou Google Pay<?= in_array('sepa_debit', ak_connect_payment_types($online['account'], $online['settings'], $cur), true) ? ', ou prélèvement SEPA' : '' ?>, sur la page sécurisée de Stripe.</div>
        <p class="pp-muted" style="margin-top:14px;">Vos nom et e-mail sont transmis à <?= $h($org_name) ?> pour enregistrer votre paiement et vous envoyer un reçu. Vos données bancaires sont traitées par Stripe : ni l’association ni Assokit n’y ont accès.<?= $is_don ? ' Ce paiement ne donne pas lieu à un reçu fiscal automatique : demandez-le à ' . $h($org_name) . ' si elle peut en délivrer.' : '' ?></p>
      </form>
      <script>
      (function () {
        var f = document.getElementById('pp-form'), amt = document.getElementById('pp-amount'), box = document.getElementById('pp-free');
        if (!f) return;
        function sync() {
          var r = f.querySelector('input[name=tier_id]:checked');
          if (box && r) box.style.display = (r.value === 'libre') ? '' : 'none';
          if (amt) amt.required = !r || r.value === 'libre';
        }
        f.addEventListener('change', sync); sync();
        Array.prototype.forEach.call(f.querySelectorAll('.pp-quick button'), function (b) {
          b.addEventListener('click', function () { amt.value = b.getAttribute('data-v'); amt.focus(); });
        });
      })();
      </script>
    <?php endif; ?>
  </div>
</div>
<?php ak_pay_foot($org_name); ?>
