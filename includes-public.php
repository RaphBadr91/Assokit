<?php
/**
 * includes-public.php
 * --------------------------------------------------------------
 * Layout public Assokit — pages marketing/blog non-connectées
 * VERSION CORRIGÉE : logo Assokit + favicon partout
 * --------------------------------------------------------------
 */

if (!defined('AK_PUBLIC_DOMAIN')) define('AK_PUBLIC_DOMAIN', 'https://assokit.fr');

if (!function_exists('pub_h')) {
    function pub_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('pub_url')) {
    function pub_url(string $path = '/'): string {
        $path = '/' . ltrim($path, '/');
        return AK_PUBLIC_DOMAIN . $path;
    }
}

if (!function_exists('render_public_head')) {
    function render_public_head(array $seo): void {
        $title       = trim((string)($seo['title'] ?? 'Assokit'));
        // Si title_full est fourni, on l'utilise tel quel (pour SEO précis homepage)
        // Sinon, on construit avec " · Assokit" en suffixe
        if (!empty($seo['title_full'])) {
            $full_title = (string)$seo['title_full'];
        } else {
            $full_title  = $title === 'Assokit'
                ? 'Assokit · Le logiciel tout-en-un pour piloter votre association et votre TPE sereinement'
                : "{$title} · Assokit";
        }
        $desc        = trim((string)($seo['description'] ?? 'Centralisez vos projets, vos adhérents et votre communication — main dans la main avec l\'IA. Pour les associations loi 1901 et les petites entreprises engagées.'));
        $path        = (string)($seo['path'] ?? '/');
        $canonical   = pub_url($path);
        $og_image    = (string)($seo['og_image'] ?? AK_PUBLIC_DOMAIN . '/assets/og-assokit-home.png');
        $og_type     = (string)($seo['og_type'] ?? 'website');
        $noindex     = !empty($seo['noindex']);

        echo "<!DOCTYPE html>\n<html lang=\"fr-FR\">\n<head>\n";
        echo "  <meta charset=\"UTF-8\">\n";
        echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        echo "  <title>" . pub_h($full_title) . "</title>\n";
        echo "  <meta name=\"description\" content=\"" . pub_h($desc) . "\">\n";
        echo "  <link rel=\"canonical\" href=\"" . pub_h($canonical) . "\">\n";
        echo "  <meta name=\"robots\" content=\"" . ($noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large') . "\">\n";

        // hreflang
        echo "  <link rel=\"alternate\" hreflang=\"fr-FR\" href=\"" . pub_h($canonical) . "\">\n";
        echo "  <link rel=\"alternate\" hreflang=\"x-default\" href=\"" . pub_h($canonical) . "\">\n";

        // Open Graph
        echo "  <meta property=\"og:type\" content=\"" . pub_h($og_type) . "\">\n";
        echo "  <meta property=\"og:site_name\" content=\"Assokit\">\n";
        echo "  <meta property=\"og:locale\" content=\"fr_FR\">\n";
        echo "  <meta property=\"og:title\" content=\"" . pub_h($full_title) . "\">\n";
        echo "  <meta property=\"og:description\" content=\"" . pub_h($desc) . "\">\n";
        echo "  <meta property=\"og:url\" content=\"" . pub_h($canonical) . "\">\n";
        echo "  <meta property=\"og:image\" content=\"" . pub_h($og_image) . "\">\n";
        echo "  <meta property=\"og:image:width\" content=\"1200\">\n";
        echo "  <meta property=\"og:image:height\" content=\"630\">\n";
        echo "  <meta property=\"og:image:alt\" content=\"Assokit — Logiciel tout-en-un pour les associations et TPE\">\n";

        if ($og_type === 'article' && !empty($seo['article_data'])) {
            $ad = $seo['article_data'];
            if (!empty($ad['published_time'])) echo "  <meta property=\"article:published_time\" content=\"" . pub_h($ad['published_time']) . "\">\n";
            if (!empty($ad['modified_time']))  echo "  <meta property=\"article:modified_time\" content=\"" . pub_h($ad['modified_time']) . "\">\n";
            if (!empty($ad['author']))         echo "  <meta property=\"article:author\" content=\"" . pub_h($ad['author']) . "\">\n";
            if (!empty($ad['section']))        echo "  <meta property=\"article:section\" content=\"" . pub_h($ad['section']) . "\">\n";
            if (!empty($ad['tags']) && is_array($ad['tags'])) {
                foreach ($ad['tags'] as $t) echo "  <meta property=\"article:tag\" content=\"" . pub_h($t) . "\">\n";
            }
        }

        // Twitter Card
        echo "  <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        echo "  <meta name=\"twitter:title\" content=\"" . pub_h($full_title) . "\">\n";
        echo "  <meta name=\"twitter:description\" content=\"" . pub_h($desc) . "\">\n";
        echo "  <meta name=\"twitter:image\" content=\"" . pub_h($og_image) . "\">\n";

        // Favicon Assokit (logo carré vert)
        echo "  <link rel=\"icon\" type=\"image/png\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"apple-touch-icon\" href=\"/assets/favicon.png\">\n";
        echo "  <meta name=\"theme-color\" content=\"#059669\">\n";

        // Fonts
        echo "  <link rel=\"preconnect\" href=\"https://fonts.bunny.net\" crossorigin>\n";
        echo "  <link rel=\"stylesheet\" href=\"https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap\">\n";

        // CSS public
        echo "  <link rel=\"stylesheet\" href=\"/assets/public.css?v=" . @filemtime(__DIR__ . "/assets/public.css") . "\">\n";
        
        // JSON-LD : Organization
        $org_jsonld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => 'Assokit',
            'url'      => AK_PUBLIC_DOMAIN,
            'logo'     => AK_PUBLIC_DOMAIN . '/assets/logo-assokit.png',
            'description' => 'Logiciel tout-en-un pour les associations loi 1901 et les TPE',
            'foundingDate' => '2026',
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => 'contact@assokit.fr',
                'areaServed' => 'FR',
                'availableLanguage' => 'fr',
            ],
        ];
        echo "  <script type=\"application/ld+json\">" . json_encode($org_jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";

        // JSON-LD : WebSite
        $site_jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Assokit',
            'url' => AK_PUBLIC_DOMAIN,
            'inLanguage' => 'fr-FR',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => AK_PUBLIC_DOMAIN . '/blog?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
        echo "  <script type=\"application/ld+json\">" . json_encode($site_jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";

        if (!empty($seo['schema_jsonld']) && is_array($seo['schema_jsonld'])) {
            foreach ($seo['schema_jsonld'] as $jl) {
                echo "  <script type=\"application/ld+json\">" . json_encode($jl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
            }
        }

        ?>
  <!-- Consentement cookies + GA4 (chargé uniquement après acceptation) -->
  <script>
  (function(){
    var GA_ID='G-JE42SH8KH9', CK='ak_cookie_consent';
    window.dataLayer=window.dataLayer||[];
    function gtag(){dataLayer.push(arguments);}
    function getCk(n){var m=document.cookie.match('(^|;)\\s*'+n+'\\s*=\\s*([^;]+)');return m?m.pop():'';}
    function setCk(n,v){var d=new Date();d.setTime(d.getTime()+182*864e5);document.cookie=n+'='+v+';expires='+d.toUTCString()+';path=/;SameSite=Lax';}
    function loadGA(){var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+GA_ID;document.head.appendChild(s);gtag('js',new Date());gtag('config',GA_ID,{'anonymize_ip':true});}
    function hideBanner(){var b=document.getElementById('ak-cookie-banner');if(b)b.remove();}
    window.akAcceptCookies=function(){setCk(CK,'granted');hideBanner();loadGA();};
    window.akRefuseCookies=function(){setCk(CK,'denied');hideBanner();};
    function showBanner(){
      var html='<div id="ak-cookie-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#0F172A;color:#fff;padding:18px 20px;font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;box-shadow:0 -4px 20px rgba(0,0,0,.25);">'
        +'<div style="max-width:1000px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:14px;justify-content:space-between;">'
        +'<div style="flex:1;min-width:260px;">\ud83c\udf6a Nous utilisons des cookies de mesure d\'audience (Google Analytics) pour am\u00e9liorer le site. <a href="/cookies" style="color:#6EE7B7;text-decoration:underline;">En savoir plus</a></div>'
        +'<div style="display:flex;gap:10px;flex-shrink:0;">'
        +'<button onclick="akRefuseCookies()" style="background:transparent;color:#fff;border:1px solid #475569;border-radius:8px;padding:10px 18px;cursor:pointer;font-size:14px;font-weight:600;">Refuser</button>'
        +'<button onclick="akAcceptCookies()" style="background:#059669;color:#fff;border:none;border-radius:8px;padding:10px 18px;cursor:pointer;font-size:14px;font-weight:600;">Accepter</button>'
        +'</div></div></div>';
      document.body.insertAdjacentHTML('beforeend',html);
    }
    var c=getCk(CK);
    if(c==='granted'){loadGA();}
    else if(c!=='denied'){if(document.readyState!=='loading')showBanner();else document.addEventListener('DOMContentLoaded',showBanner);}
  })();
  </script>
  <!-- /Consentement -->
</head>
<body>
<?php
    }
}

if (!function_exists('render_public_nav')) {
    function render_public_nav(string $active = ''): void {
        $is_logged = !empty($_SESSION['user_id'] ?? null);
        ?>
        <header class="pub-nav">
          <div class="pub-nav-inner">
            <a href="/" class="pub-logo" aria-label="Accueil Assokit">
              <img src="/assets/logo-assokit.png" alt="Assokit" class="pub-logo-img">
              <span class="pub-logo-text">Assokit</span>
            </a>
            <nav class="pub-nav-links" aria-label="Navigation principale">
              <a href="/fonctionnalites" class="<?= $active === 'fonctionnalites' ? 'active' : '' ?>">Fonctionnalités</a>
              <a href="/pour-organismes" class="<?= $active === 'organismes' ? 'active' : '' ?>">Collectivités</a>
              <a href="/tarifs" class="<?= $active === 'tarifs' ? 'active' : '' ?>">Tarifs</a>
              <a href="/blog" class="<?= $active === 'blog' ? 'active' : '' ?>">Blog</a>
              <a href="/a-propos" class="<?= $active === 'a-propos' ? 'active' : '' ?>">À propos</a>
              <a href="/contact" class="<?= $active === 'contact' ? 'active' : '' ?>">Contact</a>
            </nav>
            <div class="pub-nav-cta">
              <?php if ($is_logged): ?>
                <a href="/dashboard" class="pub-btn pub-btn-primary">Mon tableau de bord</a>
              <?php else: ?>
                <a href="/connexion" class="pub-btn pub-btn-ghost">Connexion</a>
                <a href="/contact" class="pub-btn pub-btn-primary">Réserver une démo</a>
              <?php endif; ?>
            </div>
            <button class="pub-nav-burger" id="pubBurger" aria-label="Menu" aria-expanded="false">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
          </div>
          <div class="pub-nav-mobile" id="pubMobileMenu">
            <a href="/fonctionnalites">Fonctionnalités</a>
            <a href="/pour-organismes">Collectivités</a>
            <a href="/tarifs">Tarifs</a>
            <a href="/blog">Blog</a>
            <a href="/a-propos">À propos</a>
            <a href="/contact">Contact</a>
            <hr>
            <?php if ($is_logged): ?>
              <a href="/dashboard">Mon tableau de bord</a>
            <?php else: ?>
              <a href="/connexion">Connexion</a>
              <a href="/contact" class="cta">Réserver une démo</a>
            <?php endif; ?>
          </div>
        </header>
        <script>
          (function(){
            const b = document.getElementById('pubBurger'), m = document.getElementById('pubMobileMenu');
            if (b && m) b.addEventListener('click', () => { m.classList.toggle('open'); b.setAttribute('aria-expanded', m.classList.contains('open')); });
          })();
        </script>
        <?php
    }
}

if (!function_exists('render_public_footer')) {
    function render_public_footer(): void {
        $year = date('Y');
        ?>
        <footer class="pub-footer">
          <div class="pub-footer-inner">
            <div class="pub-footer-grid">
              <div class="pub-footer-brand">
                <div class="pub-logo">
                  <img src="/assets/logo-assokit.png" alt="Assokit" class="pub-logo-img">
                  <span class="pub-logo-text">Assokit</span>
                </div>
                <p>L'allié quotidien des associations loi 1901 et des petites entreprises engagées.</p>
                <p class="pub-footer-by">Édité par <strong style="color:rgba(255,255,255,0.85);">RBPS</strong></p>
              </div>

              <div class="pub-footer-col">
                <h4>Produit</h4>
                <a href="/fonctionnalites">Fonctionnalités</a>
                <a href="/pour-organismes">Collectivités</a>
                <a href="/tarifs">Tarifs</a>
                <a href="/contact">Réserver une démo</a>
                <a href="/connexion">Connexion</a>
              </div>

              <div class="pub-footer-col">
                <h4>Ressources</h4>
                <a href="/blog">Blog</a>
                <a href="/blog?categorie=associations">Pour les associations</a>
                <a href="/blog?categorie=tpe">Pour les TPE</a>
                <a href="/blog?categorie=comptabilite">Comptabilité</a>
              </div>

              <div class="pub-footer-col">
                <h4>Entreprise</h4>
                <a href="/a-propos">À propos</a>
                <a href="/contact">Contact</a>
                <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>
              </div>

              <div class="pub-footer-col">
                <h4>Légal</h4>
                <a href="/mentions-legales">Mentions légales</a>
                <a href="/cgu">CGU / CGV</a>
                <a href="/confidentialite">Confidentialité</a>
                <a href="/cookies">Cookies</a>
              </div>
            </div>

            <div class="pub-footer-bottom">
              <p>© <?= $year ?> Assokit · Édité par RBPS · Pensé en France 🇫🇷</p>
              <p class="pub-footer-tagline">🌿 Hébergé en France · Conforme RGPD · Vos données restent les vôtres.</p>
            </div>
          </div>
        </footer>
        <?php
    }
}

if (!function_exists('render_public_foot')) {
    function render_public_foot(): void {
        echo "</body>\n</html>\n";
    }
}

if (!function_exists('build_breadcrumb_jsonld')) {
    function build_breadcrumb_jsonld(array $items): array {
        $list = [];
        foreach ($items as $i => $item) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'item' => isset($item['url']) ? pub_url($item['url']) : null,
            ];
        }
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn($i) => array_filter($i), $list),
        ];
    }
}
