<?php
/**
 * asso-invoice-email-helpers.php
 * Fonctions d'envoi d'email de factures (via Resend)
 */

if (!function_exists('ak_asso_invoice_send_email')) {

/**
 * Envoie une facture par email au client.
 *
 * @param PDO $pdo
 * @param int $invoice_id
 * @param string $email_type 'initial' | 'dunning1' | 'dunning2' | 'dunning3' | 'manual'
 * @param int|null $sent_by_user_id
 * @return array ['success' => bool, 'log_id' => int, 'message' => string]
 */
function ak_asso_invoice_send_email(PDO $pdo, int $invoice_id, string $email_type = 'initial', ?int $sent_by_user_id = null): array
{
    $stmt = $pdo->prepare("
        SELECT i.*, c.email AS client_email, c.display_name AS client_name,
               o.name AS org_name, o.billing_email AS org_email
        FROM asso_invoices i
        LEFT JOIN asso_clients c ON c.id = i.client_id
        LEFT JOIN organizations o ON o.id = i.org_id
        WHERE i.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $invoice_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) throw new RuntimeException('Facture introuvable');

    $recipient = $inv['client_email'];
    if (empty($recipient)) throw new RuntimeException('Email client vide');

    // CC = email de l'asso (récupéré depuis billing_email ou via JOIN sur organizations)
    $cc = !empty($inv['org_email']) ? $inv['org_email'] : null;

    // Récup paramètres
    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id LIMIT 1");
    $stmt->execute([':id' => $inv['org_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Récup template
    $tpl = ak_asso_get_email_template($settings, $email_type);

    // Variables à substituer
    $public_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/facture/' . $inv['public_uuid'];
    $vars = [
        '{NOM_CLIENT}'    => $inv['client_name'] ?? 'Madame, Monsieur',
        '{NUMERO}'        => $inv['invoice_number'],
        '{MONTANT_TTC}'   => number_format($inv['amount_ttc_cents'] / 100, 2, ',', ' ') . ' €',
        '{DATE_EMISSION}' => date('d/m/Y', strtotime($inv['issued_at'])),
        '{DATE_ECHEANCE}' => date('d/m/Y', strtotime($inv['due_at'])),
        '{NOM_ASSO}'      => $inv['org_name'] ?? 'Votre association',
        '{LIEN_PUBLIC}'   => $public_url,
        '{IBAN}'          => $settings['bank_iban'] ?? '—',
    ];

    $subject = strtr($tpl['subject'], $vars);
    $body_text = strtr($tpl['body'], $vars);

    // Convertir le body en HTML
    $body_html = nl2br(htmlspecialchars($body_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $body_html = preg_replace('/(https?:\/\/\S+)/', '<a href="$1" style="color:#059669;font-weight:600;">$1</a>', $body_html);

    // PDF en pièce jointe
    $pdf_path_full = __DIR__ . ($inv['pdf_path'] ?? '');
    $attachment = null;
    if (!empty($inv['pdf_path']) && file_exists($pdf_path_full)) {
        $attachment = [
            'filename' => $inv['invoice_number'] . '.pdf',
            'content'  => base64_encode(file_get_contents($pdf_path_full)),
        ];
    }

    // Log
    $log_stmt = $pdo->prepare("
        INSERT INTO asso_invoice_emails_log
        (invoice_id, org_id, recipient_email, cc_email, email_type, subject, status, sent_by_user_id)
        VALUES (:inv, :org, :em, :cc, :type, :subj, 'queued', :uid)
    ");
    $log_stmt->execute([
        ':inv' => $invoice_id,
        ':org' => $inv['org_id'],
        ':em'  => $recipient,
        ':cc'  => $cc,
        ':type' => $email_type,
        ':subj' => mb_substr($subject, 0, 255),
        ':uid' => $sent_by_user_id,
    ]);
    $log_id = (int) $pdo->lastInsertId();

    // Envoi via Resend
    try {
        $sent = ak_asso_send_resend($recipient, $subject, $body_html, $cc, $attachment, $inv['org_name']);

        $pdo->prepare("UPDATE asso_invoice_emails_log SET status='sent', sent_at=NOW() WHERE id = :id")
            ->execute([':id' => $log_id]);

        // Si email initial : marquer la facture comme envoyée
        if ($email_type === 'initial') {
            $pdo->prepare("UPDATE asso_invoices SET sent_at = NOW(), sent_to_email = :em WHERE id = :id")
                ->execute([':em' => $recipient, ':id' => $invoice_id]);
        }

        return ['success' => true, 'log_id' => $log_id, 'message' => 'Email envoyé à ' . $recipient];

    } catch (Throwable $e) {
        $pdo->prepare("UPDATE asso_invoice_emails_log SET status='failed', error_message = :err WHERE id = :id")
            ->execute([':err' => mb_substr($e->getMessage(), 0, 1000), ':id' => $log_id]);
        return ['success' => false, 'log_id' => $log_id, 'message' => 'Erreur : ' . $e->getMessage()];
    }
}

/**
 * Wrapper Resend (récupère la clé API depuis config / env).
 */
function ak_asso_send_resend(string $to, string $subject, string $html, ?string $cc, ?array $attachment, ?string $from_name): bool
{
    // Vérifier la clé Resend
    $api_key = defined('RESEND_API_KEY') ? RESEND_API_KEY : (getenv('RESEND_API_KEY') ?: '');
    if (empty($api_key)) {
        throw new RuntimeException('Clé Resend manquante (RESEND_API_KEY)');
    }

    $from_email = defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : 'noreply@assokit.fr';
    $from = ($from_name ? '"' . str_replace('"', '', $from_name) . '" <' . $from_email . '>' : $from_email);

    $payload = [
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if (!empty($cc)) $payload['cc'] = [$cc];
    if (!empty($attachment)) $payload['attachments'] = [$attachment];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException('cURL Resend : ' . $err);

    if ($http_code < 200 || $http_code >= 300) {
        $msg = $response;
        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['message'])) {
            $msg = $decoded['message'];
        }
        throw new RuntimeException('Resend HTTP ' . $http_code . ' : ' . $msg);
    }

    return true;
}

/**
 * Récupère le template d'email correspondant.
 * Utilise les templates personnalisés de l'asso si présents,
 * sinon les templates par défaut.
 */
function ak_asso_get_email_template(array $settings, string $type): array
{
    // Mapping type → colonne BDD
    $col_map = [
        'initial'   => ['email_invoice_subject', 'email_invoice_body'],
        'dunning1'  => ['email_relance1_subject', 'email_relance1_body'],
        'dunning2'  => ['email_relance2_subject', 'email_relance2_body'],
        'dunning3'  => ['email_relance3_subject', 'email_relance3_body'],
        'manual'    => ['email_relance1_subject', 'email_relance1_body'],
    ];

    $cols = $col_map[$type] ?? $col_map['initial'];
    $custom_subject = $settings[$cols[0]] ?? null;
    $custom_body    = $settings[$cols[1]] ?? null;

    // Templates par défaut
    $defaults = [
        'initial' => [
            'subject' => '{NOM_ASSO} — Votre facture {NUMERO}',
            'body' => "Bonjour {NOM_CLIENT},\n\n"
                . "Nous vous adressons ci-joint la facture {NUMERO} d'un montant de {MONTANT_TTC}.\n\n"
                . "Échéance de paiement : {DATE_ECHEANCE}\n\n"
                . "Coordonnées bancaires :\n{IBAN}\n\n"
                . "Vous pouvez également consulter cette facture en ligne :\n{LIEN_PUBLIC}\n\n"
                . "Cordialement,\n{NOM_ASSO}",
        ],
        'dunning1' => [
            'subject' => 'Relance — Votre facture {NUMERO} en attente',
            'body' => "Bonjour {NOM_CLIENT},\n\n"
                . "Sauf erreur de notre part, votre facture {NUMERO} d'un montant de {MONTANT_TTC} émise le {DATE_EMISSION} reste impayée.\n\n"
                . "Pourriez-vous nous indiquer la date à laquelle nous pouvons attendre votre règlement ?\n\n"
                . "Lien direct : {LIEN_PUBLIC}\n\n"
                . "Bien cordialement,\n{NOM_ASSO}",
        ],
        'dunning2' => [
            'subject' => '2e RELANCE — Facture {NUMERO}',
            'body' => "Bonjour {NOM_CLIENT},\n\n"
                . "Malgré notre précédente relance, votre facture {NUMERO} d'un montant de {MONTANT_TTC} reste impayée.\n\n"
                . "Nous vous remercions de procéder au règlement dans les meilleurs délais.\n\n"
                . "Lien direct : {LIEN_PUBLIC}\n\n"
                . "Cordialement,\n{NOM_ASSO}",
        ],
        'dunning3' => [
            'subject' => 'MISE EN DEMEURE — Facture {NUMERO}',
            'body' => "Bonjour {NOM_CLIENT},\n\n"
                . "Votre facture {NUMERO} d'un montant de {MONTANT_TTC} est impayée depuis plus de 45 jours.\n\n"
                . "Sans règlement de votre part dans les 8 jours, nous nous réservons le droit d'engager toute procédure utile au recouvrement de cette créance.\n\n"
                . "Lien direct : {LIEN_PUBLIC}\n\n"
                . "Cordialement,\n{NOM_ASSO}",
        ],
        'manual' => [
            'subject' => 'Rappel — Facture {NUMERO}',
            'body' => "Bonjour {NOM_CLIENT},\n\n"
                . "Nous vous rappelons votre facture {NUMERO} d'un montant de {MONTANT_TTC} émise le {DATE_EMISSION}.\n\n"
                . "Lien direct : {LIEN_PUBLIC}\n\n"
                . "Cordialement,\n{NOM_ASSO}",
        ],
    ];

    $default = $defaults[$type] ?? $defaults['initial'];

    return [
        'subject' => !empty($custom_subject) ? $custom_subject : $default['subject'],
        'body'    => !empty($custom_body) ? $custom_body : $default['body'],
    ];
}

} // fin if
