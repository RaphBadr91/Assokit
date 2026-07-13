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

    // File d'attente (sujets programmés, non encore générés)
    $queue = [];
    try {
        $st = $pdo->query("SELECT id, topic_title, category, priority, created_at FROM asso_blog_topics WHERE status='pending' ORDER BY priority ASC, created_at ASC LIMIT 40");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $queue[] = [
                'id'       => (int) $t['id'],
                'title'    => (string) $t['topic_title'],
                'category' => (string) ($t['category'] ?? ''),
                'priority' => (int) ($t['priority'] ?? 5),
                'date'     => !empty($t['created_at']) ? date('d/m/Y', strtotime($t['created_at'])) : '',
            ];
        }
    } catch (Throwable $e) {}

    $categories = [
        ['key' => 'associations',  'label' => 'Associations'],
        ['key' => 'tpe',           'label' => 'TPE & indépendants'],
        ['key' => 'comptabilite',  'label' => 'Comptabilité'],
        ['key' => 'juridique',     'label' => 'Juridique'],
        ['key' => 'communication', 'label' => 'Communication'],
        ['key' => 'gestion',       'label' => 'Gestion'],
    ];

    echo json_encode([
        'ok' => true,
        'stats' => ['total' => $total, 'published' => $published, 'drafts' => $drafts, 'last30' => $last30, 'topics' => $topics, 'queue' => count($queue)],
        'articles' => $articles,
        'queue' => $queue,
        'categories' => $categories,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-blog] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
