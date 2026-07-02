<?php
/**
 * sitemap-blog.xml.php — Sitemap des articles de blog
 * --------------------------------------------------------------
 * URL servie : /sitemap-blog.xml (via .htaccess)
 * 
 * Stratégie SEO :
 *   - <lastmod> : date de mise à jour réelle de chaque article
 *   - <priority> :
 *       0.9 si article < 7 jours (frais)
 *       0.8 si article < 30 jours
 *       0.7 si article < 90 jours
 *       0.6 sinon
 *   - <changefreq> : weekly (les articles peuvent être enrichis)
 *   - Pages catégories incluses (priority 0.7)
 *
 * Limites :
 *   - 50 000 URLs max par sitemap (norme Google)
 *   - 50 Mo non compressé
 *   À 433 articles prévus, on est très loin de ces limites.
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php'; // pour récupérer $pdo et helpers

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://assokit.fr';

// --- Récupération des articles publiés ---
$articles = [];
try {
    if (!isset($pdo)) {
        throw new RuntimeException('Connexion BDD non disponible');
    }
    $stmt = $pdo->query("
        SELECT slug, category, published_at, updated_at
        FROM asso_blog_articles
        WHERE is_published = 1 AND slug IS NOT NULL AND slug != ''
        ORDER BY COALESCE(updated_at, published_at) DESC
        LIMIT 50000
    ");
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('sitemap-blog: ' . $e->getMessage());
}

// --- Catégories distinctes (pour pages /blog?categorie=X) ---
$categories = [];
foreach ($articles as $a) {
    $cat = $a['category'] ?? '';
    if ($cat === '') continue;
    $when = $a['updated_at'] ?: $a['published_at'];
    if (!isset($categories[$cat]) || strtotime($when) > strtotime($categories[$cat])) {
        $categories[$cat] = $when;
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<?php // Pages catégories ?>
<?php foreach ($categories as $cat => $lastmod): ?>
    <url>
        <loc><?= htmlspecialchars($site_url . '/blog?categorie=' . rawurlencode($cat), ENT_XML1) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($lastmod)) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
<?php endforeach; ?>

<?php // Articles ?>
<?php
$now = time();
foreach ($articles as $article):
    $url = $site_url . '/blog/' . $article['slug'];
    $lastmod_ts = strtotime($article['updated_at'] ?: $article['published_at']);
    if (!$lastmod_ts) $lastmod_ts = $now;
    $age_days = max(0, ($now - $lastmod_ts) / 86400);
    
    // Priorité dégressive
    if      ($age_days < 7)   { $priority = '0.9'; $changefreq = 'daily'; }
    elseif  ($age_days < 30)  { $priority = '0.8'; $changefreq = 'weekly'; }
    elseif  ($age_days < 90)  { $priority = '0.7'; $changefreq = 'weekly'; }
    else                       { $priority = '0.6'; $changefreq = 'monthly'; }
?>
    <url>
        <loc><?= htmlspecialchars($url, ENT_XML1) ?></loc>
        <lastmod><?= date('Y-m-d', $lastmod_ts) ?></lastmod>
        <changefreq><?= $changefreq ?></changefreq>
        <priority><?= $priority ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
