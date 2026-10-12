<?php
/**
 * API : récupérer les nouveaux messages d'un channel depuis un ID donné
 * GET /api-channel-messages.php?channel_id=X&since=Y[&seen=0|1]
 * Retourne : { ok: true, messages: [...], last_id: N }
 * Lecture : les messages renvoyés sont affichés dans le canal ouvert, ils sont donc
 * marqués lus (channel_reads + notifications du canal). seen=0 : onglet en arrière-plan,
 * rien n'est marqué ; seen=1 : retour sur l'onglet, tout ce qui est affiché (jusqu'à since) est lu.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
require_once __DIR__ . '/notification-helpers.php';
ak_rate_limit_or_die('api_channel_msg', 90, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
require_login();

$current = current_user();
$user_id = (int)$current['id'];
$channel_id = (int)($_GET['channel_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);
$seen = isset($_GET['seen']) ? (string)$_GET['seen'] : '';

if ($channel_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_channel']);
    exit;
}

try {
    // === Sécurité : accès au canal (aligné sur messages.php et mairie-messages.php) ===
    // Canaux public/announce : ouverts à tous les membres de l'org.
    // Canaux privés : réservés aux membres du canal.
    // Canal « Suivi · Mairie » : aussi ouvert aux agents de la mairie membres du canal
    // (ils n'ont pas d'org_id : leur rattachement passe par parent_org_id).
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'channel_not_found']);
        exit;
    }

    $same_org = (int)$channel['org_id'] === (int)($current['org_id'] ?? 0);
    $mairie_agent = !empty($channel['is_mairie_channel'])
        && !empty($channel['parent_org_id'])
        && (int)($current['parent_org_id'] ?? 0) === (int)$channel['parent_org_id'];
    if (!$same_org && !$mairie_agent) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    // Suiveurs (financeurs externes) : pas d'accès à la messagerie interne
    if (($current['role'] ?? '') === 'follower') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    if ($mairie_agent || !in_array($channel['type'], ['public', 'announce'], true)) {
        $stmt = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
        $stmt->execute([$channel_id, $user_id]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'not_member']);
            exit;
        }
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

    // Marquer lu ce qui est affiché (même repère que le compteur de non-lus de messages.php)
    if ($seen !== '0' && ($messages || $seen === '1') && $last_id > 0) {
        try {
            // Jamais au-delà du dernier message du canal (since vient du navigateur)
            $mx = $pdo->prepare("SELECT MAX(id) FROM channel_messages WHERE channel_id = ?");
            $mx->execute([$channel_id]);
            $read_upto = min($last_id, (int)$mx->fetchColumn());
            if ($read_upto > 0) {
                $pdo->prepare("
                    INSERT INTO channel_reads (channel_id, user_id, last_read_message_id, last_read_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id)), last_read_at = NOW()
                ")->execute([$channel_id, $user_id, $read_upto]);
            }
            ak_channel_notifs_mark_read($pdo, $user_id, $channel);
        } catch (Throwable $e) {
            error_log('[api-channel-messages] lecture : ' . $e->getMessage());
        }
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
