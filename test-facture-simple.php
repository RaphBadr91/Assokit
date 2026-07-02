<?php
/**
 * test-facture-simple.php
 * Test simplifié de création facture (sans JS complexe)
 * À supprimer après usage
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) die('Pas d\'asso');
$org_id = (int)$user['org_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

ak_asso_invoice_settings($pdo, $org_id);
$slug = ak_asso_ensure_slug($pdo, $org_id);

render_head('TEST simple facture');
render_sidebar('factures');
?>

<div class="main">
    <div class="main-head">
        <div>
            <h1 class="page-title">🧪 TEST simple</h1>
            <div class="page-sub">Test minimal de création facture - <?= h($slug) ?></div>
        </div>
    </div>

    <div class="card" style="padding:22px; margin-bottom:16px; background:#FFFBEB; border:1px solid #FCD34D;">
        <strong>Test ULTRA simple</strong> : remplis juste les 4 champs ci-dessous + clique. Pas de JS, pas d'autocomplétion.
    </div>

    <form method="POST" action="/mon-asso-facture-save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="client_type" value="company">
        <input type="hidden" name="issued_at" value="<?= date('Y-m-d') ?>">
        <input type="hidden" name="due_days" value="30">

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3>Client</h3>
            <div style="margin-bottom:14px;">
                <label>Nom *</label>
                <input type="text" name="display_name" value="Client Test" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
            </div>
            <div style="margin-bottom:14px;">
                <label>Email *</label>
                <input type="email" name="email" value="test@test.fr" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
            </div>
        </div>

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3>Une seule ligne</h3>
            <div style="display:grid; grid-template-columns: 1fr 100px 100px; gap:10px;">
                <div>
                    <label>Designation *</label>
                    <input type="text" name="lines[0][designation]" value="Test prestation" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                </div>
                <div>
                    <label>Qté</label>
                    <input type="number" name="lines[0][quantity]" value="1" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                </div>
                <div>
                    <label>PU HT (€)</label>
                    <input type="number" name="lines[0][unit_price_ht]" value="100" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                </div>
            </div>
            <input type="hidden" name="lines[0][vat_rate]" value="">
        </div>

        <div style="text-align:center; padding:20px;">
            <button type="submit" style="padding:14px 30px; font-size:16px; background:#10B981; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:bold;">
                📤 CRÉER LA FACTURE TEST
            </button>
        </div>
    </form>

    <div style="margin-top:30px; padding:18px; background:#F3F4F6; border-radius:8px; font-family:monospace; font-size:12px;">
        <strong>Debug formulaire :</strong><br>
        Action : /mon-asso-facture-save.php (DOIT être .php)<br>
        Method : POST<br>
        CSRF token : <?= substr($csrf, 0, 30) ?>...
    </div>

</div>

<?php render_foot(); ?>
