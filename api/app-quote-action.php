<?php
/**
 * api/app-quote-action.php — Actions sur un devis depuis l'app (natif).
 * Reproduit fidèlement mon-asso-devis-send.php (email + PJ PDF) et
 * mon-asso-devis-convert.php (conversion en facture). Renvoie du JSON.
 *
 * Actions : send (email au client), convert (devis signé → facture).
 * Rôle requis : admin ou coordinateur (parité site).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';
@require_once __DIR__ . '/../asso-quote-helpers.php';
@require_once __DIR__ . '/../asso-invoice-email-helpers.php';
@require_once __DIR__ . '/../rate-limit-helper.php';

if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Rôle insuffisant.');
}

$quote_id = (int) ($input['quote_id'] ?? 0);
$action   = (string) ($input['action'] ?? '');
if ($quote_id <= 0) app_fail(422, 'invalid', 'Devis manquant.');

$st = $pdo->prepare("
    SELECT q.*, c.email AS client_email, c.display_name AS client_name,
           o.name AS org_name, o.billing_email AS org_email
    FROM asso_quotes q
    LEFT JOIN asso_clients c ON c.id = q.client_id
    LEFT JOIN organizations o ON o.id = q.org_id
    WHERE q.id = ? AND q.org_id = ? LIMIT 1
");
$st->execute([$quote_id, $org_id]);
$quote = $st->fetch(PDO::FETCH_ASSOC);
if (!$quote) app_fail(404, 'not_found', 'Devis introuvable.');

/* ── Envoi du devis par email (mêmes contenu et effets que le site) ── */
if ($action === 'send') {
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_quote_send', 10, 60, (string) $uid);
    if (!function_exists('ak_asso_send_resend')) app_fail(500, 'unavailable', 'Envoi indisponible.');
    if (empty($quote['client_email'])) {
        app_fail(422, 'no_email', 'Ce client n\'a pas d\'adresse email : ajoutez-la puis réessayez.');
    }
    try {
        $public_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/devis/' . $quote['public_uuid'];
        $vars = [
            '{NOM_CLIENT}'   => $quote['client_name'] ?? 'Madame, Monsieur',
            '{NUMERO}'       => $quote['quote_number'],
            '{MONTANT_TTC}'  => number_format($quote['amount_ttc_cents'] / 100, 2, ',', ' ') . ' €',
            '{DATE_EMISSION}' => date('d/m/Y', strtotime($quote['issued_at'])),
            '{DATE_VALIDITE}' => date('d/m/Y', strtotime($quote['expires_at'])),
            '{NOM_ASSO}'     => $quote['org_name'] ?? 'Votre association',
            '{LIEN_PUBLIC}'  => $public_url,
        ];
        $subject = strtr('{NOM_ASSO} — Devis {NUMERO}', $vars);
        $body_text = strtr(
            "Bonjour {NOM_CLIENT},\n\n"
            . "Veuillez trouver ci-joint notre devis {NUMERO} d'un montant de {MONTANT_TTC}.\n\n"
            . "Validité : jusqu'au {DATE_VALIDITE}\n\n"
            . "Pour accepter ce devis en ligne, cliquez ici :\n{LIEN_PUBLIC}\n\n"
            . "Cordialement,\n{NOM_ASSO}",
            $vars
        );
        $body_html = nl2br(htmlspecialchars($body_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $body_html = preg_replace('/(https?:\/\/\S+)/', '<a href="$1" style="color:#7E22CE;font-weight:600;">$1</a>', $body_html);

        $pdf_full = __DIR__ . '/..' . ($quote['pdf_path'] ?? '');
        $attachment = null;
        if (!empty($quote['pdf_path']) && file_exists($pdf_full)) {
            $attachment = ['filename' => $quote['quote_number'] . '.pdf', 'content' => base64_encode(file_get_contents($pdf_full))];
        }

        ak_asso_send_resend($quote['client_email'], $subject, $body_html, $quote['org_email'], $attachment, $quote['org_name']);

        $pdo->prepare("UPDATE asso_quotes SET status = IF(status='draft', 'sent', status), sent_at = NOW(), sent_to_email = ? WHERE id = ?")
            ->execute([$quote['client_email'], $quote_id]);

        echo json_encode(['ok' => true, 'id' => $quote_id, 'message' => 'Devis envoyé à ' . $quote['client_email'] . '.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[app-quote-action send] ' . $e->getMessage());
        app_fail(500, 'server', 'Envoi impossible : ' . $e->getMessage());
    }
    exit;
}

/* ── Conversion du devis signé en facture ──────────────────────────── */
if ($action === 'convert') {
    if (!function_exists('ak_asso_quote_convert_to_invoice')) app_fail(500, 'unavailable', 'Conversion indisponible.');
    if ((string) $quote['status'] !== 'signed') {
        app_fail(409, 'state', 'Le devis doit être signé par le client pour être converti en facture.');
    }
    if (!empty($quote['converted_to_invoice_id'])) {
        app_fail(409, 'state', 'Ce devis a déjà été converti en facture.');
    }
    try {
        $invoice = ak_asso_quote_convert_to_invoice($pdo, $quote_id, $uid);
        echo json_encode([
            'ok' => true, 'id' => $quote_id,
            'invoice_id' => (int) ($invoice['id'] ?? 0),
            'message' => 'Facture ' . ($invoice['invoice_number'] ?? '') . ' créée à partir du devis.',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[app-quote-action convert] ' . $e->getMessage());
        app_fail(500, 'server', 'Conversion impossible : ' . $e->getMessage());
    }
    exit;
}

app_fail(400, 'action', 'Action inconnue.');
