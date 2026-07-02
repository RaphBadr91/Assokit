<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-impayes.php (v2 PATCHED)
 * Job : Relances factures impayées (J+3, J+7, J+15)
 * ============================================================
 * Changements v2 :
 *   - invoices.org_id (au lieu de subscription_id)
 *   - Récupération du destinataire via cron_get_org_admin()
 * ============================================================
 */

if (!defined('CRON_EXECUTING')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var PDO $pdo */
/** @var int $runId */

$stats = [
    'processed' => 0,
    'succeeded' => 0,
    'failed'    => 0,
    'emails'    => 0,
    'details'   => ['j3' => 0, 'j7' => 0, 'j15' => 0, 'errors' => []],
];

// ----- Récupération des factures en retard
$sql = "
    SELECT
        i.id            AS invoice_id,
        i.invoice_number,
        i.amount_cents,
        i.due_date,
        i.status        AS invoice_status,
        i.reminder_j3_sent_at,
        i.reminder_j7_sent_at,
        i.reminder_j15_sent_at,
        i.org_id,
        o.name          AS org_name,
        DATEDIFF(CURDATE(), i.due_date) AS days_overdue
    FROM invoices i
    INNER JOIN organizations o ON o.id = i.org_id
    WHERE i.status IN ('pending', 'overdue')
      AND i.due_date < CURDATE()
    ORDER BY i.due_date ASC
    LIMIT 500
";

$factures = $pdo->query($sql)->fetchAll();
$stats['processed'] = count($factures);

foreach ($factures as $f) {
    try {
        $days = (int) $f['days_overdue'];
        $type = null;
        $flagCol = null;

        if ($days >= 15 && empty($f['reminder_j15_sent_at'])) {
            $type = 'relance_j15';
            $flagCol = 'reminder_j15_sent_at';
        } elseif ($days >= 7 && $days < 15 && empty($f['reminder_j7_sent_at'])) {
            $type = 'relance_j7';
            $flagCol = 'reminder_j7_sent_at';
        } elseif ($days >= 3 && $days < 7 && empty($f['reminder_j3_sent_at'])) {
            $type = 'relance_j3';
            $flagCol = 'reminder_j3_sent_at';
        }

        if ($type === null) continue;

        // ----- Récupération du destinataire
        $admin = cron_get_org_admin($pdo, (int) $f['org_id']);
        if (!$admin) {
            $stats['failed']++;
            $stats['details']['errors'][] = 'Invoice #' . $f['invoice_id'] . ' — aucun admin pour org #' . $f['org_id'];
            continue;
        }

        // ----- Préparation email
        $vars = [
            'first_name'     => $admin['first_name'] ?: 'bonjour',
            'asso_name'      => $f['org_name'] ?: 'votre association',
            'amount'         => cron_format_euros((int) $f['amount_cents']),
            'due_date'       => cron_format_date_fr($f['due_date']),
            'invoice_number' => $f['invoice_number'],
            'billing_url'    => 'https://assokit.fr/facturation',
        ];
        $email = cron_email_render($type, $vars);

        $ok = cron_send_email(
            $pdo, $runId,
            $admin['email'], $type,
            $email['subject'], $email['html'],
            [
                'user_id'    => (int) $admin['id'],
                'invoice_id' => (int) $f['invoice_id'],
                'org_id'     => (int) $f['org_id'],
            ]
        );

        if ($ok) {
            // Marquer la relance comme envoyée
            $pdo->prepare("UPDATE invoices SET {$flagCol} = NOW() WHERE id = :id")
                ->execute([':id' => (int) $f['invoice_id']]);

            // Si J+15 : passer la facture en status='overdue'
            if ($type === 'relance_j15') {
                $pdo->prepare("UPDATE invoices SET status = 'overdue' WHERE id = :id AND status = 'pending'")
                    ->execute([':id' => (int) $f['invoice_id']]);
            }

            $stats['emails']++;
            $stats['succeeded']++;
            $palier = ($type === 'relance_j15') ? 'j15' : (($type === 'relance_j7') ? 'j7' : 'j3');
            $stats['details'][$palier]++;
        } else {
            $stats['failed']++;
            $stats['details']['errors'][] = 'Invoice #' . $f['invoice_id'] . ' — échec envoi ' . $type;
        }

    } catch (Throwable $e) {
        $stats['failed']++;
        $stats['details']['errors'][] = 'Invoice #' . ($f['invoice_id'] ?? '?') . ' — ' . $e->getMessage();
        error_log('[CRON impayes] ' . $e->getMessage());
    }
}

return $stats;
