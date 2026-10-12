<?php
/**
 * cron_asso_invoice_dunning_send.php
 * Tourne toutes les 15 min.
 * Envoie les emails pour les notifs avec response='not_paid' et dunning_email_sent_at IS NULL
 * (cas où l'admin a confirmé "pas payée" + validé l'envoi du mail).
 *
 * Au plus une relance par facture et par passage, et jamais avant l'échéance : le stade
 * envoyé est celui que décide le moteur de /relances (relances-engine.php) d'après les
 * relances déjà parties, tous circuits confondus — dans l'ordre 1 → 2 → 3, 7 jours au
 * moins entre deux relances. Une relance validée trop tôt attend son heure ; une relance
 * déjà partie par un autre circuit (/relances, cron-relances) n'est pas renvoyée.
 *
 * Appel (tâche cron cPanel), au choix :
 *   /usr/local/bin/php /home/pura7044/public_html/cron_asso_invoice_dunning_send.php
 *   curl -s "https://assokit.fr/cron_asso_invoice_dunning_send?token=XXX"
 */

require_once __DIR__ . '/config.php';

// CLI (binaire php lancé par la tâche cron, aucune requête HTTP) : pas de jeton.
// Appel HTTP : jeton CRON_TOKEN obligatoire.
$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
if (!$is_cli) {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    $expected_token = defined('CRON_TOKEN') ? (string)CRON_TOKEN : '';
    if ($expected_token === '') { http_response_code(500); echo "CRON_TOKEN non configure\n"; exit; }
    if (!hash_equals($expected_token, $token)) {
        http_response_code(403); die('Forbidden');
    }
}

require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-invoice-email-helpers.php';
require_once __DIR__ . '/relances-engine.php';

if (!$is_cli) header('Content-Type: text/plain; charset=UTF-8');

echo "=== CRON ASSO INVOICE DUNNING SEND ===\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n\n";

$sent = 0;
$failed = 0;
$deferred = 0;
$waiting = 0;
$skipped = 0;

try {
    // Notifs à envoyer : admin a répondu "not_paid" + validé envoi + pas encore envoyé.
    // 50 envois au plus par passage. Une association en essai arrivée à son plafond d'envoi
    // est écartée pour le reste du passage : ses relances attendent sans prendre la place
    // de celles des autres associations (elles reviendraient sinon en tête à chaque passage).
    $blocked_orgs = [];
    $tried = [];
    while ($sent + $failed < 50) {
        // Une ligne par facture : la demande validée de plus haut niveau (les autres
        // demandes de la même facture sont écartées une fois la relance partie).
        $sql = "
        SELECT n.*, i.invoice_number, i.org_id AS invoice_org_id,
               DATEDIFF(CURDATE(), DATE(i.due_at)) AS days_overdue
        FROM asso_invoice_notifications n
        JOIN asso_invoices i ON i.id = n.invoice_id
        JOIN organizations o ON o.id = i.org_id
        WHERE n.response = 'not_paid'
          AND n.responded_at IS NOT NULL
          AND n.dunning_email_sent_at IS NULL
          AND n.dismissed_at IS NULL
          AND i.status IN ('pending', 'overdue')
          AND i.paid_at IS NULL
          AND i.dunning_enabled = 1
          AND " . ak_asso_org_active_sql('o') . "
          AND NOT EXISTS (
              SELECT 1 FROM asso_invoice_notifications n2
              WHERE n2.invoice_id = n.invoice_id
                AND n2.response = 'not_paid' AND n2.dunning_email_sent_at IS NULL AND n2.dismissed_at IS NULL
                AND (n2.level > n.level OR (n2.level = n.level AND n2.id > n.id))
          )"
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
            $inv_id = (int)$notif['invoice_id'];

            // Stade à envoyer maintenant, d'après le retard et les relances déjà parties.
            $h = ak_rel_invoice_history($pdo, [$inv_id])[$inv_id] ?? ['max_sent' => 0, 'days_since' => null];
            $dec = $notif['days_overdue'] === null ? ['stage' => 0, 'due_now' => false]
                 : ak_rel_decide((int)$notif['days_overdue'], (int)$h['max_sent'], $h['days_since']);
            if ((int)$dec['stage'] === 0) {
                // Facture pas encore échue : aucune relance, toutes ses demandes sont classées
                // (le cron du matin en proposera une le moment venu).
                $pdo->prepare("UPDATE asso_invoice_notifications SET dismissed_at = NOW() WHERE invoice_id = :inv AND dismissed_at IS NULL AND dunning_email_sent_at IS NULL AND (responded_at IS NULL OR response = 'not_paid')")
                    ->execute([':inv' => $inv_id]);
                echo "  {$notif['invoice_number']} : pas encore échue, rien à envoyer\n";
                $skipped++;
                continue;
            }
            if ((int)$notif['level'] <= (int)$h['max_sent']) {
                // Déjà relancée à ce stade par un autre circuit (/relances, cron-relances) :
                // les demandes validées de ce niveau ou en dessous sont classées sans envoi.
                $pdo->prepare("UPDATE asso_invoice_notifications SET dismissed_at = NOW() WHERE invoice_id = :inv AND dismissed_at IS NULL AND dunning_email_sent_at IS NULL AND response = 'not_paid' AND level <= :lvl")
                    ->execute([':inv' => $inv_id, ':lvl' => (int)$h['max_sent']]);
                echo "  {$notif['invoice_number']} : déjà relancée au stade {$h['max_sent']}, rien à envoyer\n";
                $skipped++;
                continue;
            }
            if (empty($dec['due_now'])) {
                // Validée trop tôt (moins de 7 jours après la précédente relance, ou retard
                // insuffisant pour ce stade) : elle partira à un prochain passage.
                echo "  {$notif['invoice_number']} : en attente ({$dec['reason']})\n";
                $waiting++;
                continue;
            }
            $level = (int)$dec['stage'];   // jamais au-delà de la demande validée (level > max_sent)
            $email_type = 'dunning' . $level; // dunning1, dunning2, dunning3
            echo "  Envoi {$email_type} pour {$notif['invoice_number']}... ";

            try {
                $result = ak_asso_invoice_send_email($pdo, $inv_id, $email_type, null);

                if ($result['success']) {
                    $pdo->prepare("UPDATE asso_invoice_notifications SET dunning_email_sent_at = NOW(), dunning_email_log_id = :lid, level = :lvl WHERE id = :id")
                        ->execute([':lid' => $result['log_id'], ':lvl' => $level, ':id' => $notif['id']]);
                    // Les autres demandes de cette facture (en attente ou validées) sont satisfaites par cet envoi.
                    $pdo->prepare("UPDATE asso_invoice_notifications SET dismissed_at = NOW() WHERE invoice_id = :inv AND id <> :id AND dismissed_at IS NULL AND dunning_email_sent_at IS NULL AND (responded_at IS NULL OR response = 'not_paid')")
                        ->execute([':inv' => $inv_id, ':id' => $notif['id']]);

                    // Mettre à jour le niveau de relance sur la facture
                    $pdo->prepare("UPDATE asso_invoices SET dunning_level = GREATEST(COALESCE(dunning_level, 0), :lvl) WHERE id = :id")
                        ->execute([':lvl' => $level, ':id' => $inv_id]);

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
    if ($waiting) echo "En attente de leur date (cadence des relances) : $waiting\n";
    if ($skipped) echo "Classées sans envoi (déjà relancées ou pas encore échues) : $skipped\n";

} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    error_log('[CRON DUNNING SEND] ' . $e->getMessage());
}

echo "\n=== FIN ===\n";
