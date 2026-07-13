<?php
/**
 * api/app-founder-support-thread.php — Fil d'un ticket support (Fondateur, natif).
 * GET ?id=  → ticket + messages. Marque les messages de l'org comme lus côté support.
 * NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

try {
    $st = $pdo->prepare("SELECT t.id, t.title, t.category, t.priority, t.status, t.created_at, t.org_id, o.name AS org_name
                         FROM support_tickets t LEFT JOIN organizations o ON o.id = t.org_id WHERE t.id = ? LIMIT 1");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    // Marque les messages de l'org comme lus (côté support)
    try { $pdo->prepare("UPDATE support_messages SET read_by_support = 1 WHERE ticket_id = ? AND author_side = 'org' AND read_by_support = 0")->execute([$id]); } catch (Throwable $e) {}

    $msgs = [];
    try {
        $ms = $pdo->prepare("SELECT id, author_side, body, is_internal_note, created_at FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 200");
        $ms->execute([$id]);
        foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $msgs[] = [
                'id'      => (int) $m['id'],
                'side'    => (string) $m['author_side'],
                'body'    => (string) $m['body'],
                'note'    => !empty($m['is_internal_note']),
                'at'      => !empty($m['created_at']) ? date('d/m H:i', strtotime($m['created_at'])) : '',
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'ticket' => [
            'id' => (int) $t['id'],
            'title' => (string) $t['title'],
            'org' => (string) ($t['org_name'] ?? '—'),
            'category' => (string) ($t['category'] ?? ''),
            'priority' => (string) ($t['priority'] ?? 'normal'),
            'status' => (string) ($t['status'] ?? 'open'),
            'date' => !empty($t['created_at']) ? date('d/m/Y', strtotime($t['created_at'])) : '',
            'closed' => in_array((string) ($t['status'] ?? ''), ['closed'], true),
        ],
        'messages' => $msgs,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-support-thread] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
