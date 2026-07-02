<?php
/**
 * sitemap-pages.xml.php — Sitemap des pages institutionnelles
 * --------------------------------------------------------------
 * URL servie : /sitemap-pages.xml (via .htaccess)
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://assokit.fr';
$today    = date('Y-m-d');

// Pages statiques du site Assokit (mêmes URLs que ton sitemap actuel)
$pages = [
    ['/',                          '1.0', 'weekly'],
    ['/plans',                     '0.9', 'monthly'],
    ['/inscription',               '0.9', 'monthly'],
    ['/contact',                   '0.7', 'monthly'],
    ['/login',                     '0.6', 'yearly'],
    ['/fonctionnalites',           '0.8', 'monthly'],
    ['/pour-associations',         '0.8', 'monthly'],
    ['/pour-tpe',                  '0.8', 'monthly'],
    ['/blog',                      '0.9', 'daily'],
    ['/mentions-legales',          '0.3', 'yearly'],
    ['/cgu',                       '0.3', 'yearly'],
    ['/cgv',                       '0.3', 'yearly'],
    ['/politique-confidentialite', '0.3', 'yearly'],
    ['/rgpd',                      '0.3', 'yearly'],
    ['/cookies',                   '0.3', 'yearly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as [$path, $priority, $changefreq]): ?>
    <url>
        <loc><?= htmlspecialchars($site_url . $path, ENT_XML1) ?></loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq><?= $changefreq ?></changefreq>
        <priority><?= $priority ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
