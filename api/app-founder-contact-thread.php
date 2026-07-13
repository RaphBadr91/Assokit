<?php
/**
 * api/app-founder-contact-thread.php — Fil complet d'une demande de contact (Fondateur).
 * GET ?id=  → message initial + fil (réponses fondateur & prospect). NE MODIFIE PAS le site.
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
require_once __DIR__ . '/_app-contact-token.php';
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

try {
    $st = $pdo->prepare("SELECT id, firstname, lastname, email, organization, type, subject, message, created_at FROM asso_contact_messages WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    ak_contact_thread_ensure($pdo);

    // Message initial du prospect = première bulle entrante
    $name = trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''));
    $msgs = [[
        'side' => 'in',
        'body' => (string) ($c['message'] ?? ''),
        'at'   => !empty($c['created_at']) ? date('d/m H:i', strtotime($c['created_at'])) : '',
    ]];

    try {
        $ms = $pdo->prepare("SELECT direction, body, created_at FROM asso_contact_thread WHERE contact_id = ? ORDER BY created_at ASC, id ASC");
        $ms->execute([$id]);
        foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $msgs[] = [
                'side' => ($m['direction'] === 'out') ? 'out' : 'in',
                'body' => (string) $m['body'],
                'at'   => !empty($m['created_at']) ? date('d/m H:i', strtotime($m['created_at'])) : '',
            ];
        }
        // Marque les entrants comme lus
        $pdo->prepare("UPDATE asso_contact_thread SET read_by_founder = 1 WHERE contact_id = ? AND direction = 'in' AND read_by_founder = 0")->execute([$id]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'contact' => [
            'id' => $id,
            'name' => $name !== '' ? $name : (string) $c['email'],
            'email' => (string) ($c['email'] ?? ''),
            'org' => (string) ($c['organization'] ?? ''),
            'subject' => (string) ($c['subject'] ?? ''),
        ],
        'messages' => $msgs,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-contact-thread] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
