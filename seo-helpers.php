<?php
/**
 * ============================================================
 * ASSOKIT — seo-helpers.php
 * ============================================================
 * Helper centralisé pour la génération des balises SEO.
 *
 * Usage dans une page :
 *   require_once __DIR__ . '/seo-helpers.php';
 *   ak_seo_render([
 *       'title'       => 'Logiciel de gestion d\'association loi 1901',
 *       'description' => 'Assokit, le logiciel français...',
 *       'canonical'   => 'https://assokit.fr/',
 *       'og_image'    => 'https://assokit.fr/assets/og-home.jpg',
 *       'page_type'   => 'website', // ou 'article', 'product'
 *       'noindex'     => false,
 *   ]);
 *
 * Toutes les valeurs ont des défauts intelligents (fallback).
 * ============================================================
 */

if (!defined('AK_SEO_BASE_URL')) {
    define('AK_SEO_BASE_URL', 'https://assokit.fr');
}
if (!defined('AK_SEO_BRAND')) {
    define('AK_SEO_BRAND', 'Assokit');
}
if (!defined('AK_SEO_BRAND_FULL')) {
    define('AK_SEO_BRAND_FULL', 'Assokit — Logiciel de gestion d\'association loi 1901 et TPE');
}
if (!defined('AK_SEO_DEFAULT_OG_IMAGE')) {
    define('AK_SEO_DEFAULT_OG_IMAGE', 'https://assokit.fr/assets/og-default.jpg');
}
if (!defined('AK_SEO_TWITTER_HANDLE')) {
    define('AK_SEO_TWITTER_HANDLE', '@assokit_fr');
}

/**
 * Échappe une valeur pour HTML (compat h() existant).
 */
if (!function_exists('ak_seo_h')) {
    function ak_seo_h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * Génère un title SEO optimisé.
 *  - Max 60 caractères (Google coupe au-delà)
 *  - Inclut la marque en suffixe sauf override
 */
function ak_seo_make_title(string $title, bool $append_brand = true): string {
    $title = trim($title);
    if ($append_brand && stripos($title, AK_SEO_BRAND) === false) {
        $full = $title . ' · ' . AK_SEO_BRAND;
        if (mb_strlen($full) <= 60) return $full;
    }
    return mb_substr($title, 0, 60);
}

/**
 * Génère une description optimisée.
 *  - Cible 155-160 caractères
 *  - Tronque proprement à la fin d'un mot
 */
function ak_seo_make_description(string $desc): string {
    $desc = trim(preg_replace('/\s+/', ' ', $desc));
    if (mb_strlen($desc) <= 160) return $desc;
    $cut = mb_substr($desc, 0, 157);
    $last_space = mb_strrpos($cut, ' ');
    if ($last_space !== false) $cut = mb_substr($cut, 0, $last_space);
    return $cut . '...';
}

/**
 * Construit l'URL canonique propre (sans paramètres tracking).
 */
function ak_seo_canonical_url(?string $custom = null): string {
    if ($custom) {
        return strpos($custom, 'http') === 0 ? $custom : AK_SEO_BASE_URL . $custom;
    }
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $path = strtok($path, '?'); // retire query string
    // Retire trailing slash sauf pour racine
    if (strlen($path) > 1) $path = rtrim($path, '/');
    return AK_SEO_BASE_URL . $path;
}

/**
 * Détecte la langue (FR par défaut).
 */
function ak_seo_locale(): string {
    return 'fr_FR';
}

/**
 * Render TOUS les tags SEO d'une page.
 * À appeler dans le <head>, AVANT les <link rel="stylesheet">.
 */
function ak_seo_render(array $opts = []): void {
    // Defaults
    $title = $opts['title'] ?? AK_SEO_BRAND_FULL;
    $description = $opts['description'] ?? 'Assokit, le logiciel français pour gérer votre association loi 1901 ou votre TPE : adhérents, projets, factures, communication, IA. Simple, intuitif, made in France.';
    $canonical = ak_seo_canonical_url($opts['canonical'] ?? null);
    $og_image = $opts['og_image'] ?? AK_SEO_DEFAULT_OG_IMAGE;
    $page_type = $opts['page_type'] ?? 'website'; // website|article|product
    $noindex = !empty($opts['noindex']);
    $keywords = $opts['keywords'] ?? null;
    $author = $opts['author'] ?? 'Assokit';
    $published = $opts['published'] ?? null;
    $modified = $opts['modified'] ?? null;

    $title_full = ak_seo_make_title($title, !empty($opts['append_brand'] ?? true));
    $description_clean = ak_seo_make_description($description);

    echo "\n<!-- ============== SEO META TAGS ============== -->\n";

    // === BASIC META ===
    echo '<title>' . ak_seo_h($title_full) . '</title>' . "\n";
    echo '<meta name="description" content="' . ak_seo_h($description_clean) . '">' . "\n";

    if ($keywords) {
        echo '<meta name="keywords" content="' . ak_seo_h($keywords) . '">' . "\n";
    }

    echo '<meta name="author" content="' . ak_seo_h($author) . '">' . "\n";
    echo '<meta name="language" content="French">' . "\n";
    echo '<meta name="robots" content="' . ($noindex ? 'noindex,nofollow' : 'index,follow,max-image-preview:large,max-snippet:-1') . '">' . "\n";
    echo '<meta name="googlebot" content="' . ($noindex ? 'noindex,nofollow' : 'index,follow') . '">' . "\n";
    echo '<meta name="theme-color" content="#059669">' . "\n";

    // === CANONICAL ===
    echo '<link rel="canonical" href="' . ak_seo_h($canonical) . '">' . "\n";

    // === OPEN GRAPH (Facebook, LinkedIn, WhatsApp) ===
    echo '<meta property="og:type" content="' . ak_seo_h($page_type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . ak_seo_h(AK_SEO_BRAND) . '">' . "\n";
    echo '<meta property="og:title" content="' . ak_seo_h($title_full) . '">' . "\n";
    echo '<meta property="og:description" content="' . ak_seo_h($description_clean) . '">' . "\n";
    echo '<meta property="og:url" content="' . ak_seo_h($canonical) . '">' . "\n";
    echo '<meta property="og:image" content="' . ak_seo_h($og_image) . '">' . "\n";
    echo '<meta property="og:image:width" content="1200">' . "\n";
    echo '<meta property="og:image:height" content="630">' . "\n";
    echo '<meta property="og:image:alt" content="' . ak_seo_h($title) . '">' . "\n";
    echo '<meta property="og:locale" content="' . ak_seo_locale() . '">' . "\n";

    if ($page_type === 'article') {
        if ($published) echo '<meta property="article:published_time" content="' . ak_seo_h($published) . '">' . "\n";
        if ($modified) echo '<meta property="article:modified_time" content="' . ak_seo_h($modified) . '">' . "\n";
        echo '<meta property="article:author" content="' . ak_seo_h($author) . '">' . "\n";
    }

    // === TWITTER CARD ===
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:site" content="' . ak_seo_h(AK_SEO_TWITTER_HANDLE) . '">' . "\n";
    echo '<meta name="twitter:title" content="' . ak_seo_h($title_full) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . ak_seo_h($description_clean) . '">' . "\n";
    echo '<meta name="twitter:image" content="' . ak_seo_h($og_image) . '">' . "\n";
    echo '<meta name="twitter:image:alt" content="' . ak_seo_h($title) . '">' . "\n";

    // === MOBILE / PWA ===
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="' . ak_seo_h(AK_SEO_BRAND) . '">' . "\n";
    echo '<meta name="format-detection" content="telephone=no">' . "\n";

    echo '<!-- ============== /SEO META TAGS ============== -->' . "\n\n";
}

/**
 * Render le JSON-LD Organization (à mettre une seule fois, dans le footer global).
 */
function ak_seo_render_organization_jsonld(): void {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Assokit',
        'legalName' => 'RBPS',
        'url' => AK_SEO_BASE_URL,
        'logo' => AK_SEO_BASE_URL . '/assets/logo.png',
        'description' => 'Logiciel français de gestion d\'associations loi 1901 et TPE.',
        'foundingDate' => '2026',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'Évry',
            'addressRegion' => 'Île-de-France',
            'postalCode' => '91000',
            'addressCountry' => 'FR',
        ],
        'contactPoint' => [
            '@type' => 'ContactPoint',
            'email' => 'contact@assokit.fr',
            'contactType' => 'customer support',
            'availableLanguage' => ['French'],
        ],
        'sameAs' => [
            // Ajoute ici tes profils sociaux quand tu les auras
            // 'https://www.linkedin.com/company/assokit',
            // 'https://twitter.com/assokit_fr',
        ],
    ];
    echo "\n" . '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n" . '</script>' . "\n";
}

/**
 * Render le JSON-LD WebSite avec SearchAction.
 */
function ak_seo_render_website_jsonld(): void {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Assokit',
        'url' => AK_SEO_BASE_URL,
        'inLanguage' => 'fr-FR',
        'description' => 'Logiciel français pour associations loi 1901 et TPE.',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => AK_SEO_BASE_URL . '/recherche?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    echo "\n" . '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n" . '</script>' . "\n";
}

/**
 * Render breadcrumb JSON-LD à partir d'un tableau [['url'=>'...', 'name'=>'...'], ...].
 */
function ak_seo_render_breadcrumb_jsonld(array $items): void {
    if (empty($items)) return;
    $list = [];
    $i = 1;
    foreach ($items as $item) {
        $list[] = [
            '@type' => 'ListItem',
            'position' => $i++,
            'name' => $item['name'],
            'item' => strpos($item['url'], 'http') === 0 ? $item['url'] : AK_SEO_BASE_URL . $item['url'],
        ];
    }
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
    echo "\n" . '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n" . '</script>' . "\n";
}

/**
 * Render FAQ JSON-LD à partir d'un tableau [['q'=>'...', 'a'=>'...'], ...].
 */
function ak_seo_render_faq_jsonld(array $faqs): void {
    if (empty($faqs)) return;
    $items = [];
    foreach ($faqs as $faq) {
        $items[] = [
            '@type' => 'Question',
            'name' => $faq['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['a'],
            ],
        ];
    }
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $items,
    ];
    echo "\n" . '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n" . '</script>' . "\n";
}

/**
 * Render JSON-LD SoftwareApplication (pour la page d'accueil).
 */
function ak_seo_render_software_jsonld(): void {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Assokit',
        'description' => 'Logiciel SaaS français de gestion d\'association loi 1901 et TPE : adhérents, projets, factures, communication, IA intégrée.',
        'url' => AK_SEO_BASE_URL,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'inLanguage' => 'fr',
        'offers' => [
            [
                '@type' => 'Offer',
                'name' => 'Plan Démarrage',
                'price' => '0',
                'priceCurrency' => 'EUR',
                'description' => 'Gratuit à vie pour commencer',
            ],
            [
                '@type' => 'Offer',
                'name' => 'Plan Assokit',
                'price' => '49.99',
                'priceCurrency' => 'EUR',
                'description' => 'Tout inclus + sous-domaine, paiement mensuel',
            ],
        ],
        // aggregateRating retire : pas d'avis reels affiches sur la page.
        // Un AggregateRating fabrique est contraire aux consignes Google
        // (risque d'action manuelle « rich results spammy »). A reintroduire
        // uniquement quand de vrais avis verifiables seront affiches.
    ];
    echo "\n" . '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n" . '</script>' . "\n";
}
