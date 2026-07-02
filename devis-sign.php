<?php
/**
 * devis-sign.php — Endpoint POST signature ou refus client
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /'); exit;
}

if (empty($_SESSION['csrf_token_public']) || !hash_equals($_SESSION['csrf_token_public'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('CSRF invalide.');
}

$uuid = $_POST['uuid'] ?? '';
if (empty($uuid) || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    http_response_code(404); die('Devis introuvable.');
}

$stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE public_uuid = :u LIMIT 1");
$stmt->execute([':u' => $uuid]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); die('Devis introuvable.'); }

if (!empty($quote['signed_at']) || !empty($quote['refused_at'])) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Ce devis a déjà été traité.'];
    header('Location: /devis/' . $uuid); exit;
}
if (strtotime($quote['expires_at']) < time()) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Ce devis a expiré.'];
    header('Location: /devis/' . $uuid); exit;
}

$action = $_POST['action'] ?? 'sign';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

try {
    if ($action === 'refuse') {
        $reason = trim((string)($_POST['refuse_reason'] ?? ''));
        $pdo->prepare("UPDATE asso_quotes SET status='refused', refused_at=NOW(), internal_notes = CONCAT(IFNULL(internal_notes,''), '\n\n[Refusé par client] ', :reason) WHERE id = :id")
            ->execute([':reason' => $reason, ':id' => $quote['id']]);

        // Notif email à l'asso
        notif_asso_quote_refused($pdo, $quote, $reason);

        $_SESSION['flash_public'] = ['type' => 'success', 'message' => 'Votre refus a été enregistré. Merci.'];
        header('Location: /devis/' . $uuid); exit;
    }

    // ── SIGNATURE ──
    $signature_name = trim((string)($_POST['signature_name'] ?? ''));
    $signature_type = in_array($_POST['signature_type'] ?? '', ['checkbox','drawn']) ? $_POST['signature_type'] : 'checkbox';

    if (empty($signature_name)) {
        $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Nom obligatoire.'];
        header('Location: /devis/' . $uuid); exit;
    }

    $signature_image_path = null;
    if ($signature_type === 'drawn' && !empty($_POST['signature_image'])) {
        $data = $_POST['signature_image'];
        if (preg_match('/^data:image\/png;base64,(.+)$/', $data, $m)) {
            $img_data = base64_decode($m[1]);
            if ($img_data && strlen($img_data) < 500000) { // max 500 Ko
                $dir = __DIR__ . '/uploads/asso-signatures';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $filename = 'sign-' . $quote['id'] . '-' . bin2hex(random_bytes(8)) . '.png';
                $full = $dir . '/' . $filename;
                file_put_contents($full, $img_data);
                @chmod($full, 0644);
                $signature_image_path = '/uploads/asso-signatures/' . $filename;
            }
        }
    }

    $pdo->prepare("UPDATE asso_quotes SET status='signed', signed_at=NOW(), signature_type=:type, signature_name=:name, signature_image_path=:path, signature_ip=:ip, signature_user_agent=:ua WHERE id=:id")
        ->execute([
            ':type' => $signature_type,
            ':name' => mb_substr($signature_name, 0, 200),
            ':path' => $signature_image_path,
            ':ip' => $ip,
            ':ua' => $ua,
            ':id' => $quote['id'],
        ]);

    // Régénérer le PDF avec la signature
    require_once __DIR__ . '/asso-invoice-helpers.php';
    require_once __DIR__ . '/asso-quote-helpers.php';
    try {
        ak_asso_quote_render_pdf($pdo, (int)$quote['id']);
    } catch (Throwable $e) {
        error_log('[ASSO QUOTE PDF AFTER SIGN] ' . $e->getMessage());
    }

    // Notif email à l'asso + au client
    notif_asso_quote_signed($pdo, $quote, $signature_name);

    $_SESSION['flash_public'] = ['type' => 'success', 'message' => '✓ Devis signé avec succès ! Merci pour votre confiance.'];
    header('Location: /devis/' . $uuid); exit;

} catch (Throwable $e) {
    error_log('[DEVIS SIGN] ' . $e->getMessage());
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    header('Location: /devis/' . $uuid); exit;
}

// ── Helpers locaux pour notifs email ──
function notif_asso_quote_signed(PDO $pdo, array $quote, string $signature_name): void
{
    if (!file_exists(__DIR__ . '/asso-invoice-email-helpers.php')) return;
    require_once __DIR__ . '/asso-invoice-email-helpers.php';

    $stmt = $pdo->prepare("SELECT name, billing_email FROM organizations WHERE id = :id");
    $stmt->execute([':id' => $quote['org_id']]);
    $org = $stmt->fetch();
    if (!$org || empty($org['billing_email'])) return;

    $public_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/devis/' . $quote['public_uuid'];
    $admin_link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/mon-asso-devis-edit?id=' . $quote['id'];
    $amount = number_format($quote['amount_ttc_cents'] / 100, 2, ',', ' ') . ' €';

    $subject = '✓ Devis ' . $quote['quote_number'] . ' signé par le client !';
    $body_html = "<p>Bonjour,</p>"
        . "<p><strong>" . htmlspecialchars($signature_name) . "</strong> vient de signer le devis <strong>" . htmlspecialchars($quote['quote_number']) . "</strong> d'un montant de <strong>$amount</strong>.</p>"
        . "<p>Vous pouvez maintenant le convertir en facture en 1 clic : <a href=\"" . htmlspecialchars($admin_link) . "\" style=\"color:#7E22CE;\">Voir le devis →</a></p>"
        . "<p style=\"font-size:12px;color:#6B7280;\">Lien public : <a href=\"" . htmlspecialchars($public_url) . "\">$public_url</a></p>";

    try { ak_asso_send_resend($org['billing_email'], $subject, $body_html, null, null, $org['name']); } catch (Throwable $e) {}

    // Email confirmation client
    $stmt = $pdo->prepare("SELECT email, display_name FROM asso_clients WHERE id = :id");
    $stmt->execute([':id' => $quote['client_id']]);
    $cli = $stmt->fetch();
    if ($cli && !empty($cli['email'])) {
        $subj_cli = 'Confirmation : devis ' . $quote['quote_number'] . ' signé';
        $body_cli = "<p>Bonjour " . htmlspecialchars($cli['display_name']) . ",</p>"
            . "<p>Nous confirmons la bonne réception de votre signature pour le devis <strong>" . htmlspecialchars($quote['quote_number']) . "</strong> ($amount).</p>"
            . "<p>Vous recevrez prochainement la facture correspondante.</p>"
            . "<p>Vous pouvez consulter le devis signé : <a href=\"" . htmlspecialchars($public_url) . "\">$public_url</a></p>"
            . "<p>Cordialement,<br>" . htmlspecialchars($org['name']) . "</p>";
        try { ak_asso_send_resend($cli['email'], $subj_cli, $body_cli, null, null, $org['name']); } catch (Throwable $e) {}
    }
}

function notif_asso_quote_refused(PDO $pdo, array $quote, string $reason): void
{
    if (!file_exists(__DIR__ . '/asso-invoice-email-helpers.php')) return;
    require_once __DIR__ . '/asso-invoice-email-helpers.php';

    $stmt = $pdo->prepare("SELECT name, billing_email FROM organizations WHERE id = :id");
    $stmt->execute([':id' => $quote['org_id']]);
    $org = $stmt->fetch();
    if (!$org || empty($org['billing_email'])) return;

    $admin_link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/mon-asso-devis-edit?id=' . $quote['id'];

    $subject = '✗ Devis ' . $quote['quote_number'] . ' refusé par le client';
    $body_html = "<p>Bonjour,</p>"
        . "<p>Le client a refusé le devis <strong>" . htmlspecialchars($quote['quote_number']) . "</strong>.</p>"
        . ($reason !== '' ? "<p><strong>Raison :</strong> " . nl2br(htmlspecialchars($reason)) . "</p>" : "")
        . "<p><a href=\"" . htmlspecialchars($admin_link) . "\" style=\"color:#7E22CE;\">Voir le devis →</a></p>";

    try { ak_asso_send_resend($org['billing_email'], $subject, $body_html, null, null, $org['name']); } catch (Throwable $e) {}
}
