<?php
/**
 * resend-helper.php — Envoi d'emails transactionnels via Resend
 * ================================================================
 *
 * SETUP :
 *   1. Dans ton config.php, ajoute 2 constantes :
 *        define('RESEND_API_KEY', 're_xxxxxxxxxxxx');  // ta clé (NOUVELLE, après révocation)
 *        define('RESEND_FROM', 'noreply@assokit.fr');
 *        define('RESEND_FROM_NAME', 'Assokit');
 *
 *   2. Ce fichier doit etre inclus via require_once dans les pages qui envoient.
 *
 * USAGE :
 *   send_transactional_email(
 *       'hakim@example.com',
 *       'Bienvenue sur Assokit !',
 *       render_email_welcome(['name' => 'Hakim', 'temp_password' => 'abc123'])
 *   );
 *
 * TEMPLATES disponibles :
 *   - render_email_welcome(...)          : Bienvenue nouveau user
 *   - render_email_password_reset(...)   : Mdp reset
 *   - render_email_invoice_sent(...)     : Envoi facture
 *   - render_email_invoice_paid(...)     : Confirmation paiement
 *   - render_email_org_suspended(...)    : Notification suspension asso
 *
 * SECURITE :
 *   - Les emails sont logges dans la table email_log (qui, quand, succes/echec)
 *   - La cle API n'apparait jamais dans les URLs ni dans les logs
 *   - Timeout de 10s pour ne pas bloquer la page si Resend est down
 */

if (!defined('RESEND_API_KEY')) {
    // Securite : si pas configure, ne jamais laisser un appel passer
    // Tu dois definir RESEND_API_KEY dans config.php
}

/**
 * Envoie un email transactionnel via Resend.
 *
 * @param string|array $to       Email destinataire (ou array de)
 * @param string       $subject  Sujet
 * @param string       $html     Corps HTML
 * @param array        $options  ['cc' => ..., 'bcc' => ..., 'reply_to' => ..., 'tag' => 'welcome']
 *
 * @return array  ['success' => bool, 'id' => string|null, 'error' => string|null]
 */
function send_transactional_email($to, string $subject, string $html, array $options = []): array {
    global $pdo;

    // Config check
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY || strpos(RESEND_API_KEY, 're_') !== 0) {
        error_log('[Resend] API key manquante ou invalide');
        return ['success' => false, 'id' => null, 'error' => 'Config Resend manquante'];
    }

    $from_email = !empty($options['from_email']) ? $options['from_email'] : (defined('RESEND_FROM') ? RESEND_FROM : 'noreply@assokit.fr');
    // from_name surchargeable (ex: nom de la mairie). L'email reste sur un domaine vérifié.
    $from_name = !empty($options['from_name']) ? $options['from_name'] : (defined('RESEND_FROM_NAME') ? RESEND_FROM_NAME : 'Assokit');
    // Nettoyer le from_name (retirer les caractères qui cassent l'en-tête)
    $from_name = trim(preg_replace('/[<>\r\n"]/', '', $from_name));
    if ($from_name === '') $from_name = 'Assokit';

    // Payload Resend
    $payload = [
        'from' => $from_name . ' <' . $from_email . '>',
        'to' => is_array($to) ? $to : [$to],
        'subject' => $subject,
        'html' => $html,
    ];

    if (!empty($options['cc'])) {
        $payload['cc'] = is_array($options['cc']) ? $options['cc'] : [$options['cc']];
    }
    if (!empty($options['bcc'])) {
        $payload['bcc'] = is_array($options['bcc']) ? $options['bcc'] : [$options['bcc']];
    }
    if (!empty($options['reply_to'])) {
        $payload['reply_to'] = $options['reply_to'];
    }
    if (!empty($options['tag'])) {
        $payload['tags'] = [['name' => 'category', 'value' => $options['tag']]];
    }

    // Appel API Resend
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $result_id = null;
    $error_msg = null;
    $success = false;

    if ($curl_error) {
        $error_msg = 'cURL: ' . $curl_error;
    } elseif ($http_code >= 200 && $http_code < 300) {
        $decoded = json_decode($response, true);
        $result_id = $decoded['id'] ?? null;
        $success = true;
    } else {
        $decoded = json_decode($response, true);
        $error_msg = 'HTTP ' . $http_code . ' : ' . ($decoded['message'] ?? $response);
    }

    // Log dans la table email_log (silencieux si table inexistante)
    try {
        $to_str = is_array($to) ? implode(', ', $to) : $to;
        $stmt = $pdo->prepare("
            INSERT INTO email_log
                (to_email, from_email, subject, tag, status, resend_id, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            mb_substr($to_str, 0, 255),
            $from_email,
            mb_substr($subject, 0, 255),
            $options['tag'] ?? null,
            $success ? 'sent' : 'failed',
            $result_id,
            $error_msg ? mb_substr($error_msg, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        // Table pas encore creee, on ignore
    }

    return [
        'success' => $success,
        'id' => $result_id,
        'error' => $error_msg,
    ];
}

// ====================================================================
// TEMPLATE DE BASE (wrapper commun a tous les emails)
// ====================================================================
function email_wrap(string $title, string $content_html, ?string $cta_text = null, ?string $cta_url = null): string {
    $acc = '#059669';
    $bg = '#FAFAF9';
    $ink = '#1C1917';
    $ink2 = '#44403C';
    $ink3 = '#78716C';
    $border = '#E7E5E4';

    $cta_html = '';
    if ($cta_text && $cta_url) {
        $cta_html = '
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0">
          <tr><td style="border-radius:10px;background:' . $acc . '">
            <a href="' . htmlspecialchars($cta_url) . '" target="_blank"
               style="display:inline-block;padding:13px 28px;color:#fff;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:600;border-radius:10px">
              ' . htmlspecialchars($cta_text) . '
            </a>
          </td></tr>
        </table>';
    }

    return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;color:' . $ink . ';line-height:1.55">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $bg . ';padding:30px 16px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;width:100%;background:#fff;border:1px solid ' . $border . ';border-radius:14px;overflow:hidden">
        <tr><td style="padding:28px 36px 8px">
          <div style="font-size:22px;font-weight:700;letter-spacing:-0.02em">
            asso<span style="color:' . $acc . '">kit</span>.
          </div>
          <div style="font-size:12px;color:' . $ink3 . ';margin-top:2px">L\'art de mener vos projets</div>
        </td></tr>
        <tr><td style="padding:16px 36px 30px;font-size:14.5px;color:' . $ink2 . '">
          ' . $content_html . '
          ' . $cta_html . '
        </td></tr>
        <tr><td style="padding:18px 36px;background:#FAFAF9;border-top:1px solid ' . $border . ';font-size:11.5px;color:' . $ink3 . ';text-align:center">
          Cet email vous a été envoyé par <strong style="color:' . $ink2 . '">Assokit</strong><br>
          <a href="https://assokit.fr" style="color:' . $acc . ';text-decoration:none">assokit.fr</a>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

// ====================================================================
// TEMPLATES SPECIFIQUES
// ====================================================================

/**
 * Email de bienvenue avec mdp temporaire
 * @param array $data ['first_name', 'last_name', 'email', 'temp_password', 'org_name'?, 'is_super_admin'?]
 */
function render_email_welcome(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $pwd = htmlspecialchars($data['temp_password'] ?? '');
    $is_sa = !empty($data['is_super_admin']);
    $org_name = $data['org_name'] ?? null;

    $intro = $is_sa
        ? '<strong>un compte super administrateur</strong> vient d\'être créé pour vous sur la plateforme Assokit.'
        : ($org_name
            ? 'votre compte sur <strong>' . htmlspecialchars($org_name) . '</strong> vient d\'être créé.'
            : 'votre compte Assokit vient d\'être créé.');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Bienvenue ' . $name . ' 👋</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>' . $intro . '</p>
      <p style="margin-top:18px"><strong>Vos identifiants de connexion :</strong></p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:10px 0;width:100%;font-family:Menlo,Consolas,monospace;font-size:13.5px">
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Email :</span> ' . $email . '</td></tr>
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Mot de passe :</span> <strong style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:4px;letter-spacing:0.05em">' . $pwd . '</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        🔐 À la première connexion, il vous sera demandé de changer ce mot de passe temporaire.
      </p>';

    return email_wrap('Bienvenue sur Assokit', $content, 'Se connecter maintenant', 'https://assokit.fr/connexion');
}

/**
 * Email de reset de mdp (nouveau mdp temporaire)
 * @param array $data ['first_name', 'email', 'temp_password']
 */
function render_email_password_reset(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $pwd = htmlspecialchars($data['temp_password'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Mot de passe réinitialisé 🔑</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>Votre mot de passe Assokit a été réinitialisé par un administrateur.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:10px 0;width:100%;font-family:Menlo,Consolas,monospace;font-size:13.5px">
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Email :</span> ' . $email . '</td></tr>
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Nouveau mot de passe :</span> <strong style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:4px;letter-spacing:0.05em">' . $pwd . '</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        🔐 Ce mot de passe est temporaire. À la prochaine connexion, un nouveau vous sera demandé.
      </p>
      <p style="font-size:13px;color:#78716C">
        ❗ Si vous n\'êtes pas à l\'origine de ce reset, contactez immédiatement l\'administrateur.
      </p>';

    return email_wrap('Mot de passe réinitialisé', $content, 'Se connecter', 'https://assokit.fr/connexion');
}

/**
 * Email d'envoi de facture
 * @param array $data ['org_name', 'invoice_number', 'amount_ttc', 'period_start', 'period_end', 'due_date']
 */
function render_email_invoice_sent(array $data): string {
    $org_name = htmlspecialchars($data['org_name'] ?? '');
    $number = htmlspecialchars($data['invoice_number'] ?? '');
    $amount = number_format((float) ($data['amount_ttc'] ?? 0), 2, ',', ' ');
    $period = date('d/m/Y', strtotime($data['period_start'])) . ' → ' . date('d/m/Y', strtotime($data['period_end']));
    $due = $data['due_date'] ? date('d/m/Y', strtotime($data['due_date'])) : '—';

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Nouvelle facture 📄</h1>
      <p>Bonjour,</p>
      <p>Voici la facture <strong>' . $number . '</strong> pour <strong>' . $org_name . '</strong>.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:14px">
        <tr><td style="padding:6px 8px;color:#78716C;width:40%">Numéro :</td><td style="padding:6px 8px"><strong>' . $number . '</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Période :</td><td style="padding:6px 8px">' . $period . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Échéance :</td><td style="padding:6px 8px">' . $due . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Montant TTC :</td><td style="padding:6px 8px"><strong style="color:#059669;font-size:16px">' . $amount . ' €</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C">
        💳 Règlement par virement bancaire. Vous recevrez une confirmation dès réception du paiement.
      </p>';

    return email_wrap('Nouvelle facture Assokit — ' . $number, $content);
}

/**
 * Email de confirmation de paiement recu
 * @param array $data ['org_name', 'invoice_number', 'amount_ttc', 'payment_date', 'payment_method']
 */
function render_email_invoice_paid(array $data): string {
    $org_name = htmlspecialchars($data['org_name'] ?? '');
    $number = htmlspecialchars($data['invoice_number'] ?? '');
    $amount = number_format((float) ($data['amount_ttc'] ?? 0), 2, ',', ' ');
    $date = date('d/m/Y', strtotime($data['payment_date']));
    $method = htmlspecialchars($data['payment_method'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Paiement reçu ✅</h1>
      <p>Bonjour,</p>
      <p>Nous confirmons la réception de votre règlement pour <strong>' . $org_name . '</strong>.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#ECFDF5;border:1px solid #6EE7B7;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:14px">
        <tr><td style="padding:6px 8px;color:#78716C;width:40%">Facture :</td><td style="padding:6px 8px"><strong>' . $number . '</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Montant :</td><td style="padding:6px 8px"><strong>' . $amount . ' €</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Date :</td><td style="padding:6px 8px">' . $date . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Méthode :</td><td style="padding:6px 8px">' . $method . '</td></tr>
      </table>
      <p>Merci pour votre confiance !</p>';

    return email_wrap('Paiement reçu — ' . $number, $content);
}

/**
 * Email de promotion en super admin (compte existant)
 * @param array $data ['first_name', 'email', 'promoted_by_name']
 */
function render_email_super_admin_promotion(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $by = htmlspecialchars($data['promoted_by_name'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Nouveau rôle : Super Admin 👑</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>Votre compte Assokit (<strong>' . $email . '</strong>) a été promu <strong>super administrateur</strong> de la plateforme' . ($by ? ' par ' . $by : '') . '.</p>
      <p>Vous conservez votre accès à votre association, et vous avez désormais accès au <strong>cockpit Super Admin</strong> :</p>
      <ul style="padding-left:20px;margin:10px 0;font-size:13.5px;color:#44403C">
        <li>Gestion de toutes les associations</li>
        <li>Vue sur tous les projets de la plateforme</li>
        <li>Suivi des abonnements et paiements</li>
        <li>Historique des actions</li>
      </ul>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        💡 À votre prochaine connexion, vous serez dirigé vers votre cockpit super admin. Un bouton "Mode asso" vous permettra de revenir à votre espace association.
      </p>';

    return email_wrap('Vous êtes désormais Super Admin Assokit', $content, 'Accéder au cockpit', 'https://assokit.fr/super-admin');
}
