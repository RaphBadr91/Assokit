<?php
/**
 * ASSOKIT — Activation/désactivation de la double authentification (2FA / TOTP)
 * URL : /securite-2fa
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/totp-helper.php';
require_login();

$current = current_user();
$uid = (int)$current['id'];

$st = $pdo->prepare("SELECT email, totp_enabled FROM users WHERE id = ? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$enabled = !empty($u['totp_enabled']);
$email = $u['email'] ?? '';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$flash = null; $flash_type = 'success';
$backup_codes_show = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expirée, réessayez.'; $flash_type = 'error';
    } else {
        $act = $_POST['act'] ?? '';
        if ($act === 'confirm') {
            $secret = $_SESSION['totp_setup_secret'] ?? '';
            if ($secret && ak_totp_verify($secret, $_POST['code'] ?? '')) {
                $codes  = ak_totp_generate_backup_codes(8);
                $hashed = array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $codes);
                $st = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?");
                $st->execute([$secret, json_encode($hashed), $uid]);
                unset($_SESSION['totp_setup_secret']);
                $enabled = true; $backup_codes_show = $codes;
                $flash = 'Double authentification activée !';
            } else {
                $flash = 'Code incorrect. Vérifiez l\'heure de votre téléphone et réessayez.'; $flash_type = 'error';
            }
        } elseif ($act === 'disable') {
            $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $h = $st->fetchColumn();
            if ($h && password_verify($_POST['password'] ?? '', $h)) {
                $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?")->execute([$uid]);
                $enabled = false; $flash = 'Double authentification désactivée.';
            } else {
                $flash = 'Mot de passe incorrect.'; $flash_type = 'error';
            }
        }
    }
}

$setup_mode = (!$enabled && isset($_GET['setup']));
$setup_secret = null; $setup_uri = null;
if ($setup_mode) {
    if (empty($_SESSION['totp_setup_secret'])) $_SESSION['totp_setup_secret'] = ak_totp_generate_secret();
    $setup_secret = $_SESSION['totp_setup_secret'];
    $setup_uri = ak_totp_uri($setup_secret, $email);
}

render_head('Sécurité · Double authentification');
render_sidebar('parametres');
?>
<div class="main" style="max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:24px;margin:0 0 6px;">Double authentification (2FA)</h1>
  <p style="color:#64748B;margin:0 0 24px;">Une couche de sécurité supplémentaire pour protéger votre compte.</p>

  <?php if ($flash): ?>
    <div style="padding:12px 16px;border-radius:10px;margin-bottom:20px;<?= $flash_type==='error' ? 'background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;' : 'background:#F0FDF4;color:#047857;border:1px solid #A7F3D0;' ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($backup_codes_show): ?>
    <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:20px;margin-bottom:24px;">
      <h2 style="margin:0 0 8px;font-size:17px;">Vos codes de secours</h2>
      <p style="color:#92400E;font-size:14px;margin:0 0 14px;">Notez-les en lieu sûr. Chaque code fonctionne une seule fois si vous perdez votre téléphone. <strong>Ils ne seront plus affichés.</strong></p>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;font-family:monospace;font-size:15px;">
        <?php foreach ($backup_codes_show as $c): ?><div style="background:#fff;border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px;text-align:center;"><?= h($c) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($enabled && !$backup_codes_show): ?>
    <div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:12px;padding:20px;margin-bottom:24px;">
      <div style="font-size:16px;font-weight:600;color:#047857;">La double authentification est active</div>
      <p style="color:#475569;font-size:14px;margin:8px 0 0;">À chaque connexion, un code de votre application d'authentification vous sera demandé.</p>
    </div>
    <form method="post" style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:20px;">
      <h2 style="font-size:16px;margin:0 0 12px;">Désactiver la 2FA</h2>
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="act" value="disable">
      <label style="display:block;font-size:14px;margin-bottom:6px;">Confirmez avec votre mot de passe :</label>
      <input type="password" name="password" required style="width:100%;padding:10px;border:1px solid #CBD5E1;border-radius:8px;margin-bottom:12px;box-sizing:border-box;">
      <button type="submit" style="background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;border-radius:8px;padding:10px 18px;cursor:pointer;font-weight:600;">Désactiver</button>
    </form>

  <?php elseif ($setup_mode): ?>
    <div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:24px;">
      <h2 style="font-size:17px;margin:0 0 4px;">1. Scannez ce QR code</h2>
      <p style="color:#64748B;font-size:14px;margin:0 0 16px;">Avec Google Authenticator, Authy ou Microsoft Authenticator.</p>
      <div id="qrcode" style="display:flex;justify-content:center;margin-bottom:12px;"></div>
      <p style="text-align:center;color:#64748B;font-size:13px;margin:0 0 6px;">Ou saisissez la clé manuellement :</p>
      <p style="text-align:center;font-family:monospace;font-size:15px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:8px;word-break:break-all;"><?= h($setup_secret) ?></p>
      <hr style="border:none;border-top:1px solid #E2E8F0;margin:20px 0;">
      <h2 style="font-size:17px;margin:0 0 12px;">2. Entrez le code à 6 chiffres</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="act" value="confirm">
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" required style="width:100%;padding:12px;border:1px solid #CBD5E1;border-radius:8px;font-size:20px;text-align:center;letter-spacing:6px;margin-bottom:14px;box-sizing:border-box;">
        <button type="submit" style="width:100%;background:#059669;color:#fff;border:none;border-radius:8px;padding:12px;cursor:pointer;font-weight:600;font-size:15px;">Activer la 2FA</button>
      </form>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>new QRCode(document.getElementById("qrcode"), { text: <?= json_encode($setup_uri) ?>, width: 200, height: 200 });</script>

  <?php else: ?>
    <div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:24px;text-align:center;">
      <div style="font-size:15px;color:#475569;margin-bottom:16px;">La double authentification n'est pas activée.</div>
      <a href="?setup=1" style="display:inline-block;background:#059669;color:#fff;border-radius:8px;padding:12px 24px;text-decoration:none;font-weight:600;">Activer la 2FA</a>
    </div>
  <?php endif; ?>

  <p style="margin-top:20px;"><a href="/parametres?tab=securite" style="color:#64748B;font-size:14px;">&larr; Retour aux paramètres</a></p>
</div>
<?php render_foot(); ?>
