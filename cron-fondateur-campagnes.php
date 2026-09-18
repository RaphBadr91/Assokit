<?php
/**
 * cron-fondateur-campagnes.php — Écoule les campagnes du Fondateur.
 * ------------------------------------------------------------------
 * À LANCER TOUTES LES 10 MINUTES :
 *   /usr/local/bin/php /home/pura7044/public_html/cron-fondateur-campagnes.php
 *
 * La page /fondateur-campagnes ne fait que remplir la file — une ligne
 * par destinataire dans fond_campagne_envois. Ce cron la vide, par
 * lots, en respectant le plafond quotidien d'envoi.
 *
 * Trois protections héritées de cron-lots.php :
 *   · un verrou, pour que deux passages ne se superposent jamais ;
 *   · un budget, pour rendre la main avant que l'hébergeur ne coupe ;
 *   · la reprise, gratuite ici : le statut de chaque ligne dit ce qui
 *     reste à faire, donc un passage interrompu ne perd ni ne renvoie
 *     rien.
 *
 * Et une quatrième, propre à l'envoi en masse : la clé unique
 * (campagne, destinataire) de la table. Même en cas d'accident, personne
 * ne peut recevoir deux fois la même campagne.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cron-lots.php';
require_once __DIR__ . '/fondateur-emailing-helpers.php';
// include_once et non require_once : l'intention est de tolérer son
// absence — ak_camp_envoyer() vérifie ensuite function_exists() et
// renvoie un échec propre. Avec require_once, le « @ » masque bien
// l'avertissement mais l'erreur reste fatale, et le cron mourrait sans
// compte rendu au lieu de le dire.
@include_once __DIR__ . '/resend-helper.php';

$is_cli  = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

if (!ak_lot_demarrer($pdo, 'campagnes')) exit(0);

// Table des contacts partagée avec la prospection, créée à la demande.
ak_prospect_tables_ensure($pdo);

// La table peut ne pas exister : la migration se passe à la main, et le
// cron peut être installé avant. On le dit et on sort proprement.
try {
    $pdo->query("SELECT 1 FROM fond_campagne_envois LIMIT 1")->closeCursor();
} catch (Throwable $e) {
    echo "  ! Tables des campagnes absentes — migration 2026-09-18 à passer.\n";
    ak_lot_terminer($pdo);
    exit(0);
}

// ------------------------------------------------------------------
// Le plafond du jour
// ------------------------------------------------------------------
// Partagé avec la séquence de prospection : c'est la réputation du
// domaine d'envoi qui est en jeu, et elle ne distingue pas les deux
// sources. Une campagne plus grande que le plafond s'étale simplement
// sur plusieurs jours — c'est le comportement voulu, pas une limite
// qu'on cherche à contourner.
$plafond = defined('AK_PROSPECT_DAILY_CAP') ? (int) AK_PROSPECT_DAILY_CAP : 40;
$deja    = ak_camp_envoyes_aujourdhui($pdo);
$budget  = max(0, $plafond - $deja);
echo "  Plafond du jour : $deja/$plafond → il reste $budget envoi(s)\n";
if ($budget <= 0) {
    echo "  Plafond atteint, on reprendra demain.\n";
    ak_lot_terminer($pdo, true);
    exit(0);
}

$DRY = !(defined('AK_PROSPECT_SENDING_ENABLED') && AK_PROSPECT_SENDING_ENABLED);
if ($DRY) echo "  [SIMULATION] AK_PROSPECT_SENDING_ENABLED est à false — rien ne partira.\n";

// ------------------------------------------------------------------
// Les campagnes en cours, la plus ancienne d'abord
// ------------------------------------------------------------------
// Une seule à la fois : mieux vaut terminer la première que d'avancer
// de trois pas sur quatre campagnes. Le destinataire, lui, n'a pas
// d'avis — mais l'opérateur qui regarde la jauge, si.
$campagnes = $pdo->query("SELECT * FROM fond_campagnes WHERE statut = 'en_cours' ORDER BY id ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);
echo "  " . count($campagnes) . " campagne(s) en cours\n";

$totalEnvoyes = 0;
$reste = false;

foreach ($campagnes as $camp) {
    if (!ak_lot_encore() || $budget <= 0) { $reste = true; break; }
    $cid = (int) $camp['id'];
    echo "── Campagne #$cid « {$camp['nom']} » ──\n";

    while (ak_lot_encore() && $budget > 0) {
        // On prend les destinataires un petit paquet à la fois plutôt
        // que toute la file : à 20 000 lignes, la charger d'un coup
        // épuiserait la mémoire pour n'en traiter que quelques-unes.
        $st = $pdo->prepare("SELECT e.id, e.prospect_id, e.email,
                                    p.name, p.org_name, p.city, p.status
                             FROM fond_campagne_envois e
                             JOIN asso_prospects p ON p.id = e.prospect_id
                             WHERE e.campagne_id = ? AND e.statut = 'attente'
                             ORDER BY e.id ASC LIMIT 25");
        $st->execute([$cid]);
        $paquet = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$paquet) break;

        foreach ($paquet as $d) {
            if (!ak_lot_encore() || $budget <= 0) { $reste = true; break 2; }

            if ($DRY) {
                // En simulation on marque quand même la ligne, sinon le
                // cron rejouerait indéfiniment le même paquet et le
                // journal ne dirait jamais rien d'autre.
                $pdo->prepare("UPDATE fond_campagne_envois
                               SET statut = 'ignore', erreur = 'simulation (envoi désactivé)'
                               WHERE id = ?")->execute([(int) $d['id']]);
                ak_lot_fait();
                continue;
            }

            $r = ak_camp_envoyer($pdo, $camp, [
                'id' => (int) $d['prospect_id'], 'email' => $d['email'],
                'name' => $d['name'], 'org_name' => $d['org_name'],
                'city' => $d['city'], 'status' => $d['status'],
            ]);

            $pdo->prepare("UPDATE fond_campagne_envois
                           SET statut = ?, erreur = ?, sent_at = ?
                           WHERE id = ?")
                ->execute([$r['statut'], $r['erreur'],
                           $r['statut'] === 'envoye' ? date('Y-m-d H:i:s') : null,
                           (int) $d['id']]);

            ak_lot_fait();
            if ($r['statut'] === 'envoye') { $budget--; $totalEnvoyes++; }
            if ($r['statut'] === 'echec')  echo "  ✗ {$d['email']} : {$r['erreur']}\n";

            // Un petit répit entre deux envois. Resend limite le débit,
            // et une rafale continue est aussi ce qui fait classer un
            // expéditeur comme indésirable.
            usleep(250000);
        }
    }

    // Les compteurs de la campagne, recalculés depuis la file : c'est
    // elle qui fait foi, et un compteur incrémenté à la main finit
    // toujours par diverger.
    $st = $pdo->prepare("SELECT SUM(statut='envoye') e, SUM(statut='echec') k,
                                SUM(statut='ignore') i, SUM(statut='attente') a, COUNT(*) t
                         FROM fond_campagne_envois WHERE campagne_id = ?");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);

    $fini = ((int) $c['a'] === 0);
    $pdo->prepare("UPDATE fond_campagnes
                   SET envoyes = ?, echecs = ?, ignores = ?, total = ?,
                       statut = IF(? = 1, 'terminee', statut),
                       terminee_at = IF(? = 1, IFNULL(terminee_at, NOW()), terminee_at)
                   WHERE id = ?")
        ->execute([(int) $c['e'], (int) $c['k'], (int) $c['i'], (int) $c['t'],
                   $fini ? 1 : 0, $fini ? 1 : 0, $cid]);

    echo "  {$c['e']} envoyé(s) · {$c['a']} en attente · {$c['k']} échec(s) · {$c['i']} écarté(s)\n";
    if ($fini) echo "  ✓ Campagne terminée\n";
    else       $reste = true;
}

echo "\n$totalEnvoyes e-mail(s) parti(s) ce passage\n";
ak_lot_terminer($pdo, $reste);
exit(0);
