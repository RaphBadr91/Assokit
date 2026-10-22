<?php
/**
 * includes-public.php
 * --------------------------------------------------------------
 * Layout public Assokit — pages marketing/blog non-connectées
 * VERSION CORRIGÉE : logo Assokit + favicon partout
 * --------------------------------------------------------------
 */

if (!defined('AK_PUBLIC_DOMAIN')) define('AK_PUBLIC_DOMAIN', 'https://assokit.fr');

// Même en-tête sur toutes les pages publiques pour un client connecté (« Mon tableau
// de bord ») : la plupart ne chargent pas config.php, la session n'y était donc pas
// ouverte. Si le navigateur présente un cookie de session, on charge config.php, qui
// ouvre la session avec les réglages de l'application (nom, cookie, stockage).
// Ce fichier est inclus en tête de page, au niveau global : $pdo reste global et
// rien n'a encore été envoyé au navigateur.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()
    && !empty($_COOKIE[session_name()]) && is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!function_exists('pub_h')) {
    function pub_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('pub_url')) {
    function pub_url(string $path = '/'): string {
        $path = '/' . ltrim($path, '/');
        return AK_PUBLIC_DOMAIN . $path;
    }
}

if (!function_exists('pub_legal_identity')) {
    /**
     * Identité légale de l'éditeur (mentions légales, confidentialité, contact).
     * Lue dans les paramètres société (super-admin › Paramètres société, table
     * company_settings, voir company-helpers.php) quand la page a chargé config.php ;
     * un champ vide garde la valeur affichée jusqu'ici, ou '' si aucune.
     * Texte brut, à échapper à l'affichage.
     */
    function pub_legal_identity(): array {
        static $id = null;
        if ($id !== null) return $id;
        $c = [];
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && is_file(__DIR__ . '/company-helpers.php')) {
            require_once __DIR__ . '/company-helpers.php';
            $c = ak_company() ?: [];
        }
        $get = static fn(string $k): string => trim((string) ($c[$k] ?? ''));
        $address = $c ? str_replace("\n", ', ', ak_company_address_multiline()) : '';
        if ($address !== '' && in_array($get('address_country'), ['', 'FR'], true)) $address .= ', France';
        $capital = (int) ($c['capital_cents'] ?? 0);
        return $id = [
            'name'     => $get('legal_name') ?: 'RBPS',
            'form'     => $get('legal_form'),
            'capital'  => $capital > 0 ? number_format($capital / 100, 0, ',', "\u{00A0}") . "\u{00A0}€" : '',
            'address'  => $address,
            'city'     => $get('address_city'),
            'siren'    => $get('siren'),
            'rcs'      => ($get('rcs_city') !== '' && $get('rcs_number') !== '') ? 'RCS ' . $get('rcs_city') . ' ' . $get('rcs_number') : '',
            'vat'      => (int) ($c['vat_subject'] ?? 0) === 1 ? $get('vat_number') : '',
            'phone'    => $get('phone') ?: '+33 7 56 89 73 36',
            'email'    => $get('email_legal') ?: 'contact@assokit.fr',
            'director' => trim($get('legal_rep_first_name') . ' ' . $get('legal_rep_last_name')),
            'director_role' => $get('legal_rep_role'),
        ];
    }
}

if (!function_exists('pub_customer_reviews')) {
    /**
     * Avis clients réels, tels que fournis par les clients (page /avis et accueil).
     * Ne jamais y ajouter de témoignage inventé ou reformulé.
     */
    function pub_customer_reviews(): array {
        return [
            [
                'name'   => 'Ismael',
                'org'    => 'Association Ethnic City',
                'initial'=> 'I',
                'color'  => '#7C3AED',
                'rating' => 5,
                'quote'  => "Très content de pouvoir compter sur Assokit pour toute la communication en interne à l'association, pour tous nos événements de danse et surtout pour mieux programmer notre spectacle annuel.",
                'tag'    => 'Communication & événements',
            ],
            [
                'name'   => 'Nabil',
                'org'    => 'Association BS Outing',
                'initial'=> 'N',
                'color'  => '#059669',
                'rating' => 5,
                'quote'  => "Très pratique pour la comptabilité sur nos projets et le bilan annuel avec la compta analytique. Je n'ai plus besoin de payer 3 000 € de comptable, juste de l'expert pour valider. Ça change tout !",
                'tag'    => 'Comptabilité analytique',
            ],
            [
                'name'   => 'Mario',
                'org'    => 'Association Latitude91',
                'initial'=> 'M',
                'color'  => '#1D4ED8',
                'rating' => 5,
                'quote'  => "On utilise l'outil surtout pour nos projets, avec la possibilité de tout voir sur l'avancée et l'évolution de chaque projet. En plus, on peut communiquer spécifiquement avec l'équipe concernée par un projet, et pas tout le monde : c'est un réel plus.",
                'tag'    => 'Suivi de projets',
            ],
            [
                'name'   => 'Bassim',
                'org'    => 'RB Group (TPE)',
                'initial'=> 'B',
                'color'  => '#EA580C',
                'rating' => 5,
                'quote'  => "En tant qu'entrepreneur, je note tous mes rendez-vous directement dans l'outil : c'est plus simple et plus visible. Je peux aussi créer un projet par chantier client, y ajouter des collègues et suivre l'avancement. On insère nos factures sur le projet et, avec la comptabilité à la fin, c'est vraiment top.",
                'tag'    => 'Projets & facturation',
            ],
        ];
    }
}

if (!defined('AK_PUBLIC_APP_PAGES')) {
    /**
     * Pages publiques qui s'affichent dans l'application mobile (WebView, agent « AssokitApp ») :
     * textes légaux, suppression de compte, retrait d'une demande de création d'espace (écran de
     * connexion de l'app iPhone, « Retirer ma demande »), contact. Aucune ne montre de prix.
     * Les autres (accueil, fonctionnalités, pages « logiciel-… », guides, comparatifs, blog…)
     * annoncent le prix de la formule Assokit (ak_offer_price) : dans l'application, elles
     * renvoient vers /connexion. Règles de paiement de Google Play et règle 3.1.1 d'Apple :
     * aucun prix ni parcours d'achat dans l'application. /signup, /connexion et
     * /mot-de-passe-oublie ont leur propre en-tête (sans prix dans l'application).
     */
    define('AK_PUBLIC_APP_PAGES', [
        'confidentialite.php', 'cgu.php', 'cookies.php', 'mentions-legales.php',
        'suppression-compte.php', 'retirer-demande.php', 'contact.php',
    ]);
}

if (!function_exists('pub_in_app')) {
    /** La page est-elle affichée dans l'application mobile (WebView, agent « AssokitApp ») ? */
    function pub_in_app(): bool {
        return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'AssokitApp') !== false;
    }
}

if (!function_exists('render_public_head')) {
    function render_public_head(array $seo): void {
        // Dans l'application, seules les pages AK_PUBLIC_APP_PAGES s'affichent (voir plus haut).
        // Fait avant tout affichage : la page n'a encore rien envoyé.
        if (pub_in_app() && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), AK_PUBLIC_APP_PAGES, true)) {
            if (!headers_sent()) {
                header('Cache-Control: no-store');
                header('Location: /connexion', true, 302);
            } else {
                echo '<!DOCTYPE html><meta http-equiv="refresh" content="0;url=/connexion"><a href="/connexion">Continuer</a>';
            }
            exit;
        }

        $title       = trim((string)($seo['title'] ?? 'Assokit'));
        // Si title_full est fourni, on l'utilise tel quel (pour SEO précis homepage)
        // Sinon, on construit avec " · Assokit" en suffixe
        $AK_TITLE_MAX = 60; // Google coupe ~600px ≈ 60 caractères
        if (!empty($seo['title_full'])) {
            $full_title = (string)$seo['title_full'];
        } elseif ($title === 'Assokit') {
            $full_title = 'Assokit · Logiciel de gestion association et TPE';
        } elseif (stripos($title, 'assokit') !== false) {
            // La marque est déjà présente dans le titre : ne pas la dupliquer.
            $full_title = $title;
        } elseif (mb_strlen($title . ' · Assokit') <= $AK_TITLE_MAX) {
            // La marque tient dans la limite : on l'ajoute.
            $full_title = "{$title} · Assokit";
        } else {
            // Titre déjà long (cas des articles de blog) : pas de suffixe de marque.
            $full_title = $title;
        }
        // Garde-fou : ne jamais dépasser la limite (troncature au dernier mot entier).
        if (mb_strlen($full_title) > $AK_TITLE_MAX) {
            $cut = mb_substr($full_title, 0, $AK_TITLE_MAX - 1);
            $sp  = mb_strrpos($cut, ' ');
            $full_title = ($sp !== false ? mb_substr($cut, 0, $sp) : $cut) . '…';
        }

        // Description : normalisée (espaces/retours markdown), fallback utile, tronquée à 160.
        $desc = trim(preg_replace('/\s+/', ' ', (string)($seo['description'] ?? '')));
        if ($desc === '') {
            $desc = 'Centralisez adhérents, cotisations, projets et communication avec Assokit, le logiciel tout-en-un pour les associations loi 1901 et les TPE.';
        }
        if (mb_strlen($desc) > 160) {
            $cut = mb_substr($desc, 0, 157);
            $sp  = mb_strrpos($cut, ' ');
            $desc = ($sp !== false ? mb_substr($cut, 0, $sp) : $cut) . '…';
        }
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
        echo "  <meta name=\"twitter:site\" content=\"@assokit_fr\">\n";
        echo "  <meta name=\"twitter:title\" content=\"" . pub_h($full_title) . "\">\n";
        echo "  <meta name=\"twitter:description\" content=\"" . pub_h($desc) . "\">\n";
        echo "  <meta name=\"twitter:image\" content=\"" . pub_h($og_image) . "\">\n";

        // Favicon Assokit (logo carré vert)
        echo "  <link rel=\"icon\" type=\"image/png\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"/assets/favicon.png\">\n";
        echo "  <link rel=\"apple-touch-icon\" href=\"/assets/favicon.png\">\n";
        echo "  <meta name=\"theme-color\" content=\"#059669\">\n";

        // Fonts — chargées en NON-BLOQUANT (le texte s'affiche via display=swap, la font se substitue ensuite)
        echo "  <link rel=\"preconnect\" href=\"https://fonts.bunny.net\" crossorigin>\n";
        echo "  <link rel=\"stylesheet\" href=\"https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap\" media=\"print\" onload=\"this.media='all'\">\n";
        echo "  <noscript><link rel=\"stylesheet\" href=\"https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap\"></noscript>\n";

        // CSS public
        echo "  <link rel=\"stylesheet\" href=\"/assets/public.css?v=" . @filemtime(__DIR__ . "/assets/public.css") . "\">\n";
        
        // JSON-LD : Organization
        $org_jsonld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => AK_PUBLIC_DOMAIN . '/#organization',
            'name'     => 'Assokit',
            'url'      => AK_PUBLIC_DOMAIN,
            'logo'     => [
                '@type' => 'ImageObject',
                'url'   => AK_PUBLIC_DOMAIN . '/assets/logo-assokit.png',
                'width' => 512, 'height' => 512,
            ],
            'description' => 'Logiciel tout-en-un pour les associations loi 1901 et les TPE',
            'foundingDate' => '2026',
            // 'sameAs' : à renseigner avec les profils RÉELS (LinkedIn, Facebook, X…) dès leur création.
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
            '@id' => AK_PUBLIC_DOMAIN . '/#website',
            'name' => 'Assokit',
            'url' => AK_PUBLIC_DOMAIN,
            'inLanguage' => 'fr-FR',
            'publisher' => ['@id' => AK_PUBLIC_DOMAIN . '/#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => AK_PUBLIC_DOMAIN . '/blog?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        echo "  <script type=\"application/ld+json\">" . json_encode($site_jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";

        if (!empty($seo['schema_jsonld']) && is_array($seo['schema_jsonld'])) {
            foreach ($seo['schema_jsonld'] as $jl) {
                echo "  <script type=\"application/ld+json\">" . json_encode($jl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
            }
        }

        // Page publique ouverte dans l'application mobile (WebView, agent « AssokitApp », voir
        // mobile/App.js) : ni bandeau cookies ni Google Analytics. La politique cookies annonce
        // une mesure d'audience « jamais dans l'application », et la fiche Google Play
        // (Sécurité des données) ne déclare aucun outil d'analyse dans l'application.
        if (pub_in_app()) {
            echo "</head>\n<body>\n";
            return;
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
    // Cookies Google Analytics (_ga, _ga_<id>), posés sur le domaine le plus large possible : effacés au retrait du consentement
    function clearGA(){
      var past=';expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/', h=location.hostname.split('.');
      document.cookie.split(';').forEach(function(c){
        var n=c.split('=')[0].trim();
        if(n.indexOf('_ga')!==0)return;
        document.cookie=n+'='+past;
        for(var i=0;i<h.length-1;i++)document.cookie=n+'='+past+';domain=.'+h.slice(i).join('.');
      });
    }
    var gaLoaded=false;
    // Durée des cookies GA limitée à 13 mois (recommandation CNIL ; 2 ans par défaut)
    function loadGA(){window['ga-disable-'+GA_ID]=false;if(gaLoaded)return;gaLoaded=true;var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+GA_ID;document.head.appendChild(s);gtag('js',new Date());gtag('config',GA_ID,{'anonymize_ip':true,'cookie_expires':34128000});}
    function hideBanner(){var b=document.getElementById('ak-cookie-banner');if(b)b.remove();}
    window.akAcceptCookies=function(){setCk(CK,'granted');hideBanner();loadGA();};
    window.akRefuseCookies=function(){setCk(CK,'denied');hideBanner();window['ga-disable-'+GA_ID]=true;clearGA();};
    // Retirer ou donner son consentement à tout moment (lien « Gérer mes cookies » du pied de page et de /cookies)
    window.akShowCookieBanner=function(){hideBanner();showBanner();};
    function showBanner(){
      var html='<div id="ak-cookie-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#0F172A;color:#fff;padding:18px 20px;font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;box-shadow:0 -4px 20px rgba(0,0,0,.25);">'
        +'<div style="max-width:1000px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:14px;justify-content:space-between;">'
        +'<div style="flex:1;min-width:260px;">\ud83c\udf6a Nous utilisons des cookies de mesure d\'audience (Google Analytics) pour am\u00e9liorer le site. Vous pourrez changer d\u2019avis \u00e0 tout moment via \u00ab\u00a0G\u00e9rer mes cookies\u00a0\u00bb en bas de page. <a href="/cookies" style="color:#6EE7B7;text-decoration:underline;">En savoir plus</a></div>'
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
        if (pub_in_app()) {
            // Application mobile : ni menu ni lien vers les pages de présentation (voir AK_PUBLIC_APP_PAGES)
            ?>
        <header class="pub-nav">
          <div class="pub-nav-inner">
            <a href="<?= $is_logged ? '/dashboard' : '/connexion' ?>" class="pub-logo" aria-label="Assokit">
              <img src="/assets/logo-assokit.png" alt="Assokit" class="pub-logo-img">
              <span class="pub-logo-text">Assokit</span>
            </a>
          </div>
        </header>
            <?php
            return;
        }
        ?>
        <header class="pub-nav">
          <div class="pub-nav-inner">
            <a href="/" class="pub-logo" aria-label="Accueil Assokit">
              <img src="/assets/logo-assokit.png" alt="Assokit" class="pub-logo-img">
              <span class="pub-logo-text">Assokit</span>
            </a>
            <style>
              .pub-nav-sol{position:relative}
              .pub-nav-sol-t{display:inline-flex;align-items:center;gap:4px;cursor:pointer}
              .pub-nav-sol-menu{position:absolute;top:100%;left:0;min-width:260px;background:#fff;border:1px solid var(--c-border,#E2E8F0);border-radius:12px;box-shadow:0 22px 44px -14px rgba(2,44,34,.20);padding:8px;display:none;flex-direction:column;gap:2px;z-index:1000}
              .pub-nav-sol:hover .pub-nav-sol-menu,.pub-nav-sol:focus-within .pub-nav-sol-menu{display:flex}
              .pub-nav-sol-menu a{padding:9px 12px;border-radius:8px;font-size:14px;color:var(--c-text-2,#475569);white-space:nowrap;text-decoration:none}
              .pub-nav-sol-menu a:hover{background:var(--c-creme-2,#F5F2EC);color:var(--c-emeraude-dark,#047857)}
            </style>
            <nav class="pub-nav-links" aria-label="Navigation principale">
              <span class="pub-nav-sol">
                <a href="/fonctionnalites" class="pub-nav-sol-t" aria-haspopup="true">Solutions
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></a>
                <span class="pub-nav-sol-menu" role="menu">
                  <a href="/logiciel-association" role="menuitem">Logiciel de gestion d'association</a>
                  <a href="/logiciel-adherents" role="menuitem">Gestion des adhérents</a>
                  <a href="/logiciel-cotisation-association" role="menuitem">Gestion des cotisations</a>
                  <a href="/logiciel-comptabilite-association" role="menuitem">Comptabilité d'association</a>
                  <a href="/logiciel-facturation" role="menuitem">Facturation &amp; devis</a>
                  <a href="/logiciel-gestion-tpe" role="menuitem">Gestion TPE &amp; indépendants</a>
                </span>
              </span>
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
                <a href="/signup" class="pub-btn pub-btn-primary">Essai gratuit</a>
              <?php endif; ?>
            </div>
            <button class="pub-nav-burger" id="pubBurger" aria-label="Menu" aria-expanded="false">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
          </div>
          <div class="pub-nav-mobile" id="pubMobileMenu">
            <a href="/fonctionnalites">Fonctionnalités</a>
            <a href="/logiciel-association">· Logiciel de gestion d'association</a>
            <a href="/logiciel-adherents">· Gestion des adhérents</a>
            <a href="/logiciel-cotisation-association">· Gestion des cotisations</a>
            <a href="/logiciel-comptabilite-association">· Comptabilité d'association</a>
            <a href="/logiciel-facturation">· Facturation &amp; devis</a>
            <a href="/logiciel-gestion-tpe">· Gestion TPE &amp; indépendants</a>
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
              <a href="/contact">Réserver une démo</a>
              <a href="/signup" class="cta">Essai gratuit</a>
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
        if (pub_in_app()) {
            // Application mobile : liens légaux et contact seulement (voir AK_PUBLIC_APP_PAGES)
            ?>
        <footer class="pub-footer">
          <div class="pub-footer-inner">
            <div class="pub-footer-grid">
              <div class="pub-footer-col">
                <h4>Légal</h4>
                <a href="/mentions-legales">Mentions légales</a>
                <a href="/cgu">CGU / CGV</a>
                <a href="/confidentialite">Confidentialité</a>
                <a href="/cookies">Cookies</a>
                <a href="/suppression-compte">Suppression de compte</a>
              </div>
              <div class="pub-footer-col">
                <h4>Contact</h4>
                <a href="/contact">Nous écrire</a>
                <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>
              </div>
            </div>
            <div class="pub-footer-bottom">
              <p>© <?= $year ?> Assokit · Édité par RBPS</p>
            </div>
          </div>
        </footer>
            <?php
            return;
        }
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
                <a href="/pour-associations">Pour les associations</a>
                <a href="/pour-tpe">Pour les TPE &amp; PME</a>
                <a href="/comptabilite-analytique">Comptabilité analytique</a>
                <a href="/pour-organismes">Collectivités</a>
                <a href="/tarifs">Tarifs</a>
                <a href="/application">Application mobile</a>
                <a href="/connexion">Connexion</a>
              </div>

              <div class="pub-footer-col">
                <h4>Solutions</h4>
                <a href="/logiciel-association">Logiciel association</a>
                <a href="/logiciel-gestion-tpe">Logiciel gestion TPE</a>
                <a href="/logiciel-facturation">Logiciel de facturation</a>
                <a href="/logiciel-adherents">Gestion des adhérents</a>
                <a href="/logiciel-cotisation-association">Gestion des cotisations</a>
                <a href="/logiciel-comptabilite-association">Comptabilité association</a>
                <a href="/logiciel-association-gratuit">Logiciel association : essai gratuit</a>
                <a href="/alternative-helloasso">Alternative à HelloAsso</a>
              </div>

              <div class="pub-footer-col">
                <h4>Ressources</h4>
                <a href="/blog">Blog</a>
                <a href="/blog?categorie=associations">Pour les associations</a>
                <a href="/blog?categorie=tpe">Pour les TPE</a>
                <a href="/blog?categorie=comptabilite">Comptabilité</a>
                <a href="/guide-association-loi-1901">Guide association loi 1901</a>
                <a href="/guide-comptabilite-association">Guide comptabilité asso</a>
                <a href="/modeles-gratuits-association">Modèles gratuits</a>
              </div>

              <div class="pub-footer-col">
                <h4>Entreprise</h4>
                <a href="/a-propos">À propos</a>
                <a href="/avis">Avis clients</a>
                <a href="/contact">Contact</a>
                <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>
              </div>

              <div class="pub-footer-col">
                <h4>Légal</h4>
                <a href="/mentions-legales">Mentions légales</a>
                <a href="/cgu">CGU / CGV</a>
                <a href="/confidentialite">Confidentialité</a>
                <a href="/cookies">Cookies</a>
                <a href="/suppression-compte">Suppression de compte</a>
                <a href="/cookies#gerer" onclick="if(window.akShowCookieBanner){akShowCookieBanner();return false;}">Gérer mes cookies</a>
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
