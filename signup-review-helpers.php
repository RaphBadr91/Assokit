<?php
/**
 * ============================================================
 * ASSOKIT — Demandes de création d'espace faites dans l'app iPhone
 * ============================================================
 * Règle 3.1.1 d'Apple : rien ne se vend ni ne s'essaie dans l'app. Sur iPhone,
 * l'inscription (signup.php, agent « AssokitApp » sans « Android ») est donc une
 * DEMANDE : l'association et son administrateur sont créés, mais rien n'est actif
 * (association « suspended » au motif AK_SIGNUP_REVIEW_REASON, validation_status
 * « pending_founder », ni essai, ni plan, ni abonnement ; la connexion répond
 * « en cours de validation », voir ak_org_access dans app-context.php). Le site et
 * l'app Android gardent l'essai ouvert dès l'inscription.
 *
 * Le Fondateur décide sous 48 h, au choix :
 *   - depuis l'e-mail d'alerte : boutons « Valider » / « Refuser » → /valider-inscription?t=…
 *     (lien signé HMAC-SHA256, valable 7 jours, propre à UNE association et à UNE action ;
 *     la page montre la demande et demande confirmation par un bouton POST + jeton CSRF) ;
 *   - depuis le cockpit web (super-admin-associations.php, « Valider » / « Refuser ») ;
 *   - depuis l'appli (api/app-founder-action.php, validate / reject).
 * Les trois passent par ak_signup_review_validate() / ak_signup_review_reject() : mêmes effets.
 *
 * Valider : l'essai PRO de AK_TRIAL_DAYS jours démarre À CE MOMENT (organizations, plan
 * d'essai, ancienne table subscriptions : ak_signup_trial_rows, comme une inscription sur le
 * site), puis l'administrateur reçoit « Votre espace Assokit est activé ».
 * Refuser : ak_org_reject (refusée ET suspendue, comme le cockpit), e-mail poli au demandeur.
 * Les deux sont idempotents : la ligne de l'association est verrouillée, une demande déjà
 * traitée n'est jamais retraitée (ni second e-mail).
 * Sans décision au bout de 24 h, cron-trial-check.php envoie UN rappel au Fondateur
 * (ak_signup_review_remind_due, carnet cron_envois).
 * ============================================================
 */

require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/app-context.php';   // AK_SIGNUP_REVIEW_REASON, ak_signup_review_pending()

/** Validité du lien de l'e-mail du Fondateur. */
if (!defined('AK_SIGNUP_REVIEW_TTL')) define('AK_SIGNUP_REVIEW_TTL', 7 * 86400);
/** Délai de réponse annoncé au demandeur (page, e-mails, message de connexion). */
if (!defined('AK_SIGNUP_REVIEW_HOURS')) define('AK_SIGNUP_REVIEW_HOURS', 48);

/** Texte sûr pour un e-mail ou une page : UTF-8 valide, échappé. */
function ak_signup_review_h($v): string
{
    $v = (string)$v;
    if (function_exists('mb_scrub')) $v = mb_scrub($v, 'UTF-8');
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Le schéma permet-il de représenter une demande en attente ? Il faut le motif de suspension
 * (assez long pour la marque entière, sinon elle serait coupée et l'espace s'ouvrirait sans
 * validation) et les valeurs « pending_founder » / « suspended ». Droits SHOW refusés : on tente,
 * signup.php relit de toute façon l'association avant de valider la création.
 */
function ak_signup_review_supported(PDO $pdo): bool
{
    $cols = ak_db_columns($pdo, 'organizations');
    if (!$cols) return true;
    if (!isset($cols['suspended_reason'], $cols['validation_status'])) return false;
    if (preg_match('/char\((\d+)\)/', $cols['suspended_reason']['type'], $m) && (int)$m[1] < mb_strlen(AK_SIGNUP_REVIEW_REASON)) return false;
    return ak_db_accepts($pdo, 'organizations', 'validation_status', 'pending_founder')
        && ak_db_accepts($pdo, 'organizations', 'status', 'suspended');
}

/* ------------------------------------------------------------------
 * Lien signé de l'e-mail du Fondateur
 * ------------------------------------------------------------------
 * Jeton « {org}.{v|r}.{expiration}.{HMAC} ». La signature couvre l'association, l'action
 * (v = valider, r = refuser), l'expiration ET la date de création de l'association : un lien ne
 * sert qu'à une action sur une demande précise, et ne vaut rien pour une autre association qui
 * reprendrait le même numéro. Rien n'est stocké : la décision est idempotente (une demande déjà
 * traitée n'est plus « en attente »), rejouer le lien ne change plus rien.
 */

/**
 * Secret serveur des liens, dérivé d'une constante déjà secrète de config.php (jamais dans le
 * dépôt) : AK_CONTACT_SECRET, à défaut RESEND_API_KEY, à défaut CRON_TOKEN. Préfixe propre à cet
 * usage : la signature d'un autre lien du site ne vaut jamais ici. '' si aucun n'est défini :
 * aucun lien n'est alors produit ni accepté (le Fondateur décide depuis le cockpit).
 */
function ak_signup_review_secret(): string
{
    foreach (['AK_CONTACT_SECRET', 'RESEND_API_KEY', 'CRON_TOKEN'] as $c) {
        if (defined($c) && is_string(constant($c)) && constant($c) !== '') {
            return hash('sha256', 'akreview-v1|' . $c . '|' . constant($c));
        }
    }
    return '';
}

/** Signature d'un jeton (hex). */
function ak_signup_review_sign(int $org_id, string $a, int $exp, string $created_at, string $secret): string
{
    return hash_hmac('sha256', 'signup-review|' . $org_id . '|' . $a . '|' . $exp . '|' . $created_at, $secret);
}

/**
 * Lien « Valider » ($action = 'validate') ou « Refuser » ('reject') d'une demande, valable
 * AK_SIGNUP_REVIEW_TTL. null si aucun secret n'est configuré ou si l'association est introuvable.
 */
function ak_signup_review_url(PDO $pdo, int $org_id, string $action): ?string
{
    $secret = ak_signup_review_secret();
    if ($secret === '' || $org_id <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT created_at FROM organizations WHERE id = ? LIMIT 1');
        $st->execute([$org_id]);
        $created = $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if ($created === false) return null;
    $a = $action === 'reject' ? 'r' : 'v';
    $exp = time() + AK_SIGNUP_REVIEW_TTL;
    $t = $org_id . '.' . $a . '.' . $exp . '.' . ak_signup_review_sign($org_id, $a, $exp, (string)$created, $secret);
    return 'https://assokit.fr/valider-inscription?t=' . $t;
}

/**
 * Lit un jeton. ['ok' => true, 'org' => ligne organizations, 'action' => 'validate'|'reject', 'exp' => int]
 * ou ['ok' => false, 'error' => 'malformed'|'signature'|'expired'|'no_secret', 'org' => ?array].
 * La signature est vérifiée AVANT l'expiration (elle la couvre) ; une association inconnue répond
 * comme une mauvaise signature (rien n'est révélé).
 */
function ak_signup_review_parse(PDO $pdo, string $t): array
{
    if (!preg_match('/^(\d{1,10})\.([vr])\.(\d{9,11})\.([a-f0-9]{64})$/', $t, $m)) return ['ok' => false, 'error' => 'malformed', 'org' => null];
    $secret = ak_signup_review_secret();
    if ($secret === '') return ['ok' => false, 'error' => 'no_secret', 'org' => null];
    $org_id = (int)$m[1];
    $exp = (int)$m[3];
    try {
        $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $st->execute([$org_id]);
        $org = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[signup-review] lecture: ' . get_class($e));
        $org = null;
    }
    $expected = ak_signup_review_sign($org_id, $m[2], $exp, (string)($org['created_at'] ?? ''), $secret);
    if (!$org || !hash_equals($expected, $m[4])) return ['ok' => false, 'error' => 'signature', 'org' => null];
    if ($exp < time()) return ['ok' => false, 'error' => 'expired', 'org' => $org];
    return ['ok' => true, 'org' => $org, 'action' => $m[2] === 'r' ? 'reject' : 'validate', 'exp' => $exp];
}

/* ------------------------------------------------------------------
 * Lecture d'une demande
 * ------------------------------------------------------------------ */

/** Administrateur de l'association demandée (créateur, sinon premier administrateur) : ligne users ou null. */
function ak_signup_review_admin(PDO $pdo, array $org): ?array
{
    try {
        $uid = (int)($org['created_by_user_id'] ?? 0);
        if ($uid > 0) {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$uid, (int)$org['id']]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) return $u;
        }
        $st = $pdo->prepare("SELECT * FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $st->execute([(int)$org['id']]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[signup-review] admin: ' . get_class($e));
        return null;
    }
}

/**
 * La demande sous la forme attendue par ak_signup_alert_founder() (même fiche que l'e-mail
 * d'une inscription) : public_signups (tout ce qui a été saisi) complétée par l'association et
 * son administrateur. Clés en plus : requested_at, user_id.
 */
function ak_signup_review_lead(PDO $pdo, array $org): array
{
    $sig = [];
    try {
        if (isset(ak_db_columns($pdo, 'public_signups')['org_id'])) {
            $st = $pdo->prepare("SELECT * FROM public_signups WHERE org_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$org['id']]);
            $sig = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {}
    $adm = ak_signup_review_admin($pdo, $org) ?: [];
    $details = [];
    if (!empty($sig['details_json'])) {
        $dec = json_decode((string)$sig['details_json'], true);
        if (is_array($dec)) foreach ($dec as $k => $v) if (is_scalar($v) && trim((string)$v) !== '') $details[(string)$k] = (string)$v;
    }
    $pick = fn(...$v) => (string)(array_values(array_filter($v, fn($x) => trim((string)$x) !== ''))[0] ?? '');
    return [
        'org_name'     => (string)($org['name'] ?? ''),
        'first_name'   => $pick($sig['admin_first_name'] ?? '', $adm['first_name'] ?? ''),
        'last_name'    => $pick($sig['admin_last_name'] ?? '', $adm['last_name'] ?? ''),
        'email'        => $pick($adm['email'] ?? '', $sig['admin_email'] ?? ''),
        'phone'        => $pick($sig['phone'] ?? '', $adm['phone'] ?? '', $org['billing_phone'] ?? ''),
        'plan'         => '',
        'type'         => $pick($sig['org_type'] ?? '', $org['org_type'] ?? ''),
        'details'      => $details,
        'ip'           => (string)($sig['ip_address'] ?? ''),
        'signup_id'    => isset($sig['id']) ? (int)$sig['id'] : null,
        'trial_end'    => null,
        'requested_at' => (string)($org['created_at'] ?? ''),
        'user_id'      => (int)($adm['id'] ?? 0),
    ];
}

/** « 19/10/2026 à 14 h 05 » à partir d'une date SQL ('' si illisible). */
function ak_signup_review_when(?string $sql): string
{
    $t = $sql ? strtotime($sql) : false;
    return $t ? date('d/m/Y', $t) . ' à ' . date('G', $t) . ' h ' . date('i', $t) : '';
}

/* ------------------------------------------------------------------
 * Décisions (cockpit, appli, lien de l'e-mail)
 * ------------------------------------------------------------------ */

/**
 * Valide une demande d'espace : l'essai PRO démarre maintenant, l'administrateur est prévenu.
 *   ['state' => 'validated', 'trial_end' => SQL, 'warnings' => string[], 'admin' => ?array, 'mailed' => bool]
 *   ['state' => 'not_request', 'org' => ?array] : association introuvable, ou pas (plus) une demande
 *     en attente (déjà validée ou refusée, ou association « à valider » créée par un super-admin) ;
 *     rien n'est modifié, l'appelant applique sa règle habituelle.
 * $by_user_id : le Fondateur (0 = lien de l'e-mail, sans compte connecté). Une erreur SQL remonte.
 */
function ak_signup_review_validate(PDO $pdo, int $org_id, int $by_user_id = 0): array
{
    $own_tx = !$pdo->inTransaction();
    try {
        if ($own_tx) $pdo->beginTransaction();
        // Verrou de la ligne : deux validations simultanées (lien + cockpit) n'ouvrent qu'un essai
        $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ? FOR UPDATE');
        $st->execute([$org_id]);
        $org = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$org || !ak_signup_review_pending($org)) {
            if ($own_tx) $pdo->rollBack();
            return ['state' => 'not_request', 'org' => $org];
        }
        $adm = ak_signup_review_admin($pdo, $org);
        $trial_end  = (string)$pdo->query("SELECT DATE_ADD(NOW(), INTERVAL " . (int)AK_TRIAL_DAYS . " DAY)")->fetchColumn();
        $trial_plan = ak_trial_plan($pdo);

        // Essai ouvert à la validation, comme signup.php l'ouvre à l'inscription sur le site
        $upd = ['trial_ends_at' => $trial_end, 'suspended_reason' => null, 'validated_by_user_id' => $by_user_id > 0 ? $by_user_id : null];
        if ($trial_plan && ak_db_accepts($pdo, 'organizations', 'plan', $trial_plan['slug'])) $upd['plan'] = $trial_plan['slug'];
        $upd['status'] = ak_db_pick($pdo, 'organizations', 'status', ['trial', 'active']) ?? 'trial';
        $upd['validation_status'] = ak_db_pick($pdo, 'organizations', 'validation_status', ['validated', 'approved']) ?? 'validated';
        ak_db_update($pdo, 'organizations', $org_id, $upd, ['validated_at' => 'NOW()']);

        $warnings = $trial_plan ? [] : ['Aucun plan d’essai trouvé (asso_plans) : l’association a le plan par défaut.'];
        // Ligne d'abonnement créée d'office pendant l'attente (plan gratuit Démarrage posé par
        // ak_get_current_plan quand le Fondateur incarne le demandeur, par exemple) : elle devient la
        // ligne d'essai (ak_org_set_plan, fin = celle posée ci-dessus). Une seconde ligne « active »
        // passerait sinon devant l'essai (ak_sub_order) et priverait l'espace des fonctions PRO.
        $existing = 0;
        try {
            $q = $pdo->prepare('SELECT COUNT(*) FROM asso_subscriptions WHERE org_id = ?');
            $q->execute([$org_id]);
            $existing = (int)$q->fetchColumn();
        } catch (Throwable $e) {}
        $warnings = array_merge($warnings, ak_signup_trial_rows($pdo, $org_id, (int)($adm['id'] ?? 0), $trial_end, $existing ? null : $trial_plan));
        if ($existing && $trial_plan) {
            ak_org_set_plan($pdo, $org_id, (int)$trial_plan['id']);
            if (ak_db_accepts($pdo, 'asso_subscriptions', 'payment_mode', 'free_grant')) {
                $pdo->prepare("UPDATE asso_subscriptions SET payment_mode = 'free_grant' WHERE org_id = ? AND plan_id = ? AND payment_mode IS NULL")
                    ->execute([$org_id, (int)$trial_plan['id']]);
            }
        }
        try {
            if (isset(ak_db_columns($pdo, 'public_signups')['org_id'])) {
                $pdo->prepare("UPDATE public_signups SET status = 'completed'"
                              . (isset(ak_db_columns($pdo, 'public_signups')['completed_at']) ? ", completed_at = NOW()" : "")
                              . " WHERE org_id = ?")->execute([$org_id]);
            }
        } catch (Throwable $e) {
            error_log('[signup-review] public_signups: ' . get_class($e));
        }
        if ($own_tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // E-mail après la validation enregistrée : un envoi lent ou en échec ne la défait jamais
    $mailed = false;
    if ($adm && filter_var((string)$adm['email'], FILTER_VALIDATE_EMAIL)) {
        @require_once __DIR__ . '/signup-email-helpers.php';
        $verify = function_exists('ak_email_verify_url') ? ak_email_verify_url((int)$adm['id']) : '';
        $mailed = ak_signup_review_mail_activated((string)$adm['email'], (string)$adm['first_name'], (string)$org['name'], ak_trial_end_label($trial_end), $verify);
    }
    return ['state' => 'validated', 'org' => $org, 'trial_end' => $trial_end, 'warnings' => $warnings, 'admin' => $adm, 'mailed' => $mailed];
}

/**
 * Refuse une demande d'espace : refusée ET suspendue (ak_org_reject, comme le cockpit), le motif
 * remplace la marque de demande, et le demandeur reçoit un e-mail poli (avec le motif, s'il y en a).
 *   ['state' => 'rejected', 'mailed' => bool] ou ['state' => 'not_request', 'org' => ?array].
 */
function ak_signup_review_reject(PDO $pdo, int $org_id, string $reason, int $by_user_id = 0): array
{
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason)), 0, 250);
    $own_tx = !$pdo->inTransaction();
    try {
        if ($own_tx) $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ? FOR UPDATE');
        $st->execute([$org_id]);
        $org = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$org || !ak_signup_review_pending($org)) {
            if ($own_tx) $pdo->rollBack();
            return ['state' => 'not_request', 'org' => $org];
        }
        $adm = ak_signup_review_admin($pdo, $org);
        ak_org_reject($pdo, $org_id, $reason, $by_user_id);
        try {
            if (isset(ak_db_columns($pdo, 'public_signups')['error_message'])) {
                $pdo->prepare("UPDATE public_signups SET error_message = ? WHERE org_id = ?")
                    ->execute(['Demande refusée le ' . date('d/m/Y H:i') . ($reason !== '' ? ' : ' . $reason : ''), $org_id]);
            }
        } catch (Throwable $e) {
            error_log('[signup-review] public_signups: ' . get_class($e));
        }
        if ($own_tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $mailed = false;
    if ($adm && filter_var((string)$adm['email'], FILTER_VALIDATE_EMAIL)) {
        $mailed = ak_signup_review_mail_refused((string)$adm['email'], (string)$adm['first_name'], (string)$org['name'], $reason);
    }
    return ['state' => 'rejected', 'org' => $org, 'mailed' => $mailed];
}

/**
 * Message du cockpit et de l'appli quand une action ouvrirait (ou dénaturerait) une demande
 * encore en attente : réactiver, suspendre, prolonger un essai, changer de formule.
 */
function ak_signup_review_block_message(): string
{
    return 'Demande de création d’espace en attente : validez-la (l’essai démarre à la validation) ou refusez-la d’abord.';
}

/* ------------------------------------------------------------------
 * Rappel au Fondateur (cron-trial-check.php)
 * ------------------------------------------------------------------ */

/**
 * Demandes sans décision depuis 24 h : UN rappel au Fondateur par demande (e-mail avec des liens
 * neufs, valables 7 jours, et cloche). Carnet cron_envois (ak_envoi_reserver, cron-lots.php) :
 * relancer le cron toutes les 10 minutes n'en envoie jamais un second.
 * @return int rappels envoyés
 */
function ak_signup_review_remind_due(PDO $pdo, int $limit = 50): int
{
    require_once __DIR__ . '/cron-lots.php';
    @require_once __DIR__ . '/signup-email-helpers.php';
    if (!isset(ak_db_columns($pdo, 'organizations')['suspended_reason'])) return 0;
    $sent = 0;
    try {
        $st = $pdo->prepare("SELECT * FROM organizations
                             WHERE validation_status = 'pending_founder' AND status = 'suspended' AND suspended_reason = ?
                               AND deleted_at IS NULL AND created_at <= NOW() - INTERVAL 24 HOUR
                             ORDER BY id LIMIT " . max(1, (int)$limit));
        $st->execute([AK_SIGNUP_REVIEW_REASON]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[signup-review] rappels: ' . get_class($e));
        return 0;
    }
    foreach ($rows as $org) {
        if (!ak_signup_review_pending($org)) continue;
        if (!ak_envoi_reserver($pdo, 'signup-review', (int)$org['id'], 'rappel-24h')) continue;   // déjà parti
        if (function_exists('ak_signup_alert_founder')) {
            ak_signup_alert_founder($pdo, ak_signup_review_lead($pdo, $org), 'review_reminder', (int)$org['id']);
            $sent++;
        }
    }
    return $sent;
}

/* ------------------------------------------------------------------
 * E-mails au demandeur (hors de l'app : ils peuvent parler de l'essai)
 * ------------------------------------------------------------------ */

/** Envoi commun (réponse = contact@assokit.fr). Jamais bloquant. */
function ak_signup_review_send(string $email, string $subject, string $title, string $content, ?string $cta = null, ?string $cta_url = null, string $tag = 'signup_review'): bool
{
    @require_once __DIR__ . '/resend-helper.php';
    if (!function_exists('send_transactional_email') || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $html = function_exists('email_wrap') ? email_wrap($title, $content, $cta, $cta_url) : $content;
    try {
        $res = send_transactional_email($email, $subject, $html,
            ['tag' => $tag, 'reply_to' => defined('AK_DEMO_NOTIFY_EMAIL') ? AK_DEMO_NOTIFY_EMAIL : 'contact@assokit.fr']);
        return !empty($res['success']);
    } catch (Throwable $e) {
        error_log('[signup-review] e-mail ' . $tag . ': ' . get_class($e));
        return false;
    }
}

/** Accusé de réception immédiat : « Demande reçue — validation sous 48 h ». */
function ak_signup_review_mail_received(string $email, string $first_name, string $org_name): bool
{
    $h = (int)AK_SIGNUP_REVIEW_HOURS;
    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">Votre demande est bien reçue</h1>'
             . '<p>Bonjour ' . ak_signup_review_h($first_name) . ',</p>'
             . '<p>Merci ! Votre demande de création de l’espace Assokit de <strong>' . ak_signup_review_h($org_name) . '</strong> nous est bien parvenue.</p>'
             . '<p>Notre équipe l’examine <strong>sous ' . $h . '&nbsp;h</strong>. Vous recevrez un e-mail dès l’activation de votre espace&nbsp;: '
             . 'vous pourrez alors vous connecter, sur le site ou dans l’application, avec l’adresse e-mail et le mot de passe que vous avez choisis.</p>'
             . '<p>Une question en attendant&nbsp;? Répondez simplement à cet e-mail.</p>'
             . '<p style="font-size:12px;color:#78716C;margin-top:18px;">Si vous n’êtes pas à l’origine de cette demande, ignorez ce message ou répondez-nous&nbsp;: elle sera supprimée.</p>';
    return ak_signup_review_send($email, 'Demande reçue — validation sous ' . $h . ' h', 'Demande reçue', $content, null, null, 'signup_review_ack');
}

/** Espace validé : « Votre espace Assokit est activé », lien de connexion (et de confirmation de l'adresse). */
function ak_signup_review_mail_activated(string $email, string $first_name, string $org_name, string $trial_end_label, string $verify_url = ''): bool
{
    $days = (int)(defined('AK_TRIAL_DAYS') ? AK_TRIAL_DAYS : 14);
    $content = '<h1 style="font-size:22px;margin:0 0 14px;color:#1C1917">Votre espace Assokit est activé &#127881;</h1>'
             . '<p>Bonjour ' . ak_signup_review_h($first_name) . ',</p>'
             . '<p>Bonne nouvelle&nbsp;: notre équipe a validé votre demande, l’espace de <strong>' . ak_signup_review_h($org_name) . '</strong> est prêt.</p>'
             . '<p>Connectez-vous avec l’adresse e-mail et le mot de passe choisis lors de votre demande, sur assokit.fr ou dans l’application.</p>'
             . '<p>Votre <strong>essai PRO de ' . $days . ' jours</strong> commence aujourd’hui&nbsp;: toutes les fonctionnalités sont ouvertes'
             . ($trial_end_label !== '' ? ' jusqu’au <strong>' . ak_signup_review_h($trial_end_label) . '</strong>' : '') . ', sans carte bancaire.</p>'
             . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
             . '&#128172; Une question&nbsp;? Répondez simplement à cet e-mail&nbsp;: l’équipe Assokit vous accompagne pour bien démarrer.'
             . '</div>'
             . ($verify_url !== '' ? '<p style="font-size:13px;">Pensez aussi à <a href="' . ak_signup_review_h($verify_url) . '" style="color:#059669;">confirmer votre adresse e-mail</a>.</p>' : '');
    return ak_signup_review_send($email, 'Votre espace Assokit est activé', 'Votre espace Assokit est activé', $content,
        'Me connecter', 'https://assokit.fr/connexion', 'signup_review_activated');
}

/** Demande refusée : e-mail poli, motif repris s'il a été donné. */
function ak_signup_review_mail_refused(string $email, string $first_name, string $org_name, string $reason = ''): bool
{
    $content = '<h1 style="font-size:20px;margin:0 0 14px;color:#1C1917">Votre demande d’espace Assokit</h1>'
             . '<p>Bonjour ' . ak_signup_review_h($first_name) . ',</p>'
             . '<p>Merci de l’intérêt que vous portez à Assokit. Après examen, nous ne sommes malheureusement pas en mesure d’activer l’espace demandé pour <strong>'
             . ak_signup_review_h($org_name) . '</strong>.</p>'
             . ($reason !== '' ? '<p><strong>Motif&nbsp;:</strong> ' . nl2br(ak_signup_review_h($reason)) . '</p>' : '')
             . '<p>S’il s’agit d’une erreur, ou si vous souhaitez nous apporter des précisions, répondez simplement à cet e-mail&nbsp;: nous reprendrons volontiers votre demande.</p>'
             . '<p>Vous pouvez aussi demander la suppression des informations transmises&nbsp;: <a href="https://assokit.fr/suppression-compte" style="color:#059669;">assokit.fr/suppression-compte</a>.</p>'
             . '<p>Bien cordialement,<br>L’équipe Assokit</p>';
    return ak_signup_review_send($email, 'Votre demande d’espace Assokit', 'Votre demande d’espace Assokit', $content, null, null, 'signup_review_refused');
}
