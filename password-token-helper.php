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
 * Valide un token : s'assure qu'il existe, n'est pas expire, n'est pas deja utilise.
 * Retourne le user_id si OK, null sinon.
 */
function validate_password_token(PDO $pdo, string $token, string $type = 'set_password'): ?array
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.user_id, t.expires_at, t.used_at, t.token_type,
               u.email, u.first_name, u.last_name, u.deleted_at
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
             . '<p style="font-size:12px; color:#78716C; margin-top:18px;">Si vous n\'attendiez pas ce message, vous pouvez simplement l\'ignorer. Aucun compte ne sera créé tant que le lien n\'a pas été utilisé.</p>';

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
