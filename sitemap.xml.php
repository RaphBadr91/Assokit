<?php
/**
 * sitemap.xml.php — Sitemap INDEX
 * --------------------------------------------------------------
 * Liste les sous-sitemaps du site Assokit.
 * URL servie : /sitemap.xml (via .htaccess)
 *
 * Architecture :
 *   /sitemap.xml          → ce fichier (index)
 *      ├── /sitemap-pages.xml  → pages statiques (accueil, tarifs, contact...)
 *      └── /sitemap-blog.xml   → articles de blog + catégories
 *
 * Note : Les robots respectent automatiquement le format sitemap_index.
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://assokit.fr';

// --- lastmod du sitemap-blog : date de l'article le plus récent ---
$blog_lastmod = date('Y-m-d');
try {
    require_once __DIR__ . '/includes-public.php'; // pour avoir accès à $pdo
    if (isset($pdo)) {
        $stmt = $pdo->query("
            SELECT GREATEST(
                COALESCE(MAX(updated_at), '1970-01-01'),
                COALESCE(MAX(published_at), '1970-01-01')
            ) AS last_change
            FROM asso_blog_articles
            WHERE is_published = 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['last_change']) && $row['last_change'] !== '1970-01-01') {
            $blog_lastmod = date('Y-m-d', strtotime($row['last_change']));
        }
    }
} catch (Throwable $e) {
    error_log('sitemap.xml lastmod blog: ' . $e->getMessage());
}

$pages_lastmod = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc><?= htmlspecialchars($site_url . '/sitemap-pages.xml', ENT_XML1) ?></loc>
        <lastmod><?= $pages_lastmod ?></lastmod>
    </sitemap>
    <sitemap>
        <loc><?= htmlspecialchars($site_url . '/sitemap-blog.xml', ENT_XML1) ?></loc>
        <lastmod><?= $blog_lastmod ?></lastmod>
    </sitemap>
</sitemapindex>
