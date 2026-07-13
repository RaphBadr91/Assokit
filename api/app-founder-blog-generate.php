<?php
/**
 * api/app-founder-blog-generate.php — Génère un article de blog via IA (Fondateur, app).
 * Réutilise le MOTEUR EXISTANT du site (admin-blog) sans le modifier.
 * POST { topic_title, category, keywords?, briefing?, is_published?, csrf }
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

// Charge le moteur du blog (mini-app admin-blog) — constantes + DB + helpers + Claude.
// Ces fichiers ne définissent que des constantes/fonctions : aucun impact site.
$blog = __DIR__ . '/../admin-blog';
if (!is_file($blog . '/config.php')) app_fail(500, 'blog_config', 'Blog non configuré sur le serveur.');
require_once $blog . '/config.php';
require_once $blog . '/includes/db.php';
require_once $blog . '/includes/article-helper.php';
require_once $blog . '/includes/claude.php';

$title    = trim((string) ($input['topic_title'] ?? ''));
$category = (string) ($input['category'] ?? '');
$keywords = trim((string) ($input['keywords'] ?? '')) ?: null;
$briefing = trim((string) ($input['briefing'] ?? '')) ?: null;
$publish  = !empty($input['is_published']) ? 1 : 0;

if ($title === '') app_fail(400, 'title', 'Indique un sujet.');
if (!defined('CATEGORIES') || !in_array($category, CATEGORIES, true)) app_fail(400, 'category', 'Catégorie invalide.');
if (!function_exists('generate_article_from_topic')) app_fail(500, 'engine', 'Moteur de génération indisponible.');

@set_time_limit(180);

try {
    $result = generate_article_from_topic($title, $category, $keywords, $briefing, ['is_published' => $publish]);
    $slug = (string) ($result['slug'] ?? '');
    echo json_encode([
        'ok'        => true,
        'slug'      => $slug,
        'title'     => (string) ($result['title'] ?? $title),
        'published' => (bool) $publish,
        'url'       => $slug !== '' ? ('https://assokit.fr/blog/' . $slug) : '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-blog-generate] ' . $e->getMessage());
    app_fail(500, 'gen', 'Génération impossible : ' . $e->getMessage());
}
