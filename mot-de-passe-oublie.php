<?php
/**
 * ============================================================
 * ASSOKIT — Mot de passe oublié
 * ============================================================
 * URL : /mot-de-passe-oublie
 *
 * Page publique. L'utilisateur saisit son email.
 * Si un compte existe, on envoie un token reset par email.
 *
 * SECURITE : on affiche le même message dans tous les cas
 *            pour empêcher l'énumération d'emails.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/password-token-helper.php';

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    require_once __DIR__ . '/rate-limit-helper.php';
    if (!ak_rate_limit('pwd_reset', 4, 900)) {
        $error = 'Trop de demandes. Merci de patienter quelques minutes avant de réessayer.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } else {
        // Petit delai aleatoire pour eviter timing attacks
        usleep(random_int(100000, 400000));

        // Chercher le user
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, is_active, deleted_at
            FROM users
            WHERE email = ? LIMIT 1
        ");
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        // Si user trouve ET actif ET non supprime → envoyer le token
        if ($u && $u['is_active'] && $u['deleted_at'] === null) {
            try {
                $token = create_password_token($pdo, (int)$u['id'], 'reset_password');
                send_reset_password_email($u['email'], $u['first_name'], $token);
            } catch (Throwable $e) {
                error_log('Reset password error: ' . $e->getMessage());
            }
        }

        // Toujours afficher le meme message (anti-enumeration)
        $success = true;
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Mot de passe oublié — Assokit</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, system-ui, sans-serif;
    margin: 0; padding: 0;
    background: linear-gradient(135deg, #FAFAF9 0%, #F5F5F4 100%);
    color: #1C1917;
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  .container { max-width: 440px; width: 100%; }
  .logo-wrap { text-align: center; margin-bottom: 24px; }
  .logo-mark {
    display: inline-block; width: 52px; height: 52px;
    background: #059669; border-radius: 13px;
    position: relative; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.25);
  }
  .logo-mark::after {
    content: ''; position: absolute;
    top: 50%; left: 50%; width: 18px; height: 18px;
    background: white; border-radius: 50%;
    transform: translate(-50%, -50%);
  }
  .logo-name { display: block; margin-top: 10px; font-size: 14px; color: #78716C; letter-spacing: 0.05em; }
  .card {
    background: white; border: 1px solid #E7E5E4;
    border-radius: 16px; padding: 32px 28px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.04);
  }
  h1 { font-size: 22px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.02em; }
  .sub { font-size: 13.5px; color: #57534E; margin: 0 0 22px; line-height: 1.55; }
  .alert-error {
    background: #FEF2F2; border: 1px solid #FECACA;
    color: #991B1B; padding: 12px 14px;
    border-radius: 10px; font-size: 13px; margin-bottom: 18px;
  }
  .success-state { text-align: center; padding: 10px 0; }
  .success-icon {
    width: 68px; height: 68px; background: #D1FAE5; color: #059669;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; margin: 0 auto 16px;
  }
  .form-group { margin-bottom: 18px; }
  .form-label { display: block; font-size: 12.5px; font-weight: 500; margin-bottom: 6px; color: #44403C; }
  .form-input {
    width: 100%; padding: 11px 13px;
    background: #FAFAF9; border: 1px solid #D6D3D1;
    border-radius: 9px; font-family: inherit; font-size: 14px; color: #1C1917;
  }
  .form-input:focus { outline: none; border-color: #059669; background: white; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }
  .btn-primary {
    width: 100%; background: #059669; color: white; border: none;
    padding: 13px 20px; border-radius: 10px;
    font-family: inherit; font-size: 14.5px; font-weight: 500;
    cursor: pointer; transition: background 0.15s;
  }
  .btn-primary:hover { background: #047857; }
  .link-back { display: block; text-align: center; margin-top: 16px; font-size: 13px; color: #059669; text-decoration: none; }
  .link-back:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="container">
  <div class="logo-wrap">
    <span class="logo-mark"></span>
    <span class="logo-name">ASSOKIT</span>
  </div>

  <div class="card">

    <?php if ($success): ?>
      <div class="success-state">
        <div class="success-icon">📧</div>
        <h1 style="text-align:center;">C'est envoyé !</h1>
        <p class="sub" style="text-align:center;">
          Si un compte existe avec cette adresse, vous recevrez un email dans quelques instants avec un lien pour réinitialiser votre mot de passe.
        </p>
        <p class="sub" style="text-align:center; font-size:12px;">
          💡 Vérifiez aussi vos spams. Le lien est valide pendant 7 jours.
        </p>
        <a href="/connexion" class="link-back">← Retour à la connexion</a>
      </div>

    <?php else: ?>
      <h1>Mot de passe oublié ?</h1>
      <p class="sub">Saisissez votre adresse email. Nous vous enverrons un lien pour réinitialiser votre mot de passe.</p>

      <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <div class="form-group">
          <label class="form-label" for="email">Adresse email</label>
          <input type="email" id="email" name="email" class="form-input" required maxlength="200" autofocus
                 placeholder="votre@email.fr">
        </div>
        <button type="submit" class="btn-primary">📧 Envoyer le lien de réinitialisation</button>
      </form>
      <a href="/connexion" class="link-back">← Retour à la connexion</a>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
