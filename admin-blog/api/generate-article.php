<?php
/**
 * API : génération d'un article via Claude
 * POST JSON ou form-encoded
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claude.php';

send_security_headers();
auth_require();
csrf_require();

set_time_limit(180); // génération peut prendre jusqu'à 2 min

try {
    // Récupération des paramètres (JSON ou form)
    $input = $_POST;
    if (empty($input)) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $mode = $input['mode'] ?? 'free'; // 'free' ou 'topic'
    $is_published = !empty($input['is_published']) ? 1 : 0;

    if ($mode === 'topic') {
        // Génération depuis un sujet en file
        $topic_id = (int) ($input['topic_id'] ?? 0);
        if ($topic_id <= 0) {
            throw new InvalidArgumentException('topic_id requis');
        }
        $topic = DB::fetch('SELECT * FROM asso_blog_topics WHERE id = ?', [$topic_id]);
        if (!$topic) {
            throw new InvalidArgumentException('Sujet introuvable');
        }
        if ($topic['status'] === 'generated') {
            throw new InvalidArgumentException('Ce sujet a déjà été utilisé');
        }
        $result = generate_article_from_topic(
            $topic['topic_title'],
            $topic['category'],
            $topic['target_keywords'],
            $topic['briefing_extra'],
            ['is_published' => $is_published]
        );
        // Marquer le sujet comme utilisé
        DB::execute(
            "UPDATE asso_blog_topics SET status = 'generated', generated_slug = ?, generated_at = NOW() WHERE id = ?",
            [$result['slug'], $topic_id]
        );
        echo json_encode([
            'ok'      => true,
            'mode'    => 'topic',
            'topic_id' => $topic_id,
            'article'  => $result,
            'edit_url' => '/admin-blog/article-edit.php?slug=' . urlencode($result['slug']),
            'public_url' => SITE_URL . '/blog/' . $result['slug'],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Génération libre
        $topic_title = trim((string) ($input['topic_title'] ?? ''));
        $category    = (string) ($input['category'] ?? '');
        $keywords    = trim((string) ($input['keywords'] ?? '')) ?: null;
        $briefing    = trim((string) ($input['briefing_extra'] ?? '')) ?: null;

        if ($topic_title === '') {
            throw new InvalidArgumentException('Sujet requis');
        }
        if (!in_array($category, CATEGORIES, true)) {
            throw new InvalidArgumentException('Catégorie invalide');
        }

        $result = generate_article_from_topic(
            $topic_title,
            $category,
            $keywords,
            $briefing,
            ['is_published' => $is_published]
        );
        echo json_encode([
            'ok'         => true,
            'mode'       => 'free',
            'article'    => $result,
            'edit_url'   => '/admin-blog/article-edit.php?slug=' . urlencode($result['slug']),
            'public_url' => SITE_URL . '/blog/' . $result['slug'],
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    admin_log('article_gen_failed', $e->getMessage(), 'error');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
