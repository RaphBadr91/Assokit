<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-impayes.php (v2 PATCHED)
 * Job : Relances factures impayées (J+3, J+7, J+15)
 * ============================================================
 * Changements v2 :
 *   - invoices.org_id (au lieu de subscription_id)
 *   - Récupération du destinataire via cron_get_org_admin()
 * v3 :
 *   - retard compté au plus tôt 15 jours après l'ÉMISSION : une facture
 *     émise avec une échéance déjà passée ne reçoit jamais la « dernière
 *     relance avant suspension » le jour même ;
 *   - montant et échéance du cockpit Factures (amount_ttc_cents, due_at)
 *     s'ils existent, sinon ceux du cron (amount_cents, due_date) ;
 *   - pas de relance pour une facture émise après la résiliation, ni pour
 *     une association en essai ou offerte (la facture est listée dans
 *     « ignorees »), ni sur une formule gratuite (listée dans « errors » :
 *     le Fondateur régularise la facture ou la formule) ;
 *   - jamais de relance d'une facture spécimen (mode test).
 * ============================================================
 */

if (!defined('CRON_EXECUTING')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var PDO $pdo */
/** @var int $runId */

require_once __DIR__ . '/trial-helpers.php';

$stats = [
    'processed' => 0,
    'succeeded' => 0,
    'failed'    => 0,
    'emails'    => 0,
    'details'   => ['j3' => 0, 'j7' => 0, 'j15' => 0, 'errors' => [], 'ignorees' => []],
];

// ----- Colonnes réelles de invoices (cockpit Factures et cron n'écrivent pas les mêmes)
$ic = ak_db_columns($pdo, 'invoices');
$amountSql = isset($ic['amount_ttc_cents']) ? 'COALESCE(NULLIF(i.amount_ttc_cents, 0), i.amount_cents)' : 'i.amount_cents';
$dueSql    = isset($ic['due_at']) ? 'COALESCE(DATE(i.due_at), i.due_date)' : 'i.due_date';
$issuedSql = isset($ic['issued_at']) ? 'i.issued_at' : (isset($ic['created_at']) ? 'i.created_at' : 'NULL');
// Échéance effective : jamais moins de 15 jours après l'émission
$effDueSql = "GREATEST($dueSql, COALESCE(DATE($issuedSql) + INTERVAL 15 DAY, $dueSql))";
$testSql   = isset($ic['is_test_mode']) ? 'AND COALESCE(i.is_test_mode, 0) = 0' : '';

// ----- Récupération des factures en retard
// Seules les factures portant une échéance « due_date » (celles du cron de renouvellement) sont relancées.
$sql = "
    SELECT
        i.id            AS invoice_id,
        i.invoice_number,
        $amountSql      AS amount_cents,
        $effDueSql      AS due_date,
        $issuedSql      AS issued_at,
        i.status        AS invoice_status,
        i.reminder_j3_sent_at,
        i.reminder_j7_sent_at,
        i.reminder_j15_sent_at,
        i.org_id,
        o.name          AS org_name,
        DATEDIFF(CURDATE(), $effDueSql) AS days_overdue
    FROM invoices i
    INNER JOIN organizations o ON o.id = i.org_id
    WHERE i.status IN ('pending', 'overdue')
      AND i.due_date IS NOT NULL
      AND $effDueSql < CURDATE()
      $testSql
      AND o.deleted_at IS NULL
      AND o.slug NOT LIKE 'demo-%'
      AND o.status <> 'cancelled'
      -- Ne pas relancer les organisations payant via Stripe (elles sont facturees automatiquement)
      AND o.id NOT IN (
          SELECT s.org_id FROM asso_subscriptions s
          WHERE s.payment_mode = 'stripe' AND s.status <> 'cancelled' AND s.org_id IS NOT NULL
      )
    ORDER BY $effDueSql ASC
    LIMIT 500
";

$factures = $pdo->query($sql)->fetchAll();
$stats['processed'] = count($factures);

$subs = [];
foreach ($factures as $f) {
    try {
        // ----- Abonnement réel : rien n'est dû par une association résiliée avant l'émission,
        // en essai, offerte ou sur une formule gratuite
        $orgId = (int) $f['org_id'];
        if (!array_key_exists($orgId, $subs)) $subs[$orgId] = cron_org_billing_state($pdo, $orgId);
        $sub = $subs[$orgId];
        $skip = null;
        $toCheck = null;
        if ($sub) {
            if ($sub['status'] === 'cancelled' && $sub['cancelled_at'] && !empty($f['issued_at'])
                && strtotime($f['issued_at']) >= strtotime($sub['cancelled_at'])) {
                $skip = 'émise après la résiliation du ' . cron_format_date_fr($sub['cancelled_at']);
            } elseif ($sub['is_trial']) {
                $skip = 'association en essai';
            } elseif ($sub['payment_mode'] === 'free_grant') {
                $skip = 'abonnement offert';
            } elseif ($sub['status'] === 'active' && !$sub['is_paid']) {
                // Facture émise avant le passage en gratuit (ou ligne Démarrage créée d'office) : pas de
                // relance, mais le Fondateur tranche (payée, annulée par avoir, formule à remettre)
                $toCheck = 'formule gratuite (' . $sub['name'] . ') mais facture impayée de '
                         . cron_format_euros((int) $f['amount_cents']) . ' : pas de relance, à régulariser';
            }
        }
        if ($skip !== null) {
            $stats['details']['ignorees'][] = 'Invoice #' . $f['invoice_id'] . ' (' . $f['invoice_number'] . ') — ' . $skip . ' : pas de relance, à vérifier';
            continue;
        }
        if ($toCheck !== null) {
            $stats['failed']++;
            $stats['details']['errors'][] = 'Invoice #' . $f['invoice_id'] . ' (' . $f['invoice_number'] . ') — ' . $toCheck;
            continue;
        }

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
            'billing_url'    => cron_billing_url(),
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
