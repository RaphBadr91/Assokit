<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-essai.php
 * Job : bascule des essais expirés dans organizations.
 * ============================================================
 * Appelé uniquement par cron.php.
 *
 * Ce job envoyait aussi les rappels J-7 / J-3 / J-0. Il ne le fait
 * plus : cron-trial-check les envoyait en parallèle depuis
 * subscriptions, et une association à J-3 recevait deux e-mails.
 * C'est subscriptions qui fait foi sur le cycle de vie de l'essai —
 * c'est elle que lit includes-layout pour afficher le bandeau — donc
 * cron-trial-check est désormais seul à écrire, et il a repris J-7 et
 * J-0 au passage.
 *
 * Ce qui reste ici, et qui n'est PAS un doublon : la bascule de
 * organizations.status. cron-trial-check bascule subscriptions.status,
 * une autre colonne, lue ailleurs — organizations.status par les pages
 * super-admin et les statistiques fondateur, subscriptions.status par
 * le bandeau d'essai. Supprimer l'une laisserait un compte suspendu
 * d'un côté et actif de l'autre.
 *
 * Logique :
 *   - Scanne organizations.status = 'trial'
 *   - Si trial_ends_at dépassé → bascule en 'suspended'
 * ============================================================
 */

if (!defined('CRON_EXECUTING')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var PDO $pdo */
/** @var int $runId */

// Plus de compteurs j7 / j3 / j0 ni d'emails : ce job n'envoie plus
// rien. Les laisser à zéro dans le rapport du cron aurait fait croire
// à une panne d'envoi, alors que les rappels partent de
// cron-trial-check.
$stats = [
    'processed' => 0,
    'succeeded' => 0,
    'failed'    => 0,
    'details'   => ['suspended' => 0, 'errors' => []],
];

// ----- Récupération des essais actifs
$sql = "
    SELECT
        o.id                AS org_id,
        o.name              AS org_name,
        o.trial_ends_at,
        o.status,
        DATEDIFF(DATE(o.trial_ends_at), CURDATE()) AS days_left
    FROM organizations o
    WHERE o.status = 'trial'
      AND o.trial_ends_at IS NOT NULL
      AND o.deleted_at IS NULL
      AND o.slug NOT LIKE 'demo-%'
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

        // ----- Les rappels J-7 / J-3 / J-0 ne partent plus d'ici.
        //
        // Ce cron et cron-trial-check les envoyaient tous les deux : une
        // association à J-3 recevait deux e-mails, l'un d'ici (à partir
        // de organizations) et l'autre de là (à partir de subscriptions).
        // C'est subscriptions qui fait foi sur le cycle de vie de
        // l'essai — c'est elle que lit includes-layout pour afficher le
        // bandeau —, donc cron-trial-check est désormais le seul à
        // écrire aux adhérents, et il a repris J-7 et J-0 au passage.
        //
        // Ce qui reste ici : la bascule de organizations.status
        // ci-dessus. Elle n'est PAS un doublon de celle de
        // cron-trial-check, qui écrit subscriptions.status. Ce sont deux
        // colonnes différentes, lues à des endroits différents —
        // organizations.status par les pages super-admin et les
        // statistiques fondateur, subscriptions.status par le bandeau
        // d'essai. En supprimer une laisserait un compte suspendu d'un
        // côté et actif de l'autre.
        //
        // Les colonnes notified_trial_j7/j3/j0 sont laissées en place :
        // elles portent l'historique de ce qui a déjà été envoyé, et les
        // effacer ferait repartir des rappels pour des essais terminés
        // depuis des mois.
        $stats['succeeded']++;

    } catch (Throwable $ex) {
        $stats['failed']++;
        $stats['details']['errors'][] = 'Org #' . ($org['org_id'] ?? '?') . ' — ' . $ex->getMessage();
        error_log('[CRON essai] ' . $ex->getMessage());
    }
}

return $stats;
