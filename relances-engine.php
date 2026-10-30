<?php
/**
 * relances-engine.php — RELANCES INTELLIGENTES
 * ------------------------------------------------------------------
 * Décide, de façon déterministe, QUI relancer, à QUEL stade, et MAINTENANT
 * ou non — pour les factures impayées et les cotisations expirées.
 *
 * S'appuie sur l'infra d'envoi existante :
 *   - factures : ak_asso_invoice_send_email() (dunning1/2/3), log asso_invoice_emails_log.
 *   - cotisations : ak_rel_send_membership() (ci-dessous), log asso_membership_reminders.
 *
 * Cadence (pratique française du recouvrement amiable) :
 *   Stade 1 « rappel courtois »  : dès J+1 de retard.
 *   Stade 2 « relance ferme »    : à partir de J+15, ≥ 7 j après le stade 1.
 *   Stade 3 « mise en demeure »  : à partir de J+45, ≥ 8 j après le stade 2.
 * On ne renvoie jamais un stade déjà envoyé ; on respecte un délai minimal
 * entre deux relances (anti-harcèlement).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/asso-invoice-email-helpers.php';
require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns (paiements en ligne : prélèvements SEPA en cours)

if (!defined('AK_REL_MIN_GAP_DAYS')) define('AK_REL_MIN_GAP_DAYS', 7); // délai mini entre 2 relances

if (!function_exists('ak_rel_stage_meta')) {
function ak_rel_stage_meta(int $stage): array {
    return [
        1 => ['Rappel courtois', 'dunning1', '#3B82F6', '#DBEAFE'],
        2 => ['Relance ferme',   'dunning2', '#F59E0B', '#FEF3C7'],
        3 => ['Mise en demeure', 'dunning3', '#EF4444', '#FEE2E2'],
    ][$stage] ?? ['—', 'manual', '#6B7280', '#F3F4F6'];
}
}

if (!function_exists('ak_rel_target_stage_by_days')) {
/** Stade « justifié » par le nombre de jours de retard. */
function ak_rel_target_stage_by_days(int $days): int {
    if ($days >= 45) return 3;
    if ($days >= 15) return 2;
    if ($days >= 1)  return 1;
    return 0;
}
}

if (!function_exists('ak_rel_decide')) {
/**
 * À partir du retard et de l'historique, décide le stade recommandé.
 * @return array{stage:int, due_now:bool, reason:string}
 */
function ak_rel_decide(int $days_overdue, int $max_sent, ?int $days_since_last): array {
    $target = ak_rel_target_stage_by_days($days_overdue);
    if ($target <= 0) return ['stage'=>0, 'due_now'=>false, 'reason'=>'Pas encore échu.'];

    $rec = min($max_sent + 1, $target);   // stade suivant, plafonné par ce que le retard justifie
    if ($rec > 3) $rec = 3;

    if ($rec <= $max_sent) {
        return ['stage'=>$max_sent, 'due_now'=>false, 'reason'=>'Dernière relance déjà au bon stade — patienter.'];
    }
    // Respect du délai minimal entre relances
    if ($days_since_last !== null && $days_since_last < AK_REL_MIN_GAP_DAYS) {
        return ['stage'=>$rec, 'due_now'=>false, 'reason'=>'Relance récente — attendre '.(AK_REL_MIN_GAP_DAYS - $days_since_last).' j.'];
    }
    $m = ak_rel_stage_meta($rec);
    return ['stage'=>$rec, 'due_now'=>true, 'reason'=>$m[0].' recommandé (retard '.$days_overdue.' j).'];
}
}

if (!function_exists('ak_rel_invoice_history')) {
/**
 * Relances déjà parties pour ces factures, d'après asso_invoice_emails_log :
 * c'est le journal commun à /relances (manuel et cron-relances) et aux relances
 * validées depuis le tableau de bord (cron_asso_invoice_dunning_*), d'où une
 * seule cadence pour tous les circuits.
 * @return array<int, array{max_sent:int, count:int, last_at:?string, days_since:?int}>
 */
function ak_rel_invoice_history(PDO $pdo, array $invoice_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoice_ids))));
    if (!$ids) return [];
    $place = implode(',', array_fill(0, count($ids), '?'));
    $hist = [];
    try {
        $hs = $pdo->prepare(
            "SELECT invoice_id, email_type, MAX(sent_at) last_at, COUNT(*) n
             FROM asso_invoice_emails_log
             WHERE status='sent' AND email_type LIKE 'dunning%' AND invoice_id IN ($place)
             GROUP BY invoice_id, email_type");
        $hs->execute($ids);
        foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $iid = (int)$h['invoice_id'];
            $stage = (int)substr($h['email_type'], -1);
            if (!isset($hist[$iid])) $hist[$iid] = ['max_sent'=>0, 'count'=>0, 'last_at'=>null, 'days_since'=>null];
            $hist[$iid]['max_sent'] = max($hist[$iid]['max_sent'], $stage);
            $hist[$iid]['count'] += (int)$h['n'];
            if ($h['last_at'] && ($hist[$iid]['last_at'] === null || $h['last_at'] > $hist[$iid]['last_at'])) {
                $hist[$iid]['last_at'] = $h['last_at'];
            }
        }
    } catch (Throwable $e) { /* log absent : on part de 0 relance */ }
    foreach ($hist as &$h) {
        $h['days_since'] = $h['last_at'] ? (int)floor((time() - strtotime($h['last_at']))/86400) : null;
    }
    unset($h);
    return $hist;
}
}

if (!function_exists('ak_rel_invoice_targets')) {
/**
 * Factures à relancer pour une org. Retourne une liste enrichie
 * (jours de retard, historique de relances, stade recommandé, due_now).
 */
function ak_rel_invoice_targets(PDO $pdo, int $org_id): array {
    // Factures impayées et échues (overdue, ou pending dont l'échéance est passée).
    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_number, i.amount_ttc_cents, DATE(i.due_at) due_date,
                DATEDIFF(CURDATE(), DATE(i.due_at)) days_overdue,
                c.display_name client_name, c.email client_email
         FROM asso_invoices i
         LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
         WHERE i.org_id = :o
           AND i.status IN ('pending','overdue')
           AND i.due_at IS NOT NULL AND i.due_at < CURDATE()
         ORDER BY i.due_at ASC
         LIMIT 500");
    $st->execute([':o'=>$org_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    // Historique de relances par facture (types dunning déjà envoyés).
    $hist = ak_rel_invoice_history($pdo, array_map(fn($r)=>(int)$r['id'], $rows));

    // Paiement en ligne : prélèvement SEPA envoyé par le client, en attente de la banque (quelques jours ouvrés) :
    // jamais relancé (le client a payé), affiché « Prélèvement SEPA en cours » sur la page Relances
    $sepa = [];
    try {
        if (function_exists('ak_db_columns') && ak_db_columns($pdo, 'asso_online_payments') && ak_db_columns($pdo, 'org_stripe_accounts')) {
            $ids = array_map(fn($r)=>(int)$r['id'], $rows);
            $ps = $pdo->prepare("SELECT op.invoice_id FROM asso_online_payments op
                                 JOIN org_stripe_accounts a ON a.stripe_account_id = op.stripe_account_id AND a.org_id = op.org_id
                                 WHERE op.org_id = ? AND op.kind = 'invoice' AND op.status = 'processing'
                                   AND op.invoice_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $ps->execute(array_merge([$org_id], $ids));
            foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $iid) $sepa[(int)$iid] = true;
        }
    } catch (Throwable $e) { /* tables absentes : aucun paiement en ligne */ }

    $out = [];
    foreach ($rows as $r) {
        $iid = (int)$r['id'];
        $h = $hist[$iid] ?? ['max_sent'=>0, 'count'=>0, 'last_at'=>null, 'days_since'=>null];
        $days = (int)$r['days_overdue'];
        $since = $h['days_since'];
        $dec = ak_rel_decide($days, (int)$h['max_sent'], $since);
        $out[] = [
            'type' => 'invoice',
            'id' => $iid,
            'number' => $r['invoice_number'],
            'client_name' => $r['client_name'] ?: '—',
            'client_email' => $r['client_email'] ?: '',
            'has_email' => !empty($r['client_email']),
            'amount_cents' => (int)$r['amount_ttc_cents'],
            'due_date' => $r['due_date'],
            'days_overdue' => $days,
            'relances_sent' => (int)$h['count'],
            'max_sent' => (int)$h['max_sent'],
            'last_relance_at' => $h['last_at'],
            'stage' => $dec['stage'],
            'due_now' => $dec['due_now'] && !empty($r['client_email']) && !isset($sepa[$iid]),
            'reason' => isset($sepa[$iid]) ? 'Prélèvement SEPA en cours : la banque le confirme sous quelques jours ouvrés.' : $dec['reason'],
            'sepa_pending' => isset($sepa[$iid]),
        ];
    }
    // Prioriser : à relancer maintenant d'abord, puis par retard décroissant.
    usort($out, fn($a,$b) => ($b['due_now'] <=> $a['due_now']) ?: ($b['days_overdue'] <=> $a['days_overdue']));
    return $out;
}
}

if (!function_exists('ak_rel_membership_targets')) {
/**
 * Cotisations expirées / bientôt expirées à relancer.
 */
function ak_rel_membership_targets(PDO $pdo, int $org_id, int $soon_days = 15): array {
    // Paiement en ligne : cotisation payée par prélèvement SEPA, en attente de la banque : pas de relance (l'adhésion
    // n'est prolongée qu'à la confirmation, quelques jours ouvrés plus tard)
    $sepa_sql = '';
    if (function_exists('ak_db_columns') && ak_db_columns($pdo, 'asso_online_payments') && ak_db_columns($pdo, 'org_stripe_accounts')) {
        $sepa_sql = "
           AND NOT EXISTS (SELECT 1 FROM asso_online_payments op
                           JOIN org_stripe_accounts a ON a.stripe_account_id = op.stripe_account_id AND a.org_id = op.org_id
                           WHERE op.org_id = u.org_id AND op.adherent_id = u.id AND op.kind = 'cotisation' AND op.status = 'processing')";
    }
    $st = $pdo->prepare(
        "SELECT id, first_name, last_name, email, DATE(adhesion_valid_until) fin,
                DATEDIFF(CURDATE(), DATE(adhesion_valid_until)) days_past
         FROM users u
         WHERE org_id = :o AND deleted_at IS NULL AND is_active = 1
           AND adhesion_valid_until IS NOT NULL
           AND adhesion_valid_until < DATE_ADD(CURDATE(), INTERVAL :d DAY)" . $sepa_sql . "
         ORDER BY adhesion_valid_until ASC
         LIMIT 500");
    $st->bindValue(':o', $org_id, PDO::PARAM_INT);
    $st->bindValue(':d', $soon_days, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    $ids = array_map(fn($r)=>(int)$r['id'], $rows);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $hist = [];
    try {
        // Relances de l'échéance en cours seulement (envoyées au plus tôt à J-$soon_days) : après
        // un renouvellement, les rappels du cycle précédent ne comptent plus et l'adhérent
        // repart du rappel amical, pas de la mise en demeure.
        $hs = $pdo->prepare(
            "SELECT r.user_id, MAX(r.stage) max_sent, COUNT(*) n, MAX(r.sent_at) last_at
             FROM asso_membership_reminders r
             JOIN users u ON u.id = r.user_id AND u.org_id = r.org_id
             WHERE r.org_id = ? AND r.user_id IN ($place)
               AND r.sent_at >= DATE_SUB(DATE(u.adhesion_valid_until), INTERVAL ? DAY)
             GROUP BY r.user_id");
        $hs->execute(array_merge([$org_id], $ids, [$soon_days + 1]));
        foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $hist[(int)$h['user_id']] = ['max_sent'=>(int)$h['max_sent'], 'count'=>(int)$h['n'], 'last_at'=>$h['last_at']];
        }
    } catch (Throwable $e) { /* table absente : 0 relance */ }

    $out = [];
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $h = $hist[$uid] ?? ['max_sent'=>0, 'count'=>0, 'last_at'=>null];
        $days = (int)$r['days_past']; // négatif = pas encore expiré (bientôt)
        // Stades cotisation : 1 dès expiration (ou J-15 avant), 2 à +14, 3 à +30.
        $target = $days >= 30 ? 3 : ($days >= 14 ? 2 : ($days >= -$soon_days ? 1 : 0));
        $since = $h['last_at'] ? (int)floor((time() - strtotime($h['last_at']))/86400) : null;
        $rec = min($h['max_sent'] + 1, max($target,1));
        if ($rec > 3) $rec = 3;
        $due_now = $target > 0 && $rec > $h['max_sent']
                   && ($since === null || $since >= AK_REL_MIN_GAP_DAYS)
                   && !empty($r['email']);
        $out[] = [
            'type' => 'membership',
            'id' => $uid,
            'name' => trim($r['first_name'].' '.$r['last_name']),
            'client_email' => $r['email'] ?: '',
            'has_email' => !empty($r['email']),
            'fin' => $r['fin'],
            'days_overdue' => $days,
            'expired' => $days >= 0,
            'relances_sent' => (int)$h['count'],
            'last_relance_at' => $h['last_at'],
            'stage' => $rec,
            'due_now' => $due_now,
            'reason' => $days >= 0 ? "Expirée depuis $days j." : 'Expire bientôt.',
        ];
    }
    usort($out, fn($a,$b) => ($b['due_now'] <=> $a['due_now']) ?: ($b['days_overdue'] <=> $a['days_overdue']));
    return $out;
}
}

if (!function_exists('ak_rel_send_membership')) {
/**
 * Envoie une relance de cotisation à un adhérent et journalise.
 * @return array{success:bool, message:string}
 */
function ak_rel_send_membership(PDO $pdo, int $org_id, int $user_id, int $stage, ?int $sent_by = null, ?string $custom_body = null, ?string $custom_subject = null): array {
    $stage = max(1, min(3, $stage));
    $st = $pdo->prepare("SELECT u.first_name, u.last_name, u.email, DATE(u.adhesion_valid_until) fin, o.name org_name, o.billing_email org_email
                         FROM users u JOIN organizations o ON o.id = u.org_id
                         WHERE u.id = ? AND u.org_id = ? LIMIT 1");
    $st->execute([$user_id, $org_id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return ['success'=>false, 'message'=>'Adhérent introuvable'];
    if (empty($m['email']) || !filter_var($m['email'], FILTER_VALIDATE_EMAIL)) return ['success'=>false, 'message'=>'Email adhérent invalide'];

    $vars = [
        '{NOM_ADHERENT}' => trim($m['first_name'].' '.$m['last_name']) ?: 'cher adhérent',
        '{PRENOM}'       => $m['first_name'] ?: '',
        '{NOM_ASSO}'     => $m['org_name'] ?: 'votre association',
        '{DATE_FIN}'     => $m['fin'] ? date('d/m/Y', strtotime($m['fin'])) : '',
    ];
    $tpl = ak_rel_membership_template($stage);
    $subject = strtr($custom_subject ?: $tpl['subject'], $vars + ['{LIEN_COTISATION}' => '', '{LIEN_PAIEMENT}' => '']);
    $body    = strtr($custom_body    ?: $tpl['body'],    $vars);
    // Paiement en ligne (Stripe Connect) : lien PERSONNEL de la campagne d'adhésion en cours ({LIEN_COTISATION}
    // remplacé, ou paragraphe ajouté) ; sans paiement en ligne, le marqueur est retiré
    $link = '';
    try {
        require_once __DIR__ . '/stripe-connect-helpers.php';
        $link = ak_connect_campaign_member_link($pdo, $org_id, $user_id);
        $body = ak_connect_add_link($body, $link, "Renouvelez votre cotisation en ligne en quelques clics (carte bancaire, Apple Pay ou Google Pay) :\n" . $link);
    } catch (Throwable $e) {
        $body = str_replace(['{LIEN_COTISATION}', '{LIEN_PAIEMENT}'], '', $body);
    }

    $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    // Liens cliquables : l'adresse s'arrête avant le « <br /> » ajouté par nl2br (sinon le lien personnel finit en « …<br »)
    if ($link !== '') $html = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#059669;font-weight:600;">$1</a>', $html);

    try {
        ak_asso_send_resend($m['email'], $subject, $html, $m['org_email'] ?: null, null, $m['org_name']);
        try {
            $pdo->prepare("INSERT INTO asso_membership_reminders (org_id, user_id, stage, channel, sent_by_user_id, sent_at) VALUES (?,?,?,?,?,NOW())")
                ->execute([$org_id, $user_id, $stage, 'email', $sent_by]);
        } catch (Throwable $e) {}
        return ['success'=>true, 'message'=>'Relance envoyée à '.$m['email']];
    } catch (Throwable $e) {
        return ['success'=>false, 'message'=>'Erreur envoi : '.$e->getMessage()];
    }
}
}

if (!function_exists('ak_rel_membership_template')) {
function ak_rel_membership_template(int $stage): array {
    $t = [
        1 => [
            'subject' => '{NOM_ASSO} — Votre adhésion arrive à échéance',
            'body' => "Bonjour {PRENOM},\n\n"
                . "Votre adhésion à {NOM_ASSO} arrive à échéance (valable jusqu'au {DATE_FIN}).\n\n"
                . "Nous serions ravis de continuer à vous compter parmi nous : pensez à renouveler votre cotisation quand vous le pourrez.\n\n"
                . "Merci pour votre soutien,\nL'équipe de {NOM_ASSO}",
        ],
        2 => [
            'subject' => '{NOM_ASSO} — Pensez à renouveler votre adhésion',
            'body' => "Bonjour {PRENOM},\n\n"
                . "Sauf erreur de notre part, votre cotisation à {NOM_ASSO} n'a pas encore été renouvelée (échéance : {DATE_FIN}).\n\n"
                . "Votre soutien nous est précieux pour poursuivre nos actions. Le renouvellement ne prend que quelques minutes.\n\n"
                . "Au plaisir de vous revoir,\n{NOM_ASSO}",
        ],
        3 => [
            'subject' => '{NOM_ASSO} — Dernier rappel : votre adhésion',
            'body' => "Bonjour {PRENOM},\n\n"
                . "Il s'agit d'un dernier rappel concernant votre adhésion à {NOM_ASSO}, échue depuis le {DATE_FIN}.\n\n"
                . "Sans renouvellement de votre part, votre statut de membre sera prochainement clôturé. Nous espérons sincèrement vous conserver parmi nous.\n\n"
                . "Bien à vous,\n{NOM_ASSO}",
        ],
    ];
    return $t[$stage] ?? $t[1];
}
}

if (!function_exists('ak_rel_send_invoice_custom')) {
/**
 * Envoie une relance de FACTURE avec un texte fourni (message personnalisé /
 * reformulé par l'IA), joint le PDF et journalise (asso_invoice_emails_log).
 * Le stade détermine l'email_type dunningN pour l'historique/cadence.
 */
function ak_rel_send_invoice_custom(PDO $pdo, int $org_id, int $invoice_id, int $stage, string $subject, string $body, ?int $sent_by = null): array {
    $stage = max(1, min(3, $stage));
    $st = $pdo->prepare("SELECT i.*, c.email client_email, c.display_name client_name, o.name org_name, o.billing_email org_email
                         FROM asso_invoices i
                         LEFT JOIN asso_clients c ON c.id = i.client_id
                         LEFT JOIN organizations o ON o.id = i.org_id
                         WHERE i.id = ? AND i.org_id = ? LIMIT 1");
    $st->execute([$invoice_id, $org_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return ['success'=>false, 'message'=>'Facture introuvable'];
    $to = $inv['client_email'] ?? '';
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return ['success'=>false, 'message'=>'Email client invalide'];

    // Paiement en ligne (Stripe Connect) : lien « Payer en ligne » ajouté au message (ou {LIEN_PAIEMENT} remplacé)
    try {
        require_once __DIR__ . '/stripe-connect-helpers.php';
        $pay = ak_connect_invoice_pay_url($pdo, $inv);
        $body = ak_connect_add_link($body, $pay, $pay === '' ? '' : ak_connect_pay_paragraph($pay,
            ak_connect_sepa_offered($pdo, $org_id, 'invoice', (string)($inv['currency'] ?: 'EUR'))));
        $subject = str_replace('{LIEN_PAIEMENT}', '', $subject);
    } catch (Throwable $e) {
        $body = str_replace('{LIEN_PAIEMENT}', '', $body);
    }

    $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#059669;font-weight:600;">$1</a>', $html);
    $cc = !empty($inv['org_email']) ? $inv['org_email'] : null;

    $attachment = null;
    $pdf_full = __DIR__ . ($inv['pdf_path'] ?? '');
    if (!empty($inv['pdf_path']) && file_exists($pdf_full)) {
        $attachment = ['filename'=>$inv['invoice_number'].'.pdf', 'content'=>base64_encode(file_get_contents($pdf_full))];
    }

    $log = $pdo->prepare("INSERT INTO asso_invoice_emails_log
        (invoice_id, org_id, recipient_email, cc_email, email_type, subject, status, sent_by_user_id)
        VALUES (?,?,?,?,?,?,'queued',?)");
    $log->execute([$invoice_id, $org_id, $to, $cc, 'dunning'.$stage, mb_substr($subject,0,255), $sent_by]);
    $log_id = (int)$pdo->lastInsertId();

    try {
        ak_asso_send_resend($to, $subject, $html, $cc, $attachment, $inv['org_name']);
        $pdo->prepare("UPDATE asso_invoice_emails_log SET status='sent', sent_at=NOW() WHERE id=?")->execute([$log_id]);
        return ['success'=>true, 'message'=>'Relance envoyée à '.$to];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE asso_invoice_emails_log SET status='failed', error_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,1000), $log_id]);
        return ['success'=>false, 'message'=>'Erreur envoi : '.$e->getMessage()];
    }
}
}

if (!function_exists('ak_rel_eur')) {
function ak_rel_eur(int $cents): string { return number_format($cents/100, 2, ',', ' ').' €'; }
}
