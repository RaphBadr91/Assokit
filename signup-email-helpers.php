<?php
/**
 * ============================================================
 * ASSOKIT — Emails du tunnel d'inscription DEMO
 * ============================================================
 *  - send_demo_founder_notification() : previent le service client
 *    qu'un nouveau prospect vient de creer sa demo (speed-to-lead).
 *  - send_demo_welcome_email() : email de bienvenue au prospect,
 *    avec bouton de confirmation d'email.
 *  - Verification d'email par signature HMAC (aucune table requise) :
 *    ak_email_verify_url() / ak_email_verify_check().
 *
 * Dependance : resend-helper.php (send_transactional_email, email_wrap).
 * Toutes les fonctions sont defensives : un echec d'envoi ne bloque
 * jamais l'inscription.
 * ============================================================
 */

@require_once __DIR__ . '/resend-helper.php';

if (!defined('AK_DEMO_NOTIFY_EMAIL')) {
    // Boite qui recoit les alertes "nouveau prospect"
    define('AK_DEMO_NOTIFY_EMAIL', 'contact@assokit.fr');
}

/**
 * Secret serveur pour signer les liens de verification d'email.
 * Reutilise une constante deja secrete de config.php ; jamais expose.
 */
function ak_email_verify_secret(): string
{
    if (defined('RESEND_API_KEY') && RESEND_API_KEY) return 'akv1:' . RESEND_API_KEY;
    if (defined('SITE_URL') && SITE_URL)             return 'akv1:' . SITE_URL . ':assokit';
    return 'akv1:assokit-email-verify';
}

/** URL de confirmation d'email pour un utilisateur donne. */
function ak_email_verify_url(int $user_id): string
{
    $sig = hash_hmac('sha256', 'verify_email:' . $user_id, ak_email_verify_secret());
    return 'https://assokit.fr/verifier-email?u=' . $user_id . '&s=' . $sig;
}

/** Verifie la signature d'un lien de confirmation d'email. */
function ak_email_verify_check(int $user_id, string $sig): bool
{
    if ($user_id <= 0 || $sig === '') return false;
    $expected = hash_hmac('sha256', 'verify_email:' . $user_id, ak_email_verify_secret());
    return hash_equals($expected, $sig);
}

/**
 * Notifie le service client qu'une nouvelle demo vient d'etre creee.
 */
function send_demo_founder_notification(string $org_name, string $admin_name, string $admin_email): bool
{
    if (!function_exists('send_transactional_email')) return false;

    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">Nouvelle demande de d&eacute;mo &#128640;</h1>'
             . '<p>Une nouvelle organisation vient de cr&eacute;er sa d&eacute;mo Assokit :</p>'
             . '<table style="font-size:14px;color:#44403C;line-height:1.9;margin:12px 0;">'
             . '<tr><td style="padding-right:16px;color:#78716C;">Organisation</td><td><strong>' . htmlspecialchars($org_name) . '</strong></td></tr>'
             . '<tr><td style="padding-right:16px;color:#78716C;">Contact</td><td>' . htmlspecialchars($admin_name) . '</td></tr>'
             . '<tr><td style="padding-right:16px;color:#78716C;">Email</td><td>' . htmlspecialchars($admin_email) . '</td></tr>'
             . '</table>'
             . '<p>Elle est <strong>en attente de validation</strong> dans le super-admin. Recontactez-la rapidement pour maximiser la conversion.</p>';

    $html = function_exists('email_wrap')
        ? email_wrap('Nouvelle d&eacute;mo Assokit', $content, '&#128073; Ouvrir le super-admin', 'https://assokit.fr/super-admin/associations?filter_validation=pending')
        : $content;

    try {
        $res = send_transactional_email(
            AK_DEMO_NOTIFY_EMAIL,
            'Nouvelle démo : ' . $org_name,
            $html,
            ['tag' => 'demo_founder_notif', 'reply_to' => $admin_email]
        );
        return !empty($res['success']);
    } catch (Throwable $e) {
        error_log('[demo_founder_notif] ' . $e->getMessage());
        return false;
    }
}

/**
 * Email de bienvenue au prospect qui vient de creer sa demo.
 */
function send_demo_welcome_email(string $email, string $first_name, string $org_name, string $verify_url): bool
{
    if (!function_exists('send_transactional_email')) return false;

    $content = '<h1 style="font-size:22px;margin:0 0 14px;color:#1C1917">Bienvenue sur votre d&eacute;mo Assokit &#128640;</h1>'
             . '<p>Bonjour ' . htmlspecialchars($first_name) . ',</p>'
             . '<p>Votre espace de d&eacute;monstration pour <strong>' . htmlspecialchars($org_name) . '</strong> est pr&ecirc;t : projets, adh&eacute;rents, factures, compta analytique&hellip; tout est l&agrave; pour tester Assokit &agrave; votre rythme.</p>'
             . '<p>Pour confirmer votre email et s&eacute;curiser votre acc&egrave;s, cliquez sur le bouton ci-dessous :</p>'
             . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
             . '&#128172; Un conseiller Assokit vous contactera bient&ocirc;t pour r&eacute;pondre &agrave; vos questions et vous accompagner.'
             . '</div>'
             . '<p style="font-size:12px;color:#78716C;margin-top:18px;">Si vous n\'&ecirc;tes pas &agrave; l\'origine de cette inscription, ignorez simplement ce message.</p>';

    $html = function_exists('email_wrap')
        ? email_wrap('Bienvenue sur Assokit', $content, '&#9989; Confirmer mon email', $verify_url)
        : $content . '<p><a href="' . htmlspecialchars($verify_url) . '">Confirmer mon email</a></p>';

    try {
        $res = send_transactional_email(
            $email,
            'Bienvenue sur votre démo Assokit',
            $html,
            ['tag' => 'demo_welcome']
        );
        return !empty($res['success']);
    } catch (Throwable $e) {
        error_log('[demo_welcome] ' . $e->getMessage());
        return false;
    }
}
