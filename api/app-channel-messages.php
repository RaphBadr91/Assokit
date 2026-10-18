<?php
/**
 * api/app-channel-messages.php — Messages d'un canal pour l'ecran natif.
 * Verifie la visibilite (public/announce OU membre) + scope org. Lecture seule.
 * NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id
require_once __DIR__ . '/../notification-helpers.php';

function m_ini($fn, $ln) {
    $x = (function_exists('mb_substr') ? mb_substr($fn, 0, 1) : substr($fn, 0, 1))
       . (function_exists('mb_substr') ? mb_substr($ln, 0, 1) : substr($ln, 0, 1));
    $x = strtoupper(trim($x));
    return $x !== '' ? $x : '?';
}

try {
    $channel_id = (int) ($_GET['channel_id'] ?? 0);
    if ($channel_id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'channel']); exit; }
    // Suiveurs (financeurs externes) : pas d'accès à la messagerie interne, comme sur le site
    if (($user['role'] ?? '') === 'follower') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

    // Le canal appartient a l'org + visibilite
    $st = $pdo->prepare("SELECT id, name, slug, type, is_mairie_channel FROM channels WHERE id = ? AND org_id = ? AND is_archived = 0 LIMIT 1");
    $st->execute([$channel_id, $org_id]);
    $ch = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ch) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    if ($ch['type'] === 'private') {
        $m = $pdo->prepare("SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1");
        $m->execute([$channel_id, $uid]);
        if (!$m->fetchColumn()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
    }

    // Marquer comme lu jusqu'au dernier message du canal (même calcul que la pastille d'app-channels.php),
    // dès les droits vérifiés : l'appli envoie en même temps api/app-notif-read.php, qui attend cette
    // lecture (last_read_at) pour renvoyer des compteurs tenant compte de ce canal.
    try {
        $mx = $pdo->prepare("SELECT MAX(id) FROM channel_messages WHERE channel_id = ? AND deleted_at IS NULL");
        $mx->execute([$channel_id]);
        $last_id = (int) $mx->fetchColumn();
        $pdo->prepare("INSERT INTO channel_reads (channel_id, user_id, last_read_message_id, last_read_at) VALUES (?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id)), last_read_at = NOW()")
            ->execute([$channel_id, $uid, $last_id]);
    } catch (Throwable $e) {}
    // Canal ouvert : ses notifications (messages, mentions) sont soldées, et seulement elles
    $notif_read = ak_channel_notifs_mark_read($pdo, $uid, $ch);

    $stmt = $pdo->prepare("
        SELECT cm.id, cm.user_id, cm.content, cm.created_at, cm.reply_to_message_id,
               u.first_name, u.last_name, u.role, u.avatar_color, u.parent_org_id AS author_parent_org_id,
               u.org_id AS author_org_id,
               rcm.content AS reply_content, rcm.user_id AS reply_user_id, ru.first_name AS reply_first, ru.last_name AS reply_last
        FROM channel_messages cm
        JOIN users u ON cm.user_id = u.id
        LEFT JOIN channel_messages rcm ON cm.reply_to_message_id = rcm.id
        LEFT JOIN users ru ON rcm.user_id = ru.id
        WHERE cm.channel_id = ? AND cm.deleted_at IS NULL
        ORDER BY cm.created_at DESC, cm.id DESC
        LIMIT 100
    ");
    $stmt->execute([$channel_id]);
    // Les 100 plus récents, remis dans l'ordre chronologique (le fil défile vers le bas)
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    // Appui long dans l'app (api/app-message-action.php) : « Supprimer » n'est proposé que si le
    // serveur l'accepterait, avec les règles d'action-message-canal.php (auteur, ou administrateur
    // sauf pour les messages des agents de la mairie sur le canal « Suivi · Mairie »).
    $viewer_admin = (($user['role'] ?? '') === 'admin');
    $messages = [];
    foreach ($rows as $r) {
        $is_mairie_agent_msg = !empty($ch['is_mairie_channel']) && (int) ($r['author_org_id'] ?? 0) !== (int) $org_id;
        $fn = trim((string) ($r['first_name'] ?? ''));
        $ln = trim((string) ($r['last_name'] ?? ''));
        $ts = strtotime((string) $r['created_at']);
        $reply = null;
        if (!empty($r['reply_to_message_id']) && $r['reply_content'] !== null) {
            $reply = [
                'author'    => trim(trim((string) ($r['reply_first'] ?? '')) . ' ' . trim((string) ($r['reply_last'] ?? ''))),
                'author_id' => (int) ($r['reply_user_id'] ?? 0),
                'content' => mb_substr((string) $r['reply_content'], 0, 90),
            ];
        }
        $messages[] = [
            'id'       => (int) $r['id'],
            'is_self'  => ((int) $r['user_id'] === $uid),
            'author_id' => (int) $r['user_id'],
            'can_delete' => ((int) $r['user_id'] === $uid) || ($viewer_admin && !$is_mairie_agent_msg),
            'author'   => trim($fn . ' ' . $ln),
            'initials' => m_ini($fn, $ln),
            'role'     => (string) ($r['role'] ?? ''),
            // Agent de la mairie (canal « Suivi · Mairie ») : à présenter comme « Mairie », pas par son rôle
            'is_mairie' => !empty($r['author_parent_org_id']),
            'color'    => function_exists('folder_color_hex') ? folder_color_hex((string) ($r['avatar_color'] ?? 'blue')) : '#3B82F6',
            'content'  => (string) $r['content'],
            'time'     => $ts ? date('d/m H:i', $ts) : '',
            'reply'    => $reply,
        ];
    }

    echo json_encode(['ok' => true, 'viewer_id' => (int) $uid, 'notifs_marked_read' => $notif_read, 'channel' => ['id' => (int) $ch['id'], 'name' => (string) $ch['name']], 'messages' => $messages], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-channel-messages] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
