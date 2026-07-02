<?php
/**
 * cron_asso_invoice_recurrences.php
 * --------------------------------------------------------------
 * CRON quotidien : génère les factures des récurrences dues.
 *
 * Configuration cPanel :
 *   0 7 * * * /usr/bin/php /home/pura7044/public_html/cron_asso_invoice_recurrences.php >> /home/pura7044/cron-recurrences.log 2>&1
 *
 * Sécurité : peut aussi être appelé via URL avec ?token=CRON_TOKEN
 * --------------------------------------------------------------
 */

// === En CLI ou avec token valide ===
$is_cli   = (php_sapi_name() === 'cli');
$token_ok = false;

require_once __DIR__ . '/config.php';

if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (defined('CRON_TOKEN') && hash_equals(CRON_TOKEN, $token)) {
        $token_ok = true;
    } else {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once __DIR__ . '/asso-recurrence-helpers.php';

$start = microtime(true);
$now   = date('Y-m-d H:i:s');

echo "==========================================\n";
echo "CRON Récurrences — {$now}\n";
echo "==========================================\n";

try {
    $stats = ak_recurrence_run_due($pdo);
} catch (Throwable $e) {
    echo "❌ Erreur fatale : " . $e->getMessage() . "\n";
    exit(1);
}

$elapsed = round(microtime(true) - $start, 2);

echo "✅ Factures générées : {$stats['generated']}\n";
echo "❌ Échecs : {$stats['failed']}\n";
echo "⏱  Durée : {$elapsed}s\n";

if (!empty($stats['invoices'])) {
    echo "\nFactures créées (IDs) :\n";
    foreach ($stats['invoices'] as $id) echo " - #{$id}\n";
}

if (!empty($stats['errors'])) {
    echo "\nErreurs détectées :\n";
    foreach ($stats['errors'] as $e) echo " ⚠️  {$e}\n";
}

// Notification admin par email si Resend configuré et événements à signaler
if (($stats['generated'] > 0 || $stats['failed'] > 0) && defined('RESEND_API_KEY') && RESEND_API_KEY) {
    $admin_email = defined('ADMIN_NOTIFY_EMAIL') ? ADMIN_NOTIFY_EMAIL : (defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : null);
    if ($admin_email) {
        $body = "<h2>CRON Récurrences Assokit</h2>"
              . "<p><strong>Date :</strong> {$now}</p>"
              . "<p><strong>Factures générées :</strong> {$stats['generated']}</p>"
              . "<p><strong>Échecs :</strong> {$stats['failed']}</p>";
        if (!empty($stats['errors'])) {
            $body .= "<h3>Erreurs</h3><ul>";
            foreach ($stats['errors'] as $e) $body .= "<li>" . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . "</li>";
            $body .= "</ul>";
        }
        $payload = [
            'from'    => 'Assokit CRON <' . (defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : 'noreply@assokit.fr') . '>',
            'to'      => [$admin_email],
            'subject' => "CRON Récurrences : {$stats['generated']} factures générées" . ($stats['failed'] ? " ({$stats['failed']} échecs)" : ''),
            'html'    => $body,
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
    }
}

echo "\n";
exit(0);
