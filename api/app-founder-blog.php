<?php
/**
 * api/app-founder-blog.php — Blog SEO auto-généré (cockpit Fondateur, natif).
 * Réservé Fondateur/Super Admin. Lecture seule. NE MODIFIE PAS le site.
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

$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

try {
    $total     = (int) $scalar("SELECT COUNT(*) FROM asso_blog_articles");
    $published = (int) $scalar("SELECT COUNT(*) FROM asso_blog_articles WHERE is_published = 1");
    $drafts    = max(0, $total - $published);
    $last30    = (int) $scalar("SELECT COUNT(*) FROM asso_blog_articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $topics    = (int) $scalar("SELECT COUNT(*) FROM asso_blog_topics");

    $rows = [];
    try {
        $st = $pdo->query("
            SELECT id, title, slug, category, is_published, reading_time_min, created_at, published_at
            FROM asso_blog_articles ORDER BY created_at DESC LIMIT 40");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try { $st = $pdo->query("SELECT id, title, slug, category, is_published, created_at FROM asso_blog_articles ORDER BY created_at DESC LIMIT 40"); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e2) {}
    }

    $articles = [];
    foreach ($rows as $r) {
        $slug = (string) ($r['slug'] ?? '');
        $articles[] = [
            'id'        => (int) $r['id'],
            'title'     => (string) ($r['title'] ?? 'Article'),
            'category'  => (string) ($r['category'] ?? ''),
            'published' => !empty($r['is_published']),
            'reading'   => (int) ($r['reading_time_min'] ?? 0),
            'date'      => !empty($r['created_at']) ? date('d/m/Y', strtotime($r['created_at'])) : '',
            'url'       => $slug !== '' ? ('https://assokit.fr/blog/' . $slug) : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'stats' => ['total' => $total, 'published' => $published, 'drafts' => $drafts, 'last30' => $last30, 'topics' => $topics],
        'articles' => $articles,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-blog] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
