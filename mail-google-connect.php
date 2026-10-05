<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail : « Se connecter avec Google »
 * ============================================================
 * Envoie l'administrateur sur l'écran de consentement Google (scope
 * gmail.modify). Le retour se fait sur /google-callback, déjà déclaré
 * dans la console Google pour l'agenda : rien à ajouter côté Google
 * hormis l'activation de l'API Gmail et le scope sur l'écran de consentement.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-helpers.php';
require_once __DIR__ . '/demo-guard.php';
require_login();
$user = current_user();

if (!mail_can_manage($user)) { header('Location: /boite-mail?err=' . urlencode('Seul un administrateur peut relier la boîte mail.')); exit; }
if (ak_demo_session())        { header('Location: /boite-mail?err=' . urlencode('Connexion Gmail désactivée dans l’espace de démonstration : la boîte de démo est déjà remplie.')); exit; }
if (!mail_google_configured()) { header('Location: /boite-mail?err=' . urlencode('La connexion Google n’est pas configurée sur le serveur (GOOGLE_CLIENT_ID).')); exit; }
if (!mail_schema_ready($pdo))   { header('Location: /boite-mail'); exit; }

$state = 'mail_' . bin2hex(random_bytes(20));
$_SESSION['mail_oauth_state'] = $state;
$_SESSION['mail_oauth_time']  = time();
header('Location: ' . mail_oauth_url($state, (string)($_GET['email'] ?? '')));
exit;
