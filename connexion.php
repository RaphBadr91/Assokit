<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php'; // ak_session_access(), ak_login_destination()

// === Tracking activité (Fondateur audit) ===
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
}

$error = null;
$email_value = '';
// Page demandée avant la connexion (?next=, posé lors de la redirection vers /connexion)
$next = ak_login_next($_POST['next'] ?? ($_GET['next'] ?? ''));
// Accès coupé pendant une session ouverte (ak_session_guard) : on dit pourquoi
$acces_msgs = ['ferme' => ak_session_access_message('org_closed'), 'desactive' => ak_session_access_message('account_closed'),
               'attente' => ak_session_access_message('org_pending')];
if (isset($_GET['acces'], $acces_msgs[$_GET['acces']])) $error = $acces_msgs[$_GET['acces']];
// Demande de création d'espace pas encore validée (app iPhone) : avis d'attente, et non une erreur
$pending_notice = ($_GET['acces'] ?? '') === 'attente';
$suspended_notice = null;
// Retour de « Supprimer mon compte » (rgpd-supprimer-compte.php) : confirmation, texte fixe
$success_msg = !empty($_GET['deleted']) ? 'Votre compte a bien été supprimé et vos données personnelles anonymisées.' : null;

// Incarnation en cours (Fondateur) : /connexion mène au choix « revenir à mon compte »
if (!empty($_SESSION['impersonation_target_user_id'])) {
    header('Location: /super-admin/incarner-stop');
    exit;
}

// Déjà connecté → page d'accueil du rôle (ou page demandée), sauf si l'accès a été coupé entre-temps
if (!empty($_SESSION['user_id'])) {
    $acc = ak_session_access($pdo);
    if ($acc['state'] === 'account_closed' || $acc['state'] === 'org_closed' || $acc['state'] === 'org_pending') {
        ak_session_end();
        session_id(session_create_id());
        session_start();
        $error = ak_session_access_message($acc['state']);
        $pending_notice = $acc['state'] === 'org_pending';
    } elseif ($acc['state'] === 'must_change_password') {
        header('Location: /changer-mot-de-passe' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
        exit;
    } elseif ($acc['state'] === 'org_suspended') {
        // Avis de suspension : seules la régularisation, l'export RGPD et la déconnexion restent ouverts
        $suspended_notice = $acc['user'];
    } else {
        // Double casquette : role='super_admin' OU is_super_admin=1
        $is_sa = (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin')
              || !empty($_SESSION['is_super_admin']);
        if ($next !== '') {
            header('Location: ' . $next);
        } elseif ($is_sa) {
            header('Location: /super-admin');
        } elseif (!empty($_SESSION['parent_org_id'])) {
            header('Location: /mairie-dashboard');
        } else {
            header('Location: /dashboard');
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $suspended_notice === null) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $email_value = $email;
    // Adresse réelle du visiteur (derrière Cloudflare / le proxy de l'hébergeur), la même que celle
    // enregistrée dans le journal des échecs
    $ip = function_exists('activity_get_ip') ? activity_get_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // === Anti-flood login (audit sécurité) : 120 POST / min / IP ===
    // Assez large pour une salle de formation qui se connecte en même temps.
    require_once __DIR__ . '/rate-limit-helper.php';
    $__login_flood = !ak_rate_limit('login_post', 120, 60, $ip);
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
                // Comptes désactivés compris : un mot de passe correct reçoit alors un message clair
                $stmt = $pdo->prepare("
                    SELECT u.*, o.name AS org_name
                    FROM users u
                    LEFT JOIN organizations o ON u.org_id = o.id
                    WHERE u.email = ? AND u.deleted_at IS NULL
                    ORDER BY u.is_active DESC
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Blocage après des échecs (10 par compte, 50 par adresse IP, en 15 minutes), même avec
                // le bon mot de passe ensuite : ak_login_lockout (app-context.php), partagé avec
                // /retirer-demande, qui demande aussi le mot de passe
                if (($lockout = ak_login_lockout($pdo, $email, $ip)) !== '') {
                    $error = $lockout;
                } elseif (!ak_login_password_ok($user ?: null, $password)) {
                    // Adresse inconnue : même durée de calcul qu'un mauvais mot de passe (ak_login_password_ok)
                    $error = 'Email ou mot de passe incorrect.';
                    // Track login failed
                    if (function_exists('activity_log_login_failed')) {
                        activity_log_login_failed($email, $user ? 'wrong_password' : 'unknown_email');
                    }
                } elseif ((int)($user['is_active'] ?? 1) !== 1) {
                    // Mot de passe correct : on peut dire pourquoi la connexion est refusée
                    $error = ak_session_access_message('account_closed');
                    if (function_exists('activity_log_login_failed')) {
                        activity_log_login_failed($email, 'account_inactive');
                    }
                } elseif (($org_access = ak_user_is_platform($user) ? 'ok' : ak_org_access($pdo, (int)($user['org_id'] ?? 0))) === 'closed') {
                    // Association résiliée ou supprimée : pas de session
                    $error = ak_session_access_message('org_closed');
                    if (function_exists('activity_log_login_failed')) {
                        activity_log_login_failed($email, 'org_closed');
                    }
                } elseif ($org_access === 'pending') {
                    // Demande de création d'espace (app iPhone) pas encore validée : pas de session,
                    // rien n'est accessible ; le message est le même sur le site et dans l'app.
                    // Bon mot de passe : tracé à part, pas comme un échec (le demandeur qui vérifie
                    // plusieurs fois si son espace est ouvert ne doit jamais être bloqué 15 minutes, ni
                    // compter dans le plafond par adresse IP)
                    $error = ak_session_access_message('org_pending');
                    $pending_notice = true;
                    if (function_exists('activity_log_action')) {
                        activity_log_action('login_pending', ['org_id' => (int)($user['org_id'] ?? 0)], $email);
                    }
                } else {
                    // === 2FA : si activee, exiger le code TOTP avant d'ouvrir la session ===
                    if (!empty($user['totp_enabled'])) {
                        session_regenerate_id(true);
                        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                        $_SESSION['pending_2fa_time'] = time();
                        $_SESSION['pending_2fa_next'] = $next;
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

                    // REDIRECTION INTELLIGENTE : compte démo commercial → sélecteur d'organisation,
                    // mot de passe provisoire → changement obligatoire, association suspendue → avis,
                    // sinon la page demandée avant la connexion ou l'accueil du rôle (cockpit, mairie, asso)
                    header('Location: ' . ak_login_destination($user, $next, $org_access));
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
    @media (max-width:480px){.login-card{padding:24px}}
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
    /* iPhone / iPad : sous 16px, Safari zoome sur le champ à chaque saisie */
    @media (max-width:480px),(pointer:coarse){input{font-size:16px}}
    .btn-submit{width:100%;padding:12px;background:var(--acc);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;margin-top:8px;font-family:inherit}
    .btn-submit:hover{background:var(--acc-dark)}
    .error-box{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
    .error-box.is-pending{background:#FFFBEB;border-color:#FCD34D;color:#92400E;line-height:1.55}
    .success-box{background:#F0FDF4;border:1px solid #86EFAC;color:#166534;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
    .forgot-link{display:block;text-align:center;font-size:12.5px;color:var(--ink-3);margin-top:14px;text-decoration:none;transition:color .15s}
    .forgot-link:hover{color:var(--acc)}
    .footer-note{text-align:center;font-size:11.5px;color:var(--ink-3);margin-top:20px;padding-top:18px;border-top:1px solid var(--border)}
    .footer-note a{color:var(--acc);text-decoration:none;font-weight:500}
    .notice-box{background:#FFFBEB;border:1px solid #FCD34D;color:#92400E;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.55;margin-bottom:18px}
    .btn-link{display:block;text-align:center;text-decoration:none;margin-top:10px}
    .btn-ghost{display:block;text-align:center;padding:11px;border:1px solid var(--border);border-radius:10px;color:var(--ink-2);font-size:13.5px;text-decoration:none;margin-top:10px}
    .btn-ghost:hover{border-color:var(--acc);color:var(--acc)}
  </style>
</head>
<body>
<div class="login-card">
  <div class="logo">
    <div class="logo-mark">asso<span>kit</span>.</div>
    <div class="logo-sub">L'art de mener vos projets</div>
  </div>
<?php if ($suspended_notice !== null):
    $is_org_admin = ($suspended_notice['role'] ?? '') === 'admin';
    // Le paiement lève tout seul la suspension d'une association en fin d'essai (trial_ends_at
    // renseignée, sans motif ou motif de fin d'essai) et celle pour impayé (motif « Impayé »,
    // « Paiement en retard »…) : stripe-webhook.php, ak_end_trial_after_payment. Les autres
    // (décision du support) se lèvent à la main.
    $susp_org = [];
    try {
        $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $st->execute([(int)($_SESSION['org_id'] ?? 0) ?: (int)($suspended_notice['org_id'] ?? 0)]);
        $susp_org = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $susp_org = [];
    }
    // Fin de l'essai gratuit (date passée, aucun abonnement payant) : dite telle quelle, avec le
    // choix de la formule, plutôt qu'une « suspension » à régulariser. Seulement sans motif ou pour
    // un motif de fin d'essai (« Fin de l'essai », « Essai terminé ») : une suspension décidée par
    // le support (abus, fraude, impayé…) garde « Accès suspendu » et son message, même essai échu.
    $trial_ended = false;
    $trial_ts = !empty($susp_org['trial_ends_at']) ? strtotime((string)$susp_org['trial_ends_at']) : false;
    $susp_reason = mb_strtolower(trim((string)($susp_org['suspended_reason'] ?? '')));
    $reason_is_trial = $susp_reason === ''
        || preg_match('/fin\s+(?:de\s+l|d)[\'’]\s*essai|essai[^.;:]{0,30}(?:termin|expir|fini|[ée]chu|achev|clos)/u', $susp_reason) === 1;
    // Levée automatique au paiement : fin d'essai (sans motif ou motif de fin d'essai) ou impayé.
    // Une suspension du support pendant l'essai (« Abus », « Fraude ») reste en place.
    $auto_unsuspend = (!empty($susp_org['trial_ends_at']) && $reason_is_trial)
        || preg_match('/impay|retard de paiement|paiement en retard|d[eé]faut de paiement|non[- ]paiement/u', $susp_reason) === 1;
    if ($trial_ts && $trial_ts > 0 && $trial_ts <= time() && $reason_is_trial) {
        require_once __DIR__ . '/trial-helpers.php';
        $trial_ended = function_exists('ak_org_is_trial') && ak_org_is_trial($pdo, (int)($susp_org['id'] ?? 0));
    }
    // Demande d'espace de l'app iPhone refusée par le Fondateur (signup-review-helpers.php) : ni « suspension »
    // à régulariser ni paiement ; le demandeur peut retirer sa demande et effacer ses informations
    require_once __DIR__ . '/signup-review-helpers.php';
    $refused_request = $susp_org && ak_signup_review_refused($pdo, $susp_org);
    if ($refused_request) $trial_ended = false;
    $show_billing = $is_org_admin && !ak_billing_hidden() && !$refused_request;
?>
  <div id="ak-acces-suspendu">
    <h1><?= $refused_request ? 'Demande non validée' : ($trial_ended ? (ak_billing_hidden() ? 'Votre période d’essai est terminée' : 'Votre essai gratuit est terminé') : 'Accès suspendu') ?></h1>
    <p class="subtitle"><?= htmlspecialchars(trim(($suspended_notice['first_name'] ?? '') . ' ' . ($suspended_notice['last_name'] ?? ''))) ?> · <?= htmlspecialchars($suspended_notice['email'] ?? '') ?></p>
    <?php if ($refused_request): ?>
    <div class="notice-box">Votre demande de création d’espace n’a pas été validée par notre équipe. Pour toute question, écrivez à <?= AK_SUPPORT_EMAIL ?>.</div>
    <a href="/retirer-demande" class="btn-ghost" id="ak-retirer-demande-lien">Retirer ma demande et effacer mes informations</a>
    <?php elseif ($trial_ended): ?>
    <div class="notice-box">Vos données sont conservées.
      <?php if ($show_billing): ?>Choisissez votre formule pour retrouver votre espace.<?php elseif (!$is_org_admin): ?><?= ak_billing_hidden() ? 'Vous pouvez en parler à l’administrateur de votre association.' : 'L’administrateur de votre association peut choisir la formule qui vous redonnera accès à votre espace.' ?><?php else: ?>Pour toute question, écrivez à <?= AK_SUPPORT_EMAIL ?>.<?php endif; ?></div>
    <?php else: ?>
    <div class="notice-box"><?= htmlspecialchars(ak_session_access_message('org_suspended')) ?>
      <?php if (!$is_org_admin): ?><br>Vous pouvez aussi en parler à l’administrateur de votre association.<?php endif; ?></div>
    <?php endif; ?>
    <?php if ($show_billing): ?>
      <a href="/mon-asso-plan" class="btn-submit btn-link"><?= $trial_ended ? 'Choisir ma formule' : 'Régulariser mon abonnement' ?></a>
      <p class="subtitle" style="margin:8px 0 0"><?= $auto_unsuspend
          ? 'L’accès revient de lui-même dès que le paiement est enregistré, en général en quelques minutes.'
          : 'Une fois le paiement effectué, prévenez ' . AK_SUPPORT_EMAIL . ' : nous rétablissons l’accès au plus vite.' ?></p>
    <?php endif; ?>
    <a href="mailto:<?= AK_SUPPORT_EMAIL ?>" class="btn-ghost">Écrire au support</a>
    <a href="/rgpd-export" class="btn-ghost">Exporter mes données personnelles</a>
    <a href="/deconnexion" class="forgot-link">Se déconnecter</a>
  </div>
<?php else: ?>
  <h1>Bienvenue</h1>
  <p class="subtitle">Connectez-vous à votre espace Assokit</p>
  <?php if ($success_msg && !$error): ?><div class="success-box">✓ <?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
  <?php // Demande en attente : même boîte (l'app lit .error-box pour son écran de connexion), ton d'attente, sans ⚠️ ?>
  <?php if ($error): ?><div class="error-box<?= $pending_notice ? ' is-pending' : '' ?>"><?= $pending_notice ? '' : '⚠️ ' ?><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php // Retrait par le demandeur, sans session (Apple 5.1.1 v) : /retirer-demande ; l'app iPhone affiche le même lien sous l'avis ?>
  <?php if ($error && $pending_notice): ?>
  <p class="subtitle" id="ak-retirer-demande-lien" style="margin:-6px 0 18px;font-size:12.5px;">Vous ne souhaitez plus de cet espace&nbsp;? <a href="/retirer-demande" style="color:var(--acc)">Retirer ma demande</a> · <a href="/contact" style="color:var(--acc)">nous écrire</a></p>
  <?php endif; ?>
  <form method="POST" action="/connexion" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>"><?php endif; ?>
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
  <?php // Dans l'application : pas de « gratuit » (Apple 3.1.1 / 3.1.3 f : rien qui évoque un achat) ?>
  <?php if (ak_is_ios_app()): // App iPhone : une demande, examinée par l'équipe (signup.php), ni essai ni gratuité ?>
  <div class="footer-note">Pas encore d’espace ? <a href="/signup">Demander la création de mon espace</a></div>
  <?php else: ?>
  <div class="footer-note">Pas encore de compte ? <a href="/signup"><?= ak_billing_hidden() ? 'Créer mon compte' : 'Créer mon compte gratuit' ?></a> · <a href="/contact">Parler à un conseiller</a></div>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
