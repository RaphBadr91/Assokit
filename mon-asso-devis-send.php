<?php
/**
 * mon-asso-devis-send.php — Envoi devis par email
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';
require_once __DIR__ . '/asso-invoice-email-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-devis'); exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('Session expirée.');
}

$quote_id = (int)($_POST['quote_id'] ?? 0);
if ($quote_id <= 0) { header('Location: /mon-asso-devis'); exit; }

// Charger devis
$stmt = $pdo->prepare("
    SELECT q.*, c.email AS client_email, c.display_name AS client_name,
           o.name AS org_name, o.billing_email AS org_email
    FROM asso_quotes q
    LEFT JOIN asso_clients c ON c.id = q.client_id
    LEFT JOIN organizations o ON o.id = q.org_id
    WHERE q.id = :id AND q.org_id = :org LIMIT 1
");
$stmt->execute([':id' => $quote_id, ':org' => $org_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); die('Devis introuvable.'); }

if (empty($quote['client_email'])) {
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Email client manquant.'];
    header('Location: /mon-asso-devis-edit?id=' . $quote_id); exit;
}

try {
    $public_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/devis/' . $quote['public_uuid'];

    $vars = [
        '{NOM_CLIENT}' => $quote['client_name'] ?? 'Madame, Monsieur',
        '{NUMERO}' => $quote['quote_number'],
        '{MONTANT_TTC}' => number_format($quote['amount_ttc_cents'] / 100, 2, ',', ' ') . ' €',
        '{DATE_EMISSION}' => date('d/m/Y', strtotime($quote['issued_at'])),
        '{DATE_VALIDITE}' => date('d/m/Y', strtotime($quote['expires_at'])),
        '{NOM_ASSO}' => $quote['org_name'] ?? 'Votre association',
        '{LIEN_PUBLIC}' => $public_url,
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

    // PDF en PJ
    $pdf_path_full = __DIR__ . ($quote['pdf_path'] ?? '');
    $attachment = null;
    if (!empty($quote['pdf_path']) && file_exists($pdf_path_full)) {
        $attachment = ['filename' => $quote['quote_number'] . '.pdf', 'content' => base64_encode(file_get_contents($pdf_path_full))];
    }

    ak_asso_send_resend($quote['client_email'], $subject, $body_html, $quote['org_email'], $attachment, $quote['org_name']);

    // Marquer le devis comme envoyé
    $pdo->prepare("UPDATE asso_quotes SET status = IF(status='draft', 'sent', status), sent_at = NOW(), sent_to_email = :em WHERE id = :id")
        ->execute([':em' => $quote['client_email'], ':id' => $quote_id]);

    $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => '📧 Devis envoyé à ' . $quote['client_email']];

} catch (Throwable $e) {
    error_log('[ASSO QUOTE SEND] ' . $e->getMessage());
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
}

header('Location: /mon-asso-devis-edit?id=' . $quote_id);
exit;
