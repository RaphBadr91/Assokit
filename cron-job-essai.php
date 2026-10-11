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
 * cron-trial-check est désormais seul à écrire, et il a repris J-7 et
 * J-0 au passage.
 *
 * Ce qui reste ici : la fin d'essai elle-même (organizations.status et
 * plan d'essai → Démarrage). Le bandeau, les badges et l'appli lisent
 * tous ak_org_plan_state() (trial-helpers.php), qui voit l'essai terminé
 * dès que la date est passée ; ce job retire les fonctionnalités PRO.
 *
 * Logique (la même que cron-trial-check, qui l'applique toutes les 10 min ;
 * ce job est le rattrapage pour les associations sans ancienne ligne) :
 *   - fin d'essai PASSÉE (trial_ends_at < NOW(), à l'instant près, comme
 *     cron-trial-check — plus le lendemain) ;
 *   - quel que soit le statut de l'association : en essai, suspendue à la
 *     main, réactivée « active », ou dont la ligne d'essai a été annulée à
 *     tort par l'ancien « Passer au gratuit » ;
 *   - ak_trial_expire() : « trial » → « suspended » (un autre statut n'est
 *     pas touché), lignes d'essai → Démarrage, ancien libellé recalé ;
 *   - jamais un client payant ; idempotent (une association traitée ne
 *     remplit plus les conditions au passage suivant).
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

require_once __DIR__ . '/trial-helpers.php';

// ----- Essais terminés à traiter (ak_trial_expired_org_ids, trial-helpers.php)
$orgs = ak_trial_expired_org_ids($pdo, 500);
$stats['processed'] = count($orgs);

foreach ($orgs as $org_id) {
    try {
        // ----- Expiration : association « trial » → « suspended », lignes d'essai → Démarrage
        if (ak_trial_expire($pdo, $org_id)) $stats['details']['suspended']++;
        $stats['succeeded']++;

        // ----- Les rappels J-7 / J-3 / J-0 ne partent plus d'ici.
        //
        // Ce cron et cron-trial-check les envoyaient tous les deux : une
        // association à J-3 recevait deux e-mails, l'un d'ici (à partir
        // de organizations) et l'autre de là (à partir de subscriptions).
        // cron-trial-check est désormais le seul à écrire aux adhérents,
        // et il a repris J-7 et J-0 au passage.
        //
        // Ce qui reste ici : la bascule de fin d'essai ci-dessus, que
        // cron-trial-check fait aussi (même fonction, même instant) ; ce
        // job la rattrape pour les associations qui n'ont pas d'ancienne
        // ligne subscriptions ou si cron-trial-check ne passe pas.
        //
        // Les colonnes notified_trial_j7/j3/j0 sont laissées en place :
        // elles portent l'historique de ce qui a déjà été envoyé, et les
        // effacer ferait repartir des rappels pour des essais terminés
        // depuis des mois.

    } catch (Throwable $ex) {
        $stats['failed']++;
        $stats['details']['errors'][] = 'Org #' . $org_id . ' — ' . $ex->getMessage();
        error_log('[CRON essai] ' . $ex->getMessage());
    }
}

return $stats;
