<?php
/**
 * cron-prospect-send.php — Automatisation des envois de prospection (séquence + relances).
 * À planifier en CRON (ex. toutes les 30 min). CLI de préférence.
 *
 * SÉCURITÉ / CONFORMITÉ :
 *   - N'envoie RIEN tant que AK_PROSPECT_SENDING_ENABLED n'est pas true (mode DRY-RUN : log only).
 *     -> À activer volontairement APRÈS mise en place d'un domaine d'envoi dédié + warm-up.
 *   - Respecte le plafond quotidien (AK_PROSPECT_DAILY_CAP) — montée en charge progressive.
 *   - Ne recontacte JAMAIS un prospect 'unsubscribed' / 'replied' / 'booked'.
 *   - Chaque email contient un lien de désinscription (obligation RGPD).
 *   - Personnalisation par IA Claude si disponible, sinon gabarit professionnel.
 * NE MODIFIE PAS le site.
 */
// php-cgi (/usr/bin/php sur O2Switch) : PHP_SAPI vaut « cgi-fcgi », sans REQUEST_METHOD ; c'est aussi la ligne de commande
$CLI = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);

ob_start();
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/resend-helper.php';
ob_end_clean();
require_once __DIR__ . '/api/_app-prospect.php';

// Sécurité (fail-closed) : en CLI (cron serveur) autorisé ; en HTTP, exige une clé
// secrète CONFIGURÉE et correcte, sinon 403. Sans AK_CRON_KEY défini, l'accès HTTP
// est refusé (on ne se repose jamais sur "constante absente = pas de contrôle").
if (!$CLI) {
    $provided = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';   // ?key[]=… : refusé sans avertissement
    if (!defined('AK_CRON_KEY') || !AK_CRON_KEY || !hash_equals((string) AK_CRON_KEY, $provided)) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

ak_prospect_tables_ensure($pdo);
$DRY = !AK_PROSPECT_SENDING_ENABLED;
$seq = ak_prospect_sequence();
$LAST_STEP = max(array_keys($seq));
$log = function ($m) use ($CLI) { if ($CLI) echo $m . "\n"; error_log('[prospect-cron] ' . $m); };

// Ce cron avait déjà tout ce qu'il faut pour travailler par lots : un
// plafond quotidien, un LIMIT, un tri par échéance et une marque après
// envoi. On ne lui ajoute que le verrou.
//
// Il en a d'autant plus besoin que son plafond se calcule en comptant
// les envois du jour : deux passages simultanés liraient le même
// compteur avant que l'un ou l'autre n'ait écrit, et dépasseraient le
// plafond à deux — précisément ce qu'une adresse en warm-up ne
// pardonne pas.
require_once __DIR__ . '/cron-lots.php';
if (!ak_lot_demarrer($pdo, 'prospect-send', ['max' => PHP_INT_MAX])) exit(0);

// Combien déjà envoyés aujourd'hui ? (respect du plafond warm-up)
$sentToday = 0;
try { $sentToday = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events WHERE type='sent' AND created_at >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
$budget = max(0, AK_PROSPECT_DAILY_CAP - $sentToday);
$log(($DRY ? '[DRY-RUN] ' : '') . "Plafond du jour : $sentToday/" . AK_PROSPECT_DAILY_CAP . " → budget restant $budget");
if ($budget <= 0) { $log('Plafond atteint, rien à envoyer.'); ak_lot_terminer($pdo); exit(0); }

// Sélectionne les prospects dus (jamais désinscrits/répondus/RDV).
// Seulement ceux qui ont une échéance : la mise en file et la relance en posent
// toujours une, la fin de séquence et la relance annulée la retirent. Sans ce
// filtre, les séquences terminées (échéance NULL, triée en premier) recevaient
// le dernier e-mail à chaque passage.
$due = [];
try {
    $st = $pdo->prepare("SELECT * FROM asso_prospects
        WHERE status IN ('queued','contacted','engaged')
          AND step <= ?
          AND next_send_at IS NOT NULL AND next_send_at <= NOW()
        ORDER BY next_send_at ASC LIMIT ?");
    $st->bindValue(1, $LAST_STEP, PDO::PARAM_INT);
    $st->bindValue(2, $budget, PDO::PARAM_INT);
    $st->execute();
    $due = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $log('select error: ' . $e->getMessage()); }

$log(count($due) . ' prospect(s) à traiter.');

// Un envoi refusé ne fait pas avancer la séquence, mais on repousse l'échéance :
// sinon la ligne restait la plus ancienne due et, à chaque passage, les échecs
// répétés reprenaient tout le budget du jour devant les prospects envoyables.
// Une panne générale (clé manquante, service indisponible) est ainsi retentée
// six heures plus tard.
$postpone = function (int $pid) use ($pdo) {
    try { $pdo->prepare("UPDATE asso_prospects SET next_send_at = DATE_ADD(NOW(), INTERVAL 6 HOUR) WHERE id = ?")->execute([$pid]); } catch (Throwable $e) {}
};

$done = 0;
foreach ($due as $p) {
    $pid = (int) $p['id'];
    $step = (int) $p['step'];
    $mail = ak_prospect_build_email($p, $step, $seq);

    if ($DRY) {
        // Simulation : journal seulement, aucune écriture. Faire avancer la séquence ici
        // marquait le prospect « Contacté » sans e-mail, et ces étapes ne partaient plus
        // jamais une fois l'envoi activé.
        $log("[DRY] would send step $step to {$p['email']} — « {$mail['subject']} »");
        $done++;
        continue;
    }

    if (!function_exists('send_transactional_email')) { $log('send_transactional_email indisponible, arrêt.'); break; }
    try {
        $res = send_transactional_email((string) $p['email'], $mail['subject'], $mail['html'], [
            'tag' => 'prospect_seq_' . $step,
            'from_email' => AK_PROSPECT_FROM, 'from_name' => AK_PROSPECT_FROM_NAME,
            'reply_to' => AK_PROSPECT_REPLY_TO,
        ]);
        // Un refus du service d'envoi ne lève pas d'exception : sans ce contrôle, l'étape
        // était comptée comme envoyée et la séquence avançait sans e-mail.
        if (is_array($res) && empty($res['success'])) { $log("send fail {$p['email']}: " . ($res['error'] ?? 'refusé')); $postpone($pid); continue; }
        ak_prospect_event($pdo, $pid, 'sent', $step, $mail['subject']);
    } catch (Throwable $e) { $log("send fail {$p['email']}: " . $e->getMessage()); $postpone($pid); continue; }

    // Planifie l'étape suivante (ou clôt la séquence : step = LAST_STEP + 1, plus jamais
    // sélectionnée)
    $nextStep = $step + 1;
    if ($nextStep > $LAST_STEP) {
        try { $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=NULL WHERE id=?")->execute([$nextStep, $pid]); } catch (Throwable $e) {}
    } else {
        $delay = (int) ($seq[$step]['delay'] ?? 18);
        try { $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")->execute([$nextStep, $delay, $pid]); } catch (Throwable $e) {}
    }
    $done++;
}

$log(($DRY ? '[DRY-RUN] ' : '') . "Terminé : $done traité(s).");
ak_lot_terminer($pdo, !$DRY && $done >= $budget);
exit(0);
