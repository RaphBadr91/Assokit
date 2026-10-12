<?php
/**
 * api/app-notif-read.php — Marque des notifications comme lues (app mobile).
 * Corps JSON : { id?: int|'p<N>', source?: 'platform', platform_id?: int, type?: 'message'|'support',
 *               channel_id?: int, project_id?: int, all?: bool, csrf }
 *   - notification de la plateforme (fondateur, api/app-notifications.php) : id 'p<N>', ou
 *     source 'platform' avec platform_id (ou id) N — seulement celles de l'utilisateur
 *   - all : aussi les notifications de la plateforme de l'utilisateur
 *   - channel_id : canal ouvert → ses notifications seulement (comme messages.php sur le site)
 *   - project_id : discussion d'un projet lue → ses notifications de messages seulement
 *   - type 'message' sans channel_id (versions de l'appli qui l'envoient à l'ouverture d'un canal) :
 *     ne solde plus TOUTES les notifications de messages et mentions (canaux et projets confondus) ;
 *     le canal ouvert est soldé par api/app-channel-messages.php (appelé en même temps, attendu
 *     ici 1,5 s au plus). Seules les notifications des canaux lus jusqu'au bout sont soldées,
 *     puis les compteurs sont renvoyés.
 * Renvoie les compteurs recalculés. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../notification-helpers.php';
require_once __DIR__ . '/../platform-flags.php';

$all  = !empty($input['all']);
$raw_id = $input['id'] ?? 0;
$platform_id = 0;
if (is_string($raw_id) && preg_match('/^p(\d+)$/', $raw_id, $pm)) {
    $platform_id = (int) $pm[1];
} elseif ((string) ($input['source'] ?? '') === 'platform') {
    $platform_id = (int) ($input['platform_id'] ?? $raw_id);
}
$id   = $platform_id > 0 ? 0 : (int) $raw_id;
// Fondateur / super admin : seuls à recevoir les notifications de la plateforme (même règle
// que la liste, api/app-notifications.php)
$pf = ak_platform_flags($user) ?: [];
$is_platform = app_is_founder($pdo, $user) || !empty($pf['is_super_admin']);
$type = (string) ($input['type'] ?? '');
$channel_id = (int) ($input['channel_id'] ?? 0);
$project_id = (int) ($input['project_id'] ?? 0);

try {
    if ($all) {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0")
            ->execute([$uid]);
        // Notifications de la plateforme de l'utilisateur (fondateur), comme la cloche du cockpit
        if ($is_platform) {
            try {
                $pdo->prepare("UPDATE platform_notifications SET is_read = 1, read_at = NOW() WHERE recipient_user_id = ? AND is_read = 0")
                    ->execute([$uid]);
            } catch (Throwable $e) {}   // table absente
        }
    } elseif ($platform_id > 0) {
        // Celle de l'utilisateur uniquement
        try {
            $pdo->prepare("UPDATE platform_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND recipient_user_id = ?")
                ->execute([$platform_id, $uid]);
        } catch (Throwable $e) {}   // table absente
    } elseif ($channel_id > 0) {
        // Canal de l'association de l'utilisateur uniquement
        $st = $pdo->prepare("SELECT id, slug FROM channels WHERE id = ? AND org_id = ? LIMIT 1");
        $st->execute([$channel_id, $org_id]);
        $ch = $st->fetch(PDO::FETCH_ASSOC);
        if ($ch && function_exists('ak_channel_notifs_mark_read')) ak_channel_notifs_mark_read($pdo, $uid, $ch);
    } elseif ($project_id > 0) {
        // Projet de l'association de l'utilisateur uniquement
        $st = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON f.id = p.folder_id WHERE p.id = ? AND f.org_id = ? LIMIT 1");
        $st->execute([$project_id, $org_id]);
        if ($st->fetchColumn() && function_exists('ak_project_notifs_mark_read')) ak_project_notifs_mark_read($pdo, $uid, $project_id);
    } elseif ($type === 'message') {
        // Pas de solde en bloc (voir l'en-tête). L'appli envoie cet appel en même temps que
        // api/app-channel-messages.php, qui solde le canal ouvert ; les deux partagent la session
        // (verrou) : si celui-ci passe le premier, on libère la session et on attend brièvement
        // (1,5 s au plus) que le canal soit marqué lu, pour renvoyer des compteurs à jour.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            $rd = $pdo->prepare("SELECT COUNT(*) FROM channel_reads WHERE user_id = ? AND last_read_at >= NOW() - INTERVAL 2 SECOND");
            for ($i = 0; $i < 15; $i++) {
                $rd->execute([$uid]);
                if ((int) $rd->fetchColumn() > 0) break;
                usleep(100000);
            }
        } catch (Throwable $e) {}
        // Puis seules les notifications des canaux lus jusqu'à leur dernier message sont soldées
        // (dont celui que l'appli vient d'ouvrir)
        if (function_exists('ak_channel_notifs_mark_read')) {
            $st = $pdo->prepare("
                SELECT c.id, c.slug
                FROM channels c
                JOIN channel_reads r ON r.channel_id = c.id AND r.user_id = ?
                WHERE c.org_id = ?
                  AND COALESCE(r.last_read_message_id, 0) >= COALESCE((SELECT MAX(m.id) FROM channel_messages m WHERE m.channel_id = c.id AND m.deleted_at IS NULL), 0)
            ");
            $st->execute([$uid, $org_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ch) ak_channel_notifs_mark_read($pdo, $uid, $ch);
        }
    } elseif ($type === 'support') {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW()
                       WHERE user_id = ? AND is_read = 0 AND notification_type = 'support'")
            ->execute([$uid]);
    } elseif ($id > 0) {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([$id, $uid]);
    }

    // Recalcul des compteurs
    $notif_unread = 0; $msg_unread = 0; $support_unread = 0;
    $st = $pdo->prepare("SELECT notification_type AS t, COUNT(*) AS c FROM user_notifications WHERE user_id = ? AND is_read = 0 GROUP BY notification_type");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = (int) $row['c']; $notif_unread += $c;
        if ($row['t'] === 'message' || $row['t'] === 'mention') $msg_unread += $c;
    }
    // Fondateur / super admin : notifications de la plateforme non lues, comptées dans le total
    // comme dans la liste (api/app-notifications.php) ; aussi renvoyées à part.
    $platform_unread = 0;
    if ($is_platform) {
        try {
            $pst = $pdo->prepare("SELECT COUNT(*) FROM platform_notifications WHERE recipient_user_id = ? AND is_read = 0");
            $pst->execute([$uid]);
            $platform_unread = (int) $pst->fetchColumn();
        } catch (Throwable $e) {}   // table absente
    }
    $notif_unread += $platform_unread;
    // Support : même calcul que l'accueil (api/app-dashboard.php) — réponses du support
    // non lues par l'association, et non des notifications : sinon le badge disparaît à tort.
    try {
        $sst = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM support_tickets t
            JOIN support_messages m ON m.ticket_id = t.id
            WHERE t.org_id = ? AND m.author_side = 'support' AND m.read_by_org = 0 AND m.is_internal_note = 0
        ");
        $sst->execute([$org_id]);
        $support_unread = (int) $sst->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'notif_unread' => $notif_unread,
        'msg_unread' => $msg_unread,
        'support_unread' => $support_unread,
        'platform_unread' => $platform_unread,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-notif-read] ' . $e->getMessage());
    app_fail(500, 'server');
}
