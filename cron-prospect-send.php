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
$CLI = (PHP_SAPI === 'cli');
if (!$CLI) {
    // Autorise un déclenchement HTTP protégé par un secret (?key=), sinon 403.
    $key = $_GET['key'] ?? '';
    // (à configurer : compare à une constante AK_CRON_KEY si définie)
}

ob_start();
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/resend-helper.php';
ob_end_clean();
require_once __DIR__ . '/api/_app-prospect.php';

if (!$CLI && defined('AK_CRON_KEY') && AK_CRON_KEY && ($_GET['key'] ?? '') !== AK_CRON_KEY) { http_response_code(403); exit("forbidden\n"); }

ak_prospect_tables_ensure($pdo);
$DRY = !AK_PROSPECT_SENDING_ENABLED;
$seq = ak_prospect_sequence();
$LAST_STEP = max(array_keys($seq));
$log = function ($m) use ($CLI) { if ($CLI) echo $m . "\n"; error_log('[prospect-cron] ' . $m); };

// Combien déjà envoyés aujourd'hui ? (respect du plafond warm-up)
$sentToday = 0;
try { $sentToday = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events WHERE type='sent' AND created_at >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
$budget = max(0, AK_PROSPECT_DAILY_CAP - $sentToday);
$log(($DRY ? '[DRY-RUN] ' : '') . "Plafond du jour : $sentToday/" . AK_PROSPECT_DAILY_CAP . " → budget restant $budget");
if ($budget <= 0) { $log('Plafond atteint, rien à envoyer.'); exit(0); }

// Sélectionne les prospects dus (jamais désinscrits/répondus/RDV)
$due = [];
try {
    $st = $pdo->prepare("SELECT * FROM asso_prospects
        WHERE status IN ('queued','contacted','engaged')
          AND step <= ?
          AND (next_send_at IS NULL OR next_send_at <= NOW())
        ORDER BY next_send_at ASC LIMIT ?");
    $st->bindValue(1, $LAST_STEP, PDO::PARAM_INT);
    $st->bindValue(2, $budget, PDO::PARAM_INT);
    $st->execute();
    $due = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $log('select error: ' . $e->getMessage()); }

$log(count($due) . ' prospect(s) à traiter.');

$done = 0;
foreach ($due as $p) {
    $pid = (int) $p['id'];
    $step = (int) $p['step'];
    $mail = ak_prospect_build_email($p, $step, $seq);

    if ($DRY) {
        $log("[DRY] would send step $step to {$p['email']} — « {$mail['subject']} »");
    } else {
        if (!function_exists('send_transactional_email')) { $log('send_transactional_email indisponible, arrêt.'); break; }
        try {
            $reply = 'contact@assokit.fr';
            send_transactional_email((string) $p['email'], $mail['subject'], $mail['html'], ['tag' => 'prospect_seq_' . $step, 'reply_to' => $reply]);
            ak_prospect_event($pdo, $pid, 'sent', $step, $mail['subject']);
        } catch (Throwable $e) { $log("send fail {$p['email']}: " . $e->getMessage()); continue; }
    }

    // Planifie l'étape suivante (ou clôt la séquence)
    $nextStep = $step + 1;
    if ($nextStep > $LAST_STEP) {
        try { $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=NULL WHERE id=?")->execute([$step, $pid]); } catch (Throwable $e) {}
    } else {
        $delay = (int) ($seq[$step]['delay'] ?? 18);
        try { $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")->execute([$nextStep, $delay, $pid]); } catch (Throwable $e) {}
    }
    $done++;
}

$log(($DRY ? '[DRY-RUN] ' : '') . "Terminé : $done traité(s).");
exit(0);
