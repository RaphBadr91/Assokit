<?php
/**
 * ============================================================
 * ASSOKIT — Changer son mot de passe
 * ============================================================
 * Page utilisée dans 2 cas :
 *   1. Premier login (must_change_password=1 → redirigé ici automatiquement)
 *   2. Changement volontaire par l'utilisateur depuis son profil
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();
$user = current_user();

$error = null;
$success = false;
$is_forced = !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée, rechargez la page.';
    } else {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';

        // Vérifier le mot de passe actuel
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current_pwd, $row['password_hash'])) {
            $error = 'Le mot de passe actuel est incorrect.';
        } elseif (strlen($new_pwd) < 8) {
            $error = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        } elseif ($new_pwd !== $confirm_pwd) {
            $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
        } elseif ($new_pwd === $current_pwd) {
            $error = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        } else {
            // OK, on change
            $new_hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
                ->execute([$new_hash, $user['id']]);

            $success = true;

            // Si c'était forcé, on redirige vers le dashboard
            if ($is_forced) {
                session_write_close();
                header('Location: /dashboard?password_changed=1');
                exit;
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Changer mon mot de passe — Assokit</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{--acc:#059669;--acc-dark:#047857;--bg:#FAFAF9;--ink:#1C1917;--ink-2:#44403C;--ink-3:#78716C;--border:#E7E5E4}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Geist',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:40px;max-width:480px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
    .logo{text-align:center;margin-bottom:28px}
    .logo-mark{font-size:22px;font-weight:600;letter-spacing:-0.03em}
    .logo-mark span{color:var(--acc)}
    h1{font-size:20px;font-weight:500;letter-spacing:-0.02em;margin-bottom:4px}
    .subtitle{font-size:13px;color:var(--ink-3);margin-bottom:24px;line-height:1.5}
    .notice{background:#FFFBEB;border:1px solid #FCD34D;color:#92400E;padding:12px 14px;border-radius:10px;font-size:12.5px;margin-bottom:20px;line-height:1.5}
    .form-group{margin-bottom:16px}
    label{display:block;font-size:12.5px;font-weight:500;color:var(--ink-2);margin-bottom:6px}
    input{width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:10px;font-size:14px;background:#fff;font-family:inherit}
    input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px rgba(5,150,105,0.08)}
    .pwd-hint{font-size:11.5px;color:var(--ink-3);margin-top:5px}
    .btn-submit{width:100%;padding:12px;background:var(--acc);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;margin-top:8px;font-family:inherit}
    .btn-submit:hover{background:var(--acc-dark)}
    .error-box{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
    .success-box{background:#F0FDF4;border:1px solid #86EFAC;color:#166534;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
    .footer-link{text-align:center;font-size:11.5px;color:var(--ink-3);margin-top:20px}
    .footer-link a{color:var(--acc);text-decoration:none;font-weight:500}
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-mark">asso<span>kit</span>.</div>
  </div>

  <h1>Changer votre mot de passe</h1>
  <p class="subtitle">Bienvenue <?= h($user['first_name']) ?> !</p>

  <?php if ($is_forced): ?>
    <div class="notice">
      🔑 <strong>Votre mot de passe actuel est temporaire.</strong><br>
      Avant de continuer, choisissez un mot de passe personnel que vous serez seul(e) à connaître.
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error-box">⚠️ <?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($success && !$is_forced): ?>
    <div class="success-box">✅ Mot de passe mis à jour avec succès.</div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

    <div class="form-group">
      <label>Mot de passe actuel</label>
      <input type="password" name="current_password" required autocomplete="current-password" autofocus>
      <?php if ($is_forced): ?>
        <div class="pwd-hint">Le mot de passe temporaire qui vous a été communiqué par l'administrateur.</div>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label>Nouveau mot de passe</label>
      <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      <div class="pwd-hint">Au moins 8 caractères. Évitez les mots de passe évidents.</div>
    </div>

    <div class="form-group">
      <label>Confirmer le nouveau mot de passe</label>
      <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
    </div>

    <button type="submit" class="btn-submit">Enregistrer le nouveau mot de passe</button>
  </form>

  <?php if (!$is_forced): ?>
    <div class="footer-link"><a href="/dashboard">← Retour au dashboard</a></div>
  <?php endif; ?>
</div>
</body>
</html>
