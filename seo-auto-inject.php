<?php
/**
 * ============================================================
 * ASSOKIT — seo-auto-inject.php (v2 — bug fix redirections)
 * ============================================================
 * Système d'AUTO-INJECTION du SEO dans les pages publiques.
 *
 * ⚡ MAGIE : pas besoin de modifier chaque page !
 *
 * v2 — FIX : ne démarre PAS l'output buffering pour les pages
 *           avec session active (qui peuvent faire des header(Location)).
 *           → Évite la page blanche sur /connexion, /dashboard, etc.
 *
 * Usage :
 *   Dans config.php :
 *     if (file_exists(__DIR__ . '/seo-auto-inject.php')) {
 *         require_once __DIR__ . '/seo-auto-inject.php';
 *     }
 * ============================================================
 */

// Sécurité : ne s'exécute que si le helper SEO existe
if (!file_exists(__DIR__ . '/seo-helpers.php') || !file_exists(__DIR__ . '/seo-config.php')) {
    return;
}

// ============================================================
// SKIP IMMÉDIAT pour pages connectées / redirections
// ============================================================
// Ces pages utilisent header('Location: ...') qui est cassé par ob_start.
// On détecte AVANT tout chargement.
$_seo_uri = $_SERVER['REQUEST_URI'] ?? '/';
$_seo_path = strtok($_seo_uri, '?');
$_seo_path = rtrim($_seo_path, '/');
if ($_seo_path === '') $_seo_path = '/';

$_seo_skip_patterns = [
    '#^/contact#i',
    '#^/blog#i',
    '#^/dashboard#i',
    '#^/connexion#i',
    '#^/login#i',
    '#^/logout#i',
    '#^/mon-asso-#i',
    '#^/admin-#i',
    '#^/super-admin#i',
    '#^/fondateur-#i',
    '#^/projet#i',
    '#^/projets#i',
    '#^/nouveau-projet#i',
    '#^/modifier-projet#i',
    '#^/supprimer-projet#i',
    '#^/action-projet#i',
    '#^/nouveau-dossier#i',
    '#^/supprimer-dossier#i',
    '#^/archives#i',
    '#^/adherent#i',
    '#^/canal#i',
    '#^/evenement#i',
    '#^/api/#i',
    '#^/webhook#i',
    '#^/cron#i',
    '#^/stripe-#i',
    '#^/google-#i',
    '#^/changer-mot-de-passe#i',
    '#^/mot-de-passe-oublie#i',
    '#^/definir-mot-de-passe#i',
    '#^/sitemap#i',
    '#^/robots#i',
];
foreach ($_seo_skip_patterns as $_seo_pattern) {
    if (preg_match($_seo_pattern, $_seo_path)) {
        return; // ← SORTIE IMMÉDIATE, aucun ob_start, aucun risque
    }
}

// SKIP : POST handlers, AJAX
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return;

// SKIP : assets (mais pas /index.php ou /index.html)
if (preg_match('#\.(css|js|jpg|jpeg|png|gif|webp|svg|ico|xml|txt|json|woff|woff2|ttf|otf|pdf|zip)$#i', $_seo_path)) {
    return;
}

require_once __DIR__ . '/seo-helpers.php';
require_once __DIR__ . '/seo-config.php';

/**
 * Détecte la clé de page SEO selon l'URL courante.
 */
function ak_seo_detect_current_page(): ?string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = strtok($uri, '?');
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';

    // MATCHING : clé SEO selon URL
    $map = [
        '/'                          => 'accueil',
        '/index.php'                 => 'accueil',
        '/index.html'                => 'accueil',
        '/plans'                     => 'plans',
        '/tarifs'                    => 'plans',
        '/inscription'               => 'inscription',
        '/signup'                    => 'inscription',
        '/contact'                   => 'contact',
        '/fonctionnalites'           => 'fonctionnalites',
        '/pour-associations'         => 'pour-associations',
        '/pour-tpe'                  => 'pour-tpe',
        '/a-propos'                  => 'fonctionnalites',
        '/mentions-legales'          => 'mentions-legales',
        '/cgu'                       => 'cgu',
        '/cgv'                       => 'cgv',
        '/politique-confidentialite' => 'politique-confidentialite',
        '/rgpd'                      => 'rgpd',
        '/cookies'                   => 'cookies',
    ];

    return $map[$path] ?? null;
}

/**
 * Détermine si on doit injecter aussi le JSON-LD SoftwareApplication.
 */
function ak_seo_should_inject_software(): bool {
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $path = rtrim($path, '/') ?: '/';
    return in_array($path, ['/', '/index.php', '/index.html'], true);
}

// ============================================================
// CAPTURE DU HTML (uniquement pour pages publiques sûres)
// ============================================================

$ak_seo_page_key = ak_seo_detect_current_page();

if ($ak_seo_page_key !== null && isset(AK_SEO_PAGES[$ak_seo_page_key])) {

    ob_start(function($html) use ($ak_seo_page_key) {

        // Si pas de <head>, on ne touche pas (probablement pas du HTML, ou redirection)
        if (stripos($html, '<head>') === false && stripos($html, '<head ') === false) {
            return $html;
        }

        // Détection : page a déjà des balises OG complètes
        $already_has_og = (stripos($html, 'property="og:title"') !== false || stripos($html, "property='og:title'") !== false);

        // Génère les balises SEO en buffer interne
        ob_start();

        echo "\n<!-- 🌿 SEO Auto-Inject Assokit -->\n";

        if (!$already_has_og) {
            ak_seo_render(AK_SEO_PAGES[$ak_seo_page_key]);
        } else {
            $canonical_url = ak_seo_canonical_url(AK_SEO_PAGES[$ak_seo_page_key]['canonical'] ?? null);
            if (stripos($html, 'rel="canonical"') === false && stripos($html, "rel='canonical'") === false) {
                echo '<link rel="canonical" href="' . htmlspecialchars($canonical_url) . '">' . "\n";
            }
        }

        // JSON-LD (sans doublon)
        if (stripos($html, '"@type":"Organization"') === false && stripos($html, "'@type':'Organization'") === false) {
            ak_seo_render_organization_jsonld();
        }
        if (stripos($html, '"@type":"WebSite"') === false) {
            ak_seo_render_website_jsonld();
        }
        if (ak_seo_should_inject_software() && stripos($html, '"@type":"SoftwareApplication"') === false) {
            ak_seo_render_software_jsonld();
        }
        if (isset(AK_SEO_FAQS[$ak_seo_page_key]) && stripos($html, '"@type":"FAQPage"') === false) {
            ak_seo_render_faq_jsonld(AK_SEO_FAQS[$ak_seo_page_key]);
        }

        echo "<!-- /🌿 SEO Auto-Inject -->\n";

        $seo_block = ob_get_clean();

        // Injecte juste après <head>
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . $seo_block, $html, 1);

        return $html;
    });
}
