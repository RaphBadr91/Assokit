<?php
/**
 * ============================================================
 * ASSOKIT — admin-cron-login.php
 * Page de login dédiée au cockpit CRON
 * ============================================================
 * URL : /admin-cron-login
 *
 * Vérifie :
 *   - Rate limiting : max 3 échecs sur 5 min par IP
 *   - Email existe + actif + non supprimé
 *   - password_verify() sur password_hash
 *   - is_super_admin=1 OU is_founder=1
 *
 * Tous les résultats sont loggés dans cron_admin_logins.
 * ============================================================
 */

define('CRON_ADMIN_UI', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/cron-includes.php';

// Si déjà connecté au cockpit → redirige
if (cron_is_super_admin()) {
    header('Location: /admin-cron');
    exit;
}

// CSRF
if (empty($_SESSION['cockpit_csrf'])) {
    $_SESSION['cockpit_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['cockpit_csrf'];

$error = null;
$email_prefill = '';

function log_attempt(PDO $pdo, string $email, ?int $userId, string $status, ?string $errorDetail = null): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cron_admin_logins
                (email_attempted, user_id, status, ip_address, user_agent, error_detail, attempted_at)
            VALUES
                (:email, :uid, :status, :ip, :ua, :err, NOW())
        ");
        $stmt->execute([
            ':email'  => mb_substr($email, 0, 191),
            ':uid'    => $userId,
            ':status' => $status,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':err'    => $errorDetail ? mb_substr($errorDetail, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[CRON LOGIN] log_attempt error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? '';
    $email_prefill = $email;

    // ---- CSRF
    if (empty($_SESSION['cockpit_csrf']) || !hash_equals($_SESSION['cockpit_csrf'], $token)) {
        $error = 'Session expirée. Rechargez la page.';
    } else {
        // ---- Rate limiting : max 3 échecs / IP / 5 min
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM cron_admin_logins
            WHERE ip_address = :ip
              AND status IN ('fail_password','fail_privilege','fail_inactive','fail_unknown')
              AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([':ip' => $ip]);
        $recentFails = (int) $stmt->fetchColumn();

        if ($recentFails >= 3) {
            log_attempt($pdo, $email, null, 'rate_limited', "IP bloquée après $recentFails échecs");
            $error = 'Trop de tentatives. Réessayez dans 5 minutes.';
        } elseif (empty($email) || empty($password)) {
            $error = 'Email et mot de passe requis.';
        } else {
            // ---- Recherche user en BDD
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
                log_attempt($pdo, $email, null, 'fail_unknown', 'Email inconnu');
                $error = 'Email ou mot de passe incorrect.';
            } elseif ((int)$user['is_active'] !== 1 || !empty($user['deleted_at'])) {
                log_attempt($pdo, $email, (int)$user['id'], 'fail_inactive', 'Compte désactivé ou supprimé');
                $error = 'Ce compte n\'est plus actif.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                log_attempt($pdo, $email, (int)$user['id'], 'fail_password', 'Mot de passe incorrect');
                $error = 'Email ou mot de passe incorrect.';
            } elseif ((int)$user['is_super_admin'] !== 1 && (int)$user['is_founder'] !== 1) {
                log_attempt($pdo, $email, (int)$user['id'], 'fail_privilege', 'Pas de flag SA/Fondateur');
                $error = 'Accès réservé aux Super Admins et Fondateurs.';
            } else {
                // ✅ SUCCÈS
                log_attempt($pdo, $email, (int)$user['id'], 'success');
                cron_issue_cockpit_cookie((int)$user['id']);
                unset($_SESSION['cockpit_csrf']);
                header('Location: /admin-cron');
                exit;
            }
        }
    }
}

function ak_cron_login_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accès cockpit · Assokit</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
  margin: 0; padding: 0; min-height: 100vh;
  font-family: 'Geist', -apple-system, system-ui, sans-serif;
  background: #0F0E1A;
  color: #E5E7EB;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.bar {
  position: fixed; top: 0; left: 0; right: 0;
  background: linear-gradient(90deg, #7F77DD 0%, #A78BFA 100%);
  padding: 10px 24px;
  color: #fff; font-size: 12.5px; letter-spacing: 0.04em;
  text-transform: uppercase; font-weight: 600;
  text-align: center;
}
.login-card {
  background: #1A1828;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 16px;
  padding: 40px 36px;
  width: 100%; max-width: 420px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}
.logo-row {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 28px;
}
.logo-dot {
  width: 12px; height: 12px; border-radius: 50%;
  background: #7F77DD;
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
  font-size: 13.5px; color: #9CA3AF;
  margin: 0 0 28px; line-height: 1.55;
}
.field { margin-bottom: 18px; }
.label {
  display: block; font-size: 12px;
  color: #9CA3AF; margin-bottom: 7px;
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
  background: #7F77DD; color: #fff;
  border: none; border-radius: 10px;
  font-size: 14px; font-weight: 600;
  cursor: pointer; font-family: inherit;
  transition: background 0.15s;
  margin-top: 8px;
}
.btn:hover { background: #6B63C9; }

.error {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #FCA5A5;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 18px;
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
  background: rgba(127, 119, 221, 0.15);
  color: #A78BFA;
  border-radius: 5px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 18px;
}
</style>
</head>
<body>

<div class="bar">🛡 Cockpit Fondateur &amp; Super Admin</div>

<div class="login-card">

  <div class="logo-row">
    <span class="logo-dot" aria-hidden="true"></span>
    <span class="logo-text">Assokit</span>
  </div>

  <span class="badge">🔒 Accès privilégié</span>

  <h1>Accès au cockpit CRON</h1>
  <p class="lede">Identifiez-vous avec votre compte Fondateur ou Super Admin pour accéder à la supervision des automatisations.</p>

  <?php if ($error): ?>
    <div class="error"><?= ak_cron_login_h($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/admin-cron-login" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= ak_cron_login_h($csrf) ?>">

    <div class="field">
      <label for="email" class="label">Email</label>
      <input
        type="email" id="email" name="email"
        class="input"
        placeholder="vous@assokit.fr"
        value="<?= ak_cron_login_h($email_prefill) ?>"
        required autofocus>
    </div>

    <div class="field">
      <label for="password" class="label">Mot de passe</label>
      <input
        type="password" id="password" name="password"
        class="input"
        placeholder="••••••••"
        required>
    </div>

    <button type="submit" class="btn">Accéder au cockpit →</button>
  </form>

  <p class="footer-note">
    L'accès expire automatiquement après <strong>15 minutes</strong> d'inactivité.<br>
    <a href="/super-admin">← Retour au tableau de bord principal</a>
  </p>

</div>

</body>
</html>
