<?php
/**
 * ============================================================
 * ASSOKIT — Démarre le flow OAuth Google
 * ============================================================
 * URL : /google-connect
 * 1. Vérifie que l'utilisateur est admin
 * 2. Génère un état CSRF (state)
 * 3. Redirige vers Google pour autorisation
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-helper.php';

require_login();
$user = current_user();

// Seuls les admins peuvent connecter Google
if ($user['role'] !== 'admin') {
    header('Location: /mon-agenda?error=not_admin');
    exit;
}

if (!is_google_enabled()) {
    header('Location: /mon-agenda?error=not_configured');
    exit;
}

// État anti-CSRF (sera vérifié au retour de Google)
$state = bin2hex(random_bytes(24));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_state_time'] = time();

// Paramètres OAuth
$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/userinfo.email',
    'access_type' => 'offline',       // Indispensable pour avoir un refresh_token
    'prompt' => 'consent',            // Force l'écran de consentement (pour garantir refresh_token)
    'state' => $state,
    'include_granted_scopes' => 'true',
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

header('Location: ' . $auth_url);
exit;
