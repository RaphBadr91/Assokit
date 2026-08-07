<?php
/**
 * cron_asso_invoice_dunning_check.php
 * Tourne 1x/jour à 8h.
 * Crée les notifications J+15, J+30, J+45 pour chaque facture
 * pending non payée et avec dunning_enabled=1.
 *
 * Appel via :
 *   curl https://assokit.fr/cron_asso_invoice_dunning_check.php?token=XXX
 */

require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
$expected_token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
if ($expected_token === '') { http_response_code(500); echo "CRON_TOKEN non configure\n"; exit; }
if (!hash_equals($expected_token, $token)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=UTF-8');

echo "=== CRON ASSO INVOICE DUNNING CHECK ===\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n\n";

$created = 0;
$skipped = 0;

try {
    // Récup factures pending non payées avec dunning_enabled=1
    $stmt = $pdo->query("
        SELECT i.id, i.invoice_number, i.issued_at, i.due_at, i.dunning_enabled
        FROM asso_invoices i
        WHERE i.status IN ('pending', 'overdue')
          AND i.dunning_enabled = 1
          AND i.paid_at IS NULL
    ");

    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Factures à analyser : " . count($invoices) . "\n\n";

    // Pour chaque facture, créer les 3 notifs si pas déjà créées
    // Calculé depuis date émission
    foreach ($invoices as $inv) {
        $issued = strtotime($inv['issued_at']);
        $now = time();
        $daysSinceIssue = floor(($now - $issued) / 86400);

        $levels = [
            1 => 15,  // J+15
            2 => 30,  // J+30
            3 => 45,  // J+45
        ];

        foreach ($levels as $level => $days) {
            // Si pas encore atteint le seuil, on skip
            if ($daysSinceIssue < $days) continue;

            // Vérifier si la notif existe déjà
            $check = $pdo->prepare("SELECT id FROM asso_invoice_notifications WHERE invoice_id = :inv AND level = :lvl LIMIT 1");
            $check->execute([':inv' => $inv['id'], ':lvl' => $level]);
            if ($check->fetchColumn()) {
                $skipped++;
                continue;
            }

            // Créer la notif
            $scheduled_at = date('Y-m-d H:i:s', $issued + ($days * 86400));
            $org_id_stmt = $pdo->prepare("SELECT org_id FROM asso_invoices WHERE id = :id");
            $org_id_stmt->execute([':id' => $inv['id']]);
            $org_id = (int) $org_id_stmt->fetchColumn();

            $ins = $pdo->prepare("
                INSERT INTO asso_invoice_notifications
                (invoice_id, org_id, level, scheduled_at, shown_at)
                VALUES (:inv, :org, :lvl, :sch, NOW())
            ");
            $ins->execute([
                ':inv' => $inv['id'],
                ':org' => $org_id,
                ':lvl' => $level,
                ':sch' => $scheduled_at,
            ]);
            $created++;
            echo "  ✓ Notif L{$level} créée pour {$inv['invoice_number']} (J+{$days} = {$scheduled_at})\n";
        }
    }

    echo "\n=== RÉCAP ===\n";
    echo "Notifications créées : $created\n";
    echo "Skipped (déjà existantes) : $skipped\n";

} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    error_log('[CRON DUNNING CHECK] ' . $e->getMessage());
}

echo "\n=== FIN ===\n";
