<?php
/**
 * ============================================================
 * ASSOKIT — Signup public (depuis la landing)
 * ============================================================
 * URL : /signup ou /signup?plan=association
 *
 * Creation asso + admin en 1 etape, sans carte bancaire :
 *   1. Nom asso + email admin + mot de passe + nom/prenom
 *   2. Creation org + user admin
 *   3. Login auto + redirection dashboard avec guide onboarding
 * ============================================================
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/password-token-helper.php';

// Si deja connecte, rediriger
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

$error = null;
$form = [
    'org_name' => '',
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'plan' => $_GET['plan'] ?? 'essentiel',
];

if (!in_array($form['plan'], ['essentiel', 'association', 'organisation'], true)) {
    $form['plan'] = 'essentiel';
}

// Generate CSRF token si absent
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/rate-limit-helper.php';
    // CSRF check
    $csrf_ok = true;
    if (function_exists('check_csrf')) {
        $csrf_ok = check_csrf($_POST['csrf_token'] ?? '');
    }

    if (!$csrf_ok) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error = 'Session expirée. Veuillez réessayer.';
    } elseif (!ak_rate_limit('signup', 5, 900)) {
        $error = 'Trop de tentatives de création de compte. Merci de patienter quelques minutes.';
    } else {
        $form['org_name']   = trim($_POST['org_name'] ?? '');
        $form['first_name'] = trim($_POST['first_name'] ?? '');
        $form['last_name']  = trim($_POST['last_name'] ?? '');
        $form['email']      = trim($_POST['email'] ?? '');
        $form['plan']       = $_POST['plan'] ?? 'essentiel';
        $password           = $_POST['password'] ?? '';
        $password_confirm   = $_POST['password_confirm'] ?? '';
        $accept_cgu         = !empty($_POST['accept_cgu']);

        // Validation
        if ($form['org_name'] === '' || mb_strlen($form['org_name']) < 2) {
            $error = 'Le nom de l\'association est requis (2 caractères minimum).';
        } elseif (mb_strlen($form['org_name']) > 200) {
            $error = 'Le nom de l\'association est trop long.';
        } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
            $error = 'Veuillez renseigner votre prénom et votre nom.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif ($password !== $password_confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (!$accept_cgu) {
            $error = 'Veuillez accepter les CGU et la politique de confidentialité.';
        } else {
            // Anti-spam simple : max 5 inscriptions / 1h / IP
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM public_signups
                    WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ");
                $stmt->execute([$ip]);
                $recent = (int) $stmt->fetchColumn();
                if ($recent >= 5) {
                    $error = 'Trop de tentatives depuis cette adresse. Merci de réessayer dans une heure.';
                }
            } catch (Throwable $e) {}

            if (!$error) {
                // Verifier doublon email
                try {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$form['email']]);
                    if ($stmt->fetchColumn()) {
                        $error = 'Un compte existe déjà avec cette adresse email. Essayez de vous connecter.';
                    }
                } catch (Throwable $e) {
                    $error = 'Erreur technique. Réessayez dans un instant.';
                }
            }

            if (!$error) {
                // Log le signup en pending
                $signup_id = null;
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO public_signups
                            (org_name, admin_email, admin_first_name, admin_last_name, plan_choice,
                             ip_address, user_agent, referer, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([
                        $form['org_name'], $form['email'], $form['first_name'], $form['last_name'],
                        $form['plan'], $ip,
                        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
                    ]);
                    $signup_id = (int) $pdo->lastInsertId();
                } catch (Throwable $e) {}

                // Creer l'asso et l'admin en transaction
                try {
                    $pdo->beginTransaction();

                    // 1. Creer organization
                    $stmt = $pdo->prepare("
                        INSERT INTO organizations (name, plan_type, trial_ends_at, created_at)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY), NOW())
                    ");
                    $stmt->execute([$form['org_name'], $form['plan']]);
                    $org_id = (int) $pdo->lastInsertId();

                    // 2. Creer user admin
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $colors = ['blue','purple','amber','pink','teal'];
                    $avatar_color = $colors[array_rand($colors)];

                    $stmt = $pdo->prepare("
                        INSERT INTO users
                            (org_id, role, email, password_hash, first_name, last_name,
                             avatar_color, adhesion_date, must_change_password, is_active,
                             can_create_projects, can_manage_members, can_manage_finances,
                             can_access_marketing, can_manage_events, can_moderate_messages,
                             created_at)
                        VALUES (?, 'admin', ?, ?, ?, ?, ?, CURDATE(), 0, 1, 1, 1, 1, 1, 1, 1, NOW())
                    ");
                    $stmt->execute([
                        $org_id, $form['email'], $password_hash,
                        $form['first_name'], $form['last_name'], $avatar_color,
                    ]);
                    $user_id = (int) $pdo->lastInsertId();

                    // 3. Creer dossier par defaut
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO folders (org_id, name, color_theme, created_at)
                            VALUES (?, ?, 'blue', NOW())
                        ");
                        $stmt->execute([$org_id, 'Projets généraux']);
                    } catch (Throwable $e) {}
                    // 3b. [trial-14j] Subscription en essai gratuit 14j (plan Pro par defaut)
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO subscriptions (org_id, plan, status, billing_cycle, price_ht, current_period_start, current_period_end, created_at)
                            VALUES (?, 'pro', 'trial', 'monthly', 49.99, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), NOW())
                        ");
                        $stmt->execute([$org_id]);
                    } catch (Throwable $e) {
                        error_log('[signup] Subscription trial: ' . $e->getMessage());
                    }

                    // 4. Maj le signup log
                    if ($signup_id) {
                        $stmt = $pdo->prepare("
                            UPDATE public_signups
                            SET org_id = ?, user_id = ?, status = 'completed', completed_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$org_id, $user_id, $signup_id]);
                    }

                    $pdo->commit();

                    // 5. Login auto
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['org_id'] = $org_id;
                    $_SESSION['user_email'] = $form['email'];
                    $_SESSION['user_name'] = $form['first_name'] . ' ' . $form['last_name'];
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['is_super_admin'] = 0;
                    $_SESSION['logged_at'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['signup_welcome'] = 1; // Trigger un message de bienvenue sur dashboard

                    session_write_close();

                    header('Location: /dashboard?welcome=1');
                    exit;

                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('signup error: ' . $e->getMessage());
                    if ($signup_id) {
                        try {
                            $stmt = $pdo->prepare("UPDATE public_signups SET status = 'blocked', error_message = ? WHERE id = ?");
                            $stmt->execute([mb_substr($e->getMessage(), 0, 500), $signup_id]);
                        } catch (Throwable $e2) {}
                    }
                    $error = 'Erreur technique lors de la création. Merci de réessayer ou nous contacter.';
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Créer mon compte — Assokit</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --acc: #059669; --acc-dark: #047857; --acc-light: #D1FAE5;
  --ink: #0A0A0B; --ink-2: #3F3F46; --ink-3: #71717A;
  --bg: #FFFFFF; --bg-2: #FAFAF9;
  --border: rgba(0,0,0,0.08); --border-strong: rgba(0,0,0,0.14);
}
body {
  font-family: 'Geist', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg-2); color: var(--ink); min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 24px; font-size: 15px; line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 460px; width: 100%; }
.logo-wrap { text-align: center; margin-bottom: 24px; }
.logo-mark {
  display: inline-block; width: 48px; height: 48px;
  background: var(--acc); border-radius: 12px; position: relative;
  box-shadow: 0 4px 14px rgba(5, 150, 105, 0.2);
}
.logo-mark::after {
  content: ''; position: absolute;
  right: 10px; bottom: 10px;
  width: 12px; height: 12px;
  background: white; border-radius: 50%;
}
.logo-name { display: block; margin-top: 10px; font-size: 14px; color: var(--ink-3); letter-spacing: 0.05em; }

.card {
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 16px; padding: 32px 28px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.04);
}
h1 { font-size: 22px; font-weight: 500; margin-bottom: 6px; letter-spacing: -0.02em; }
.sub { font-size: 13.5px; color: var(--ink-2); margin-bottom: 22px; line-height: 1.55; }

.plan-pill {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 4px 12px; background: var(--acc-light); color: var(--acc-dark);
  border-radius: 999px; font-size: 12px; font-weight: 500; margin-bottom: 18px;
}
.plan-pill-dot { width: 6px; height: 6px; background: var(--acc); border-radius: 50%; }

.alert-error {
  background: #FEF2F2; border: 1px solid #FCA5A5;
  color: #991B1B; padding: 12px 14px;
  border-radius: 10px; font-size: 13px;
  margin-bottom: 16px; line-height: 1.5;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.form-group { margin-bottom: 12px; }
label { display: block; font-size: 12.5px; font-weight: 500; color: var(--ink-2); margin-bottom: 6px; }
input[type="text"], input[type="email"], input[type="password"] {
  width: 100%; padding: 11px 13px;
  background: var(--bg-2); border: 1px solid var(--border-strong);
  border-radius: 9px; font-family: inherit; font-size: 14px; color: var(--ink);
  transition: border-color 0.15s, background 0.15s;
}
input:focus { outline: none; border-color: var(--acc); background: var(--bg); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }

.pw-hint { font-size: 11.5px; color: var(--ink-3); margin-top: 5px; }

.cgu-row {
  display: flex; align-items: flex-start; gap: 8px;
  font-size: 12.5px; color: var(--ink-2); margin: 14px 0 16px;
  line-height: 1.5;
}
.cgu-row input[type="checkbox"] { margin-top: 2px; accent-color: var(--acc); flex-shrink: 0; }
.cgu-row a { color: var(--acc); text-decoration: none; }
.cgu-row a:hover { text-decoration: underline; }

.btn-submit {
  width: 100%; background: var(--ink); color: var(--bg);
  border: none; padding: 13px 20px; border-radius: 10px;
  font-family: inherit; font-size: 14.5px; font-weight: 500;
  cursor: pointer; transition: opacity 0.15s, transform 0.15s;
}
.btn-submit:hover { opacity: 0.88; transform: translateY(-1px); }

.login-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: var(--ink-3); }
.login-link a { color: var(--acc); text-decoration: none; font-weight: 500; }
.login-link a:hover { text-decoration: underline; }

.trust-line {
  text-align: center; font-size: 11.5px; color: var(--ink-3);
  margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);
  display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
}
</style>
</head>
<body>

<div class="wrap">

  <div class="logo-wrap">
    <a href="/" style="display:inline-block;">
      <span class="logo-mark"></span>
    </a>
    <span class="logo-name">ASSOKIT</span>
  </div>

  <div class="card">

    <?php if ($form['plan'] === 'association'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Plan Association sélectionné · 49 €/mois</span>
    <?php elseif ($form['plan'] === 'organisation'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Plan Organisation · Sur mesure</span>
    <?php else: ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Plan Essentiel gratuit</span>
    <?php endif; ?>

    <h1>Créez votre espace Assokit</h1>
    <p class="sub">Une association, un admin, et c'est parti. Sans carte bancaire, sans engagement.</p>

    <?php if ($error): ?>
      <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="plan" value="<?= htmlspecialchars($form['plan']) ?>">

      <div class="form-group">
        <label for="org_name">Nom de votre association *</label>
        <input type="text" id="org_name" name="org_name" required maxlength="200" autofocus
               placeholder="Ex : Latitude 91" value="<?= htmlspecialchars($form['org_name']) ?>">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="first_name">Votre prénom *</label>
          <input type="text" id="first_name" name="first_name" required maxlength="100"
                 value="<?= htmlspecialchars($form['first_name']) ?>">
        </div>
        <div class="form-group">
          <label for="last_name">Votre nom *</label>
          <input type="text" id="last_name" name="last_name" required maxlength="100"
                 value="<?= htmlspecialchars($form['last_name']) ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="email">Votre email *</label>
        <input type="email" id="email" name="email" required maxlength="200" autocomplete="email"
               placeholder="vous@association.fr" value="<?= htmlspecialchars($form['email']) ?>">
      </div>

      <div class="form-group">
        <label for="password">Mot de passe *</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="pw-hint">8 caractères minimum — choisissez un mot de passe robuste.</div>
      </div>

      <div class="form-group">
        <label for="password_confirm">Confirmez le mot de passe *</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
      </div>

      <label class="cgu-row">
        <input type="checkbox" name="accept_cgu" value="1" required>
        <span>J'accepte les <a href="/cgu" target="_blank">conditions générales d'utilisation</a> et la <a href="/politique-confidentialite" target="_blank">politique de confidentialité</a>.</span>
      </label>

      <button type="submit" class="btn-submit">Créer mon espace →</button>
    </form>

    <div class="trust-line">
      <span>🇫🇷 Hébergé en France</span>
      <span>🔒 Conforme RGPD</span>
      <span>✨ IA incluse</span>
    </div>

  </div>

  <div class="login-link">
    Vous avez déjà un compte ? <a href="/connexion">Se connecter</a>
  </div>

</div>

</body>
</html>
