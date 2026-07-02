<?php
/**
 * API : suppression d'un article
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/article-helper.php';

send_security_headers();
auth_require();
csrf_require();

try {
    $input = $_POST;
    if (empty($input)) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    $slug = trim((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        throw new InvalidArgumentException('slug requis');
    }
    $deleted = DB::execute('DELETE FROM asso_blog_articles WHERE slug = ?', [$slug]);
    if ($deleted === 0) {
        throw new RuntimeException('Article introuvable');
    }
    admin_log('article_deleted', "Slug: {$slug}", 'success');
    echo json_encode(['ok' => true, 'slug' => $slug]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
