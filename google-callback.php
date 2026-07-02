<?php
/**
 * ============================================================
 * ASSOKIT — Callback OAuth Google
 * ============================================================
 * URL : /google-callback
 * Google redirige ici après autorisation avec un "code".
 * On échange ce code contre un access_token + refresh_token.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-helper.php';

require_login();
$user = current_user();

if ($user['role'] !== 'admin') {
    header('Location: /mon-agenda?error=not_admin');
    exit;
}

// Gestion des erreurs renvoyées par Google (user a refusé, etc.)
if (isset($_GET['error'])) {
    $err = $_GET['error'];
    header('Location: /mon-agenda?error=' . urlencode($err));
    exit;
}

// Vérification du state (anti-CSRF)
$received_state = $_GET['state'] ?? '';
$expected_state = $_SESSION['google_oauth_state'] ?? '';
if (empty($received_state) || empty($expected_state) || !hash_equals($expected_state, $received_state)) {
    header('Location: /mon-agenda?error=invalid_state');
    exit;
}
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_state_time']);

// On doit avoir un code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Location: /mon-agenda?error=no_code');
    exit;
}

// Échange du code contre les tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code',
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    $data = json_decode($response, true);
    $err = $data['error_description'] ?? $data['error'] ?? 'HTTP ' . $http_code;
    header('Location: /mon-agenda?error=' . urlencode($err));
    exit;
}

$tokens = json_decode($response, true);
if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
    // Si pas de refresh_token, l'utilisateur doit révoquer l'accès et retenter
    // https://myaccount.google.com/permissions
    header('Location: /mon-agenda?error=no_refresh_token');
    exit;
}

// Récupérer l'email du compte Google connecté
$google_email = get_google_user_email($tokens['access_token']);
if (!$google_email) {
    header('Location: /mon-agenda?error=no_email');
    exit;
}

$expires_at = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600) - 60);

// Enregistrer la connexion (upsert)
$existing = get_org_google_connection($user['org_id']);

if ($existing) {
    $stmt = $pdo->prepare("
        UPDATE org_google_calendar
        SET connected_by_user_id = ?,
            google_email = ?,
            access_token = ?,
            refresh_token = ?,
            token_expires_at = ?,
            sync_token = NULL,
            sync_enabled = 1
        WHERE id = ?
    ");
    $stmt->execute([
        $user['id'],
        $google_email,
        $tokens['access_token'],
        $tokens['refresh_token'],
        $expires_at,
        $existing['id'],
    ]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO org_google_calendar
            (org_id, connected_by_user_id, google_email, google_calendar_id,
             access_token, refresh_token, token_expires_at, sync_enabled)
        VALUES (?, ?, ?, 'primary', ?, ?, ?, 1)
    ");
    $stmt->execute([
        $user['org_id'],
        $user['id'],
        $google_email,
        $tokens['access_token'],
        $tokens['refresh_token'],
        $expires_at,
    ]);
}

sync_log($user['org_id'], null, 'push', 'oauth_connect', 'success', 'Compte connecté : ' . $google_email);

header('Location: /mon-agenda?connected=1');
exit;
