<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-renouvellements.php (v2 PATCHED)
 * Job : Renouvellement mensuel des abonnements actifs
 * ============================================================
 * Changements v2 :
 *   - Scanne organizations.status='active' (au lieu de subscriptions)
 *   - invoices.org_id (au lieu de subscription_id)
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
    'details'   => ['invoices_created' => 0, 'errors' => []],
];

// ----- Récupération des orgas à renouveler
$sql = "
    SELECT
        o.id                   AS org_id,
        o.name                 AS org_name,
        o.plan,
        o.monthly_price_cents,
        o.current_period_start,
        o.current_period_end
    FROM organizations o
    WHERE o.status = 'active'
      AND o.current_period_end IS NOT NULL
      AND o.current_period_end <= CURDATE()
      AND o.monthly_price_cents > 0
    ORDER BY o.current_period_end ASC
    LIMIT 500
";

$orgs = $pdo->query($sql)->fetchAll();
$stats['processed'] = count($orgs);

foreach ($orgs as $org) {
    try {
        $pdo->beginTransaction();

        // ----- Récupération du destinataire
        $admin = cron_get_org_admin($pdo, (int) $org['org_id']);
        if (!$admin) {
            $pdo->rollBack();
            $stats['failed']++;
            $stats['details']['errors'][] = 'Org #' . $org['org_id'] . ' — aucun admin';
            continue;
        }

        // ----- 1) Nouvelle période : +1 mois
        $newPeriodStart = date('Y-m-d', strtotime($org['current_period_end'] . ' +1 day'));
        $newPeriodEnd   = date('Y-m-d', strtotime($newPeriodStart . ' +1 month -1 day'));
        $dueDate        = date('Y-m-d', strtotime($newPeriodStart . ' +15 days'));

        // ----- 2) Génération numéro + création facture
        $invoiceNumber = cron_generate_invoice_number($pdo);

        $ins = $pdo->prepare("
            INSERT INTO invoices
                (org_id, user_id, invoice_number, amount_cents, currency,
                 status, issued_at, due_date, period_start, period_end, created_at)
            VALUES
                (:oid, :uid, :num, :amt, 'EUR',
                 'pending', NOW(), :due, :ps, :pe, NOW())
        ");
        $ins->execute([
            ':oid' => (int) $org['org_id'],
            ':uid' => (int) $admin['id'],
            ':num' => $invoiceNumber,
            ':amt' => (int) $org['monthly_price_cents'],
            ':due' => $dueDate,
            ':ps'  => $newPeriodStart,
            ':pe'  => $newPeriodEnd,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        // ----- 3) Mise à jour organisation (nouvelle période)
        $pdo->prepare("
            UPDATE organizations SET
                current_period_start = :ps,
                current_period_end   = :pe
            WHERE id = :id
        ")->execute([
            ':ps' => $newPeriodStart,
            ':pe' => $newPeriodEnd,
            ':id' => (int) $org['org_id'],
        ]);

        $pdo->commit();
        $stats['details']['invoices_created']++;

        // ----- 4) Envoi email
        $vars = [
            'first_name'     => $admin['first_name'] ?: 'bonjour',
            'asso_name'      => $org['org_name'] ?: 'votre association',
            'amount'         => cron_format_euros((int) $org['monthly_price_cents']),
            'invoice_number' => $invoiceNumber,
            'period_end'     => cron_format_date_fr($newPeriodEnd),
            'billing_url'    => 'https://assokit.fr/facturation',
        ];
        $email = cron_email_render('renouvellement', $vars);

        $ok = cron_send_email(
            $pdo, $runId,
            $admin['email'], 'renouvellement',
            $email['subject'], $email['html'],
            [
                'user_id'    => (int) $admin['id'],
                'invoice_id' => $invoiceId,
                'org_id'     => (int) $org['org_id'],
            ]
        );

        if ($ok) {
            $stats['emails']++;
            $stats['succeeded']++;
        } else {
            // Facture bien créée, mais email raté → on compte en succès partiel
            $stats['succeeded']++;
            $stats['details']['errors'][] = 'Org #' . $org['org_id'] . ' — facture créée, email KO';
        }

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $stats['failed']++;
        $stats['details']['errors'][] = 'Org #' . ($org['org_id'] ?? '?') . ' — ' . $ex->getMessage();
        error_log('[CRON renouvellements] ' . $ex->getMessage());
    }
}

return $stats;
