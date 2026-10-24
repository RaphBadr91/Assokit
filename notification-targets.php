<?php
/**
 * ============================================================
 * ASSOKIT — Où mène une notification (appli mobile)
 * ============================================================
 * Une seule vérité, côté serveur : api/app-notifications.php (liste de l'appli)
 * et cron-push-notifications.php (données des notifications push) donnent à
 * chaque notification une cible « target », calculée ici. L'appli ouvre l'écran
 * natif désigné, sinon la page du site (screen 'web', avec retour), sinon la
 * fiche native de la notification (screen 'detail') : un appui ne reste jamais
 * sans effet.
 *
 * Cibles (clé « screen ») :
 *   fdorgs        { org_id }        fiche d'une association du cockpit Fondateur (org_id null : la liste)
 *   fdsupport     { ticket_id }     fil d'un ticket de support (Fondateur ; ticket_id null : la liste)
 *   fdcontacts    { contact_id }    message de contact du cockpit (demande d'essai à rappeler, résumé)
 *   founder                         accueil du cockpit Fondateur
 *   project       { project_id }    fiche native d'un projet
 *   channel       { channel_id, name, slug }  conversation d'un canal de la messagerie
 *   messages                        liste des canaux
 *   ticket        { ticket_id }     ticket de support de l'association (page du site, retour à la liste)
 *   item          { kind, id }      autre fiche native : member, event, cotisation, grant, client
 *   menu          { menu }          écran natif du menu (agenda, cotisations, subventions, tickets…)
 *   tab           { tab }           onglet natif (projets, factures, people)
 *   web           { path }          page du site dans l'appli (aucun écran natif)
 *   notifications                   liste des notifications (push regroupée)
 *   detail        { open?, note? }  fiche de la notification seule ; « open » : cible du bouton « Ouvrir »
 * Chaque cible porte « label », le texte du bouton « Ouvrir » de la fiche de la notification.
 *
 * Les cibles du cockpit (fd…, founder, pages /super-admin) ne sont JAMAIS données à un compte qui
 * n'est ni Fondateur ni super-admin (mêmes droits que les API du cockpit, qui refusent de toute
 * façon) : il reçoit la fiche de la notification. Les alertes anciennes sans identifiant
 * (« Nouvel essai activé : … » liée à /super-admin) sont retrouvées d'après leur titre, pour le
 * Fondateur seulement.
 * ============================================================
 */

/** Repère des copies du résumé horaire (signup-email-helpers.php, AK_SIGNUP_DIGEST_SUBJECT). */
if (!defined('AK_NOTIF_DIGEST_LIKE')) define('AK_NOTIF_DIGEST_LIKE', '[ESSAI-RESUME]%');

/**
 * Le compte qui lit ses notifications : ce qu'il peut ouvrir.
 *   uid, org_id, role ; platform : Fondateur ou super-admin (cockpit) ; mairie : agent d'une
 *   collectivité sans association (aucun écran natif d'association : pages du site).
 */
function ak_notif_viewer(PDO $pdo, array $user): array
{
    @require_once __DIR__ . '/platform-flags.php';
    @require_once __DIR__ . '/api/_app-founder.php';
    $uid = (int)($user['id'] ?? 0);
    $pf = function_exists('ak_platform_flags') ? (ak_platform_flags($user) ?: []) : $user;
    $founder = function_exists('app_is_founder') ? app_is_founder($pdo, $user) : !empty($pf['is_founder']);
    $org_id = (int)($user['org_id'] ?? 0);
    $parent = $user['parent_org_id'] ?? null;
    if (!array_key_exists('parent_org_id', $user) && $uid > 0 && $org_id <= 0) {
        try {
            $st = $pdo->prepare("SELECT parent_org_id FROM users WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $parent = $st->fetchColumn();
        } catch (Throwable $e) {}
    }
    return [
        'uid'      => $uid,
        'org_id'   => $org_id,
        'role'     => (string)($user['role'] ?? ''),
        'platform' => $founder || !empty($pf['is_super_admin']) || (($user['role'] ?? '') === 'super_admin'),
        'mairie'   => $org_id <= 0 && (int)$parent > 0,
    ];
}

/** Même lecture pour un compte désigné par son identifiant (notifications push, sans session). */
function ak_notif_viewer_by_id(PDO $pdo, int $uid): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $uid];
    } catch (Throwable $e) {
        $u = ['id' => $uid];
    }
    return ak_notif_viewer($pdo, $u);
}

/** Chemin du site d'un lien de notification (sans https://assokit.fr) ; '' si vide ou externe. */
function ak_notif_path(?string $link): string
{
    $l = trim((string)$link);
    if ($l === '') return '';
    $l = (string)preg_replace('#^https?://(?:www\.)?assokit\.fr#i', '', $l);
    if ($l === '') return '/';
    // « //hôte » et « /\hôte » : le navigateur de l'appli les lirait comme une autre adresse
    if ($l[0] !== '/' || strpos($l, '//') === 0 || strpos($l, '\\') !== false || preg_match('/[\x00-\x1F]/', $l)) return '';
    return $l;
}

/** Paramètre ?$key= d'un chemin ('' s'il manque). */
function ak_notif_param(string $path, string $key): string
{
    $q = parse_url($path, PHP_URL_QUERY);
    if (!$q) return '';
    parse_str($q, $a);
    return isset($a[$key]) && is_scalar($a[$key]) ? trim((string)$a[$key]) : '';
}

/** « Aujourd’hui à 14 h 05 », « Hier à 9 h 12 », « Lundi 19 octobre 2026 à 14 h 05 » ('' si illisible). */
function ak_notif_date_label(?string $sql): string
{
    $t = $sql ? strtotime($sql) : false;
    if (!$t) return '';
    $h = date('G', $t) . ' h ' . date('i', $t);
    $d = date('Y-m-d', $t);
    if ($d === date('Y-m-d')) return 'Aujourd’hui à ' . $h;
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Hier à ' . $h;
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $j = (int)date('j', $t);
    return $jours[(int)date('w', $t)] . ' ' . $j . ($j === 1 ? 'er' : '') . ' ' . $mois[(int)date('n', $t) - 1] . ' ' . date('Y', $t) . ' à ' . $h;
}

/** Page du site ouverte dans l'appli. */
function ak_notif_web(string $path, string $label = 'Ouvrir la page'): array
{
    return ['screen' => 'web', 'path' => $path, 'label' => $label];
}

/** Fiche de la notification seule ; $open : cible du bouton « Ouvrir », $note : précision affichée. */
function ak_notif_detail(?array $open = null, string $note = ''): array
{
    $t = ['screen' => 'detail', 'label' => (string)($open['label'] ?? '')];
    if ($open) $t['open'] = $open;
    if ($note !== '') $t['note'] = $note;
    return $t;
}

/**
 * Pages que l'appli ne charge jamais (règle 3.1.1 d'Apple : tarifs, abonnement, paiement ;
 * mobile/App.js, onShouldStartLoadWithRequest) : jamais proposées comme cible.
 */
function ak_notif_web_blocked(string $path): bool
{
    return (bool)preg_match('#^/(tarifs|abonnement|mon-asso-plan|mon-asso-paiement|mon-asso-abonnement|mon-asso-annuler-abonnement)(?:[/?\#.]|$)#i', $path);
}

/* ------------------------------------------------------------------
 * Cockpit Fondateur
 * ------------------------------------------------------------------ */

/** Fiche d'une association du cockpit ; association supprimée : fiche de la notification avec la raison. */
function ak_notif_org_target(PDO $pdo, int $org_id): array
{
    static $cache = [];
    if ($org_id <= 0) return ['screen' => 'fdorgs', 'org_id' => null, 'label' => 'Voir les associations'];
    if (!array_key_exists($org_id, $cache)) {
        try {
            $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
            $st->execute([$org_id]);
            $cache[$org_id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $cache[$org_id] = ['id' => $org_id];   // base illisible : la fiche dira elle-même ce qu'il en est
        }
    }
    $o = $cache[$org_id];
    if (!$o) return ak_notif_detail(null, 'Cette structure n’existe plus dans Assokit.');
    if (!empty($o['deleted_at'])) return ak_notif_detail(null, 'Cette structure a été supprimée du cockpit.');
    return ['screen' => 'fdorgs', 'org_id' => $org_id,
            'label' => (($o['org_type'] ?? '') === 'tpe') ? 'Voir l’entreprise' : 'Voir l’association'];
}

/** Fil d'un ticket de support du cockpit ; ticket disparu : fiche de la notification. */
function ak_notif_ticket_target(PDO $pdo, int $ticket_id): array
{
    if ($ticket_id <= 0) return ['screen' => 'fdsupport', 'ticket_id' => null, 'label' => 'Voir les tickets de support'];
    try {
        $st = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? LIMIT 1");
        $st->execute([$ticket_id]);
        if ($st->fetchColumn() === false) return ak_notif_detail(null, 'Ce ticket de support n’existe plus.');
    } catch (Throwable $e) {}
    return ['screen' => 'fdsupport', 'ticket_id' => $ticket_id, 'label' => 'Ouvrir le ticket'];
}

/** Message de contact du cockpit (copie d'une demande d'essai) ; contact_id null : la liste. */
function ak_notif_contact_target(int $contact_id): array
{
    return $contact_id > 0
        ? ['screen' => 'fdcontacts', 'contact_id' => $contact_id, 'label' => 'Voir la demande']
        : ['screen' => 'fdcontacts', 'contact_id' => null, 'label' => 'Voir les messages de contact'];
}

/**
 * Alerte d'inscription ANCIENNE, liée à /super-admin sans identifiant : la structure est retrouvée
 * d'après le titre (nom exact, la plus proche de la date de la notification), le message de contact
 * d'après son objet (« [ESSAI] … », « [ESSAI-RESUME] … ») et sa date. Fondateur seulement.
 */
function ak_notif_founder_resolve(PDO $pdo, array $n): array
{
    $title = trim((string)($n['title'] ?? ''));
    $at = (string)($n['created_at'] ?? '') ?: date('Y-m-d H:i:s');

    // Résumé horaire des demandes non ouvertes : sa copie dans les messages de contact
    if (preg_match('/^⚠️ \d+ demandes? d’essai non ouvertes?/u', $title)) {
        return ak_notif_contact_target(ak_notif_contact_near($pdo, AK_NOTIF_DIGEST_LIKE, $at));
    }
    // Demande d'essai à rappeler (aucun espace créé) : sa copie « [ESSAI] <structure> »
    if (strpos($title, '⚠️ Demande d’essai à rappeler : ') === 0) {
        $name = trim(mb_substr($title, mb_strlen('⚠️ Demande d’essai à rappeler : ')));
        return ak_notif_contact_target($name !== '' ? ak_notif_contact_near($pdo, '[ESSAI] ' . $name, $at) : 0);
    }
    foreach (['Nouvel essai activé : ', 'Nouvel essai (à vérifier) : ', '🔔 Demande d’espace à valider : ',
              '⏰ Demande d’espace à valider depuis 24 h : ', 'Demande retirée par le demandeur : '] as $p) {
        if (strpos($title, $p) !== 0) continue;
        $name = trim(mb_substr($title, mb_strlen($p)));
        $oid = $name !== '' ? ak_notif_org_by_name($pdo, $name, $at, mb_strlen($title) >= 190) : 0;
        return $oid > 0 ? ak_notif_org_target($pdo, $oid) : ak_notif_org_target($pdo, 0);
    }
    return ['screen' => 'founder', 'label' => 'Ouvrir le cockpit'];
}

/**
 * Structure au nom exact (ou commençant par $name si le titre a été coupé), créée autour de la
 * date de la notification (30 jours avant, 1 jour après), la plus proche ; à défaut la plus
 * récente de ce nom. 0 si aucune.
 */
function ak_notif_org_by_name(PDO $pdo, string $name, string $at, bool $cut = false): int
{
    $cond = $cut ? 'name LIKE ?' : 'name = ?';
    $arg = $cut ? addcslashes($name, '%_\\') . '%' : $name;
    try {
        $st = $pdo->prepare("SELECT id FROM organizations WHERE $cond
                               AND created_at BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
                             ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) ASC, id DESC LIMIT 1");
        $st->execute([$arg, $at, $at, $at]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;
        $st = $pdo->prepare("SELECT id FROM organizations WHERE $cond ORDER BY created_at DESC, id DESC LIMIT 1");
        $st->execute([$arg]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Message de contact d'objet $subject (LIKE si % présent) le plus proche de $at (± 1 jour). 0 si aucun. */
function ak_notif_contact_near(PDO $pdo, string $subject, string $at): int
{
    $cond = strpos($subject, '%') !== false ? 'subject LIKE ?' : 'subject = ?';
    try {
        $st = $pdo->prepare("SELECT id FROM asso_contact_messages WHERE $cond
                               AND created_at BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
                             ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) ASC, id DESC LIMIT 1");
        $st->execute([mb_substr($subject, 0, 200), $at, $at, $at]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Lien du cockpit (/super-admin…) d'une notification de la cloche : écran natif du Fondateur. */
function ak_notif_founder_target(PDO $pdo, array $n, string $path): array
{
    // Fiche d'une structure : toutes les alertes d'inscription (essai, demande iPhone, rappel, retrait)
    if (preg_match('#^/super-admin/associations(?:[/?\#]|$)#', $path)) {
        return ak_notif_org_target($pdo, (int)ak_notif_param($path, 'id'));
    }
    if (preg_match('#^/super-admin/support(?:/(\d+))?(?:[/?\#]|$)#', $path, $m)) {
        return ak_notif_ticket_target($pdo, (int)(($m[1] ?? '') !== '' ? $m[1] : ak_notif_param($path, 'id')));
    }
    if ($path === '' || preg_match('#^/super-admin/?(?:[?\#]|$)#', $path)) {
        // Demande d'essai à rappeler, résumé horaire : leur copie dans les messages de contact
        $cid = (int)ak_notif_param($path, 'contact');
        if ($cid > 0) return ak_notif_contact_target($cid);
        return ak_notif_founder_resolve($pdo, $n);
    }
    if (ak_notif_web_blocked($path)) return ak_notif_detail();
    return ak_notif_web($path, 'Ouvrir dans le cockpit');
}

/* ------------------------------------------------------------------
 * Espace de l'association
 * ------------------------------------------------------------------ */

/** Canal de la messagerie que ce compte peut lire (même règle qu'api/app-channels.php), ou null. */
function ak_notif_channel(PDO $pdo, array $viewer, string $slug, int $channel_id): ?array
{
    static $cache = [];
    if ($viewer['org_id'] <= 0 || $viewer['role'] === 'follower' || ($slug === '' && $channel_id <= 0)) return null;
    $key = $viewer['uid'] . '|' . $slug . '|' . $channel_id;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT c.id, c.name, c.slug FROM channels c
                             WHERE c.org_id = ? AND " . ($channel_id > 0 ? 'c.id = ?' : 'c.slug = ?') . " AND c.is_archived = 0
                               AND (c.type IN ('public','announce')
                                    OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = ?))
                             LIMIT 1");
        $st->execute([$viewer['org_id'], $channel_id > 0 ? $channel_id : $slug, $viewer['uid']]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = $c ? ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'slug' => (string)$c['slug']] : null;
    } catch (Throwable $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}

/** Lien d'une page de l'association : écran natif s'il existe, sinon page du site. */
function ak_notif_asso_target(PDO $pdo, string $path, array $viewer): array
{
    // Discussion d'un projet : page du site (la fiche native n'a pas le fil) ; autre lien d'un projet : sa fiche
    if (preg_match('#^/projet/(\d+)/messages#', $path, $m)) {
        return ['screen' => 'web', 'path' => '/projet/' . $m[1] . '/messages', 'project_id' => (int)$m[1], 'label' => 'Ouvrir la discussion du projet'];
    }
    if (preg_match('#^/projet/(\d+)#', $path, $m)) return ['screen' => 'project', 'project_id' => (int)$m[1], 'label' => 'Voir le projet'];
    if (preg_match('#^/projets?(?:[/?\#.]|$)#', $path)) return ['screen' => 'tab', 'tab' => 'projets', 'label' => 'Voir les projets'];

    // Messagerie : la conversation du canal (?c=<slug>, ancien ?channel=<id>), sinon la liste des canaux.
    // Suiveur (financeur externe) : messagerie interne fermée, la fiche de la notification suffit
    if (preg_match('#^/messages(?:[/?\#.]|$)#', $path)) {
        if ($viewer['role'] === 'follower') return ak_notif_detail(null, 'La messagerie interne est réservée aux membres de l’association.');
        $c = ak_notif_channel($pdo, $viewer, ak_notif_param($path, 'c'), (int)ak_notif_param($path, 'channel'));
        return $c ? ['screen' => 'channel', 'channel_id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'label' => 'Ouvrir la conversation']
                  : ['screen' => 'messages', 'label' => 'Ouvrir la messagerie'];
    }
    // Support de l'association : le fil d'un ticket (page du site), sinon la liste des tickets
    if (preg_match('#^/support(?:/ticket/(\d+)|[^\#]*[?&]id=(\d+))#', $path, $m)) {
        return ['screen' => 'ticket', 'ticket_id' => (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]), 'label' => 'Ouvrir le ticket'];
    }
    if (preg_match('#^/support(?:[/?\#.-]|$)#', $path)) return ['screen' => 'menu', 'menu' => 'tickets', 'label' => 'Voir mes demandes de support'];

    // Fiches natives (même identifiant que la page du site)
    foreach (['#^/adherent/(\d+)#' => 'member', '#^/evenement/(\d+)#' => 'event', '#^/cotisation/(\d+)#' => 'cotisation',
              '#^/subvention/(\d+)#' => 'grant', '#^/mon-asso-client-detail[^\#]*[?&]id=(\d+)#' => 'client'] as $re => $kind) {
        if (preg_match($re, $path, $m)) return ['screen' => 'item', 'kind' => $kind, 'id' => (int)$m[1], 'label' => 'Voir la fiche'];
    }
    // Écrans natifs du menu et onglets
    foreach (['agenda' => 'agenda', 'cotisations' => 'cotisations', 'subventions' => 'subventions', 'assemblees' => 'assemblies',
              'boite-mail' => 'mail', 'mon-asso-devis' => 'devis', 'mon-asso-stats' => 'stats', 'dashboard' => 'dashboard',
              'adherents' => 'members', 'mon-asso-clients' => 'clients', 'parametres' => 'settings'] as $p => $menu) {
        if (preg_match('#^/' . preg_quote($p, '#') . '(?:[/?\#.]|$)#', $path)) return ['screen' => 'menu', 'menu' => $menu, 'label' => 'Ouvrir'];
    }
    if (preg_match('#^/mon-asso-factures(?:[/?\#.]|$)#', $path)) return ['screen' => 'tab', 'tab' => 'factures', 'label' => 'Voir les factures'];
    if (preg_match('#^/notifications(?:[/?\#.]|$)#', $path)) return ['screen' => 'notifications', 'label' => 'Voir les notifications'];

    // Pages sans écran natif : le site, dans l'appli
    if (preg_match('#^/financements(?:[/?\#.]|$)#', $path)) return ak_notif_web($path, 'Voir les pistes de financement');
    if (preg_match('#^/notes-de-frais(?:[/?\#.]|$)#', $path)) return ak_notif_web($path, 'Voir la note de frais');
    if (ak_notif_web_blocked($path)) return ak_notif_detail();
    return ak_notif_web($path);
}

/**
 * Cible d'une notification de la cloche (user_notifications : notification_type, title, body,
 * link_url, created_at…) pour ce compte (ak_notif_viewer).
 */
function ak_notif_target(PDO $pdo, array $n, array $viewer): array
{
    $type = (string)($n['notification_type'] ?? '');
    $raw  = trim((string)($n['link_url'] ?? ''));
    $path = ak_notif_path($raw);

    // Alertes du cockpit (inscriptions, demandes d'espace, retraits…) : Fondateur et super-admins seulement
    if ($type === 'signup' || preg_match('#^/super-admin(?:[/?\#-]|$)#', $path)) {
        if (!$viewer['platform']) return ak_notif_detail();
        if (strpos($path, '/super-admin-') === 0) return ak_notif_web_blocked($path) ? ak_notif_detail() : ak_notif_web($path, 'Ouvrir dans le cockpit');
        return ak_notif_founder_target($pdo, $n, $path);
    }
    if ($path === '') {
        // Lien externe (rare) : proposé depuis la fiche de la notification
        return preg_match('#^https?://#i', $raw) ? ak_notif_detail(['screen' => 'web', 'path' => $raw, 'label' => 'Ouvrir le lien']) : ak_notif_detail();
    }
    // Espace collectivité (agents) : pages du site
    if (preg_match('#^/mairie-#', $path)) {
        return ak_notif_web($path, strpos($path, '/mairie-messages') === 0 ? 'Ouvrir la conversation' : 'Ouvrir l’espace collectivité');
    }
    // Agent d'une collectivité : aucun écran natif d'association, la page du site (projet en lecture…)
    if ($viewer['mairie']) return ak_notif_web_blocked($path) ? ak_notif_detail() : ak_notif_web($path);
    return ak_notif_asso_target($pdo, $path, $viewer);
}

/**
 * Cible d'une notification de la plateforme (platform_notifications : type, target_url,
 * target_org_id…). Liste réservée au Fondateur et aux super-admins (api/app-notifications.php).
 */
function ak_notif_platform_target(PDO $pdo, array $r): array
{
    $path = ak_notif_path((string)($r['target_url'] ?? ''));
    if (preg_match('#^/super-admin/support(?:/(\d+))?(?:[/?\#]|$)#', $path, $m)) {
        return ak_notif_ticket_target($pdo, (int)(($m[1] ?? '') !== '' ? $m[1] : ak_notif_param($path, 'id')));
    }
    if (($r['type'] ?? '') === 'new_org_pending' || preg_match('#^/super-admin/associations(?:[/?\#]|$)#', $path)) {
        $oid = (int)($r['target_org_id'] ?? 0);
        if ($oid <= 0) $oid = (int)ak_notif_param($path, 'id');
        return ak_notif_org_target($pdo, $oid);
    }
    // Demande de support d'une collectivité : le texte entier dans la fiche, puis la fiche de la collectivité (site)
    if (preg_match('#^/super-admin-mairie#', $path)) return ak_notif_detail(ak_notif_web($path, 'Voir la collectivité dans le cockpit'));
    if ($path === '' || ak_notif_web_blocked($path)) return ak_notif_detail();
    return ak_notif_detail(ak_notif_web($path, 'Ouvrir dans le cockpit'));
}

/** Données d'une notification push (cron-push-notifications.php) : lien, identifiant (lu au tap), cible. */
function ak_notif_push_data(PDO $pdo, array $n, array $viewer): array
{
    $data = ['url' => (string)($n['link_url'] ?? ''), 'notif_id' => (int)($n['id'] ?? 0)];
    try { $data['target'] = ak_notif_target($pdo, $n, $viewer); } catch (Throwable $e) {}
    return $data;
}
