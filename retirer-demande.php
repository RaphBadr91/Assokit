<?php
/**
 * retirer-demande.php — Page publique « Retirer ma demande de création d'espace ».
 * --------------------------------------------------------------
 * Règle 5.1.1 (v) d'Apple : un compte créé dans l'app doit pouvoir être supprimé dans l'app. Sur
 * iPhone, l'inscription est une DEMANDE validée par le Fondateur (signup-review-helpers.php) : tant
 * qu'elle attend, ou après un refus, le demandeur ne peut pas ouvrir de session, donc pas atteindre
 * Paramètres › Supprimer mon compte. Cette page le lui permet, sans session : l'adresse e-mail et le
 * mot de passe de la demande suffisent. L'app l'ouvre depuis son écran de connexion (« Retirer ma
 * demande », sous l'avis « en cours de validation ») ; /connexion y renvoie aussi.
 *
 *   - mot de passe : MÊME blocage que /connexion (ak_login_lockout, vérifié avant le mot de passe :
 *     10 échecs par compte, 50 par adresse IP, en 15 minutes) ; une erreur est notée « login_failed »
 *     comme sur /connexion et compte donc pour les deux pages. Adresse inconnue ou mot de passe faux :
 *     le message de /connexion, sans dire lequel des deux (aucune liste d'adresses à deviner) ;
 *   - demande en attente ou refusée, ce compte en étant le seul : ak_signup_review_withdraw (compte
 *     anonymisé et espace fermé comme une suppression dans l'app, informations effacées, e-mail de
 *     confirmation, Fondateur prévenu) ;
 *   - tout autre compte (espace validé, même à l'instant, association à plusieurs comptes…) : rien
 *     n'est fait, la page dit comment supprimer un compte actif (/suppression-compte § 1 et 2).
 * Dans l'application (AK_PUBLIC_APP_PAGES, includes-public.php) : en-tête et pied de page de
 * l'app ; aucun prix, ni essai, ni achat. Hors du plan du site (noindex).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/signup-review-helpers.php';   // ak_signup_review_withdraw ; app-context.php (ak_login_lockout)
require_once __DIR__ . '/rate-limit-helper.php';
// Journal des échecs (activity_log_login_failed), le même que /connexion. Cette page est dans
// AK_GUARD_PUBLIC : l'état d'une session ouverte dans ce navigateur n'y change rien.
if (file_exists(__DIR__ . '/activity-tracker.php')) require_once __DIR__ . '/activity-tracker.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$error = null;        // refus de la saisie (identifiants, blocage, session expirée…)
$info = null;         // mot de passe correct, mais rien à retirer : comment supprimer un compte actif
$email_value = '';
// Retour après un retrait réussi (redirection : recharger la page ne renvoie rien)
$done = null;
if (($_GET['retrait'] ?? '') === 'ok' && is_array($_SESSION['signup_withdrawn'] ?? null)) {
    $done = $_SESSION['signup_withdrawn'];
    unset($_SESSION['signup_withdrawn']);
}

/** Comment supprimer un compte actif : chemins de /suppression-compte (§ 1 et 2). */
$how_to_delete = 'Pour supprimer votre compte : dans l’application, Plus › Paramètres › Supprimer mon compte (RGPD) ; '
               . 'sur le site, Paramètres › Compte › Supprimer mon compte.';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $email_value = $email;
    // Adresse réelle du visiteur, la même que celle du journal des échecs (comme /connexion)
    $ip = function_exists('activity_get_ip') ? activity_get_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Même garde anti-rafale que /connexion, même compteur : alterner les deux pages n'en double pas le débit
    if (!ak_rate_limit('login_post', 120, 60, $ip)) {
        $error = 'Trop de tentatives de connexion. Merci de patienter une minute avant de réessayer.';
    } elseif (!function_exists('check_csrf') || !check_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error = 'Session expirée. Réessayez maintenant.';
    } elseif (!empty($_SESSION['impersonation_target_user_id'])) {
        // Incarnation (support Fondateur) : seule la personne elle-même retire sa demande
        $error = 'Retrait impossible pendant une incarnation.';
    } elseif ($email === '' || $password === '') {
        $error = 'Merci de remplir e-mail et mot de passe.';
    } elseif (empty($_POST['confirm'])) {
        $error = 'Cochez la case de confirmation pour retirer votre demande.';
    } else {
        try {
            // Même lecture que /connexion (comptes désactivés compris)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL ORDER BY is_active DESC LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (($lockout = ak_login_lockout($pdo, $email, $ip)) !== '') {
                $error = $lockout;
            } elseif (!$user || !password_verify($password, (string)$user['password_hash'])) {
                // Le message de /connexion, et le même journal : l'échec compte pour le blocage des deux pages
                $error = 'Email ou mot de passe incorrect.';
                if (function_exists('activity_log_login_failed')) {
                    activity_log_login_failed($email, $user ? 'wrong_password' : 'unknown_email');
                }
            } elseif ((int)($user['is_active'] ?? 1) !== 1) {
                // Comme /connexion : compte désactivé par un administrateur, noté comme un échec
                $error = ak_session_access_message('account_closed');
                if (function_exists('activity_log_login_failed')) {
                    activity_log_login_failed($email, 'account_inactive');
                }
            } elseif (!empty($user['totp_enabled']) && (int)($_SESSION['user_id'] ?? 0) !== (int)$user['id']) {
                // Double authentification activée (possible après un refus : la session s'ouvre alors,
                // avis « Demande non validée ») : le mot de passe seul ne suffit pas. Connecté dans ce
                // navigateur (code déjà donné), le retrait reste possible ici ou par « Mon compte ».
                $info = 'Ce compte est protégé par la double authentification : le mot de passe seul ne suffit pas. '
                      . 'Connectez-vous (mot de passe puis code), puis revenez sur cette page ; si la connexion est impossible, '
                      . 'écrivez-nous à ' . AK_SUPPORT_EMAIL . ' depuis l’adresse de la demande : nous la retirons pour vous.';
            } else {
                $rv = ak_signup_review_withdraw($pdo, (int)($user['org_id'] ?? 0), (int)$user['id']);
                if ($rv['state'] === 'withdrawn') {
                    // Session ouverte de ce compte dans ce navigateur (demandeur refusé) : fermée, comme
                    // après une suppression de compte
                    if ((int)($_SESSION['user_id'] ?? 0) === (int)$user['id']) {
                        ak_session_end();
                        session_id(session_create_id());
                        session_start();
                    }
                    $_SESSION['signup_withdrawn'] = ['org_name' => (string)($rv['org']['name'] ?? ''), 'mailed' => !empty($rv['mailed'])];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    session_write_close();
                    header('Location: /retirer-demande?retrait=ok', true, 303);
                    exit;
                } elseif ($rv['state'] === 'already') {
                    // Double envoi (deux appuis) : l'autre requête vient de retirer la demande
                    $info = 'Votre demande est déjà retirée : votre compte est supprimé et les informations transmises sont effacées.';
                } elseif ($rv['state'] === 'billing') {
                    $error = 'Le retrait n’a pas pu aboutir : rien n’a été modifié. Réessayez dans quelques minutes, ou écrivez à ' . AK_SUPPORT_EMAIL . '.';
                } else {
                    // Pas (plus) une demande : espace validé (même à l'instant, pendant ce retrait),
                    // ou compte qui n'est pas le seul de sa demande. Rien n'est fait.
                    $o = $rv['org'] ?? [];
                    $validated = in_array((string)($o['validation_status'] ?? ''), ['validated', 'approved'], true)
                        && !in_array((string)($o['status'] ?? ''), ['cancelled'], true) && empty($o['deleted_at']);
                    $info = ($validated
                            ? 'Votre espace est actif : ce n’est pas (ou plus) une demande en attente, rien n’a été modifié. '
                            : 'Aucune demande de création d’espace en attente n’est liée à ce compte : rien n’a été modifié. ')
                          . $how_to_delete;
                }
            }
        } catch (Throwable $e) {
            error_log('[retirer-demande] ' . get_class($e) . ' ' . $e->getMessage());
            $error = 'Une erreur est survenue : rien n’a été modifié. Réessayez dans un instant.';
        }
    }
}

$in_app = pub_in_app();
/** Texte échappé, avec les espaces insécables de la typographie française (avant « : ; ! ? », dans les guillemets). */
$t = function (string $s): string {
    return str_replace([' :', ' ;', ' !', ' ?', '« ', ' »'], ['&nbsp;:', '&nbsp;;', '&nbsp;!', '&nbsp;?', '«&nbsp;', '&nbsp;»'], pub_h($s));
};

render_public_head([
    'title'       => 'Retirer ma demande de création d’espace',
    'description' => 'Retirer une demande de création d’espace Assokit en attente de validation ou refusée, et effacer les informations transmises.',
    'path'        => '/retirer-demande',
    'noindex'     => true,
]);
render_public_nav('');
?>
<style>
  .rd-card{background:#fff;border:1px solid var(--c-border,#E7E5E4);border-radius:16px;padding:32px;max-width:460px;margin:0 auto;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
  @media (max-width:480px){.rd-card{padding:22px}}
  .rd-card h2{font-size:19px;font-weight:600;letter-spacing:-0.02em;margin:0 0 6px}
  .rd-sub{font-size:13.5px;color:var(--c-text-3,#78716C);line-height:1.6;margin:0 0 20px}
  .rd-group{margin-bottom:16px}
  .rd-card label{display:block;font-size:12.5px;font-weight:500;color:var(--c-text-2,#44403C);margin-bottom:6px}
  .rd-card input[type=email],.rd-card input[type=password],.rd-card input[type=text]{width:100%;padding:11px 14px;border:1px solid var(--c-border,#E7E5E4);border-radius:10px;font-size:14px;background:#fff;font-family:inherit;box-sizing:border-box}
  .rd-card input:focus{outline:none;border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,0.08)}
  /* iPhone / iPad : sous 16px, Safari zoome sur le champ à chaque saisie */
  @media (max-width:480px),(pointer:coarse){.rd-card input[type=email],.rd-card input[type=password],.rd-card input[type=text]{font-size:16px}}
  .rd-check{display:flex;gap:10px;align-items:flex-start;font-size:13px !important;font-weight:400 !important;line-height:1.5;color:var(--c-encre,#1C1917) !important;margin:6px 0 18px;cursor:pointer}
  .rd-check input{margin-top:3px;width:18px;height:18px;flex-shrink:0;accent-color:#B91C1C}
  .rd-btn{display:block;width:100%;padding:12px;background:#B91C1C;color:#fff;border:none;border-radius:10px;font-size:14.5px;font-weight:600;cursor:pointer;font-family:inherit;text-align:center;text-decoration:none}
  .rd-btn:hover{background:#991B1B}
  .rd-btn-ok{background:#059669}.rd-btn-ok:hover{background:#047857}
  .rd-box{padding:12px 14px;border-radius:10px;font-size:13.5px;line-height:1.55;margin-bottom:16px}
  .rd-err{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B}
  .rd-info{background:#FFFBEB;border:1px solid #FCD34D;color:#92400E}
  .rd-ok{background:#F0FDF4;border:1px solid #86EFAC;color:#166534}
  .rd-links{text-align:center;font-size:12.5px;margin-top:16px;color:var(--c-text-3,#78716C)}
  .rd-links a{color:#059669;text-decoration:none;font-weight:500}
  .rd-note{font-size:12.5px;color:var(--c-text-3,#78716C);line-height:1.6;margin-top:18px;padding-top:16px;border-top:1px solid var(--c-border,#E7E5E4)}
  .rd-note a{color:#059669}
</style>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <?php if (!$in_app): ?>
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Retirer ma demande</strong></div>
    <?php endif; ?>
    <h1 class="pub-h1" style="font-size:32px;">Retirer ma demande de création d’espace</h1>
    <p class="pub-tagline">Votre demande est en cours de validation, ou n’a pas été acceptée&nbsp;? Vous pouvez la retirer vous-même, ici.</p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <div class="rd-card" id="ak-retirer-demande">
    <?php if ($done !== null): ?>
      <h2>Demande retirée</h2>
      <div class="rd-box rd-ok" id="ak-retrait-ok">✓ Votre demande<?= ($done['org_name'] ?? '') !== '' ? ' pour «&nbsp;' . pub_h($done['org_name']) . '&nbsp;»' : '' ?> est retirée.
        Votre compte est supprimé, l’espace demandé est fermé et les informations transmises sont effacées.</div>
      <p class="rd-sub"><?= !empty($done['mailed']) ? 'Un e-mail de confirmation vient de vous être envoyé.' : 'Vous n’avez rien d’autre à faire.' ?>
        Vous pourrez faire une nouvelle demande à tout moment.</p>
      <a href="/connexion" class="rd-btn rd-btn-ok">Retour à la connexion</a>
    <?php else: ?>
      <h2>Retirer ma demande</h2>
      <p class="rd-sub">Saisissez l’adresse e-mail et le mot de passe choisis lors de votre demande. Votre compte est alors supprimé,
        l’espace demandé est fermé et les informations transmises sont effacées. Un e-mail vous le confirme.</p>
      <?php if ($error): ?><div class="rd-box rd-err" role="alert">⚠️ <?= $t($error) ?></div><?php endif; ?>
      <?php if ($info): ?><div class="rd-box rd-info" role="status" id="ak-retrait-info"><?= $t($info) ?> <a href="/suppression-compte" style="color:inherit;font-weight:600;">Toutes les explications</a></div><?php endif; ?>
      <form method="POST" action="/retirer-demande" novalidate>
        <input type="hidden" name="csrf_token" value="<?= pub_h($_SESSION['csrf_token']) ?>">
        <div class="rd-group">
          <label for="rd-email">E-mail</label>
          <input type="email" id="rd-email" name="email" required value="<?= pub_h($email_value) ?>" autocomplete="email" autocapitalize="none" placeholder="vous@association.fr">
        </div>
        <div class="rd-group">
          <label for="rd-password">Mot de passe</label>
          <div style="position:relative;">
            <input type="password" id="rd-password" name="password" required autocomplete="current-password" placeholder="••••••••" style="padding-right:44px;">
            <button type="button" id="rd-toggle" aria-label="Afficher le mot de passe" aria-pressed="false"
              style="position:absolute;top:50%;right:8px;transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;color:#64748B;font-size:13px;font-weight:600;">👁</button>
          </div>
        </div>
        <label class="rd-check">
          <input type="checkbox" name="confirm" value="1" required>
          <span>Je confirme le retrait de ma demande et l’effacement des informations transmises</span>
        </label>
        <button type="submit" class="rd-btn" id="rd-submit">Retirer ma demande</button>
      </form>
      <script>
        (function(){
          var b=document.getElementById('rd-toggle'), p=document.getElementById('rd-password');
          if(b&&p){ b.addEventListener('click', function(){
            var show=p.type==='password'; p.type=show?'text':'password';
            b.setAttribute('aria-pressed', show?'true':'false');
            b.setAttribute('aria-label', show?'Masquer le mot de passe':'Afficher le mot de passe');
          }); }
          // Case non cochée : le navigateur le signale (le formulaire est en novalidate pour les champs)
          var f=document.querySelector('#ak-retirer-demande form'), c=f?f.querySelector('input[name=confirm]'):null;
          if(f&&c){ f.addEventListener('submit', function(e){ if(!c.checked){ e.preventDefault(); c.focus(); if(c.reportValidity) c.reportValidity(); } }); }
        })();
      </script>
      <div class="rd-links"><a href="/connexion">Retour à la connexion</a> · <a href="/mot-de-passe-oublie">Mot de passe oublié&nbsp;?</a></div>
      <p class="rd-note">Votre espace est déjà actif&nbsp;? Ce formulaire ne s’applique pas. <?= $t($how_to_delete) ?>
        Détails sur la page <a href="/suppression-compte">Supprimer votre compte</a>.<br>
        Plus accès à l’adresse e-mail ou au mot de passe de la demande&nbsp;? Écrivez-nous à <a href="mailto:<?= AK_SUPPORT_EMAIL ?>?subject=Retrait%20de%20ma%20demande"><?= AK_SUPPORT_EMAIL ?></a>.</p>
    <?php endif; ?>
    </div>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
