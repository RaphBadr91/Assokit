<?php
/**
 * contact-send.php
 * --------------------------------------------------------------
 * Endpoint POST formulaire contact
 * Sauve en BDD + envoie email à l'équipe via Resend
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contact');
    exit;
}

// Honeypot anti-bot
if (!empty($_POST['website'])) {
    // Silently fake success pour les bots
    $_SESSION['flash_contact'] = ['type' => 'success', 'msg' => 'Message envoyé !'];
    header('Location: /contact');
    exit;
}

// Récupération + sanitization
$firstname    = trim((string)($_POST['firstname'] ?? ''));
$lastname     = trim((string)($_POST['lastname'] ?? ''));
$email        = trim((string)($_POST['email'] ?? ''));
$organization = trim((string)($_POST['organization'] ?? ''));
$type         = trim((string)($_POST['type'] ?? ''));
$subject      = trim((string)($_POST['subject'] ?? ''));
$message      = trim((string)($_POST['message'] ?? ''));
$rgpd         = !empty($_POST['rgpd']);

// Validation
$errors = [];
if ($firstname === '' || strlen($firstname) > 80)  $errors[] = 'Prénom invalide';
if ($lastname === ''  || strlen($lastname) > 80)   $errors[] = 'Nom invalide';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = 'Email invalide';
if ($message === ''   || strlen($message) > 3000)  $errors[] = 'Message invalide';
if (!$rgpd) $errors[] = 'Veuillez accepter la politique de confidentialité';

if (!empty($errors)) {
    $_SESSION['flash_contact'] = ['type' => 'error', 'msg' => implode(' · ', $errors)];
    header('Location: /contact');
    exit;
}

// Sauvegarde BDD (table créée par migration v45)
try {
    $st = $pdo->prepare("
        INSERT INTO asso_contact_messages
            (firstname, lastname, email, organization, type, subject, message, ip, user_agent, created_at)
        VALUES
            (:f, :l, :e, :o, :t, :s, :m, :ip, :ua, NOW())
    ");
    $st->execute([
        ':f' => $firstname, ':l' => $lastname, ':e' => $email,
        ':o' => $organization, ':t' => $type, ':s' => $subject, ':m' => $message,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ':ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
} catch (Throwable $e) {
    error_log('[contact-send] DB error: ' . $e->getMessage());
    // Continue malgré l'erreur DB pour pouvoir envoyer l'email
}

// Envoi email via Resend
$subject_labels = [
    'demo' => 'Réservation démo', 'info' => 'Demande d\'infos',
    'quote' => 'Devis sur-mesure', 'partner' => 'Partenariat',
    'press' => 'Contact presse', 'other' => 'Autre',
];
$type_labels = [
    'asso' => 'Association loi 1901', 'tpe' => 'TPE / petite entreprise',
    'indep' => 'Indépendant·e / artisan', 'autre' => 'Autre',
];

$subject_label = $subject_labels[$subject] ?? 'Contact';
$type_label    = $type_labels[$type] ?? '';

if (defined('RESEND_API_KEY') && RESEND_API_KEY) {
    $to_email = defined('CONTACT_NOTIFY_EMAIL') ? CONTACT_NOTIFY_EMAIL : 'contact@assokit.fr';
    $from     = defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : 'noreply@assokit.fr';

    $body_html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:30px auto;padding:20px;color:#0F172A;">'
        . '<div style="background:linear-gradient(135deg,#0F172A,#059669);color:white;padding:24px;border-radius:12px 12px 0 0;">'
        . '<h2 style="margin:0;font-size:18px;">📬 Nouveau message · ' . pub_h($subject_label) . '</h2>'
        . '</div>'
        . '<div style="background:white;border:1px solid #E2E8F0;border-top:none;padding:24px;border-radius:0 0 12px 12px;">'
        . '<p><strong>De :</strong> ' . pub_h($firstname . ' ' . $lastname) . ' &lt;' . pub_h($email) . '&gt;</p>'
        . ($organization ? '<p><strong>Structure :</strong> ' . pub_h($organization) . ($type_label ? ' (' . pub_h($type_label) . ')' : '') . '</p>' : '')
        . '<p><strong>Objet :</strong> ' . pub_h($subject_label) . '</p>'
        . '<hr style="border:none;border-top:1px solid #E2E8F0;margin:18px 0;">'
        . '<div style="white-space:pre-wrap;line-height:1.6;">' . pub_h($message) . '</div>'
        . '<hr style="border:none;border-top:1px solid #E2E8F0;margin:18px 0;">'
        . '<p style="font-size:12px;color:#64748B;">Reçu via assokit.fr/contact · ' . date('d/m/Y H:i') . '</p>'
        . '</div></body></html>';

    $payload = [
        'from'     => 'Assokit Contact <' . $from . '>',
        'to'       => [$to_email],
        'reply_to' => $email,
        'subject'  => '[Assokit] ' . $subject_label . ' — ' . $firstname . ' ' . $lastname,
        'html'     => $body_html,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Email d'accusé de réception au visiteur
    $ack_body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:30px auto;padding:20px;color:#0F172A;">'
        . '<div style="background:linear-gradient(135deg,#0F172A,#059669);color:white;padding:30px 24px;border-radius:12px 12px 0 0;text-align:center;">'
        . '<div style="font-size:36px;margin-bottom:10px;">🌿</div>'
        . '<h1 style="margin:0;font-size:22px;">Merci de nous avoir contactés !</h1>'
        . '</div>'
        . '<div style="background:white;border:1px solid #E2E8F0;border-top:none;padding:30px 24px;border-radius:0 0 12px 12px;line-height:1.7;">'
        . '<p>Bonjour ' . pub_h($firstname) . ',</p>'
        . '<p>On a bien reçu votre message. <strong>L\'équipe Assokit le lit aujourd\'hui</strong> et vous répondra personnellement dans les <strong>24 heures (jours ouvrés)</strong>.</p>'
        . '<p>En attendant, vous pouvez :</p>'
        . '<ul>'
        . '<li>Découvrir nos <a href="https://assokit.fr/fonctionnalites" style="color:#059669;">fonctionnalités</a></li>'
        . '<li>Lire notre <a href="https://assokit.fr/blog" style="color:#059669;">blog</a> sur les associations et les TPE</li>'
        . '<li>Consulter nos <a href="https://assokit.fr/tarifs" style="color:#059669;">tarifs</a></li>'
        . '</ul>'
        . '<p>À très vite,<br><strong>L\'équipe Assokit 🌿</strong></p>'
        . '</div>'
        . '<p style="text-align:center;font-size:12px;color:#94A3B8;margin-top:18px;">Assokit · édité par Latitude91 · pensé en France 🇫🇷</p>'
        . '</body></html>';

    $payload2 = [
        'from'    => 'Assokit <' . $from . '>',
        'to'      => [$email],
        'subject' => '🌿 Bien reçu ! On vous répond très vite — Assokit',
        'html'    => $ack_body,
    ];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload2, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$_SESSION['flash_contact'] = [
    'type' => 'success',
    'msg'  => '✅ Message envoyé ! Vous allez recevoir un accusé de réception. Réponse personnelle sous 24h.',
];
header('Location: /contact');
exit;
