<?php
/**
 * ============================================================
 * ASSOKIT — API : récupérer les nouveaux messages d'un projet
 * ============================================================
 * GET /api-messages.php?project_id=X&since=Y
 *   - project_id : ID du projet
 *   - since : ID du dernier message connu (0 pour récupérer tous)
 * 
 * Retourne JSON :
 *   { ok: true, messages: [...], last_id: N }
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('api_messages', 90, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

$project_id = (int)($_GET['project_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);

if ($project_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_project']);
    exit;
}

// Vérifier que le projet appartient bien à l'org
$stmt = $pdo->prepare("
    SELECT p.id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $org_id]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Récupérer les messages depuis $since
try {
    $stmt = $pdo->prepare("
        SELECT m.id, m.content, m.message_type, m.created_at,
               m.author_id, u.first_name, u.last_name, u.avatar_color
        FROM project_messages m
        JOIN users u ON m.author_id = u.id
        WHERE m.project_id = ? 
          AND m.id > ?
          AND m.message_type = 'text'
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT 50
    ");
    $stmt->execute([$project_id, $since]);
    $messages = $stmt->fetchAll();

    $result_messages = [];
    $last_id = $since;
    
    foreach ($messages as $m) {
        $result_messages[] = [
            'id'         => (int)$m['id'],
            'author_id'  => (int)$m['author_id'],
            'first_name' => $m['first_name'],
            'last_name'  => $m['last_name'],
            'avatar_color' => $m['avatar_color'] ?? 'blue',
            'initials'   => mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1)),
            'content'    => $m['content'],
            'created_at' => $m['created_at'],
            'time_label' => format_time_p_safe($m['created_at']),
            'is_self'    => ((int)$m['author_id'] === (int)$current['id']),
        ];
        $last_id = max($last_id, (int)$m['id']);
    }

    echo json_encode([
        'ok' => true,
        'messages' => $result_messages,
        'last_id'  => $last_id,
        'count'    => count($result_messages),
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}

/**
 * Format date façon "il y a 5 min" / "à l'instant" / "10:42" / "Hier 14:30"
 */
function format_time_p_safe(string $dt_str): string {
    try {
        $dt = new DateTime($dt_str);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        
        if ($diff < 60) return "à l'instant";
        if ($diff < 3600) return "il y a " . floor($diff / 60) . " min";
        
        $today = $now->format('Y-m-d');
        $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
        $msg_date = $dt->format('Y-m-d');
        
        if ($msg_date === $today) return $dt->format('H:i');
        if ($msg_date === $yesterday) return 'hier ' . $dt->format('H:i');
        
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $dt_str;
    }
}
