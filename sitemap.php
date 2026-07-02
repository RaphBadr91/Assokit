<?php
/**
 * ============================================================
 * ASSOKIT — sitemap.php
 * ============================================================
 * Sitemap XML dynamique conforme aux standards Google/Bing.
 *
 * Inclut :
 *   - Pages publiques statiques (accueil, plans, légal)
 *   - Pages associations publiques actives (slug)
 *
 * Accessible via :
 *   https://assokit.fr/sitemap.xml  (via redirection .htaccess)
 *
 * Cache : 1 heure côté serveur pour éviter requêtes BDD massives.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex'); // le sitemap lui-même n'est pas à indexer
header('Cache-Control: public, max-age=3600');

$base_url = 'https://assokit.fr';
$today = date('Y-m-d');

// ============================================================
// PAGES PUBLIQUES STATIQUES
// ============================================================
$static_pages = [
    // Pages principales
    ['url' => '/',                       'priority' => '1.0', 'changefreq' => 'weekly',  'lastmod' => $today],
    ['url' => '/plans',                  'priority' => '0.9', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['url' => '/inscription',            'priority' => '0.9', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['url' => '/contact',                'priority' => '0.7', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['url' => '/login',                  'priority' => '0.6', 'changefreq' => 'yearly',  'lastmod' => $today],

    // Pages produit / fonctionnalités (à adapter selon ton site)
    ['url' => '/fonctionnalites',        'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['url' => '/pour-associations',      'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['url' => '/pour-tpe',               'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $today],

    // Pages légales
    ['url' => '/mentions-legales',       'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
    ['url' => '/cgu',                    'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
    ['url' => '/cgv',                    'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
    ['url' => '/politique-confidentialite', 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => $today],
    ['url' => '/rgpd',                   'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
    ['url' => '/cookies',                'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
];

// ============================================================
// PAGES ASSOCIATIONS PUBLIQUES (slug actifs)
// ============================================================
$asso_pages = [];
try {
    $stmt = $pdo->query("
        SELECT slug, updated_at
        FROM organizations
        WHERE deleted_at IS NULL
          AND is_public = 1
          AND slug IS NOT NULL
          AND slug != ''
        ORDER BY updated_at DESC
        LIMIT 5000
    ");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $asso_pages[] = [
                'url' => '/asso/' . $row['slug'],
                'priority' => '0.7',
                'changefreq' => 'weekly',
                'lastmod' => !empty($row['updated_at']) ? date('Y-m-d', strtotime($row['updated_at'])) : $today,
            ];
        }
    }
} catch (Throwable $e) {
    // Si la table organizations n'a pas exactement ces colonnes, on continue sans crash
    error_log('[sitemap] ' . $e->getMessage());
}

// ============================================================
// GÉNÉRATION XML
// ============================================================
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

$all_pages = array_merge($static_pages, $asso_pages);

foreach ($all_pages as $page) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($base_url . $page['url'], ENT_XML1, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($page['lastmod']) . "</lastmod>\n";
    echo '    <changefreq>' . htmlspecialchars($page['changefreq']) . "</changefreq>\n";
    echo '    <priority>' . htmlspecialchars($page['priority']) . "</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
