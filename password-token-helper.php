<?php
/**
 * ============================================================
 * ASSOKIT — Helper pour les tokens de mot de passe
 * ============================================================
 * Fonctions communes pour :
 *   - Generer un token (creation d'adherent OU mot de passe oublie)
 *   - Envoyer l'email avec le lien magique
 *   - Valider/consommer un token
 *
 * Dependance : resend-helper.php (send_transactional_email, email_wrap)
 * ============================================================
 */

@require_once __DIR__ . '/resend-helper.php';

if (!defined('PW_TOKEN_EXPIRY_DAYS')) {
    define('PW_TOKEN_EXPIRY_DAYS', 7);
}

/**
 * Cree un token pour un utilisateur et l'insere en BDD.
 * @param PDO $pdo
 * @param int $user_id
 * @param string $type 'set_password' ou 'reset_password'
 * @return string Le token en clair (a envoyer dans l'URL)
 */
function create_password_token(PDO $pdo, int $user_id, string $type = 'set_password'): string
{
    if (!in_array($type, ['set_password', 'reset_password'], true)) {
        throw new InvalidArgumentException('Type de token invalide');
    }

    // Token aleatoire crypto-secure
    $token = bin2hex(random_bytes(32));  // 64 caracteres hex

    $expires_at = date('Y-m-d H:i:s', time() + PW_TOKEN_EXPIRY_DAYS * 86400);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO user_password_tokens
            (user_id, token, token_type, expires_at, ip_requested, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $token, $type, $expires_at, $ip]);

    return $token;
}

/**
 * Valide un token : s'assure qu'il existe, n'est pas expire, n'est pas deja utilise,
 * et que le compte est toujours actif (un compte desactive par l'administrateur ne
 * se reactive pas avec un ancien lien d'invitation ou de mot de passe oublie).
 * Retourne le token et le user si OK, null sinon.
 */
function validate_password_token(PDO $pdo, string $token, string $type = 'set_password'): ?array
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.user_id, t.expires_at, t.used_at, t.token_type,
               u.email, u.first_name, u.last_name, u.deleted_at, u.is_active
        FROM user_password_tokens t
        JOIN users u ON t.user_id = u.id
        WHERE t.token = ? AND t.token_type = ?
        LIMIT 1
    ");
    $stmt->execute([$token, $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;
    if ($row['used_at'] !== null) return null; // Deja utilise
    if (strtotime($row['expires_at']) < time()) return null; // Expire
    if ($row['deleted_at'] !== null) return null; // User supprime
    if ((int)$row['is_active'] !== 1) return null; // User desactive par l'administrateur

    return $row;
}

/**
 * Lien deja utilise il y a quelques minutes, avec ce meme mot de passe : c'est le meme envoi
 * qui revient (double clic, formulaire renvoye par le navigateur). Retourne la ligne du token
 * (token_type, user) pour afficher la confirmation au lieu de « lien invalide », null sinon.
 * Ne revele rien a qui n'a pas a la fois le lien et le mot de passe qu'il vient de definir.
 */
function password_token_just_used(PDO $pdo, string $token, string $password, int $minutes = 10): ?array
{
    if ($token === '' || $password === '') return null;
    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.token_type, t.used_at, NOW() AS now_db, u.password_hash
            FROM user_password_tokens t
            JOIN users u ON t.user_id = u.id
            WHERE t.token = ? AND t.used_at IS NOT NULL
              AND u.deleted_at IS NULL AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[password-token] ' . $e->getMessage());
        return null;
    }
    if (!$row) return null;
    // Délai mesuré avec l'horloge de la base (celle qui a écrit used_at). Colonne sans heure :
    // seul le jour peut être comparé.
    $used = (string)$row['used_at'];
    $now  = (string)$row['now_db'];
    $recent = strlen(trim($used)) <= 10
        ? substr($used, 0, 10) === substr($now, 0, 10)
        : (strtotime($now) - strtotime($used)) <= $minutes * 60;
    if (!$recent || empty($row['password_hash']) || !password_verify($password, (string)$row['password_hash'])) return null;
    unset($row['password_hash'], $row['used_at'], $row['now_db']);
    return $row;
}

/**
 * Marque un token comme consomme.
 */
function consume_password_token(PDO $pdo, int $token_id): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("UPDATE user_password_tokens SET used_at = NOW(), ip_used = ? WHERE id = ?");
    $stmt->execute([$ip, $token_id]);
}

/**
 * Envoie l'email "Bienvenue - creez votre mot de passe" a un nouvel adherent.
 */
function send_welcome_email(string $email, string $first_name, string $org_name, string $token): bool
{
    if (!function_exists('send_transactional_email')) return false;

    $url = 'https://assokit.fr/definir-mot-de-passe/' . $token;

    $content = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Bienvenue sur Assokit 🎉</h1>'
             . '<p>Bonjour ' . htmlspecialchars($first_name) . ',</p>'
             . '<p>Votre compte vient d\'être créé pour l\'association <strong>' . htmlspecialchars($org_name) . '</strong>.</p>'
             . '<p>Pour activer votre compte, il vous suffit de créer votre mot de passe en cliquant sur le bouton ci-dessous :</p>'
             . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
             . '⏰ <strong>Ce lien expire dans ' . PW_TOKEN_EXPIRY_DAYS . ' jours.</strong> Si vous ne pouvez pas créer votre mot de passe dans ce délai, contactez votre administrateur pour recevoir un nouveau lien.'
             . '</div>'
             . '<p style="font-size:12px; color:#78716C; margin-top:18px;">Si vous n\'attendiez pas ce message, ignorez-le : personne ne pourra se connecter à ce compte sans ce lien.</p>';

    $html = function_exists('email_wrap')
        ? email_wrap('Bienvenue sur Assokit', $content, '🔐 Créer mon mot de passe', $url)
        : $content . '<p><a href="' . $url . '">Créer mon mot de passe</a></p>';

    try {
        $result = send_transactional_email($email, 'Bienvenue sur Assokit — Créez votre mot de passe', $html, ['tag' => 'welcome_set_password']);
        return !empty($result['success']);
    } catch (Throwable $e) {
        error_log('Email welcome error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Envoie l'email "Mot de passe oublie" a un utilisateur existant.
 */
function send_reset_password_email(string $email, string $first_name, string $token): bool
{
    if (!function_exists('send_transactional_email')) return false;

    $url = 'https://assokit.fr/definir-mot-de-passe/' . $token;

    $content = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Réinitialisation de votre mot de passe 🔑</h1>'
             . '<p>Bonjour ' . htmlspecialchars($first_name) . ',</p>'
             . '<p>Vous avez demandé à réinitialiser votre mot de passe Assokit. Pour définir un nouveau mot de passe, cliquez sur le bouton ci-dessous :</p>'
             . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
             . '⏰ <strong>Ce lien expire dans ' . PW_TOKEN_EXPIRY_DAYS . ' jours.</strong>'
             . '</div>'
             . '<p style="font-size:12px; color:#78716C; margin-top:18px;"><strong>Vous n\'êtes pas à l\'origine de cette demande ?</strong><br>'
             . 'Vous pouvez ignorer ce message en toute sécurité. Votre mot de passe actuel reste valide tant que vous ne cliquez pas sur le lien ci-dessus.</p>';

    $html = function_exists('email_wrap')
        ? email_wrap('Réinitialisation mot de passe', $content, '🔑 Réinitialiser mon mot de passe', $url)
        : $content . '<p><a href="' . $url . '">Réinitialiser mon mot de passe</a></p>';

    try {
        $result = send_transactional_email($email, 'Réinitialisation de votre mot de passe Assokit', $html, ['tag' => 'reset_password']);
        return !empty($result['success']);
    } catch (Throwable $e) {
        error_log('Email reset error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Adresse de connexion modifiée (Paramètres > Compte, l'app, ou l'administrateur de l'association
 * pour un autre compte : $by_admin, qui adapte le texte des deux e-mails) :
 *   - l'ANCIENNE adresse est prévenue : si ce n'est pas le titulaire qui a fait le changement
 *     (session restée ouverte sur un poste partagé), il le sait tout de suite ;
 *   - la nouvelle adresse reçoit un lien de confirmation, et email_verified_at repasse à NULL
 *     jusqu'au clic (si la colonne existe) ;
 *   - le changement est noté dans le journal d'activité : l'export RGPD ne reprend les essais de
 *     connexion anonymes faits à la nouvelle adresse qu'à partir de là (avant, elle pouvait servir
 *     à un autre compte).
 * Best effort : un envoi raté ne bloque jamais la modification, il est seulement journalisé.
 */
function ak_account_email_changed(PDO $pdo, int $user_id, string $old_email, string $new_email, string $first_name, bool $by_admin = false): void
{
    try {
        $pdo->prepare("INSERT INTO assokit_activity_log (user_id, user_email, event_type, event_action, ip)
                       VALUES (?, ?, 'action', 'email_changed', ?)")
            ->execute([$user_id, mb_substr($new_email, 0, 255),
                       function_exists('activity_get_ip') ? activity_get_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $e) {
        error_log('[email-change] journal : ' . $e->getMessage());
    }
    try {
        if (function_exists('ak_db_update')) {
            ak_db_update($pdo, 'users', $user_id, ['email_verified_at' => null]);
        } else {
            $pdo->prepare("UPDATE users SET email_verified_at = NULL WHERE id = ?")->execute([$user_id]);
        }
    } catch (Throwable $e) {
        // Colonne absente (migration email_verified_at non passée) : rien à remettre à zéro
    }
    if (!function_exists('send_transactional_email')) return;
    @require_once __DIR__ . '/signup-email-helpers.php';

    $hello = '<p>Bonjour ' . htmlspecialchars($first_name) . ',</p>';
    $notice = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Votre adresse de connexion a changé</h1>'
            . $hello
            . ($by_admin
                ? '<p>L\'administrateur de votre association a remplacé l\'adresse e-mail de connexion de votre compte Assokit par <strong>' . htmlspecialchars($new_email) . '</strong>, le ' . date('d/m/Y à H:i') . '.</p>'
                : '<p>L\'adresse e-mail de connexion de votre compte Assokit vient d\'être remplacée par <strong>' . htmlspecialchars($new_email) . '</strong>, le ' . date('d/m/Y à H:i') . '.</p>')
            . '<p>À partir de maintenant, c\'est cette nouvelle adresse qui sert à vous connecter et à recevoir les liens de mot de passe.</p>'
            . '<div style="background:#FEF2F2;border-left:3px solid #DC2626;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#991B1B;line-height:1.6;">'
            . ($by_admin
                ? '<strong>Vous n\'étiez pas au courant ?</strong> Contactez l\'administrateur de votre association, ou écrivez à contact@assokit.fr.'
                : '<strong>Ce n\'est pas vous ?</strong> Prévenez tout de suite l\'administrateur de votre association, ou écrivez à contact@assokit.fr : quelqu\'un a utilisé votre session pour prendre la main sur votre compte.')
            . '</div>';
    $verify = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Confirmez votre nouvelle adresse</h1>'
            . $hello
            . ($by_admin
                ? '<p>L\'administrateur de votre association a indiqué cette adresse pour votre compte Assokit : c\'est désormais elle qui sert à vous connecter. Confirmez-la en cliquant sur le bouton ci-dessous.</p>'
                : '<p>Cette adresse est désormais celle de votre compte Assokit. Confirmez-la en cliquant sur le bouton ci-dessous.</p>')
            . ($by_admin
                ? '<p style="font-size:12px; color:#78716C; margin-top:18px;">Ce message ne vous concerne pas ? Ignorez-le et écrivez à contact@assokit.fr : l\'adresse a sans doute été saisie par erreur.</p>'
                : '<p style="font-size:12px; color:#78716C; margin-top:18px;">Si vous n\'êtes pas à l\'origine de ce changement, ignorez ce message et prévenez l\'administrateur de votre association.</p>');
    $verify_url = function_exists('ak_email_verify_url') ? ak_email_verify_url($user_id) : 'https://assokit.fr/connexion';

    try {
        $r1 = send_transactional_email($old_email, 'Votre adresse de connexion Assokit a été modifiée',
            function_exists('email_wrap') ? email_wrap('Adresse de connexion modifiée', $notice) : $notice,
            ['tag' => 'email_changed']);
        $r2 = send_transactional_email($new_email, 'Confirmez votre nouvelle adresse e-mail Assokit',
            function_exists('email_wrap') ? email_wrap('Confirmez votre adresse', $verify, 'Confirmer mon adresse', $verify_url)
                                          : $verify . '<p><a href="' . $verify_url . '">Confirmer mon adresse</a></p>',
            ['tag' => 'email_verify']);
        if (empty($r1['success']) || empty($r2['success'])) {
            error_log('[email-change] envoi incomplet pour user ' . $user_id . ' : ' . ($r1['error'] ?? '') . ' / ' . ($r2['error'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('[email-change] ' . $e->getMessage());
    }
}

/**
 * Valide la robustesse d'un mot de passe.
 * Retourne null si OK, message d'erreur sinon.
 */
function validate_password_strength(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Le mot de passe doit faire au moins 8 caractères.';
    }
    if (strlen($pw) > 200) {
        return 'Le mot de passe est trop long (max 200 caractères).';
    }
    return null;
}
