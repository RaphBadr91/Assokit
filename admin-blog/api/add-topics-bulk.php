<?php
/**
 * api/add-topics-bulk.php
 * Reçoit un tableau de sujets, les insère tous dans asso_blog_topics avec status=pending.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/article-helper.php';

header('Content-Type: application/json; charset=utf-8');

send_security_headers();
auth_start_session();

if (!auth_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!csrf_check($input['_csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF invalide']);
    exit;
}

$topics = $input['topics'] ?? [];
if (!is_array($topics) || empty($topics)) {
    echo json_encode(['success' => false, 'error' => 'Aucun sujet fourni']);
    exit;
}
if (count($topics) > 50) {
    echo json_encode(['success' => false, 'error' => 'Trop de sujets (max 50 par lot)']);
    exit;
}

$added = 0;
$skipped = 0;
$ids = [];

try {
    foreach ($topics as $t) {
        if (!is_array($t)) { $skipped++; continue; }
        
        $title    = trim((string)($t['title'] ?? ''));
        $cat      = trim((string)($t['category'] ?? ''));
        $keywords = trim((string)($t['keywords'] ?? ''));
        $briefing = trim((string)($t['briefing'] ?? ''));
        $priority = (int)($t['priority'] ?? 5);
        
        // Validation
        if ($title === '' || mb_strlen($title) > 255) { $skipped++; continue; }
        if (!in_array($cat, CATEGORIES, true)) { $skipped++; continue; }
        if ($priority < 1 || $priority > 10) $priority = 5;
        
        // Anti-doublon : skip si un sujet identique existe déjà en pending
        $exists = DB::fetch(
            "SELECT id FROM asso_blog_topics WHERE topic_title = ? AND status = 'pending' LIMIT 1",
            [$title]
        );
        if ($exists) { $skipped++; continue; }
        
        // Insert
        DB::execute(
            "INSERT INTO asso_blog_topics (topic_title, category, target_keywords, briefing_extra, priority, status, created_at) 
             VALUES (?, ?, ?, ?, ?, 'pending', NOW())",
            [$title, $cat, mb_substr($keywords, 0, 500), mb_substr($briefing, 0, 1000), $priority]
        );
        
        $ids[] = (int)DB::lastInsertId();
        $added++;
    }
    
    admin_log('topics_added_bulk', "+{$added} sujets ajoutés (skipped: {$skipped})", 'success');
    
    echo json_encode([
        'success' => true,
        'added'   => $added,
        'skipped' => $skipped,
        'ids'     => $ids,
    ]);
    
} catch (Throwable $e) {
    admin_log('topics_add_bulk_failed', $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur BDD : ' . $e->getMessage(),
        'added'   => $added,
    ]);
}
