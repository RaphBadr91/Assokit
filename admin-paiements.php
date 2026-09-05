<?php
/**
 * /admin-paiements - Configuration moyens de paiement (RIB + Stripe)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin uniquement.'); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $iban = trim($_POST['bank_iban'] ?? '') ?: null;
    $bic = trim($_POST['bank_bic'] ?? '') ?: null;
    $holder = trim($_POST['bank_holder'] ?? '') ?: null;
    $check_to = trim($_POST['check_payable_to'] ?? '') ?: null;

    $stripe_enabled = !empty($_POST['stripe_enabled']) ? 1 : 0;
    $stripe_mode = in_array($_POST['stripe_mode'] ?? '', ['test','live'], true) ? $_POST['stripe_mode'] : 'test';
    $stripe_pub = trim($_POST['stripe_publishable_key'] ?? '') ?: null;
    $stripe_sec = trim($_POST['stripe_secret_key'] ?? '') ?: null;
    $stripe_wh = trim($_POST['stripe_webhook_secret'] ?? '') ?: null;

    $stmt = $pdo->prepare("INSERT INTO org_payment_settings (org_id, bank_iban, bank_bic, bank_holder, check_payable_to, stripe_enabled, stripe_mode, stripe_publishable_key, stripe_secret_key, stripe_webhook_secret) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bank_iban=VALUES(bank_iban), bank_bic=VALUES(bank_bic), bank_holder=VALUES(bank_holder), check_payable_to=VALUES(check_payable_to), stripe_enabled=VALUES(stripe_enabled), stripe_mode=VALUES(stripe_mode), stripe_publishable_key=VALUES(stripe_publishable_key), stripe_secret_key=VALUES(stripe_secret_key), stripe_webhook_secret=VALUES(stripe_webhook_secret)");
    $stmt->execute([$org_id, $iban, $bic, $holder, $check_to, $stripe_enabled, $stripe_mode, $stripe_pub, $stripe_sec, $stripe_wh]);
    $msg = 'Paramètres enregistrés.';
}

$s = ck_load_org_payment($pdo, $org_id);

render_head('Paiements');
?>
<?= render_sidebar('admin') ?>
<main class="main">
  <div class="ck-page" style="max-width:780px;">
    <a href="/cotisations" class="ck-back">← Cotisations</a>
    <h1 class="ck-pg-title">⚙️ Configuration des paiements</h1>
    <?php if ($msg): ?><div class="ck-flash">✅ <?= h($msg) ?></div><?php endif; ?>

    <form method="POST" class="ck-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

      <h2 class="ck-section">🏦 Coordonnées bancaires (virement)</h2>
      <p class="ck-help">Affichées sur la page de paiement publique pour les virements.</p>
      <div class="ck-fld"><label>Titulaire du compte</label><input type="text" name="bank_holder" value="<?= h($s['bank_holder'] ?? '') ?>" maxlength="150"></div>
      <div class="ck-row">
        <div class="ck-fld"><label>IBAN</label><input type="text" name="bank_iban" value="<?= h($s['bank_iban'] ?? '') ?>" maxlength="40" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX"></div>
        <div class="ck-fld"><label>BIC / SWIFT</label><input type="text" name="bank_bic" value="<?= h($s['bank_bic'] ?? '') ?>" maxlength="15"></div>
      </div>

      <h2 class="ck-section">✉️ Chèque</h2>
      <div class="ck-fld"><label>Ordre du chèque</label><input type="text" name="check_payable_to" value="<?= h($s['check_payable_to'] ?? '') ?>" maxlength="150" placeholder="Nom de l'association"></div>

      <h2 class="ck-section">💳 Stripe (paiement en ligne par carte)</h2>
      <div class="ck-stripe-info">
        <strong>📌 Stripe sera activé dès que ta société sera créée.</strong><br>
        Les clés ci-dessous peuvent être laissées vides pour l'instant. Une fois ton compte Stripe créé : (1) coche "Activer", (2) colle les clés depuis dashboard.stripe.com → Développeurs → Clés API, (3) configure le webhook sur <code><?= h($_SERVER['HTTP_HOST'] ?? 'assokit.fr') ?>/stripe-webhook</code>.
      </div>

      <div class="ck-fld">
        <label><input type="checkbox" name="stripe_enabled" value="1" <?= $s['stripe_enabled'] ? 'checked' : '' ?>> Activer Stripe</label>
      </div>
      <div class="ck-row">
        <div class="ck-fld"><label>Mode</label>
          <select name="stripe_mode"><option value="test" <?= $s['stripe_mode']=='test'?'selected':'' ?>>Test (recommandé pour démarrer)</option><option value="live" <?= $s['stripe_mode']=='live'?'selected':'' ?>>Live (production)</option></select>
        </div>
      </div>
      <div class="ck-fld"><label>Clé publique (pk_…)</label><input type="text" name="stripe_publishable_key" value="<?= h($s['stripe_publishable_key'] ?? '') ?>" maxlength="200" placeholder="pk_test_..."></div>
      <div class="ck-fld"><label>Clé secrète (sk_…)</label><input type="password" name="stripe_secret_key" value="<?= h($s['stripe_secret_key'] ?? '') ?>" maxlength="200" placeholder="sk_test_..." autocomplete="new-password"></div>
      <div class="ck-fld"><label>Webhook signing secret (whsec_…)</label><input type="password" name="stripe_webhook_secret" value="<?= h($s['stripe_webhook_secret'] ?? '') ?>" maxlength="200" placeholder="whsec_..." autocomplete="new-password"></div>

      <div class="ck-actions">
        <button type="submit" class="ck-btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</main>
<style>
.ck-flash { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.ck-stripe-info { background: #fffbea; border: 1px solid #fde68a; color: #78350f; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.55; margin-bottom: 16px; }
.ck-stripe-info code { background: rgba(0,0,0,0.06); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
</style>
<?= render_foot() ?>
