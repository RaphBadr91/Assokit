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

// Pages statiques du site Assokit — uniquement des URLs qui repondent en 200.
// (Les anciennes URLs /plans, /inscription, /login, /pour-associations,
//  /pour-tpe, /cgv n'existaient pas : 404 -> retirees. Elles sont
//  redirigees en 301 vers les vraies pages via .htaccess.)
$pages = [
    ['/',                          '1.0', 'weekly'],
    ['/tarifs',                    '0.9', 'monthly'],
    ['/fonctionnalites',           '0.8', 'monthly'],
    ['/logiciel-association',      '0.9', 'monthly'],
    ['/logiciel-comptabilite-association', '0.8', 'monthly'],
    ['/logiciel-cotisation-association', '0.8', 'monthly'],
    ['/logiciel-adherents',        '0.8', 'monthly'],
    ['/logiciel-facturation',      '0.9', 'monthly'],
    ['/logiciel-gestion-tpe',      '0.9', 'monthly'],
    ['/pour-associations',         '0.8', 'monthly'],
    ['/pour-tpe',                  '0.8', 'monthly'],
    ['/comptabilite-analytique',   '0.8', 'monthly'],
    ['/pour-organismes',           '0.7', 'monthly'],
    ['/a-propos',                  '0.6', 'monthly'],
    ['/avis',                      '0.7', 'monthly'],
    ['/application',               '0.6', 'monthly'],
    ['/contact',                   '0.7', 'monthly'],
    ['/blog',                      '0.9', 'daily'],
    ['/guide-association-loi-1901',           '0.8', 'monthly'],
    ['/guide-comptabilite-association',       '0.8', 'monthly'],
    ['/logiciel-association-gratuit',         '0.7', 'monthly'],
    ['/alternative-helloasso',                '0.6', 'monthly'],
    ['/modeles-gratuits-association',         '0.7', 'monthly'],
    ['/modele-statuts-association',           '0.7', 'monthly'],
    ['/modele-proces-verbal-assemblee-generale', '0.6', 'monthly'],
    ['/modele-convocation-assemblee-generale','0.6', 'monthly'],
    ['/modele-recu-fiscal-association',       '0.6', 'monthly'],
    ['/mentions-legales',          '0.3', 'yearly'],
    ['/cgu',                       '0.3', 'yearly'],
    ['/confidentialite',           '0.3', 'yearly'],
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
