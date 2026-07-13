<?php
/**
 * api/app-founder-blog-topic.php — Programme un article (file d'attente IA) depuis l'app.
 * Ajoute un sujet dans asso_blog_topics (status=pending) : le cron du site le génère
 * automatiquement ensuite — exactement comme la programmation du site, sans y toucher.
 * POST { action:'add'|'delete', topic_title?, category?, keywords?, briefing?, priority?, id?, csrf }
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$CATS = ['associations', 'tpe', 'comptabilite', 'juridique', 'communication', 'gestion'];
$action = (string) ($input['action'] ?? 'add');

try {
    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) app_fail(400, 'id');
        $pdo->prepare("DELETE FROM asso_blog_topics WHERE id = ? AND status = 'pending'")->execute([$id]);
        echo json_encode(['ok' => true, 'deleted' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $title    = trim((string) ($input['topic_title'] ?? ''));
    $category = (string) ($input['category'] ?? '');
    $keywords = mb_substr(trim((string) ($input['keywords'] ?? '')), 0, 500);
    $briefing = mb_substr(trim((string) ($input['briefing'] ?? '')), 0, 1000);
    $priority = (int) ($input['priority'] ?? 5);
    if ($priority < 1 || $priority > 10) $priority = 5;

    if ($title === '') app_fail(400, 'title', 'Indique un sujet.');
    if (!in_array($category, $CATS, true)) app_fail(400, 'category', 'Catégorie invalide.');

    // Anti-doublon (comme le site)
    $dup = $pdo->prepare("SELECT id FROM asso_blog_topics WHERE topic_title = ? AND status = 'pending' LIMIT 1");
    $dup->execute([$title]);
    if ($dup->fetchColumn()) app_fail(409, 'duplicate', 'Ce sujet est déjà programmé.');

    $pdo->prepare("INSERT INTO asso_blog_topics (topic_title, category, target_keywords, briefing_extra, priority, status, created_at)
                   VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
        ->execute([$title, $category, $keywords, $briefing, $priority]);

    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'title' => $title], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-blog-topic] ' . $e->getMessage());
    app_fail(500, 'server');
}
