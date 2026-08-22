<?php
/**
 * mon-asso-sso.php — Clé SSO WordPress (générer / révoquer).
 * Réservé aux administrateurs. La clé authentifie le plugin WordPress
 * pour ouvrir Assokit déjà connecté (au nom de cet administrateur).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid = (int)($user['id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));
if ($org_id <= 0 || !in_array($role, ['admin','founder'], true)) {
    $_SESSION['flash_error'] = 'Réservé aux administrateurs.'; header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');
$new_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('CSRF'); }
    $act = $_POST['action'] ?? '';
    if ($act === 'generate') {
        // Une seule clé active par utilisateur : on révoque les précédentes.
        $pdo->prepare("UPDATE sso_keys SET revoked = 1 WHERE user_id = ? AND revoked = 0")->execute([$uid]);
        $new_key = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sso_keys (user_id, org_id, key_hash, label) VALUES (?,?,?,?)")
            ->execute([$uid, $org_id, hash('sha256', $new_key), 'WordPress']);
        $_SESSION['flash_success'] = 'Clé SSO générée. Copiez-la maintenant (elle ne sera plus affichée).';
    } elseif ($act === 'revoke') {
        $pdo->prepare("UPDATE sso_keys SET revoked = 1 WHERE user_id = ? AND revoked = 0")->execute([$uid]);
        $_SESSION['flash_success'] = 'Clé SSO révoquée.';
        header('Location: /mon-asso-sso'); exit;
    }
}

$active = (int)$pdo->query("SELECT COUNT(*) FROM sso_keys WHERE user_id = ".$uid." AND revoked = 0")->fetchColumn();

render_head('Clé SSO WordPress');
echo render_sidebar('mon-asso-sso');
?>
<main class="main">
  <div style="max-width:720px;margin:0 auto;padding:24px 22px;">
    <h1 style="display:flex;align-items:center;gap:11px;font-size:23px;color:#0F172A;"><?= ak_icon_badge('key','#059669',36) ?><span>Connexion WordPress (SSO)</span></h1>
    <p style="color:#64748B;font-size:14px;">Générez une clé pour le plugin Assokit sur WordPress. Le bouton « Ouvrir Assokit » de votre site WP vous connectera directement à ce compte.</p>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div style="background:#D1FAE5;color:#065F46;padding:10px 14px;border-radius:10px;font-weight:600;font-size:13.5px;margin:12px 0;"><?= h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>

    <?php if ($new_key): ?>
      <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;padding:16px;margin:14px 0;">
        <div style="font-weight:750;color:#92400E;margin-bottom:6px;">Votre clé SSO (copiez-la, non ré-affichée) :</div>
        <code style="display:block;word-break:break-all;background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:10px;font-size:13px;"><?= h($new_key) ?></code>
      </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:16px;margin-top:12px;">
      <p style="font-size:13.5px;color:#334155;margin:0 0 12px;">Statut : <strong><?= $active > 0 ? 'Clé active' : 'Aucune clé active' ?></strong></p>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="generate">
        <button style="padding:10px 18px;background:linear-gradient(135deg,#10B981,#059669);color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer;"><?= $active > 0 ? 'Régénérer la clé' : 'Générer une clé' ?></button>
      </form>
      <?php if ($active > 0): ?>
      <form method="POST" style="display:inline;margin-left:8px;" onsubmit="return confirm('Révoquer la clé ? Le bouton WordPress ne fonctionnera plus.')">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="revoke">
        <button style="padding:10px 18px;background:#FEE2E2;color:#991B1B;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Révoquer</button>
      </form>
      <?php endif; ?>
    </div>

    <p style="font-size:12.5px;color:#94A3B8;margin-top:16px;line-height:1.5;">Dans WordPress : Réglages → Assokit → collez cette clé. La clé authentifie au nom de votre compte administrateur — gardez-la secrète, révoquez-la si nécessaire.</p>
  </div>
</main>
<?php render_foot(); ?>
