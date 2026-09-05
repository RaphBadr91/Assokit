<?php
/**
 * ASSOKIT — 2ᵉ facteur : saisie du code TOTP après mot de passe validé.
 * Activé uniquement si l'utilisateur a totp_enabled = 1.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/totp-helper.php';

$pid   = $_SESSION['pending_2fa_user_id'] ?? null;
$ptime = $_SESSION['pending_2fa_time'] ?? 0;

// Pas de pré-auth valide ou expiré (5 min) → retour login
if (!$pid || (time() - (int)$ptime) > 300) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_time']);
    header('Location: /connexion.php');
    exit;
}

$st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$st->execute([(int)$pid]);
$user = $st->fetch();
if (!$user || empty($user['totp_enabled'])) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_time']);
    header('Location: /connexion.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée, réessayez.';
    } else {
        require_once __DIR__ . '/rate-limit-helper.php';
        if (!ak_rate_limit('login_2fa', 10, 300, (string)$pid)) {
            $error = 'Trop de tentatives. Patientez quelques minutes.';
        } else {
            $code = preg_replace('/\s+/', '', $_POST['code'] ?? '');
            $ok = false;
            // 1) Code TOTP
            if (!empty($user['totp_secret']) && ak_totp_verify($user['totp_secret'], $code)) {
                $ok = true;
            // 2) Code de secours (usage unique)
            } elseif (!empty($user['totp_backup_codes'])) {
                $backup = json_decode($user['totp_backup_codes'], true) ?: [];
                foreach ($backup as $i => $hash) {
                    if (password_verify(strtoupper($code), $hash)) {
                        $ok = true;
                        unset($backup[$i]);
                        $pdo->prepare("UPDATE users SET totp_backup_codes = ? WHERE id = ?")
                            ->execute([json_encode(array_values($backup)), (int)$pid]);
                        break;
                    }
                }
            }

            if ($ok) {
                // ===== Ouverture de session (repris de connexion.php) =====
                session_regenerate_id(true);
                $_SESSION['user_id']         = (int)$user['id'];
                $_SESSION['org_id']          = (int)$user['org_id'];
                $_SESSION['user_email']      = $user['email'];
                $_SESSION['user_name']       = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_role']       = $user['role'];
                $_SESSION['parent_org_id']   = $user['parent_org_id'] ?? null;
                $_SESSION['parent_org_role'] = $user['parent_org_role'] ?? null;
                $_SESSION['is_super_admin']  = !empty($user['is_super_admin']) || $user['role'] === 'super_admin' ? 1 : 0;
                $_SESSION['logged_at']       = time();
                $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_time']);

                try { $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]); } catch (Throwable $e) {}
                if (function_exists('activity_log_login')) {
                    activity_log_login((int)$user['id'], $user['email'], $user['role'] ?? null, (int)($user['org_id'] ?? 0) ?: null);
                }
                session_write_close();

                if ($user['email'] === 'demo@assokit.fr') { header('Location: /demo-selector.php'); exit; }
                $is_sa = !empty($user['is_super_admin']) || $user['role'] === 'super_admin';
                if ($is_sa) {
                    header('Location: ' . (!empty($user['must_change_password']) ? '/changer-mot-de-passe?next=/super-admin' : '/super-admin'));
                    exit;
                }
                if (!empty($user['must_change_password'])) { header('Location: /changer-mot-de-passe'); exit; }
                if (!empty($user['parent_org_id'])) { header('Location: /mairie-dashboard'); } else { header('Location: /dashboard'); }
                exit;
            } else {
                $error = 'Code incorrect.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Vérification en deux étapes · Assokit</title>
<style>
  body{font-family:system-ui,-apple-system,sans-serif;background:#F0FDF4;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;color:#0F172A;}
  .box{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:36px 30px;max-width:380px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.06);}
  h1{font-size:21px;margin:0 0 6px;}
  p.sub{color:#64748B;font-size:14px;margin:0 0 22px;}
  input[type=text]{width:100%;padding:14px;border:1px solid #CBD5E1;border-radius:10px;font-size:22px;text-align:center;letter-spacing:8px;box-sizing:border-box;}
  button{width:100%;background:#059669;color:#fff;border:none;border-radius:10px;padding:13px;font-size:15px;font-weight:600;cursor:pointer;margin-top:16px;}
  .err{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:14px;margin-bottom:18px;}
  .hint{color:#64748B;font-size:13px;margin-top:18px;text-align:center;}
  a{color:#047857;}
</style>
</head>
<body>
  <div class="box">
    <h1>Vérification en deux étapes</h1>
    <p class="sub">Entrez le code à 6 chiffres de votre application d'authentification.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="000000" autofocus required>
      <button type="submit">Vérifier</button>
    </form>
    <p class="hint">Téléphone perdu ? Saisissez l'un de vos <strong>codes de secours</strong> dans le champ ci-dessus.</p>
    <p class="hint"><a href="/deconnexion.php">Annuler</a></p>
  </div>
</body>
</html>
