<?php
/**
 * api/app-notifications.php — Notifications de l'utilisateur pour l'ecran natif.
 * JSON, scope user. NE MODIFIE PAS le site.
 * Chaque notification (cloche de l'association comme cloche de la plateforme) porte sa cible
 * « target », calculée côté serveur (notification-targets.php, la même pour les notifications
 * push) : écran natif, page du site, ou fiche de la notification. Les cibles du cockpit ne sont
 * données qu'au Fondateur et aux super-admins. « body » : texte entier ; « date » : date lisible
 * (« Aujourd’hui à 14 h 05 ») pour la fiche de la notification.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id
require_once __DIR__ . '/../platform-flags.php';
require_once __DIR__ . '/../notification-targets.php';

$ICON = ['message' => 'chatbubble', 'mention' => 'at', 'team_added' => 'people', 'step_assigned' => 'checkmark-circle', 'support' => 'help-buoy',
         'signup' => 'person-add', 'grant_radar' => 'telescope', 'mairie_message' => 'chatbubbles', 'expense_report' => 'receipt',
         'paiement' => 'card'];
// Notifications de la plateforme (cloche du cockpit fondateur)
$PICON = ['new_org_pending' => 'business', 'new_ticket' => 'help-buoy', 'new_ticket_message' => 'help-buoy',
          'payment_received' => 'cash', 'invoice_overdue' => 'warning', 'super_admin_action' => 'shield-checkmark'];

function nago($v) {
    $ts = strtotime((string) $v);
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return 'à l\'instant';
    if ($d < 3600) return floor($d / 60) . ' min';
    if ($d < 86400) return floor($d / 3600) . ' h';
    if ($d < 604800) return floor($d / 86400) . ' j';
    return date('d/m/Y', $ts);
}

try {
    $stmt = $pdo->prepare("
        SELECT n.id, n.notification_type, n.title, n.body, n.link_url, n.is_read, n.created_at,
               n.project_id, n.message_id, n.step_id,
               u.first_name AS actor_first, u.last_name AS actor_last, u.avatar_color AS actor_color
        FROM user_notifications n
        LEFT JOIN users u ON n.actor_id = u.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Total des non lues (et pas seulement dans cette page de 100) : même nombre que le badge
    // de l'accueil et que le site, sinon « Tout marquer comme lu » n'apparaît plus.
    $cst = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $cst->execute([$uid]);
    $unread = (int) $cst->fetchColumn();

    // Ce que ce compte peut ouvrir (cockpit : Fondateur / super-admin ; agent de collectivité : le site)
    $viewer = ak_notif_viewer($pdo, $user);

    $items = [];
    foreach ($rows as $r) {
        $isr = !empty($r['is_read']);
        $items[] = [
            'id'      => (int) $r['id'],
            'source'  => 'user',
            'type'    => (string) ($r['notification_type'] ?? ''),
            'title'   => (string) ($r['title'] ?? ''),
            'body'    => (string) ($r['body'] ?? ''),
            'link'    => (string) ($r['link_url'] ?? ''),
            'target'  => ak_notif_target($pdo, $r, $viewer),
            'actor'   => trim(($r['actor_first'] ?? '') . ' ' . ($r['actor_last'] ?? '')),
            'icon'    => $ICON[$r['notification_type'] ?? ''] ?? 'notifications',
            'read'    => $isr,
            'ago'     => nago($r['created_at'] ?? null),
            'date'    => ak_notif_date_label($r['created_at'] ?? null),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }

    // Fondateur / super admin : notifications de la plateforme (nouveau message support,
    // association à valider…), celles de la cloche du cockpit web. Identifiant préfixé « p » :
    // distinct de celui des notifications de l'association (api/app-notif-read.php le reconnaît).
    $platform_unread = 0;
    if ($uid > 0 && $viewer['platform']) {
        try {
            $pst = $pdo->prepare("
                SELECT * FROM platform_notifications
                WHERE recipient_user_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $pst->execute([$uid]);
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $items[] = [
                    'id'          => 'p' . (int) $r['id'],
                    'platform_id' => (int) $r['id'],
                    'source'      => 'platform',
                    'type'        => (string) ($r['type'] ?? ''),
                    'title'       => (string) ($r['title'] ?? ''),
                    'body'        => (string) ($r['message'] ?? ''),
                    'link'        => (string) ($r['target_url'] ?? ''),
                    'target'      => ak_notif_platform_target($pdo, $r),
                    'icon'        => $PICON[$r['type'] ?? ''] ?? 'notifications',
                    'read'        => !empty($r['is_read']),
                    'ago'         => nago($r['created_at'] ?? null),
                    'date'        => ak_notif_date_label($r['created_at'] ?? null),
                    'created_at'  => (string) ($r['created_at'] ?? ''),
                ];
            }
            $pc = $pdo->prepare("SELECT COUNT(*) FROM platform_notifications WHERE recipient_user_id = ? AND is_read = 0");
            $pc->execute([$uid]);
            $platform_unread = (int) $pc->fetchColumn();
            // Les plus récentes d'abord, toutes sources confondues
            usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        } catch (Throwable $e) {}   // table absente : aucune notification plateforme
    }

    echo json_encode(['ok' => true, 'unread' => $unread + $platform_unread, 'platform_unread' => $platform_unread,
                      'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-notifications] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
