<?php
/**
 * api/app-channel-messages.php — Messages d'un canal pour l'ecran natif.
 * Verifie la visibilite (public/announce OU membre) + scope org. Lecture seule.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

function m_ini($fn, $ln) {
    $x = (function_exists('mb_substr') ? mb_substr($fn, 0, 1) : substr($fn, 0, 1))
       . (function_exists('mb_substr') ? mb_substr($ln, 0, 1) : substr($ln, 0, 1));
    $x = strtoupper(trim($x));
    return $x !== '' ? $x : '?';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $uid = (int) ($user['id'] ?? ($_SESSION['user_id'] ?? 0));
    $channel_id = (int) ($_GET['channel_id'] ?? 0);
    if ($channel_id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'channel']); exit; }

    // Le canal appartient a l'org + visibilite
    $st = $pdo->prepare("SELECT id, name, type FROM channels WHERE id = ? AND org_id = ? AND is_archived = 0 LIMIT 1");
    $st->execute([$channel_id, $org_id]);
    $ch = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ch) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    if ($ch['type'] === 'private') {
        $m = $pdo->prepare("SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1");
        $m->execute([$channel_id, $uid]);
        if (!$m->fetchColumn()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
    }

    $stmt = $pdo->prepare("
        SELECT cm.id, cm.user_id, cm.content, cm.created_at, cm.reply_to_message_id,
               u.first_name, u.last_name, u.role, u.avatar_color,
               rcm.content AS reply_content, ru.first_name AS reply_first, ru.last_name AS reply_last
        FROM channel_messages cm
        JOIN users u ON cm.user_id = u.id
        LEFT JOIN channel_messages rcm ON cm.reply_to_message_id = rcm.id
        LEFT JOIN users ru ON rcm.user_id = ru.id
        WHERE cm.channel_id = ? AND cm.deleted_at IS NULL
        ORDER BY cm.created_at ASC, cm.id ASC
        LIMIT 100
    ");
    $stmt->execute([$channel_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $messages = [];
    foreach ($rows as $r) {
        $fn = trim((string) ($r['first_name'] ?? ''));
        $ln = trim((string) ($r['last_name'] ?? ''));
        $ts = strtotime((string) $r['created_at']);
        $reply = null;
        if (!empty($r['reply_to_message_id']) && $r['reply_content'] !== null) {
            $reply = [
                'author'  => trim(trim((string) ($r['reply_first'] ?? '')) . ' ' . trim((string) ($r['reply_last'] ?? ''))),
                'content' => mb_substr((string) $r['reply_content'], 0, 90),
            ];
        }
        $messages[] = [
            'id'       => (int) $r['id'],
            'is_self'  => ((int) $r['user_id'] === $uid),
            'author'   => trim($fn . ' ' . $ln),
            'initials' => m_ini($fn, $ln),
            'role'     => (string) ($r['role'] ?? ''),
            'color'    => function_exists('folder_color_hex') ? folder_color_hex((string) ($r['avatar_color'] ?? 'blue')) : '#3B82F6',
            'content'  => (string) $r['content'],
            'time'     => $ts ? date('d/m H:i', $ts) : '',
            'reply'    => $reply,
        ];
    }

    // Marquer comme lu (best effort)
    if (!empty($messages)) {
        $last_id = (int) end($messages)['id'];
        try {
            $pdo->prepare("INSERT INTO channel_reads (channel_id, user_id, last_read_message_id) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))")
                ->execute([$channel_id, $uid, $last_id]);
        } catch (Throwable $e) {}
    }

    echo json_encode(['ok' => true, 'channel' => ['id' => (int) $ch['id'], 'name' => (string) $ch['name']], 'messages' => $messages], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-channel-messages] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
