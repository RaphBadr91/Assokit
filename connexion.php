<?php
require_once __DIR__ . '/config.php';

// === Tracking activité (Fondateur audit) ===
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
}

// Si déjà connecté → dashboard (ou super-admin si super_admin)
if (!empty($_SESSION['user_id'])) {
    // Double casquette : role='super_admin' OU is_super_admin=1
    $is_sa = (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin')
          || !empty($_SESSION['is_super_admin']);
    if ($is_sa) {
        header('Location: /super-admin');
    } elseif (!empty($_SESSION['parent_org_id'])) {
        header('Location: /mairie-dashboard');
    } else {
        header('Location: /dashboard');
    }
    exit;
}

$error = null;
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $email_value = $email;
    // === Anti-flood login (audit sécurité) : 30 POST / min / IP ===
    require_once __DIR__ . '/rate-limit-helper.php';
    $__login_flood = !ak_rate_limit('login_post', 30, 60);
    if ($__login_flood) {
        $error = 'Trop de tentatives de connexion. Merci de patienter une minute avant de réessayer.';
    }

    // CSRF — tolérant mais régénère si cassé
    $csrf_ok = true;
    if (function_exists('check_csrf')) {
        $csrf_ok = check_csrf($_POST['csrf_token'] ?? '');
        if (!$csrf_ok) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $error = 'Session expirée. Réessayez maintenant.';
        }
    }

    if ($csrf_ok && empty($__login_flood)) {
        if ($email === '' || $password === '') {
            $error = 'Merci de remplir email et mot de passe.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT u.*, o.name AS org_name
                    FROM users u
                    LEFT JOIN organizations o ON u.org_id = o.id
                    WHERE u.email = ? AND u.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ((int)(function_exists('__ak_bf_count') ? __ak_bf_count($pdo, $email) : (function() use ($pdo, $email) {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $s = $pdo->prepare("SELECT COUNT(*) FROM assokit_activity_log WHERE event_type='login_failed' AND (user_email = ? OR ip = ?) AND created_at > (NOW() - INTERVAL 15 MINUTE)");
                    $s->execute([$email, $ip]);
                    return (int)$s->fetchColumn();
                })()) >= 10) {
                    $error = 'Trop de tentatives de connexion échouées. Pour votre sécurité, patientez 15 minutes avant de réessayer.';
                } elseif (!$user || !password_verify($password, $user['password_hash'])) {
                    $error = 'Email ou mot de passe incorrect.';
                    // Track login failed
                    if (function_exists('activity_log_login_failed')) {
                        activity_log_login_failed($email, $user ? 'wrong_password' : 'unknown_email');
                    }
                } else {
                    // === 2FA : si activee, exiger le code TOTP avant d'ouvrir la session ===
                    if (!empty($user['totp_enabled'])) {
                        session_regenerate_id(true);
                        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                        $_SESSION['pending_2fa_time'] = time();
                        header('Location: /connexion-2fa');
                        exit;
                    }
                    // CORRECTION CRITIQUE : session_regenerate_id + session_write_close
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['org_id'] = (int)$user['org_id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['parent_org_id'] = $user['parent_org_id'] ?? null;
                    $_SESSION['parent_org_role'] = $user['parent_org_role'] ?? null;
                    $_SESSION['is_super_admin'] = !empty($user['is_super_admin']) || $user['role'] === 'super_admin' ? 1 : 0;
                    $_SESSION['logged_at'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    // Mise à jour last_login_at (best effort, silencieux si la colonne n'existe pas)
                    try {
                        $stmt_upd = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                        $stmt_upd->execute([(int)$user['id']]);
                    } catch (Throwable $e) {
                        // silencieux
                    }

                    // Track login réussi (Fondateur audit)
                    if (function_exists('activity_log_login')) {
                        activity_log_login(
                            (int)$user['id'],
                            $user['email'],
                            $user['role'] ?? null,
                            (int)($user['org_id'] ?? 0) ?: null
                        );
                    }

                    // IMPORTANT : écriture forcée avant redirection
                    session_write_close();

                    // =======================================================
                    // COMPTE DEMO COMMERCIAL → sélecteur d'organisation
                    // =======================================================
                    if ($user['email'] === 'demo@assokit.fr') {
                        header('Location: /demo-selector.php');
                        exit;
                    }

                    // =======================================================
                    // REDIRECTION INTELLIGENTE selon le rôle
                    // =======================================================
                    // Double casquette : role='super_admin' OU is_super_admin=1
                    $is_sa = !empty($user['is_super_admin']) || $user['role'] === 'super_admin';

                    // 1. Super admin → cockpit dédié
                    if ($is_sa) {
                        if (!empty($user['must_change_password'])) {
                            header('Location: /changer-mot-de-passe?next=/super-admin');
                        } else {
                            header('Location: /super-admin');
                        }
                        exit;
                    }

                    // 2. Utilisateur normal avec mdp temporaire
                    if (!empty($user['must_change_password'])) {
                        header('Location: /changer-mot-de-passe');
                        exit;
                    }

                    // 3. Utilisateur normal → dashboard asso
                    if (!empty($user['parent_org_id'])) {
                        header('Location: /mairie-dashboard');
                    } else {
                        header('Location: /dashboard');
                    }
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Erreur de base de données.';
                if (defined('DEBUG') && DEBUG) {
                    $error .= ' (' . $e->getMessage() . ')';
                }
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — Assokit</title>
  <meta name="robots" content="noindex, follow">
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
  <link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'%3E%3Crect width='180' height='180' rx='40' fill='%23059669'/%3E%3Ccircle cx='125' cy='125' r='25' fill='%23FFFFFF'/%3E%3C/svg%3E">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{--acc:#059669;--acc-dark:#047857;--bg:#FAFAF9;--ink:#1C1917;--ink-2:#44403C;--ink-3:#78716C;--border:#E7E5E4}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Geist',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .login-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:40px;max-width:420px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
    .logo{text-align:center;margin-bottom:28px}
    .logo-mark{font-size:22px;font-weight:600;letter-spacing:-0.03em}
    .logo-mark span{color:var(--acc)}
    .logo-sub{font-size:12.5px;color:var(--ink-3);margin-top:4px}
    h1{font-size:19px;font-weight:500;letter-spacing:-0.02em;margin-bottom:4px}
    .subtitle{font-size:13px;color:var(--ink-3);margin-bottom:24px}
    .form-group{margin-bottom:16px}
    label{display:block;font-size:12.5px;font-weight:500;color:var(--ink-2);margin-bottom:6px}
    input{width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:10px;font-size:14px;background:#fff;font-family:inherit}
    input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px rgba(5,150,105,0.08)}
    .btn-submit{width:100%;padding:12px;background:var(--acc);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;margin-top:8px;font-family:inherit}
    .btn-submit:hover{background:var(--acc-dark)}
    .error-box{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
    .forgot-link{display:block;text-align:center;font-size:12.5px;color:var(--ink-3);margin-top:14px;text-decoration:none;transition:color .15s}
    .forgot-link:hover{color:var(--acc)}
    .footer-note{text-align:center;font-size:11.5px;color:var(--ink-3);margin-top:20px;padding-top:18px;border-top:1px solid var(--border)}
    .footer-note a{color:var(--acc);text-decoration:none;font-weight:500}
  </style>
</head>
<body>
<div class="login-card">
  <div class="logo">
    <div class="logo-mark">asso<span>kit</span>.</div>
    <div class="logo-sub">L'art de mener vos projets</div>
  </div>
  <h1>Bienvenue</h1>
  <p class="subtitle">Connectez-vous à votre espace Assokit</p>
  <?php if ($error): ?><div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST" action="/connexion" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="form-group">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($email_value) ?>" autocomplete="email" placeholder="vous@association.fr">
    </div>
    <div class="form-group">
      <label for="password">Mot de passe</label>
      <div style="position:relative;">
        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••" style="padding-right:44px;">
        <button type="button" id="togglePwd" aria-label="Afficher le mot de passe" aria-pressed="false"
          style="position:absolute;top:50%;right:8px;transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;color:#64748B;font-size:13px;font-weight:600;">👁</button>
      </div>
    </div>
    <script>
      (function(){
        var b=document.getElementById('togglePwd'), p=document.getElementById('password');
        if(b&&p){ b.addEventListener('click', function(){
          var show=p.type==='password'; p.type=show?'text':'password';
          b.setAttribute('aria-pressed', show?'true':'false');
          b.setAttribute('aria-label', show?'Masquer le mot de passe':'Afficher le mot de passe');
        }); }
      })();
    </script>
    <button type="submit" class="btn-submit">Se connecter</button>
  </form>
  <a href="/mot-de-passe-oublie" class="forgot-link">Mot de passe oublié ?</a>
  <div class="footer-note">Pas encore de compte ? <a href="/signup">Créer mon compte gratuit</a> · <a href="/contact">Parler à un conseiller</a></div>
</div>
</body>
</html>
