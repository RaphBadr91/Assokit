<?php
/**
 * API : récupérer les nouveaux messages d'un channel depuis un ID donné
 * GET /api-channel-messages.php?channel_id=X&since=Y
 * Retourne : { ok: true, messages: [...], last_id: N }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('api_channel_msg', 90, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
require_login();

$current = current_user();
$user_id = (int)$current['id'];
$channel_id = (int)($_GET['channel_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);

if ($channel_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_channel']);
    exit;
}

try {
    // === Sécurité : vérifier que l'utilisateur est membre du channel ===
    $stmt = $pdo->prepare("SELECT id, is_archived FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'channel_not_found']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
    $stmt->execute([$channel_id, $user_id]);
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'not_member']);
        exit;
    }

    // === Récupérer les nouveaux messages ===
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.user_id, cm.content, cm.created_at, cm.is_edited,
               u.first_name, u.last_name, u.parent_org_id AS author_parent_org_id,
               u.avatar_color, u.role AS author_role
        FROM channel_messages cm
        INNER JOIN users u ON u.id = cm.user_id
        WHERE cm.channel_id = ?
          AND cm.id > ?
          AND cm.deleted_at IS NULL
        ORDER BY cm.created_at ASC, cm.id ASC
        LIMIT 100
    ");
    $stmt->execute([$channel_id, $since]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    $last_id = $since;
    foreach ($rows as $r) {
        $is_mairie = !empty($r['author_parent_org_id']);
        // time_short : HH:MM si aujourd'hui, "Hier HH:MM", sinon DD/MM/YYYY HH:MM
        $ts = strtotime($r['created_at']);
        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day', $today);
        if ($ts >= $today) $time_short = date('H:i', $ts);
        elseif ($ts >= $yesterday) $time_short = 'Hier ' . date('H:i', $ts);
        else $time_short = date('d/m/Y H:i', $ts);

        $initials = strtoupper(mb_substr($r['first_name'], 0, 1) . mb_substr($r['last_name'], 0, 1));

        $messages[] = [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'is_self' => ((int)$r['user_id'] === $user_id),
            'author_name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'author_role' => $r['author_role'] ?? '',
            'avatar_initials' => $initials,
            'avatar_color' => $r['avatar_color'] ?? 'gray',
            'role_label' => $is_mairie ? 'Mairie' : 'Asso',
            'role_class' => $is_mairie ? 'mairie' : 'asso',
            'content' => $r['content'],
            'created_at' => $r['created_at'],
            'created_at_display' => date('d/m/Y H:i', strtotime($r['created_at'])),
            'time_short' => $time_short,
            'is_edited' => (bool)$r['is_edited'],
        ];
        if ((int)$r['id'] > $last_id) $last_id = (int)$r['id'];
    }

    echo json_encode([
        'ok' => true,
        'channel_id' => $channel_id,
        'archived' => (bool)$channel['is_archived'],
        'messages' => $messages,
        'last_id' => $last_id,
        'count' => count($messages),
    ]);

} catch (Throwable $e) {
    error_log('[api-channel-messages] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
