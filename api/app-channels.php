<?php
/**
 * api/app-channels.php — Liste des canaux de discussion pour l'ecran natif.
 * Reproduit la visibilite de messages.php (public/announce OU membre). Scope org.
 * NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

try {
    // Suiveurs (financeurs externes) : pas d'accès à la messagerie interne, comme sur le site
    if (($user['role'] ?? '') === 'follower') {
        echo json_encode(['ok' => true, 'allowed' => false, 'channels' => [],
                          'message' => 'La messagerie interne est réservée aux membres de l\'association.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Sans association (agent d'une collectivité seul) : pas de messagerie d'association ici
    if ($org_id <= 0) {
        echo json_encode(['ok' => true, 'allowed' => false, 'error' => 'no_org', 'channels' => [],
                          'message' => 'La messagerie interne est réservée aux membres d’une association.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Admin nommé après le rattachement à une mairie : il rejoint le canal « Suivi · Mairie »
    // (même synchronisation que messages.php sur le site)
    if (($user['role'] ?? '') === 'admin' && is_file(__DIR__ . '/../includes-mairie-channels.php')) {
        require_once __DIR__ . '/../includes-mairie-channels.php';
        if (function_exists('sync_mairie_channels_for_org_admin')) {
            try { sync_mairie_channels_for_org_admin($pdo, $uid, $org_id); }
            catch (Throwable $e) { error_log('[api/app-channels] sync mairie : ' . $e->getMessage()); }
        }
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.slug, c.type, c.color_theme, c.icon,
               (SELECT COUNT(*) FROM channel_messages cm WHERE cm.channel_id = c.id AND cm.deleted_at IS NULL) AS msg_count,
               (SELECT MAX(id) FROM channel_messages WHERE channel_id = c.id AND deleted_at IS NULL) AS last_msg_id,
               (SELECT last_read_message_id FROM channel_reads WHERE channel_id = c.id AND user_id = ?) AS last_read_id
        FROM channels c
        WHERE c.org_id = ? AND c.is_archived = 0
          AND (c.type IN ('public','announce')
               OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = ?))
        ORDER BY c.position ASC, c.name ASC
        LIMIT 100
    ");
    $stmt->execute([$uid, $org_id, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $channels = [];
    foreach ($rows as $r) {
        $last = (int) ($r['last_msg_id'] ?? 0);
        $read = (int) ($r['last_read_id'] ?? 0);
        $channels[] = [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'slug'   => (string) $r['slug'],
            'type'   => (string) $r['type'],
            'color'  => function_exists('folder_color_hex') ? folder_color_hex((string) ($r['color_theme'] ?? 'blue')) : '#3B82F6',
            'count'  => (int) ($r['msg_count'] ?? 0),
            'unread' => ($last > $read),
        ];
    }

    echo json_encode(['ok' => true, 'channels' => $channels], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-channels] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
