<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-essai.php (v2 PATCHED)
 * Job : Rappels fin d'essai gratuit (J-7, J-3, J-0)
 * ============================================================
 * Appelé uniquement par cron.php.
 *
 * Changements v2 :
 *   - Scanne `organizations` (au lieu de `subscriptions`)
 *   - Utilise `trial_ends_at` (au lieu de `trial_end_at`)
 *   - Récupère le destinataire via cron_get_org_admin()
 *
 * Logique :
 *   - Scanne organizations.status = 'trial'
 *   - Calcule les jours restants avant trial_ends_at
 *       J-7 (entre 4 et 7 jours) → essai_j7  + notified_trial_j7 = 1
 *       J-3 (entre 1 et 3 jours) → essai_j3  + notified_trial_j3 = 1
 *       J-0 (aujourd'hui)        → essai_j0  + notified_trial_j0 = 1
 *   - Si trial_ends_at dépassé et status toujours 'trial' → bascule en 'suspended'
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
    'details'   => ['j7' => 0, 'j3' => 0, 'j0' => 0, 'suspended' => 0, 'errors' => []],
];

// ----- Récupération des essais actifs
$sql = "
    SELECT
        o.id                AS org_id,
        o.name              AS org_name,
        o.trial_ends_at,
        o.notified_trial_j7,
        o.notified_trial_j3,
        o.notified_trial_j0,
        o.status,
        DATEDIFF(DATE(o.trial_ends_at), CURDATE()) AS days_left
    FROM organizations o
    WHERE o.status = 'trial'
      AND o.trial_ends_at IS NOT NULL
      AND EXISTS (
            SELECT 1 FROM asso_subscriptions s
            JOIN asso_plans p ON p.id = s.plan_id
            WHERE s.org_id = o.id AND p.is_trial = 1
          )
    ORDER BY o.trial_ends_at ASC
    LIMIT 500
";

$orgs = $pdo->query($sql)->fetchAll();
$stats['processed'] = count($orgs);

foreach ($orgs as $org) {
    try {
        $days = (int) $org['days_left'];

        // ----- Expiration : trial dépassé, on bascule en 'suspended'
        if ($days < 0) {
            $pdo->prepare("UPDATE organizations SET status = 'suspended' WHERE id = :id AND status = 'trial'")
                ->execute([':id' => (int) $org['org_id']]);
            $stats['details']['suspended']++;
            $stats['succeeded']++;
            continue;
        }

        // ----- Détermination de la relance
        $type    = null;
        $flagCol = null;

        if ($days === 0 && empty($org['notified_trial_j0'])) {
            $type = 'essai_j0';
            $flagCol = 'notified_trial_j0';
        } elseif ($days >= 1 && $days <= 3 && empty($org['notified_trial_j3'])) {
            $type = 'essai_j3';
            $flagCol = 'notified_trial_j3';
        } elseif ($days >= 4 && $days <= 7 && empty($org['notified_trial_j7'])) {
            $type = 'essai_j7';
            $flagCol = 'notified_trial_j7';
        }

        if ($type === null) {
            continue; // Déjà notifié à ce palier ou pas encore l'heure
        }

        // ----- Récupération du destinataire (admin de l'orga)
        $admin = cron_get_org_admin($pdo, (int) $org['org_id']);
        if (!$admin) {
            $stats['failed']++;
            $stats['details']['errors'][] = 'Org #' . $org['org_id'] . ' — aucun admin trouvé';
            continue;
        }

        // ----- Préparation et envoi de l'email
        $vars = [
            'first_name'     => $admin['first_name'] ?: 'bonjour',
            'asso_name'      => $org['org_name'] ?: 'votre association',
            'trial_end_date' => cron_format_date_fr($org['trial_ends_at']),
            'upgrade_url'    => 'https://assokit.fr/tarifs',
        ];
        $email = cron_email_render($type, $vars);

        $ok = cron_send_email(
            $pdo, $runId,
            $admin['email'], $type,
            $email['subject'], $email['html'],
            [
                'user_id' => (int) $admin['id'],
                'org_id'  => (int) $org['org_id'],
            ]
        );

        if ($ok) {
            $pdo->prepare("UPDATE organizations SET {$flagCol} = 1 WHERE id = :id")
                ->execute([':id' => (int) $org['org_id']]);

            $stats['emails']++;
            $stats['succeeded']++;
            $stats['details'][substr($type, -2)]++; // j7, j3, j0
        } else {
            $stats['failed']++;
            $stats['details']['errors'][] = 'Org #' . $org['org_id'] . ' — échec envoi ' . $type;
        }

    } catch (Throwable $ex) {
        $stats['failed']++;
        $stats['details']['errors'][] = 'Org #' . ($org['org_id'] ?? '?') . ' — ' . $ex->getMessage();
        error_log('[CRON essai] ' . $ex->getMessage());
    }
}

return $stats;
