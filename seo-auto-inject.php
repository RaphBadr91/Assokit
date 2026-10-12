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
 * v3 — FIX : plus d'ob_start() dans le gestionnaire de sortie (fatal) ;
 *           les pages qui ont déjà leurs balises OG sont laissées intactes.
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
    '#^/signup#i',
    '#^/inscription#i',
    '#^/verifier-email#i',
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

    // v3 — Les balises sont produites ICI, avant la page : un gestionnaire de
    // sortie ne peut pas lui-même appeler ob_start() (erreur fatale PHP, la page
    // partait vide avec un code 200).
    $ak_seo_capture = static function (callable $render): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    };
    $ak_seo_parts = [
        'meta'         => $ak_seo_capture(static fn() => ak_seo_render(AK_SEO_PAGES[$ak_seo_page_key])),
        'Organization' => $ak_seo_capture('ak_seo_render_organization_jsonld'),
        'WebSite'      => $ak_seo_capture('ak_seo_render_website_jsonld'),
        'SoftwareApplication' => ak_seo_should_inject_software() ? $ak_seo_capture('ak_seo_render_software_jsonld') : '',
        'FAQPage'      => isset(AK_SEO_FAQS[$ak_seo_page_key])
            ? $ak_seo_capture(static fn() => ak_seo_render_faq_jsonld(AK_SEO_FAQS[$ak_seo_page_key])) : '',
    ];

    ob_start(static function($html) use ($ak_seo_parts) {

        // Si pas de <head>, on ne touche pas (probablement pas du HTML, ou redirection)
        if (!preg_match('/<head(?:\s[^>]*)?>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        // La page rend déjà ses propres balises (render_public_head : title,
        // description, OG, canonical, JSON-LD) : rien à ajouter, sinon doublons.
        if (stripos($html, 'property="og:title"') !== false || stripos($html, "property='og:title'") !== false) {
            return $html;
        }

        $seo_block = "\n<!-- 🌿 SEO Auto-Inject Assokit -->\n" . $ak_seo_parts['meta'];

        // JSON-LD (sans doublon, que la page l'écrive compact ou indenté)
        foreach (['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage'] as $type) {
            if ($ak_seo_parts[$type] !== '' && !preg_match('/["\']@type["\']\s*:\s*["\']' . $type . '["\']/i', $html)) {
                $seo_block .= $ak_seo_parts[$type];
            }
        }

        $seo_block .= "<!-- /🌿 SEO Auto-Inject -->\n";

        // Injecte juste après <head> (insertion littérale : pas de motif de remplacement)
        $after_head = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $after_head) . $seo_block . substr($html, $after_head);
    });
}
