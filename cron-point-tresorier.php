<?php
/**
 * ============================================================
 * ASSOKIT — cron-point-tresorier.php : le point du lundi du trésorier
 * ============================================================
 * Formule Équipe (feature_treasurer_digest). Chaque lundi à 7 h 40 (cPanel → Tâches cron) :
 *   40 7 * * 1  /usr/local/bin/php /home/<compte>/public_html/cron-point-tresorier.php
 * ou par HTTP : https://assokit.fr/cron-point-tresorier?token=<CRON_TOKEN>
 * (--force en ligne de commande, ou &force=1, pour l'envoyer un autre jour : essais).
 *
 * Pour chaque association dont la formule l'inclut, un e-mail sans IA aux administrateurs et
 * aux comptes « Gérer les finances » (sauf ceux qui l'ont décoché sur /anomalies,
 * table treasurer_digest_optout) :
 *   - les NOUVELLES anomalies depuis le dernier point (anomalies-engine.php ; empreintes gardées
 *     dans treasurer_digest_state, migrations/2026-11-10-point-tresorier.sql) ;
 *   - la trésorerie prévue à 3 mois (previsions-engine.php, solde de départ saisi sur /previsions) ;
 *   - les impayés et cotisations à relancer maintenant (relances-engine.php).
 * Ces e-mails ne sont pas comptés dans le quota d'e-mails de la formule.
 * Un seul envoi par association et par semaine (treasurer_digest_state.last_sent_at).
 * Journal : cron_runs / cron_email_log (cron-includes.php).
 * ============================================================
 */
define('CRON_EXECUTING', true);

$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
require_once __DIR__ . '/cron-includes.php';   // config.php, $pdo, cron_log_*, cron_send_email, cron_email_wrap
if (!$is_cli) {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    if (!defined('CRON_TOKEN') || (string)CRON_TOKEN === '' || !hash_equals((string)CRON_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/asso-invoice-helpers.php';   // ak_asso_org_active_sql()
require_once __DIR__ . '/anomalies-engine.php';
require_once __DIR__ . '/previsions-engine.php';
require_once __DIR__ . '/relances-engine.php';
require_once __DIR__ . '/plan-controls.php';

$force = $is_cli ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);
if ((int)date('N') !== 1 && !$force) { echo "Le point du trésorier part le lundi (--force pour l'envoyer aujourd'hui).\n"; exit(0); }

$lock = cron_try_lock('point-tresorier');
if (!$lock) { echo "Déjà en cours\n"; exit(0); }
$run = cron_log_start($pdo, 'point-tresorier', $is_cli ? 'cron' : 'http');

$state_ok = true;
try { $pdo->query("SELECT org_id FROM treasurer_digest_state LIMIT 0"); } catch (Throwable $e) { $state_ok = false; }
$optout_ok = true;
try { $pdo->query("SELECT user_id FROM treasurer_digest_optout LIMIT 0"); } catch (Throwable $e) { $optout_ok = false; }
$ucols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'users') : [];
$fin_col = isset($ucols['can_manage_finances']);

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$eur = fn(float $v): string => number_format($v, 0, ',', "\u{202F}") . "\u{00A0}€";
$monday = date('Y-m-d 00:00:00', strtotime('monday this week'));
$stats = ['orgs' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
// Clés lues par cron_log_end (cron_runs)
$journal = fn(array $s): array => ['processed' => $s['orgs'], 'succeeded' => $s['sent'], 'failed' => $s['failed'], 'emails' => $s['sent'], 'details' => $s];

try {
    $orgs = $pdo->query("SELECT o.id, o.name FROM organizations o WHERE " . ak_asso_org_active_sql('o') . " ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    cron_log_end($pdo, $run, 'error', $journal($stats), $ex->getMessage());
    cron_unlock($lock);
    echo "Lecture des associations impossible : " . $ex->getMessage() . "\n";
    exit(1);
}

foreach ($orgs as $o) {
    $org_id = (int)$o['id'];
    // Formule Équipe (et au-delà) seulement ; jamais en accès limité
    if (!akc_has($pdo, $org_id, 'treasurer_digest', false) || akc_readonly($pdo, $org_id, false)) continue;
    $stats['orgs']++;

    // Un seul point par semaine
    $prev = null;
    if ($state_ok) {
        try {
            $st = $pdo->prepare("SELECT anomaly_hashes, last_sent_at FROM treasurer_digest_state WHERE org_id = ?");
            $st->execute([$org_id]);
            $prev = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $ex) { $prev = null; }
        if ($prev && !empty($prev['last_sent_at']) && $prev['last_sent_at'] >= $monday && !$force) { $stats['skipped']++; continue; }
    }

    // Destinataires : administrateurs et comptes « Gérer les finances », hors désinscrits
    $who = "u.org_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email <> ''"
         . " AND (u.role = 'admin'" . ($fin_col ? " OR COALESCE(u.can_manage_finances, 0) = 1" : '') . ")"
         . ($optout_ok ? " AND NOT EXISTS (SELECT 1 FROM treasurer_digest_optout x WHERE x.user_id = u.id)" : '');
    try {
        $st = $pdo->prepare("SELECT u.id, u.email, u.first_name FROM users u WHERE $who");
        $st->execute([$org_id]);
        $dest = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) { $dest = []; }
    $dest = array_values(array_filter($dest, fn($d) => filter_var($d['email'], FILTER_VALIDATE_EMAIL)));
    if (!$dest) { $stats['skipped']++; continue; }

    // 1. Anomalies : nouvelles depuis le dernier point (toutes au premier envoi)
    $anoms = [];
    try { $anoms = ak_anom_scan($pdo, $org_id, false); } catch (Throwable $ex) {}
    $seen = [];
    if ($prev && $prev['anomaly_hashes'] !== null) $seen = array_flip(array_filter(explode(',', (string)$prev['anomaly_hashes'])));
    $known_before = $state_ok && $prev && $prev['anomaly_hashes'] !== null;
    $new = array_values(array_filter($anoms, fn($a) => !isset($seen[$a['hash']])));

    // 2. Trésorerie prévue à 3 mois
    $start = 0.0; $balance_set = false;
    try {
        $bp = $pdo->prepare("SELECT start_balance_cents, balance_set_at FROM org_forecast_prefs WHERE org_id = ?");
        $bp->execute([$org_id]);
        if ($b = $bp->fetch(PDO::FETCH_ASSOC)) { $start = ((int)$b['start_balance_cents']) / 100; $balance_set = !empty($b['balance_set_at']); }
    } catch (Throwable $ex) {}
    $fc_rows = [];
    try { $fc_rows = ak_prev_forecast($pdo, $org_id, 3, $balance_set ? $start : 0.0)['rows'] ?? []; } catch (Throwable $ex) {}

    // 3. À relancer maintenant
    $inv_due = []; $mem_due = [];
    try { $inv_due = array_values(array_filter(ak_rel_invoice_targets($pdo, $org_id), fn($t) => !empty($t['due_now']))); } catch (Throwable $ex) {}
    try { $mem_due = array_values(array_filter(ak_rel_membership_targets($pdo, $org_id), fn($t) => !empty($t['due_now']))); } catch (Throwable $ex) {}
    $inv_total = array_sum(array_map(fn($t) => (int)($t['amount_cents'] ?? 0), $inv_due)) / 100;

    // Corps de l'e-mail (sans IA)
    $sev = ['high' => 'Critique', 'medium' => 'À vérifier', 'low' => 'Info'];
    $body = '<p style="margin:0 0 18px;">Voici le point de la semaine pour <strong>' . $e($o['name']) . '</strong>.</p>';

    $body .= '<h2 style="font-size:16px;margin:0 0 8px;color:#0A0A0B;">' . ($known_before ? 'Nouvelles anomalies depuis le dernier point' : 'Anomalies à vérifier') . '</h2>';
    if (!$new) {
        $body .= '<p style="margin:0 0 18px;">Aucune nouvelle anomalie dans vos factures et cotisations.</p>';
    } else {
        $body .= '<ul style="margin:0 0 6px;padding-left:18px;">';
        foreach (array_slice($new, 0, 8) as $a) {
            $body .= '<li style="margin:0 0 4px;"><strong>' . $e($sev[$a['severity']] ?? 'À vérifier') . '</strong> — ' . $e($a['title']) . '</li>';
        }
        $body .= '</ul>';
        if (count($new) > 8) $body .= '<p style="margin:0 0 6px;color:#71717A;">… et ' . (count($new) - 8) . ' autre(s).</p>';
        $body .= '<p style="margin:0 0 18px;color:#71717A;font-size:13px;">Des contrôles automatiques repèrent des erreurs courantes ; vérifiez chaque point avant de corriger.</p>';
    }

    $body .= '<h2 style="font-size:16px;margin:0 0 8px;color:#0A0A0B;">Trésorerie prévue à 3 mois</h2>';
    if ($fc_rows) {
        $body .= '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 6px;font-size:14px;">';
        foreach ($fc_rows as $r) {
            $body .= '<tr><td style="padding:3px 16px 3px 0;color:#3F3F46;">' . $e($r['label']) . '</td>'
                   . '<td style="padding:3px 16px 3px 0;color:#3F3F46;">solde net du mois ' . $e($eur((float)$r['net'])) . '</td>'
                   . '<td style="padding:3px 0;font-weight:600;">' . ($balance_set ? 'trésorerie ' : 'cumul ') . $e($eur((float)$r['cum'])) . '</td></tr>';
        }
        $body .= '</table>';
        $body .= '<p style="margin:0 0 18px;color:#71717A;font-size:13px;">Estimation à partir de vos 6 derniers mois et des entrées déjà connues'
               . ($balance_set ? '.' : ' ; saisissez votre solde actuel sur la page Prévisions pour obtenir la trésorerie prévue.') . '</p>';
    } else {
        $body .= '<p style="margin:0 0 18px;">Pas encore assez d’historique pour une prévision.</p>';
    }

    $body .= '<h2 style="font-size:16px;margin:0 0 8px;color:#0A0A0B;">À relancer maintenant</h2>';
    if (!$inv_due && !$mem_due) {
        $body .= '<p style="margin:0 0 6px;">Aucune relance à faire cette semaine.</p>';
    } else {
        $body .= '<ul style="margin:0 0 6px;padding-left:18px;">';
        if ($inv_due) $body .= '<li>' . count($inv_due) . ' facture' . (count($inv_due) > 1 ? 's' : '') . ' impayée' . (count($inv_due) > 1 ? 's' : '') . ' (' . $e($eur($inv_total)) . ')</li>';
        if ($mem_due) $body .= '<li>' . count($mem_due) . ' cotisation' . (count($mem_due) > 1 ? 's' : '') . ' à renouveler</li>';
        $body .= '</ul>';
    }
    $body .= '<p style="margin:18px 0 0;color:#71717A;font-size:12.5px;">Vous recevez ce point chaque lundi en tant qu’administrateur ou responsable des finances. '
           . 'Pour ne plus le recevoir, décochez « Recevoir le point du lundi » sur la page Anomalies.</p>';

    $title = 'Le point du lundi';
    $nb = count($new); $pl = $nb > 1 ? 's' : '';
    $subject = 'Le point du lundi — ' . $o['name']
             . ($nb ? ' : ' . $nb . ($known_before ? " nouvelle$pl anomalie$pl" : " anomalie$pl à vérifier") : '');
    $pre = ($new ? count($new) . ' anomalie(s) à vérifier · ' : '') . count($inv_due) . ' facture(s) et ' . count($mem_due) . ' cotisation(s) à relancer';

    $ok_any = false;
    foreach ($dest as $d) {
        $html = cron_email_wrap($title, '<p style="margin:0 0 12px;">Bonjour ' . $e($d['first_name'] ?: '') . ',</p>' . $body,
                                ['label' => 'Voir les anomalies', 'url' => 'https://assokit.fr/anomalies'], $pre);
        $sent = cron_send_email($pdo, $run, (string)$d['email'], 'point_tresorier', $subject, $html, ['org_id' => $org_id, 'user_id' => (int)$d['id']]);
        if ($sent) { $stats['sent']++; $ok_any = true; } else { $stats['failed']++; }
    }
    echo "org #$org_id : " . count($dest) . " destinataire(s), " . count($new) . " nouvelle(s) anomalie(s), " . count($inv_due) . " facture(s) et " . count($mem_due) . " cotisation(s) à relancer\n";

    // Mémorise les anomalies signalées (et la semaine) dès qu'au moins un envoi a abouti
    if ($state_ok && $ok_any) {
        try {
            $pdo->prepare("INSERT INTO treasurer_digest_state (org_id, anomaly_hashes, last_sent_at, updated_at) VALUES (?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE anomaly_hashes = VALUES(anomaly_hashes), last_sent_at = NOW(), updated_at = NOW()")
                ->execute([$org_id, implode(',', array_map(fn($a) => $a['hash'], $anoms))]);
        } catch (Throwable $ex) { error_log('[point-tresorier] état org #' . $org_id . ' : ' . $ex->getMessage()); }
    }
}

cron_log_end($pdo, $run, $stats['failed'] ? 'error' : 'success', $journal($stats));
cron_unlock($lock);
echo "Terminé : {$stats['orgs']} association(s), {$stats['sent']} e-mail(s) envoyé(s), {$stats['failed']} échec(s), {$stats['skipped']} sautée(s)\n";
exit(0);
