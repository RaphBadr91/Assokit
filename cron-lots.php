<?php
/**
 * cron-lots.php — Faire travailler les crons par lots, sans se marcher dessus.
 * ------------------------------------------------------------------
 * Le problème que ça résout :
 *
 * Nos crons parcouraient toutes les associations d'une traite, avec un
 * appel à l'IA ou un e-mail pour chacune. À 200 associations ça passe.
 * À 10 000, un seul appel de 5 secondes par association fait 14 heures
 * de travail dans un seul processus — l'hébergeur le coupe bien avant,
 * et la coupure tombe au milieu de la liste, toujours au même endroit :
 * les dernières associations ne sont jamais servies.
 *
 * Trois choses manquaient, que ce fichier apporte :
 *
 *   1. un budget — on s'arrête de soi-même, entre deux associations et
 *      jamais au milieu de l'une, avant que l'hébergeur ne coupe ;
 *   2. un verrou — un cron relancé toutes les 10 minutes finirait par
 *      se superposer à lui-même si un passage dure plus longtemps, et
 *      deux passages simultanés enverraient l'e-mail deux fois ;
 *   3. de quoi reprendre — sans curseur à tenir : chaque cron demande
 *      à sa requête ce qui RESTE à faire. Voir plus bas.
 *
 * Sur la reprise, justement. On aurait pu mémoriser « j'en étais à
 * l'association 4 300 ». C'est fragile : il faut remettre le compteur à
 * zéro au bon moment, et une association créée entre-temps décale tout.
 *
 * Nos crons savent déjà reconnaître ce qui est fait — un rapport porte
 * sa date d'envoi, une alerte de subvention est notée dans
 * grant_alert_sent, une fin d'essai dans notified_trial_j7. Il suffit
 * donc que la requête exclue ce qui est déjà traité et rende les N
 * premiers restants. Chaque passage avance, aucun compteur à tenir, et
 * un passage interrompu ne perd rien : ce qu'il a fait reste marqué,
 * le reste sera repris au passage suivant.
 *
 * Conséquence pratique : ces crons doivent désormais tourner SOUVENT
 * (toutes les 10 ou 15 minutes) plutôt qu'une fois par jour. Quand il
 * n'y a rien à faire, la requête ne renvoie rien et le passage se
 * termine en quelques millisecondes.
 * ------------------------------------------------------------------
 */

if (!defined('AK_LOT_SECONDES')) define('AK_LOT_SECONDES', 240);  // 4 min de travail utile
if (!defined('AK_LOT_MAX'))      define('AK_LOT_MAX', 200);       // et pas plus de 200 éléments

/** État du lot en cours. Un processus de cron n'en mène qu'un. */
$GLOBALS['ak_lot'] = null;

/**
 * Ouvre un lot : prend le verrou et arme le budget.
 *
 * @param array $opts secondes, max, verrou (bool)
 * @return bool false si un autre passage tourne déjà — il faut alors
 *              sortir sans rien faire, ce n'est pas une erreur.
 */
function ak_lot_demarrer(PDO $pdo, string $job, array $opts = []): bool
{
    $secondes = (int) ($opts['secondes'] ?? AK_LOT_SECONDES);
    $max      = (int) ($opts['max'] ?? AK_LOT_MAX);

    $verrou = null;
    if (($opts['verrou'] ?? true)) {
        // GET_LOCK plutôt qu'un fichier ou une ligne en base : le verrou
        // tombe tout seul quand la connexion se ferme, donc un passage
        // tué par l'hébergeur ne laisse pas la porte fermée derrière lui.
        //
        // Le nom est préfixé du nom de la base : sur un hébergement
        // mutualisé, GET_LOCK porte sur tout le serveur MySQL et pas sur
        // notre base — sans préfixe, on pourrait bloquer le cron d'un
        // autre client, ou l'inverse.
        try {
            $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $verrou = mb_substr($base . ':' . $job, 0, 64);
            $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $st->execute([$verrou]);
            if ((int) $st->fetchColumn() !== 1) {
                echo "[" . date('H:i:s') . "] Un autre passage de « $job » tourne déjà — on laisse la main.\n";
                return false;
            }
        } catch (Throwable $e) {
            // Sans verrou plutôt que pas de cron : on le dit, et on passe.
            echo "[" . date('H:i:s') . "] Verrou indisponible ({$e->getMessage()}) — on continue sans.\n";
            $verrou = null;
        }
    }

    $GLOBALS['ak_lot'] = [
        'job'      => $job,
        'debut'    => microtime(true),
        'secondes' => $secondes,
        'max'      => $max,
        'faits'    => 0,
        'verrou'   => $verrou,
        'arret'    => '',
    ];
    echo "[" . date('Y-m-d H:i:s') . "] « $job » — budget : {$max} éléments ou {$secondes}s\n";
    return true;
}

/** Nombre d'éléments qu'il reste le droit de traiter (pour le LIMIT SQL). */
function ak_lot_place(): int
{
    $l = $GLOBALS['ak_lot'] ?? null;
    return $l ? max(0, $l['max'] - $l['faits']) : 0;
}

/**
 * Reste-t-il du budget ? À tester en tête de boucle.
 *
 * Le temps est vérifié AVANT de commencer un élément et jamais pendant :
 * un e-mail à moitié envoyé ou un appel à l'IA interrompu coûterait plus
 * cher que d'attendre le passage suivant.
 */
function ak_lot_encore(): bool
{
    $l = &$GLOBALS['ak_lot'];
    if (!$l) return false;
    if ($l['faits'] >= $l['max']) {
        $l['arret'] = 'lot plein';
        return false;
    }
    if (microtime(true) - $l['debut'] >= $l['secondes']) {
        $l['arret'] = 'temps écoulé';
        return false;
    }
    return true;
}

/** Compte un élément traité. */
function ak_lot_fait(int $n = 1): void
{
    if ($GLOBALS['ak_lot']) $GLOBALS['ak_lot']['faits'] += $n;
}

/**
 * Ferme le lot : rend le verrou et résume le passage.
 *
 * @param bool $reste vrai s'il reste du travail (le cron le sait mieux
 *                    que nous : il a vu si sa requête était pleine).
 */
function ak_lot_terminer(PDO $pdo, bool $reste = false): void
{
    $l = $GLOBALS['ak_lot'] ?? null;
    if (!$l) return;

    if ($l['verrou']) {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$l['verrou']]); }
        catch (Throwable $e) { /* la connexion se ferme, le verrou tombe */ }
    }

    $duree = round(microtime(true) - $l['debut'], 1);
    echo "[" . date('Y-m-d H:i:s') . "] « {$l['job']} » — {$l['faits']} traité(s) en {$duree}s";
    if ($l['arret'] !== '') echo " · arrêt : {$l['arret']}";
    echo "\n";

    if ($reste || $l['arret'] !== '') {
        // Le dire explicitement : un opérateur qui lit « 200 traités »
        // sans cette ligne croit le travail terminé.
        echo "  → Il reste du travail. Le prochain passage reprendra là où celui-ci s'arrête.\n";
    }
    $GLOBALS['ak_lot'] = null;
}

// ------------------------------------------------------------------
// Ne pas envoyer deux fois le même message
// ------------------------------------------------------------------
// La plupart de nos crons reconnaissent déjà leur travail fait : une
// relance est dans asso_invoice_emails_log, une alerte de subvention
// dans grant_alert_sent. Ceux-là n'ont besoin de rien de plus.
//
// Il en restait un qui ne notait rien et se contentait de tourner une
// fois par jour : tant qu'on ne le lançait qu'une fois, ça tenait. Passé
// au quart d'heure, il aurait envoyé le même rappel 96 fois. D'où ce
// carnet, sur le modèle de grant_alert_sent.
//
// On RÉSERVE avant d'envoyer, on ne vérifie pas après : c'est
// l'insertion elle-même, avec sa clé unique, qui tranche. Deux passages
// simultanés ne peuvent donc pas gagner tous les deux, là où un
// « SELECT puis INSERT » leur laisserait le temps de se croiser.

/**
 * Réserve un envoi. Renvoie false si quelqu'un l'a déjà réservé.
 *
 * @param string $cle Ce qui rend l'envoi unique — « j3:2026-09-20 ».
 *                    Y mettre la date évite de bloquer à jamais un
 *                    rappel qui doit repartir au prochain essai.
 */
function ak_envoi_reserver(PDO $pdo, string $job, int $org_id, string $cle): bool
{
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO cron_envois (job, org_id, cle) VALUES (?, ?, ?)");
        $st->execute([$job, $org_id, $cle]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        // Table absente (migration pas passée) : on laisse passer l'envoi
        // plutôt que de rendre le cron muet, et on le signale.
        echo "  ! Carnet d'envois indisponible ({$e->getMessage()}) — envoi non dédoublonné.\n";
        return true;
    }
}

// ------------------------------------------------------------------
// Le tour de rôle
// ------------------------------------------------------------------
// Pour les crons dont le travail fait est marqué ailleurs que sur
// l'association — une relance est notée sur la facture, une alerte sur
// la subvention. On ne peut donc pas demander à la base « celles qu'il
// reste à voir » : elle les renverrait toutes, et chaque passage
// épuiserait son lot sur les mêmes deux cents.
//
// On sert d'abord celles qui attendent depuis le plus longtemps.

/**
 * Parmi $cibles, les $n vues il y a le plus longtemps. Celles jamais
 * vues passent en tête.
 *
 * Le tri se fait en PHP : la liste des candidates vient déjà d'être
 * chargée par l'appelant, et un IN() de dix mille identifiants pour la
 * retrier côté base coûterait plus cher que de la parcourir ici.
 */
function ak_tour_prochains(PDO $pdo, string $job, array $cibles, int $n): array
{
    if ($n <= 0 || !$cibles) return [];
    $vus = [];
    try {
        $st = $pdo->prepare("SELECT cible, vu_at FROM cron_tours WHERE job = ?");
        $st->execute([$job]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$o, $d]) $vus[(int) $o] = (string) $d;
    } catch (Throwable $e) {
        // Table absente : pas de tour de rôle, mais le cron tourne quand
        // même — sur les premières de la liste, comme avant.
        echo "  ! Tour de rôle indisponible ({$e->getMessage()}) — ordre naturel.\n";
        return array_slice($cibles, 0, $n);
    }
    usort($cibles, function ($a, $b) use ($vus) {
        $va = $vus[(int) $a] ?? '';   // jamais vue : chaîne vide, donc en tête
        $vb = $vus[(int) $b] ?? '';
        return $va === $vb ? ((int) $a <=> (int) $b) : strcmp($va, $vb);
    });
    return array_slice($cibles, 0, $n);
}

/** Note qu'on vient d'examiner cet élément — qu'on ait envoyé ou non. */
function ak_tour_vu(PDO $pdo, string $job, int $cible): void
{
    try {
        $pdo->prepare("INSERT INTO cron_tours (job, cible, vu_at) VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE vu_at = NOW()")
            ->execute([$job, $cible]);
    } catch (Throwable $e) { /* sans tour de rôle, le cron reste correct */ }
}

/**
 * Rend une réservation, quand l'envoi a finalement échoué.
 *
 * Sans ça, une panne de messagerie d'une minute ferait sauter le rappel
 * définitivement : il serait marqué envoyé sans l'avoir été.
 */
function ak_envoi_rendre(PDO $pdo, string $job, int $org_id, string $cle): void
{
    try {
        $pdo->prepare("DELETE FROM cron_envois WHERE job = ? AND org_id = ? AND cle = ?")
            ->execute([$job, $org_id, $cle]);
    } catch (Throwable $e) { /* au pire, un rappel de moins */ }
}
