<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-login.php
 * Page de login dédiée au cockpit SA / Fondateur (Niveau 2)
 * ============================================================
 * URL : /super-admin-login
 *
 * Vérifications :
 *   - Rate limiting : 5 échecs IP / 10 min → blocage 15 min
 *   - Email existe + actif + non supprimé
 *   - password_verify() sur password_hash
 *   - is_super_admin=1 OU is_founder=1
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sa-auth-helpers.php';

// Si déjà connecté SA → redirige vers /fondateur-cockpit
if (sa_auth_is_active()) {
    header('Location: /fondateur-cockpit');
    exit;
}

// Doit être connecté au site Assokit d'abord
if (!function_exists('current_user') || !current_user()) {
    header('Location: /connexion?return=/super-admin-login');
    exit;
}

// CSRF
if (empty($_SESSION['sa_login_csrf'])) {
    $_SESSION['sa_login_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['sa_login_csrf'];

$error = null;
$email_prefill = '';
$just_logged_out = !empty($_GET['logged_out']);
$just_expired   = !empty($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? '';
    $email_prefill = $email;

    if (empty($_SESSION['sa_login_csrf']) || !hash_equals($_SESSION['sa_login_csrf'], $token)) {
        $error = 'Session expirée. Rechargez la page.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (sa_auth_is_rate_limited($pdo, $ip)) {
            sa_auth_log_attempt($pdo, $email, null, 'rate_limited', 'IP bloquée après 5 échecs');
            $error = 'Trop de tentatives. Réessayez dans 10 minutes.';
        } elseif (empty($email) || empty($password)) {
            $error = 'Email et mot de passe requis.';
        } else {
            $stmt = $pdo->prepare("
                SELECT id, email, first_name, last_name, password_hash,
                       is_super_admin, is_founder, is_active, deleted_at
                FROM users
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                sa_auth_log_attempt($pdo, $email, null, 'fail_unknown', 'Email inconnu');
                $error = 'Email ou mot de passe incorrect.';
            } elseif ((int)$user['is_active'] !== 1 || !empty($user['deleted_at'])) {
                sa_auth_log_attempt($pdo, $email, (int)$user['id'], 'fail_inactive', 'Compte désactivé');
                $error = 'Ce compte n\'est plus actif.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                sa_auth_log_attempt($pdo, $email, (int)$user['id'], 'fail_password', 'Mot de passe incorrect');
                $error = 'Email ou mot de passe incorrect.';
            } elseif ((int)$user['is_super_admin'] !== 1 && (int)$user['is_founder'] !== 1) {
                sa_auth_log_attempt($pdo, $email, (int)$user['id'], 'fail_privilege', 'Pas de flag SA/Fondateur');
                $error = 'Accès réservé aux Super Admins et Fondateurs.';
            } else {
                // ✅ SUCCÈS
                sa_auth_log_attempt($pdo, $email, (int)$user['id'], 'success');
                sa_auth_issue_cookie((int)$user['id']);
                unset($_SESSION['sa_login_csrf']);
                header('Location: /fondateur-cockpit');
                exit;
            }
        }
    }
}

function h_sa($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Cockpit Fondateur · Assokit</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
  margin: 0; padding: 0; min-height: 100vh;
  font-family: 'Geist', -apple-system, system-ui, sans-serif;
  background: #0F0E1A;
  background-image: radial-gradient(circle at 20% 20%, rgba(127, 119, 221, 0.08), transparent 40%),
                    radial-gradient(circle at 80% 80%, rgba(251, 191, 36, 0.05), transparent 40%);
  color: #E5E7EB;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.bar {
  position: fixed; top: 0; left: 0; right: 0;
  background: linear-gradient(90deg, #7F77DD 0%, #FCD34D 100%);
  padding: 10px 24px;
  color: #0F0E1A; font-size: 12.5px; letter-spacing: 0.04em;
  text-transform: uppercase; font-weight: 700;
  text-align: center;
}
.login-card {
  background: #1A1828;
  border: 1px solid rgba(127, 119, 221, 0.25);
  border-radius: 16px;
  padding: 40px 36px;
  width: 100%; max-width: 440px;
  box-shadow: 0 30px 80px rgba(0,0,0,0.5);
  position: relative; overflow: hidden;
}
.login-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, #7F77DD 0%, #FCD34D 100%);
}
.logo-row {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 28px;
}
.logo-dot {
  width: 12px; height: 12px; border-radius: 50%;
  background: linear-gradient(135deg, #7F77DD 0%, #FCD34D 100%);
}
.logo-text {
  font-size: 18px; font-weight: 600; color: #F3F4F6;
  letter-spacing: -0.02em;
}
h1 {
  font-size: 22px; font-weight: 600;
  color: #F3F4F6; letter-spacing: -0.02em;
  margin: 0 0 10px;
}
.lede {
  font-size: 13.5px; color: #6B7280;
  margin: 0 0 28px; line-height: 1.55;
}
.field { margin-bottom: 18px; }
.label {
  display: block; font-size: 12px;
  color: #6B7280; margin-bottom: 7px;
  font-weight: 500; letter-spacing: 0.02em;
}
.input {
  width: 100%; padding: 12px 14px;
  background: #22202F;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 10px;
  color: #F3F4F6; font-size: 14px;
  font-family: inherit;
  transition: border-color 0.15s;
}
.input:focus {
  outline: none;
  border-color: #7F77DD;
  box-shadow: 0 0 0 3px rgba(127, 119, 221, 0.15);
}
.btn {
  width: 100%; padding: 13px;
  background: linear-gradient(90deg, #7F77DD 0%, #A78BFA 100%);
  color: #fff; border: none; border-radius: 10px;
  font-size: 14px; font-weight: 600;
  cursor: pointer; font-family: inherit;
  transition: all 0.15s;
  margin-top: 8px;
}
.btn:hover { opacity: 0.9; transform: translateY(-1px); }
.error {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #FCA5A5;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px; margin-bottom: 18px;
}
.info {
  background: rgba(127, 119, 221, 0.1);
  border: 1px solid rgba(127, 119, 221, 0.25);
  color: #C4B5FD;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px; margin-bottom: 18px;
}
.footer-note {
  margin-top: 22px; text-align: center;
  font-size: 12px; color: #6B7280;
  line-height: 1.6;
}
.footer-note a { color: #A78BFA; text-decoration: none; }
.badge {
  display: inline-block;
  padding: 3px 9px;
  background: linear-gradient(135deg, rgba(127, 119, 221, 0.15), rgba(251, 191, 36, 0.15));
  color: #FCD34D;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 5px;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin-bottom: 18px;
}
</style>
</head>
<body>

<div class="bar">🏗️ Cockpit Fondateur · Double authentification requise</div>

<div class="login-card">
  <div class="logo-row">
    <span class="logo-dot" aria-hidden="true"></span>
    <span class="logo-text">Assokit</span>
  </div>

  <span class="badge">🛡 Accès Fondateur</span>

  <h1>Confirmation d'identité</h1>
  <p class="lede">Pour accéder au cockpit privilégié, identifiez-vous à nouveau. Session active pendant <strong>30 minutes</strong>.</p>

  <?php if ($just_logged_out): ?>
    <div class="info">✓ Vous avez été déconnecté du cockpit.</div>
  <?php endif; ?>

  <?php if ($just_expired): ?>
    <div class="info">⏱ Votre session a expiré après 30 minutes d'inactivité.</div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error"><?= h_sa($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/super-admin-login" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h_sa($csrf) ?>">

    <div class="field">
      <label for="email" class="label">Email</label>
      <input type="email" id="email" name="email" class="input"
             placeholder="vous@assokit.fr"
             value="<?= h_sa($email_prefill) ?>"
             required autofocus>
    </div>

    <div class="field">
      <label for="password" class="label">Mot de passe</label>
      <input type="password" id="password" name="password" class="input"
             placeholder="••••••••" required>
    </div>

    <button type="submit" class="btn">Accéder au cockpit Fondateur →</button>
  </form>

  <p class="footer-note">
    Toutes les tentatives sont loggées.<br>
    <a href="/">← Retour à l'accueil</a>
  </p>
</div>

</body>
</html>
