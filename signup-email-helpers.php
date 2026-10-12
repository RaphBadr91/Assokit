<?php
/**
 * ============================================================
 * ASSOKIT — Emails du tunnel d'inscription DEMO
 * ============================================================
 *  - ak_signup_alert_founder() : previent le fondateur de CHAQUE demande
 *    d'essai (activee, a verifier, ou echouee -> prospect a rappeler).
 *  - send_demo_welcome_email() : bienvenue au prospect, essai actif
 *    jusqu'au JJ/MM, avec bouton de confirmation d'email.
 *  - ak_signup_ack_prospect() : accuse de reception si l'ouverture a echoue.
 *  - Verification d'email par signature HMAC (aucune table requise) :
 *    ak_email_verify_url() / ak_email_verify_check().
 *
 * Dependance : resend-helper.php (send_transactional_email, email_wrap).
 * Toutes les fonctions sont defensives : un echec d'envoi ne bloque
 * jamais l'inscription.
 * ============================================================
 */

@require_once __DIR__ . '/resend-helper.php';

if (!defined('AK_DEMO_NOTIFY_EMAIL')) {
    // Boite qui recoit les alertes "nouveau prospect"
    define('AK_DEMO_NOTIFY_EMAIL', 'contact@assokit.fr');
}

/**
 * Secret serveur pour signer les liens de verification d'email.
 * Reutilise une constante deja secrete de config.php ; jamais expose.
 */
function ak_email_verify_secret(): string
{
    if (defined('RESEND_API_KEY') && RESEND_API_KEY) return 'akv1:' . RESEND_API_KEY;
    if (defined('SITE_URL') && SITE_URL)             return 'akv1:' . SITE_URL . ':assokit';
    return 'akv1:assokit-email-verify';
}

/**
 * Adresse ACTUELLE du compte (minuscules), '' si introuvable. Le lien de confirmation la signe : un
 * ancien lien (inscription, adresse précédente) ne confirme jamais une adresse changée depuis.
 */
function ak_email_verify_address(int $user_id): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || $user_id <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $st->execute([$user_id]);
        return mb_strtolower(trim((string)$st->fetchColumn()), 'UTF-8');
    } catch (Throwable $e) {
        return '';
    }
}

/** URL de confirmation d'email pour un utilisateur donne (adresse en cours, voir ak_email_verify_address). */
function ak_email_verify_url(int $user_id): string
{
    $email = ak_email_verify_address($user_id);
    $data = $email !== '' ? 'verify_email:' . $user_id . ':' . $email : 'verify_email:' . $user_id;
    $sig = hash_hmac('sha256', $data, ak_email_verify_secret());
    return 'https://assokit.fr/verifier-email?u=' . $user_id . '&s=' . $sig;
}

/**
 * Verifie la signature d'un lien de confirmation d'email : elle doit porter l'adresse actuelle du
 * compte. Les liens envoyés avant le 12/10/2026 (identifiant seul) restent valables jusqu'au
 * 26/10/2026, le temps qu'ils servent ; ensuite seuls les liens liés à l'adresse confirment.
 */
function ak_email_verify_check(int $user_id, string $sig): bool
{
    if ($user_id <= 0 || $sig === '') return false;
    $email = ak_email_verify_address($user_id);
    if ($email !== '' && hash_equals(hash_hmac('sha256', 'verify_email:' . $user_id . ':' . $email, ak_email_verify_secret()), $sig)) {
        return true;
    }
    if (time() >= strtotime('2026-10-27 00:00:00')) return false;
    return hash_equals(hash_hmac('sha256', 'verify_email:' . $user_id, ak_email_verify_secret()), $sig);
}

/** Texte sûr pour un e-mail : UTF-8 valide, échappé. */
function ak_signup_h($v): string
{
    $v = (string)$v;
    if (function_exists('mb_scrub')) $v = mb_scrub($v, 'UTF-8');
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Alerte le fondateur pour CHAQUE demande d'essai, quoi qu'il arrive.
 *   $kind : 'ok'         → essai activé automatiquement
 *           'incomplete' → compte créé mais un élément de l'essai a échoué ($warnings)
 *           'failed'     → la création a échoué : le prospect est à rappeler ($tech_error)
 * Canaux, chacun indépendant (un échec n'empêche pas les autres) :
 *   1. e-mail à contact@assokit.fr et aux adresses fondateur (répondre = écrire au prospect) ;
 *   2. notification dans la cloche fondateur (site + appli) ;
 *   3. en cas d'échec, ou si l'e-mail n'est pas parti : copie dans les messages de contact
 *      du cockpit, pour qu'aucune demande ne soit jamais perdue.
 * Jamais de mot de passe ni de donnée sensible.
 */
function ak_signup_alert_founder(PDO $pdo, array $lead, string $kind, ?int $org_id = null, ?string $tech_error = null, array $warnings = []): void
{
    @require_once __DIR__ . '/trial-helpers.php';
    $org   = (string)($lead['org_name'] ?? '');
    $name  = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $email = (string)($lead['email'] ?? '');
    $end   = function_exists('ak_trial_end_label') ? ak_trial_end_label($lead['trial_end'] ?? null) : '';
    $days  = defined('AK_TRIAL_DAYS') ? AK_TRIAL_DAYS : 14;

    if ($kind === 'failed') {
        $subject = '⚠️ Demande d’essai à rappeler : ' . $org;
        $title   = 'Demande d’essai &mdash; ouverture automatique impossible';
        $intro   = '<p>Un prospect a demandé un essai gratuit, mais son espace n’a pas pu être créé automatiquement. '
                 . '<strong>Recontactez-le</strong> (répondre à cet e-mail lui écrit directement) et créez son espace depuis le cockpit.</p>';
        $cta     = ['📞 Écrire au prospect', 'mailto:' . rawurlencode($email)];
    } elseif ($kind === 'incomplete') {
        $subject = 'Nouvel essai (à vérifier) : ' . $org;
        $title   = 'Nouvel essai gratuit &mdash; à vérifier';
        $intro   = '<p>L’espace est créé et le prospect est connecté, mais un élément de l’essai n’a pas pu être préparé (voir ci-dessous).</p>';
        $cta     = ['👉 Ouvrir l’association', 'https://assokit.fr/super-admin/associations?id=' . (int)$org_id];
    } else {
        $subject = 'Nouvel essai PRO ' . $days . ' j : ' . $org . ' (' . (($lead['type'] ?? '') === 'tpe' ? 'TPE' : 'association') . ')';
        $title   = 'Nouvel essai PRO activé &#128640;';
        $intro   = '<p>Un prospect vient de remplir la demande d’essai : son essai PRO de ' . (int)$days . ' jours est <strong>déjà actif</strong>. '
                 . 'Voici toutes les informations qu’il a saisies &mdash; c’est le bon moment pour l’appeler.</p>';
        $cta     = ['👉 Ouvrir l’association', 'https://assokit.fr/super-admin/associations?id=' . (int)$org_id];
    }

    $type_lbl = ($lead['type'] ?? '') === 'tpe' ? 'TPE / entreprise' : 'Association';
    $rows = [
        'Structure'    => '<strong>' . ak_signup_h($org) . '</strong> &middot; ' . $type_lbl,
        'Contact'      => ak_signup_h($name),
        'E-mail'       => '<a href="mailto:' . ak_signup_h($email) . '">' . ak_signup_h($email) . '</a>',
    ];
    if (!empty($lead['phone'])) {
        $tel = preg_replace('/[^0-9+]/', '', (string)$lead['phone']);
        $rows['Téléphone'] = '<a href="tel:' . ak_signup_h($tel) . '">' . ak_signup_h($lead['phone']) . '</a>';
    }
    // Toutes les informations saisies dans le formulaire (forme juridique, SIRET/RNA, adresse, taille, besoins…)
    foreach ((array)($lead['details'] ?? []) as $k => $v) {
        if (in_array($k, ['Type', 'Téléphone'], true) || $v === '' || $v === null) continue;
        $rows[ak_signup_h($k)] = nl2br(ak_signup_h($v));
    }
    $rows['Formule vue sur le site'] = ak_signup_h($lead['plan'] ?? '');
    if ($end !== '' && $kind !== 'failed') $rows['Fin de l’essai'] = ak_signup_h($end);
    if (!empty($lead['ip'])) $rows['Adresse IP'] = ak_signup_h($lead['ip']);
    if (!empty($lead['signup_id'])) $rows['Demande n°'] = (int)$lead['signup_id'];
    $tbl = '<table style="font-size:14px;color:#44403C;line-height:1.9;margin:12px 0;">';
    foreach ($rows as $k => $v) $tbl .= '<tr><td style="padding-right:16px;color:#78716C;vertical-align:top;">' . $k . '</td><td>' . $v . '</td></tr>';
    $tbl .= '</table>';
    $extra = '';
    if ($warnings) {
        $extra .= '<p style="margin-top:14px;"><strong>Points à vérifier :</strong></p><ul>';
        foreach ($warnings as $w) $extra .= '<li>' . ak_signup_h($w) . '</li>';
        $extra .= '</ul>';
    }
    if ($tech_error) {
        $extra .= '<p style="margin-top:14px;font-size:12px;color:#78716C;">Détail technique : <code>' . ak_signup_h(mb_substr($tech_error, 0, 300)) . '</code></p>';
    }
    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">' . $title . '</h1>' . $intro . $tbl . $extra;

    // 1. E-mail
    $mail_ok = false;
    try {
        if (function_exists('send_transactional_email')) {
            $html = function_exists('email_wrap') ? email_wrap('Demande d’essai Assokit', $content, $cta[0], $cta[1]) : $content;
            $to = function_exists('ak_founder_emails') ? ak_founder_emails() : [AK_DEMO_NOTIFY_EMAIL];
            $opts = ['tag' => 'demo_founder_notif'];
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $opts['reply_to'] = $email;
            foreach ($to as $dest) {
                $res = send_transactional_email($dest, $subject, $html, $opts);
                if (!empty($res['success'])) $mail_ok = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[signup alert] e-mail: ' . get_class($e));
    }

    // 2. Cloche fondateur (site + appli)
    try {
        @require_once __DIR__ . '/notification-helpers.php';
        if (function_exists('ak_notif_create') && function_exists('ak_founder_user_ids')) {
            $titre = $kind === 'failed' ? '⚠️ Demande d’essai à rappeler : ' . $org : ($kind === 'incomplete' ? 'Nouvel essai (à vérifier) : ' . $org : 'Nouvel essai activé : ' . $org);
            $corps = $name . ' · ' . $email;
            $lien  = $org_id ? '/super-admin/associations?id=' . (int)$org_id : '/super-admin';
            foreach (ak_founder_user_ids($pdo) as $uid) ak_notif_create($pdo, $uid, 'signup', mb_substr($titre, 0, 190), mb_substr($corps, 0, 250), $lien);
        }
    } catch (Throwable $e) {
        error_log('[signup alert] cloche: ' . get_class($e));
    }

    // 3. Trace dans les messages de contact du cockpit (échec, ou e-mail non parti)
    if ($kind === 'failed' || !$mail_ok) {
        ak_signup_record_contact($pdo, $lead, $kind === 'failed' ? " — création automatique IMPOSSIBLE, à rappeler." : " — e-mail d'alerte non parti.", $warnings, $tech_error);
    }
}

/** Copie d'une demande d'essai dans les messages de contact du cockpit (aucune demande ne se perd). */
function ak_signup_record_contact(PDO $pdo, array $lead, string $note, array $warnings = [], ?string $tech_error = null): void
{
    try {
        if (!function_exists('ak_db_insert') || !ak_db_columns($pdo, 'asso_contact_messages')) return;
        $org   = (string)($lead['org_name'] ?? '');
        $name  = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $email = (string)($lead['email'] ?? '');
        $msg = "Demande d'essai gratuit" . $note
             . "\nOrganisation : " . $org . "\nContact : " . $name . "\nE-mail : " . $email
             . (!empty($lead['phone']) && empty($lead['details']['Téléphone']) ? "\nTéléphone : " . $lead['phone'] : '')
             . implode('', array_map(fn($k, $v) => "\n" . $k . ' : ' . $v, array_keys((array)($lead['details'] ?? [])), (array)($lead['details'] ?? [])))
             . ($warnings ? "\nPoints à vérifier : " . implode(' | ', $warnings) : '')
             . ($tech_error ? "\nDétail technique : " . mb_substr($tech_error, 0, 300) : '');
        ak_db_insert($pdo, 'asso_contact_messages', [
            'firstname' => mb_substr((string)($lead['first_name'] ?? ''), 0, 100),
            'lastname' => mb_substr((string)($lead['last_name'] ?? ''), 0, 100),
            'email' => mb_substr($email, 0, 190),
            'organization' => mb_substr($org, 0, 200),
            'type' => '',
            'subject' => mb_substr('[ESSAI] ' . $org, 0, 200),
            'message' => $msg,
            'ip' => (string)($lead['ip'] ?? ''),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ], ['created_at' => 'NOW()']);
    } catch (Throwable $e) {
        error_log('[signup alert] contact: ' . get_class($e));
    }
}

/** Objet (et repère) du résumé horaire dans les messages de contact du cockpit. */
if (!defined('AK_SIGNUP_DIGEST_SUBJECT')) define('AK_SIGNUP_DIGEST_SUBJECT', '[ESSAI-RESUME]');

/** Drapeau « un résumé attend son heure » (hors préfixe akrl_ : le ménage du limiteur ne l'efface pas). */
function ak_signup_digest_flag(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/ak_signup_digest_pending';
}

/**
 * Repère du résumé : n° (public_signups.id) de la dernière demande déjà résumée, lu dans le
 * dernier résumé copié au cockpit, ou à défaut dans un fichier. Un repère par n° et non par
 * date : une demande enregistrée pendant l'envoi d'un résumé est datée d'avant lui, et
 * serait oubliée par les deux résumés.
 */
function ak_signup_digest_last_id(PDO $pdo, ?string $mark): int
{
    $last = (int)@file_get_contents(rtrim(sys_get_temp_dir(), '/') . '/ak_signup_digest_last_id');
    if ($mark !== null) {
        try {
            $msg = (string)$pdo->query("SELECT message FROM asso_contact_messages WHERE $mark ORDER BY id DESC LIMIT 1")->fetchColumn();
            if (preg_match('/\[repère #(\d+)/u', $msg, $m)) $last = max($last, (int)$m[1]);
        } catch (Throwable $e) {}
    }
    // Table vidée depuis (n° repartis de 1) : on repart de zéro
    try {
        if ($last > (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM public_signups")->fetchColumn()) $last = 0;
    } catch (Throwable $e) {}
    return $last;
}

/**
 * Rafale de demandes non ouvertes (disjoncteur anti-abus, limites par IP / par adresse,
 * incidents en nombre) : au lieu d'une alerte par demande, AU PLUS UN résumé par heure au
 * fondateur (e-mail + cloche + copie dans les messages de contact du cockpit). Il liste les
 * demandes non ouvertes après celles du résumé précédent (ak_signup_digest_last_id ; 24 à
 * 48 h au plus), toutes enregistrées dans public_signups.
 * Si un résumé est déjà parti dans l'heure, un drapeau est posé : le suivant part à la
 * première visite de /signup une fois l'heure passée, pour que la fin d'une rafale ne
 * reste jamais sans alerte.
 * @return bool true si un résumé est parti
 */
function ak_signup_digest_maybe(PDO $pdo): bool
{
    @require_once __DIR__ . '/trial-helpers.php';
    $flag = ak_signup_digest_flag();
    try {
        $has_contact = (bool)ak_db_columns($pdo, 'asso_contact_messages');
        // Repère infalsifiable : les formulaires publics exigent un e-mail, le résumé n'en a pas
        $mark = $has_contact ? "subject LIKE '" . AK_SIGNUP_DIGEST_SUBJECT . "%' AND (email = '' OR email IS NULL)" : null;
        if ($mark !== null && (int)$pdo->query("SELECT COUNT(*) FROM asso_contact_messages WHERE $mark AND created_at > NOW() - INTERVAL 1 HOUR")->fetchColumn() > 0) {
            @touch($flag);
            return false;
        }

        $cols = ak_db_columns($pdo, 'public_signups');
        if (!isset($cols['id'], $cols['created_at'])) { @unlink($flag); return false; }
        $last = ak_signup_digest_last_id($pdo, $mark);
        // CURDATE() : juste aussi si created_at n'est qu'une date
        $where = ['id > ?', 'created_at >= CURDATE() - INTERVAL 1 DAY'];
        $args = [$last];
        $pending = 0;
        if (isset($cols['status'])) {
            // Demande d'une requête en cours (pas encore « blocked ») : le résumé s'arrête juste
            // avant elle, elle et les suivantes iront dans le prochain
            $st = $pdo->prepare("SELECT COALESCE(MIN(id), 0) FROM public_signups WHERE id > ? AND status = 'pending' AND created_at >= NOW() - INTERVAL 10 MINUTE");
            $st->execute([$last]);
            if ($pending = (int)$st->fetchColumn()) { $where[] = 'id < ?'; $args[] = $pending; }
            $where[] = "status = 'blocked'";
        }
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(id), 0) FROM public_signups WHERE " . implode(' AND ', $where));
        $st->execute($args);
        [$total, $max_id] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        if ($total === 0) {
            if ($pending) @touch($flag); else @unlink($flag);
            return false;
        }

        // Une seule fois par heure, même si deux demandes arrivent ensemble (ou sans table de contact)
        @require_once __DIR__ . '/rate-limit-helper.php';
        if (function_exists('ak_rate_limit') && !ak_rate_limit('signup_digest', 1, 3600, 'all')) { @touch($flag); return false; }

        // Les demandes comptées, et seulement elles : le repère est le plus grand n° lu
        $where[] = 'id <= ?';
        $args[] = $max_id;
        $st = $pdo->prepare("SELECT * FROM public_signups WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 60");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[signup digest] ' . get_class($e));
        return false;
    }

    $lines_html = '';
    $lines_txt = '';
    foreach ($rows as $r) {
        $who   = trim(($r['admin_first_name'] ?? '') . ' ' . ($r['admin_last_name'] ?? ''));
        $mail  = (string)($r['admin_email'] ?? '');
        $why   = (string)($r['error_message'] ?? '');
        $when  = !empty($r['created_at']) ? date('d/m H:i', strtotime((string)$r['created_at'])) : '';
        $lines_html .= '<tr><td style="padding:4px 10px 4px 0;color:#78716C;vertical-align:top;white-space:nowrap;">' . ak_signup_h($when) . '</td>'
                     . '<td style="padding:4px 0;vertical-align:top;"><strong>' . ak_signup_h($r['org_name'] ?? '—') . '</strong>'
                     . ($who !== '' ? ' &middot; ' . ak_signup_h($who) : '')
                     . ($mail !== '' ? ' &middot; <a href="mailto:' . ak_signup_h($mail) . '">' . ak_signup_h($mail) . '</a>' : '')
                     . (!empty($r['phone']) ? ' &middot; ' . ak_signup_h($r['phone']) : '')
                     . (!empty($r['ip_address']) || $why !== '' ? '<br><span style="font-size:12px;color:#78716C;">' . (!empty($r['ip_address']) ? 'IP ' . ak_signup_h($r['ip_address']) : '')
                       . (!empty($r['ip_address']) && $why !== '' ? ' &middot; ' : '') . ak_signup_h(mb_substr($why, 0, 160)) . '</span>' : '')
                     . '</td></tr>';
        $lines_txt .= "\n- " . $when . ' · ' . ($r['org_name'] ?? '—') . ($who !== '' ? ' · ' . $who : '') . ($mail !== '' ? ' · ' . $mail : '')
                    . (!empty($r['phone']) ? ' · ' . $r['phone'] : '') . (!empty($r['ip_address']) ? ' · IP ' . $r['ip_address'] : '');
    }
    $more = $total > count($rows) ? '<p style="font-size:13px;color:#78716C;">… et ' . ($total - count($rows)) . ' autre(s), dans la table public_signups.</p>' : '';
    $subject = '⚠️ ' . $total . ' demande' . ($total > 1 ? 's' : '') . ' d’essai non ouverte' . ($total > 1 ? 's' : '') . ' (résumé)';
    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">Demandes d’essai non ouvertes automatiquement</h1>'
             . '<p>De nombreuses demandes d’essai n’ont pas pu être ouvertes automatiquement (protections anti-abus : plus de 30 essais ouverts en 1 h, '
             . 'trop de demandes depuis une même adresse ; ou incident technique, voir le motif de chaque demande). '
             . 'Pour ne pas vous inonder, vous recevez <strong>un seul résumé par heure</strong> au lieu d’une alerte par demande, '
             . 'et aucun accusé de réception n’est envoyé aux adresses saisies.</p>'
             . '<p><strong>' . $total . '</strong> demande' . ($total > 1 ? 's' : '') . ' depuis le résumé précédent. Vérifiez-' . ($total > 1 ? 'les' : 'la') . ', puis créez les espaces légitimes depuis le cockpit&nbsp;:</p>'
             . '<table style="font-size:14px;color:#44403C;line-height:1.5;margin:12px 0;">' . $lines_html . '</table>' . $more;

    try {
        if (function_exists('send_transactional_email')) {
            $html = function_exists('email_wrap') ? email_wrap('Demandes d’essai Assokit', $content, '👉 Ouvrir le cockpit', 'https://assokit.fr/super-admin') : $content;
            $to = function_exists('ak_founder_emails') ? ak_founder_emails() : [AK_DEMO_NOTIFY_EMAIL];
            foreach ($to as $dest) send_transactional_email($dest, $subject, $html, ['tag' => 'demo_founder_digest']);
        }
    } catch (Throwable $e) {
        error_log('[signup digest] e-mail: ' . get_class($e));
    }
    try {
        @require_once __DIR__ . '/notification-helpers.php';
        if (function_exists('ak_notif_create') && function_exists('ak_founder_user_ids')) {
            foreach (ak_founder_user_ids($pdo) as $uid) {
                ak_notif_create($pdo, $uid, 'signup', mb_substr($subject, 0, 190), 'Résumé horaire : demandes enregistrées, à vérifier.', '/super-admin');
            }
        }
    } catch (Throwable $e) {
        error_log('[signup digest] cloche: ' . get_class($e));
    }
    // Copie dans les messages de contact (et repère du prochain résumé)
    try {
        if ($has_contact) {
            ak_db_insert($pdo, 'asso_contact_messages', [
                'firstname' => 'Inscription', 'lastname' => 'Résumé', 'email' => '', 'organization' => '',
                'type' => '', 'subject' => mb_substr(AK_SIGNUP_DIGEST_SUBJECT . ' ' . $total . ' demande(s) non ouverte(s)', 0, 200),
                'message' => "Demandes d'essai non ouvertes automatiquement, depuis le résumé précédent :" . $lines_txt
                           . ($total > count($rows) ? "\n… et " . ($total - count($rows)) . " autre(s), dans la table public_signups." : '')
                           . "\n[repère #" . $max_id . " : dernière demande résumée]",
                'ip' => '', 'user_agent' => '',
            ], ['created_at' => 'NOW()']);
        }
    } catch (Throwable $e) {
        error_log('[signup digest] contact: ' . get_class($e));
    }
    @file_put_contents(rtrim(sys_get_temp_dir(), '/') . '/ak_signup_digest_last_id', (string)$max_id, LOCK_EX);
    // Demandes arrivées pendant l'envoi (ou laissées pour le prochain) : le drapeau reste
    try {
        $st = $pdo->prepare("SELECT 1 FROM public_signups WHERE id > ? AND created_at >= CURDATE() - INTERVAL 1 DAY"
                            . (isset($cols['status']) ? " AND status IN ('blocked', 'pending')" : '') . " LIMIT 1");
        $st->execute([$max_id]);
        $rest = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $rest = true;
    }
    if ($rest) @touch($flag); else @unlink($flag);
    return true;
}

/** Compatibilité : ancienne alerte (même canal que l'alerte complète). */
function send_demo_founder_notification(string $org_name, string $admin_name, string $admin_email): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) return false;
    $parts = explode(' ', $admin_name, 2);
    ak_signup_alert_founder($pdo, ['org_name' => $org_name, 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'email' => $admin_email], 'ok');
    return true;
}

/**
 * Bienvenue au prospect : son essai est ACTIF, jusqu'au JJ/MM/AAAA.
 */
function send_demo_welcome_email(string $email, string $first_name, string $org_name, string $verify_url, string $trial_end_label = '', string $org_type = 'asso'): bool
{
    if (!function_exists('send_transactional_email')) return false;
    $days = defined('AK_TRIAL_DAYS') ? AK_TRIAL_DAYS : 14;
    // Ce que contient l'espace, dans le vocabulaire de la structure (une TPE n'a ni adhérents ni subventions)
    $features = $org_type === 'tpe'
        ? 'clients, devis, factures, relances, projets, comptabilité analytique'
        : 'projets, adhérents, cotisations, factures, subventions, comptabilité analytique';

    $content = '<h1 style="font-size:22px;margin:0 0 14px;color:#1C1917">Votre essai PRO Assokit est activé &#127881;</h1>'
             . '<p>Bonjour ' . ak_signup_h($first_name) . ',</p>'
             . '<p>L’espace de <strong>' . ak_signup_h($org_name) . '</strong> est prêt&nbsp;: ' . $features . '&hellip; '
             . 'Vous profitez de toutes les fonctionnalités <strong>PRO</strong> pendant <strong>' . (int)$days . ' jours</strong>'
             . ($trial_end_label !== '' ? ', jusqu’au <strong>' . ak_signup_h($trial_end_label) . '</strong>' : '') . ', sans carte bancaire.</p>'
             . '<p>Confirmez votre adresse e-mail pour sécuriser votre accès&nbsp;:</p>'
             . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
             . '&#128172; Une question&nbsp;? Répondez simplement à cet e-mail&nbsp;: l’équipe Assokit vous accompagne pour bien démarrer.'
             . '</div>'
             . '<p style="font-size:12px;color:#78716C;margin-top:18px;">Si vous n’êtes pas à l’origine de cette inscription, ignorez ce message.</p>';

    $html = function_exists('email_wrap')
        ? email_wrap('Bienvenue sur Assokit', $content, '✅ Confirmer mon e-mail', $verify_url)
        : $content . '<p><a href="' . ak_signup_h($verify_url) . '">Confirmer mon e-mail</a></p>';

    try {
        $res = send_transactional_email($email, 'Votre essai PRO Assokit est activé', $html,
            ['tag' => 'demo_welcome', 'reply_to' => defined('AK_DEMO_NOTIFY_EMAIL') ? AK_DEMO_NOTIFY_EMAIL : 'contact@assokit.fr']);
        return !empty($res['success']);
    } catch (Throwable $e) {
        error_log('[demo_welcome] ' . get_class($e));
        return false;
    }
}

/**
 * Accusé de réception quand l'ouverture automatique a échoué :
 * le prospect sait que sa demande est bien arrivée et qu'on le recontacte.
 */
function ak_signup_ack_prospect(string $email, string $first_name, string $org_name): bool
{
    if (!function_exists('send_transactional_email') || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">Votre demande d’essai est bien reçue</h1>'
             . '<p>Bonjour ' . ak_signup_h($first_name) . ',</p>'
             . '<p>Merci pour votre intérêt pour Assokit. Votre demande d’essai gratuit pour <strong>' . ak_signup_h($org_name) . '</strong> nous est bien parvenue.</p>'
             . '<p>Un incident technique nous a empêchés d’ouvrir votre espace automatiquement&nbsp;: notre équipe l’active pour vous et vous écrit '
             . '<strong>sous 24 h ouvrées</strong>. Inutile de recommencer l’inscription.</p>'
             . '<p>Une question en attendant&nbsp;? Répondez simplement à cet e-mail.</p>';
    $html = function_exists('email_wrap') ? email_wrap('Demande d’essai reçue', $content) : $content;
    try {
        $res = send_transactional_email($email, 'Votre demande d’essai Assokit est bien reçue', $html,
            ['tag' => 'demo_ack', 'reply_to' => defined('AK_DEMO_NOTIFY_EMAIL') ? AK_DEMO_NOTIFY_EMAIL : 'contact@assokit.fr']);
        return !empty($res['success']);
    } catch (Throwable $e) {
        error_log('[demo_ack] ' . get_class($e));
        return false;
    }
}
