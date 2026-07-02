<?php
/**
 * ============================================================
 * ASSOKIT — Définir/Réinitialiser un mot de passe
 * ============================================================
 * URL : /definir-mot-de-passe/{token}
 * Page publique (sans login) utilisée pour :
 *   - Création de compte (token type = set_password)
 *   - Réinitialisation (token type = reset_password)
 *
 * Le token valide pendant 7 jours, usage unique.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/password-token-helper.php';

// Le token peut venir via URL propre (.htaccess) ou param GET
$token = $_GET['token'] ?? '';
$token = preg_replace('/[^a-f0-9]/i', '', $token);

$error = null;
$success = false;
$user_info = null;
$token_data = null;
$is_valid = false;

if (!$token || strlen($token) !== 64) {
    $error = 'Lien invalide.';
} else {
    // Essayer les 2 types de tokens (set ou reset)
    $token_data = validate_password_token($pdo, $token, 'set_password');
    if (!$token_data) {
        $token_data = validate_password_token($pdo, $token, 'reset_password');
    }

    if (!$token_data) {
        $error = 'Ce lien est invalide ou a expiré. Demandez un nouveau lien à votre administrateur, ou utilisez "Mot de passe oublié".';
    } else {
        $is_valid = true;
        $user_info = $token_data;
    }
}

// Soumission du formulaire
if ($is_valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pas de CSRF ici car on n'a pas de session (page publique)
    // La sécurité vient du token aléatoire 64 chars

    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    $validation_error = validate_password_strength($password);
    if ($validation_error) {
        $error = $validation_error;
    } elseif ($password !== $password_confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $pdo->beginTransaction();
        try {
            // Hasher et sauvegarder
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = ?, must_change_password = 0, is_active = 1
                WHERE id = ?
            ");
            $stmt->execute([$password_hash, (int)$token_data['user_id']]);

            // Consommer le token
            consume_password_token($pdo, (int)$token_data['id']);

            // Invalider tous les autres tokens de ce user (nettoyage)
            $stmt = $pdo->prepare("
                UPDATE user_password_tokens
                SET used_at = NOW()
                WHERE user_id = ? AND used_at IS NULL AND id != ?
            ");
            $stmt->execute([(int)$token_data['user_id'], (int)$token_data['id']]);

            $pdo->commit();
            $success = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Erreur technique : ' . $e->getMessage();
        }
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $success ? 'Compte activé' : ($is_valid ? 'Définir votre mot de passe' : 'Lien invalide') ?> — Assokit</title>
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
  .container { max-width: 460px; width: 100%; }

  .logo-wrap { text-align: center; margin-bottom: 24px; }
  .logo-mark {
    display: inline-block;
    width: 52px; height: 52px;
    background: #059669;
    border-radius: 13px;
    position: relative;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.25);
  }
  .logo-mark::after {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    width: 18px; height: 18px;
    background: white;
    border-radius: 50%;
    transform: translate(-50%, -50%);
  }
  .logo-name { display: block; margin-top: 10px; font-size: 14px; color: #78716C; letter-spacing: 0.05em; }

  .card {
    background: white;
    border: 1px solid #E7E5E4;
    border-radius: 16px;
    padding: 32px 28px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.04);
  }
  h1 { font-size: 22px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.02em; }
  .sub { font-size: 13.5px; color: #57534E; margin: 0 0 22px; line-height: 1.55; }

  .alert-error {
    background: #FEF2F2; border: 1px solid #FECACA;
    color: #991B1B; padding: 12px 14px;
    border-radius: 10px; font-size: 13px;
    margin-bottom: 18px; line-height: 1.5;
  }

  .success-state { text-align: center; padding: 10px 0 4px; }
  .success-icon {
    width: 68px; height: 68px;
    background: #D1FAE5; color: #059669;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 34px;
    margin: 0 auto 18px;
  }
  .success-title { font-size: 20px; font-weight: 600; margin: 0 0 10px; letter-spacing: -0.01em; }
  .success-text { font-size: 14px; color: #57534E; margin: 0 0 22px; line-height: 1.55; }

  .greet {
    background: #ECFDF5; border: 1px solid #A7F3D0;
    padding: 12px 14px; border-radius: 10px;
    font-size: 13px; color: #065F46;
    margin-bottom: 20px;
  }
  .greet strong { color: #047857; }

  .form-group { margin-bottom: 14px; }
  .form-label { display: block; font-size: 12.5px; font-weight: 500; margin-bottom: 6px; color: #44403C; }
  .form-input {
    width: 100%; padding: 11px 13px;
    background: #FAFAF9; border: 1px solid #D6D3D1;
    border-radius: 9px; font-family: inherit; font-size: 14px; color: #1C1917;
    transition: border-color 0.15s, background 0.15s;
  }
  .form-input:focus { outline: none; border-color: #059669; background: white; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }

  .pw-hint { font-size: 11.5px; color: #78716C; margin-top: 6px; line-height: 1.5; }

  .btn-primary {
    width: 100%; background: #059669; color: white; border: none;
    padding: 13px 20px; border-radius: 10px;
    font-family: inherit; font-size: 14.5px; font-weight: 500;
    cursor: pointer; transition: background 0.15s;
    margin-top: 8px;
  }
  .btn-primary:hover { background: #047857; }

  .btn-secondary {
    display: block; text-align: center;
    background: transparent; color: #059669;
    padding: 10px 20px; border-radius: 10px;
    font-family: inherit; font-size: 14px; font-weight: 500;
    text-decoration: none;
    border: 1px solid #A7F3D0;
  }
  .btn-secondary:hover { background: #ECFDF5; }
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
        <div class="success-icon">✓</div>
        <h2 class="success-title">Compte activé !</h2>
        <p class="success-text">
          Votre mot de passe a été enregistré avec succès.<br>
          Vous pouvez maintenant vous connecter à Assokit.
        </p>
        <a href="/connexion" class="btn-primary" style="display:inline-block; text-decoration:none; text-align:center; padding:12px 28px;">Me connecter →</a>
      </div>

    <?php elseif (!$is_valid): ?>
      <h1>Lien invalide ou expiré</h1>
      <p class="sub"><?= h($error ?: 'Ce lien n\'est plus valide.') ?></p>
      <a href="/connexion" class="btn-secondary">← Retour à la connexion</a>

    <?php else: ?>
      <?php $is_reset = ($token_data['token_type'] === 'reset_password'); ?>
      <h1><?= $is_reset ? 'Nouveau mot de passe' : 'Bienvenue sur Assokit' ?></h1>
      <p class="sub">
        <?= $is_reset
            ? 'Choisissez un nouveau mot de passe pour sécuriser votre compte.'
            : 'Créez votre mot de passe pour activer votre compte.' ?>
      </p>

      <div class="greet">
        👤 Connecté en tant que <strong><?= h($user_info['first_name'] . ' ' . $user_info['last_name']) ?></strong> (<?= h($user_info['email']) ?>)
      </div>

      <?php if ($error): ?>
        <div class="alert-error"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <div class="form-group">
          <label class="form-label" for="password">Nouveau mot de passe *</label>
          <input type="password" id="password" name="password" class="form-input" required minlength="8" autocomplete="new-password" autofocus>
          <div class="pw-hint">Minimum 8 caractères. Choisissez un mot de passe robuste.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password_confirm">Confirmer le mot de passe *</label>
          <input type="password" id="password_confirm" name="password_confirm" class="form-input" required minlength="8" autocomplete="new-password">
        </div>

        <button type="submit" class="btn-primary"><?= $is_reset ? '🔑 Réinitialiser' : '✓ Activer mon compte' ?></button>
      </form>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
