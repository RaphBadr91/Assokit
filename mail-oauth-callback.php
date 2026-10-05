<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail : retour de Google
 * ============================================================
 * Inclus par google-callback.php quand le « state » est celui de la
 * boîte mail (et non celui de l'agenda). Jamais appelé directement.
 * ============================================================
 */
if (!defined('AK_MAIL_CALLBACK')) { http_response_code(404); exit; }
require_once __DIR__ . '/mail-helpers.php';

$user = current_user();
$back = function (string $msg, bool $ok = false) {
    header('Location: /boite-mail' . ($ok ? '?connected=1' : '?err=' . urlencode($msg)));
    exit;
};
unset($_SESSION['mail_oauth_state'], $_SESSION['mail_oauth_time']);

if (!mail_can_manage($user)) $back('Seul un administrateur peut relier la boîte mail.');
if (isset($_GET['error']))   $back($_GET['error'] === 'access_denied' ? 'Autorisation refusée sur l’écran Google.' : 'Google : ' . $_GET['error']);
$code = (string)($_GET['code'] ?? '');
if ($code === '') $back('Réponse Google incomplète.');

$granted = ' ' . (string)($_GET['scope'] ?? '') . ' ';
if (strpos($granted, 'gmail.modify') === false && strpos($granted, 'mail.google.com') === false) {
    $back('L’accès à Gmail n’a pas été coché sur l’écran Google : recommencez en cochant toutes les autorisations.');
}

$tok = mail_exchange_code($code);
if (!empty($tok['error']))         $back('Google : ' . $tok['error']);
if (empty($tok['refresh_token']))  $back('Google n’a pas fourni d’accès permanent : retirez Assokit sur myaccount.google.com/permissions puis recommencez.');

$org = (int)$user['org_id'];
$acc = ['id' => 0, 'org_id' => $org, 'provider' => 'gmail', 'email' => '', 'status' => 'active',
        'access_token_enc' => mail_encrypt($tok['access_token']), 'refresh_token_enc' => mail_encrypt($tok['refresh_token']),
        'token_expires_at' => date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600) - 60)];

// Adresse de la boîte (profil Gmail), sans enregistrer quoi que ce soit avant
[$c, $profile] = mail_http('GET', MAIL_GMAIL_API . 'profile', ['Authorization: Bearer ' . $tok['access_token']]);
$profile = json_decode((string)$profile, true) ?: [];
if ($c !== 200 || empty($profile['emailAddress'])) $back($c === 403 ? 'L’API Gmail n’est pas activée dans le projet Google Cloud.' : 'Lecture du profil Gmail impossible.');
$email = strtolower($profile['emailAddress']);

$old = mail_get_account($pdo, $org);
if ($old && strtolower($old['email']) !== $email) {
    // Autre boîte : on repart de zéro pour ne pas mélanger deux boîtes
    mail_disconnect($pdo, $org, true);
    $old = null;
}
$o = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
$o->execute([$org]);
$display = (string)$o->fetchColumn();

if ($old) {
    $pdo->prepare("UPDATE mail_accounts SET provider = 'gmail', access_token_enc = ?, refresh_token_enc = ?, token_expires_at = ?, status = 'active', last_error = NULL, connected_by_user_id = ? WHERE id = ?")
        ->execute([$acc['access_token_enc'], $acc['refresh_token_enc'], $acc['token_expires_at'], $user['id'], $old['id']]);
} else {
    $pdo->prepare("INSERT INTO mail_accounts (org_id, provider, email, display_name, access_token_enc, refresh_token_enc, token_expires_at, history_id, status, connected_by_user_id)
                   VALUES (?, 'gmail', ?, ?, ?, ?, ?, ?, 'active', ?)")
        ->execute([$org, $email, $display ?: null, $acc['access_token_enc'], $acc['refresh_token_enc'], $acc['token_expires_at'], (string)($profile['historyId'] ?? '') ?: null, $user['id']]);
}
mail_ensure_categories($pdo, $org);
if (function_exists('activity_log_action')) { try { activity_log_action('mail_connect', ['email' => $email]); } catch (Throwable $e) {} }
$back('', true);
