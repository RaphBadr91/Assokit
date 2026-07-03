<?php
/**
 * verifier-email.php — Confirmation d'email (tunnel demo)
 * URL : /verifier-email?u=<user_id>&s=<signature HMAC>
 * La signature HMAC prouve la legitimite du lien (aucune table requise).
 * Page autonome : ne depend que de config.php ($pdo) et du helper de signature.
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/signup-email-helpers.php';

$u = (int)($_GET['u'] ?? 0);
$s = (string)($_GET['s'] ?? '');

$ok = false;
if ($u > 0 && $s !== '' && function_exists('ak_email_verify_check') && ak_email_verify_check($u, $s)) {
    $ok = true; // signature valide = email confirme
    try {
        // Enregistre la confirmation si la colonne existe (migration email_verified_at).
        $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL")
            ->execute([$u]);
    } catch (Throwable $e) {
        // Colonne absente (migration non passee) : on confirme quand meme cote UX.
        error_log('[verifier-email] ' . $e->getMessage());
    }
}

$icon    = $ok ? '&#9989;' : '&#9888;&#65039;';
$heading = $ok ? 'Votre email est confirm&eacute;' : 'Lien invalide ou expir&eacute;';
$message = $ok
    ? 'Merci ! Votre adresse email est bien confirm&eacute;e. Vous pouvez d&egrave;s maintenant explorer votre d&eacute;mo Assokit.'
    : 'Ce lien de confirmation n\'est pas valide. Vous pouvez vous connecter directement &agrave; votre espace.';
$cta_url = $ok ? '/dashboard' : '/connexion';
$cta_txt = $ok ? 'Acc&eacute;der &agrave; ma d&eacute;mo' : 'Se connecter';
$title   = $ok ? 'Email confirm&eacute; — Assokit' : 'Lien invalide — Assokit';

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $title ?></title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#FAFAF9;color:#1C1917;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border:1px solid #E7E5E4;border-radius:16px;padding:40px;max-width:480px;width:100%;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
    .icon{font-size:48px;line-height:1;margin-bottom:16px}
    h1{font-size:23px;letter-spacing:-0.02em;margin-bottom:10px}
    p{font-size:15px;color:#475569;line-height:1.6;margin-bottom:24px}
    a.btn{display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;font-size:14px}
    a.btn:hover{background:#047857}
  </style>
</head>
<body>
  <div class="card">
    <div class="icon"><?= $icon ?></div>
    <h1><?= $heading ?></h1>
    <p><?= $message ?></p>
    <a class="btn" href="<?= htmlspecialchars($cta_url) ?>"><?= $cta_txt ?></a>
  </div>
</body>
</html>
