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

/** Génère (IA si dispo, sinon gabarit) le sujet + corps HTML personnalisés pour une étape. */
function prospect_email(array $p, int $step, array $seq): array {
    $type = ($p['type'] ?? 'asso') === 'tpe' ? 'tpe' : 'asso';
    $who  = $type === 'tpe' ? 'votre entreprise' : 'votre association';
    $name = trim((string) ($p['name'] ?? ''));
    $org  = trim((string) ($p['org_name'] ?? ''));
    $hello = $name !== '' ? "Bonjour $name," : ($org !== '' ? "Bonjour," : "Bonjour,");
    $link  = ak_prospect_link((int) $p['id'], (string) $p['email']);
    $unsub = ak_prospect_unsub_link((int) $p['id'], (string) $p['email']);

    // --- Personnalisation IA Claude si un helper est disponible ---
    $body_txt = null;
    if (class_exists('ClaudeAPI') && method_exists('ClaudeAPI', 'callMessages')) {
        try {
            $sys = "Tu écris un email de prospection B2B court, humain et professionnel en français pour Assokit, "
                 . "logiciel tout-en-un pour " . ($type === 'tpe' ? 'TPE/PME et indépendants' : 'associations loi 1901') . ". "
                 . "3-5 phrases max, ton respectueux, pas de superlatifs racoleurs, une seule idée + un appel à l'action doux (découvrir la page / réserver 20 min). "
                 . "Ne mets NI objet, NI formule de politesse d'ouverture, NI signature : juste le corps.";
            $u = "Étape de séquence : " . ($seq[$step]['label'] ?? 'contact') . ". Destinataire : " . ($org ?: $who) . ".";
            $body_txt = trim((string) ClaudeAPI::callMessages($sys, $u, 400));
        } catch (Throwable $e) { $body_txt = null; }
    }
    if (!$body_txt) {
        // Gabarit de secours par étape
        $templates = [
            0 => "Je me permets de vous contacter car Assokit aide " . $who . " à gérer " . ($type === 'tpe' ? "devis, factures, chantiers et comptabilité" : "adhérents, cotisations, projets et comptabilité") . " depuis un seul outil, sans paperasse. J'ai pensé que cela pourrait vous faire gagner du temps.",
            1 => "Je reviens vers vous rapidement : beaucoup de structures comme la vôtre nous disent gagner plusieurs heures par semaine avec Assokit. Cela vaut peut-être un coup d'œil ?",
            2 => "Petit rappel amical — si le sujet de la gestion simplifiée vous parle, je serais ravi de vous montrer concrètement ce qu'Assokit peut faire pour vous, en 20 minutes.",
            3 => "Je ne voudrais pas vous déranger inutilement. Si ce n'est pas le bon moment, dites-le moi simplement. Sinon, la page ci-dessous résume tout en 2 minutes.",
            4 => "Dernier message de ma part : je vous laisse la page de présentation, à consulter quand vous voulez. Et si un jour la gestion de " . $who . " devient un casse-tête, vous saurez où nous trouver !",
        ];
        $body_txt = $templates[$step] ?? $templates[0];
    }

    $subjects = [0 => "Simplifier la gestion de " . $who, 1 => "Re: gagner du temps sur votre gestion", 2 => "20 minutes pour vous montrer Assokit ?", 3 => "Est-ce le bon moment ?", 4 => "Je vous laisse ça"];
    $subject = $subjects[$step] ?? "Assokit — " . $who;

    $html = "<div style=\"font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0F172A;line-height:1.6;font-size:15px;\">"
        . "<p>" . htmlspecialchars($hello) . "</p>"
        . "<p>" . nl2br(htmlspecialchars($body_txt)) . "</p>"
        . "<p><a href=\"" . htmlspecialchars($link) . "\" style=\"display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:11px 20px;border-radius:9px;font-weight:600;\">Découvrir Assokit en 2 minutes</a></p>"
        . "<p style=\"margin-top:18px;\">Bien à vous,<br>Raphael · Assokit<br><a href=\"https://assokit.fr\" style=\"color:#059669;\">assokit.fr</a></p>"
        . "<hr style=\"border:none;border-top:1px solid #E2E8F0;margin:20px 0;\">"
        . "<p style=\"font-size:11px;color:#94A3B8;\">Vous recevez cet email professionnel car votre structure pourrait être concernée. "
        . "Pour ne plus être contacté·e : <a href=\"" . htmlspecialchars($unsub) . "\" style=\"color:#94A3B8;\">se désinscrire</a>.</p>"
        . "</div>";
    return ['subject' => $subject, 'html' => $html];
}

$done = 0;
foreach ($due as $p) {
    $pid = (int) $p['id'];
    $step = (int) $p['step'];
    $mail = prospect_email($p, $step, $seq);

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
