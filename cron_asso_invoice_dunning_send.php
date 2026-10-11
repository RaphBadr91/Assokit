<?php
/**
 * cron_asso_invoice_dunning_send.php
 * Tourne toutes les 15 min.
 * Envoie les emails pour les notifs avec response='not_paid' et dunning_email_sent_at IS NULL
 * (cas où l'admin a confirmé "pas payée" + validé l'envoi du mail).
 *
 * Appel : curl https://assokit.fr/cron_asso_invoice_dunning_send.php?token=XXX
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-invoice-email-helpers.php';

$token = $_GET['token'] ?? '';
$expected_token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
if ($expected_token === '') { http_response_code(500); echo "CRON_TOKEN non configure\n"; exit; }
if (!hash_equals($expected_token, $token)) {
    http_response_code(403); die('Forbidden');
}

header('Content-Type: text/plain; charset=UTF-8');

echo "=== CRON ASSO INVOICE DUNNING SEND ===\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n\n";

$sent = 0;
$failed = 0;
$deferred = 0;

try {
    // Notifs à envoyer : admin a répondu "not_paid" + validé envoi + pas encore envoyé.
    // 50 envois au plus par passage. Une association en essai arrivée à son plafond d'envoi
    // est écartée pour le reste du passage : ses relances attendent sans prendre la place
    // de celles des autres associations (elles reviendraient sinon en tête à chaque passage).
    $blocked_orgs = [];
    $tried = [];
    while ($sent + $failed < 50) {
        $sql = "
        SELECT n.*, i.invoice_number, i.org_id AS invoice_org_id
        FROM asso_invoice_notifications n
        JOIN asso_invoices i ON i.id = n.invoice_id
        WHERE n.response = 'not_paid'
          AND n.responded_at IS NOT NULL
          AND n.dunning_email_sent_at IS NULL
          AND i.status IN ('pending', 'overdue')
          AND i.paid_at IS NULL"
            . ($blocked_orgs ? "\n          AND i.org_id NOT IN (" . implode(',', array_map('intval', array_keys($blocked_orgs))) . ")" : "")
            . ($tried ? "\n          AND n.id NOT IN (" . implode(',', array_map('intval', $tried)) . ")" : "") . "
        ORDER BY n.responded_at ASC
        LIMIT 50
    ";
        $notifs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$notifs) break;
        echo "Relances à envoyer : " . count($notifs) . "\n\n";

        foreach ($notifs as $notif) {
            if ($sent + $failed >= 50) break 2;
            if (isset($blocked_orgs[(int)$notif['invoice_org_id']])) continue;
            $tried[] = (int)$notif['id'];
            $email_type = 'dunning' . $notif['level']; // dunning1, dunning2, dunning3
            echo "  Envoi {$email_type} pour {$notif['invoice_number']}... ";

            try {
                $result = ak_asso_invoice_send_email($pdo, (int)$notif['invoice_id'], $email_type, null);

                if ($result['success']) {
                    $pdo->prepare("UPDATE asso_invoice_notifications SET dunning_email_sent_at = NOW(), dunning_email_log_id = :lid WHERE id = :id")
                        ->execute([':lid' => $result['log_id'], ':id' => $notif['id']]);

                    // Mettre à jour le niveau de relance sur la facture
                    $pdo->prepare("UPDATE asso_invoices SET dunning_level = :lvl WHERE id = :id")
                        ->execute([':lvl' => $notif['level'], ':id' => $notif['invoice_id']]);

                    echo "✅\n";
                    $sent++;
                } elseif (!empty($result['trial_limit'])) {
                    // Essai gratuit, plafond d'envoi atteint : la relance reste due, elle repartira à un prochain passage
                    echo "⏸ reportée (essai gratuit) : " . $result['message'] . "\n";
                    $blocked_orgs[(int)$notif['invoice_org_id']] = true;
                    $deferred++;
                    continue;
                } else {
                    echo "❌ " . $result['message'] . "\n";
                    $failed++;
                }
            } catch (Throwable $e) {
                echo "❌ EXCEPTION : " . $e->getMessage() . "\n";
                $failed++;
            }

            // Petit sleep pour pas saturer Resend
            usleep(200000); // 0.2s
        }
    }

    echo "\n=== RÉCAP ===\n";
    echo "Envoyés : $sent\n";
    echo "Échecs : $failed\n";
    if ($deferred) echo "Reportées (limite d'essai gratuit) : $deferred\n";

} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    error_log('[CRON DUNNING SEND] ' . $e->getMessage());
}

echo "\n=== FIN ===\n";
