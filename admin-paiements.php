<?php
/**
 * /admin-paiements - Coordonnées de paiement (RIB, chèque) ; paiement en ligne : /encaissement-en-ligne (Stripe Connect)
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

    // Coordonnées seulement : le paiement en ligne passe par le compte Stripe relié de l'association
    // (/encaissement-en-ligne, Stripe Connect). Les clés Stripe ne sont plus saisies ni écrites ici.
    // Une ligne par association (pas d'index unique sur org_id : mise à jour de la plus récente, sinon création)
    $cur = $pdo->prepare("SELECT id FROM org_payment_settings WHERE org_id = ? ORDER BY id DESC LIMIT 1");
    $cur->execute([$org_id]);
    $sid = (int)$cur->fetchColumn();
    if ($sid > 0) {
        $pdo->prepare("UPDATE org_payment_settings SET bank_iban = ?, bank_bic = ?, bank_holder = ?, check_payable_to = ? WHERE id = ?")
            ->execute([$iban, $bic, $holder, $check_to, $sid]);
    } else {
        $pdo->prepare("INSERT INTO org_payment_settings (org_id, bank_iban, bank_bic, bank_holder, check_payable_to) VALUES (?,?,?,?,?)")
            ->execute([$org_id, $iban, $bic, $holder, $check_to]);
    }
    $msg = 'Paramètres enregistrés.';
}

$s = ck_load_org_payment($pdo, $org_id);
require_once __DIR__ . '/stripe-connect-helpers.php';
$online_acc = ak_connect_account($pdo, $org_id);

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
      <p class="ck-help">Conservées ici pour les communiquer à vos adhérents qui paient par virement.</p>
      <div class="ck-fld"><label>Titulaire du compte</label><input type="text" name="bank_holder" value="<?= h($s['bank_holder'] ?? '') ?>" maxlength="150"></div>
      <div class="ck-row">
        <div class="ck-fld"><label>IBAN</label><input type="text" name="bank_iban" value="<?= h($s['bank_iban'] ?? '') ?>" maxlength="40" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX"></div>
        <div class="ck-fld"><label>BIC / SWIFT</label><input type="text" name="bank_bic" value="<?= h($s['bank_bic'] ?? '') ?>" maxlength="15"></div>
      </div>

      <h2 class="ck-section">✉️ Chèque</h2>
      <div class="ck-fld"><label>Ordre du chèque</label><input type="text" name="check_payable_to" value="<?= h($s['check_payable_to'] ?? '') ?>" maxlength="150" placeholder="Nom de l'association"></div>

      <h2 class="ck-section">💳 Paiement en ligne (carte, Apple Pay, Google Pay, prélèvement SEPA)</h2>
      <div class="ck-stripe-info">
        <?php if ($online_acc && !empty($online_acc['charges_enabled'])): ?>
          <strong>✓ Paiements en ligne actifs</strong> sur le compte Stripe de l’association. Réglages (factures, cotisations, dons, SEPA) : <a href="/encaissement-en-ligne">Paiements en ligne</a>.
        <?php else: ?>
          Recevez les cotisations, les dons et le règlement de vos factures directement sur le compte bancaire de l’association, en reliant son compte Stripe : <a href="/encaissement-en-ligne">Paiements en ligne</a>.
        <?php endif; ?>
      </div>

      <div class="ck-actions">
        <button type="submit" class="ck-btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</main>
<style>
.ck-flash { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.ck-stripe-info { background: #fffbea; border: 1px solid #fde68a; color: #78350f; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.55; margin-bottom: 16px; }
.ck-stripe-info a { color: #065F46; font-weight: 600; }
.ck-stripe-info code { background: rgba(0,0,0,0.06); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
</style>
<?= render_foot() ?>
