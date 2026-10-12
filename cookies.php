<?php
require_once __DIR__ . '/includes-public.php';

render_public_head([
    'title'       => 'Politique cookies',
    'description' => 'Politique des cookies utilisés par Assokit. Cookies de mesure d\'audience (Google Analytics) uniquement avec votre consentement. Pas de cookies publicitaires.',
    'path'        => '/cookies',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Cookies</strong></div>
    <h1 class="pub-h1" style="font-size:36px;">Politique cookies</h1>
    <p class="pub-tagline">Le minimum, et rien de plus.</p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content">
      <p style="background:var(--c-emeraude-light);border-left:4px solid var(--c-emeraude);padding:16px 20px;border-radius:8px;">
        🍪 <strong>En une phrase</strong> : nous utilisons des cookies <em>strictement nécessaires</em> et, uniquement avec votre consentement, une mesure d'audience (Google Analytics, IP anonymisée). Pas de publicitaire.
      </p>

      <h2>Qu'est-ce qu'un cookie ?</h2>
      <p>Un cookie est un petit fichier texte stocké sur votre appareil par un site web. Il permet au site de "se souvenir" de certaines informations entre vos visites.</p>

      <h2>Quels cookies utilisons-nous ?</h2>

      <h3>1. Cookie de session (obligatoire)</h3>
      <p>Maintient votre connexion au compte Assokit pendant votre navigation. Le jeton de sécurité anti-falsification de requêtes (CSRF) est conservé sur nos serveurs, dans cette session : il ne fait pas l’objet d’un cookie distinct.</p>
      <ul>
        <li><strong>Nom</strong> : <code>PHPSESSID</code></li>
        <li><strong>Durée</strong> : durée de la session</li>
        <li><strong>Finalité</strong> : authentification, sécurité</li>
      </ul>

      <h3>2. Mémorisation de votre choix (obligatoire)</h3>
      <p>Retient si vous avez accepté ou refusé la mesure d’audience, pour ne pas vous reposer la question à chaque page.</p>
      <ul>
        <li><strong>Nom</strong> : <code>ak_cookie_consent</code></li>
        <li><strong>Durée</strong> : 6 mois</li>
        <li><strong>Finalité</strong> : respect de votre choix</li>
      </ul>

      <h3>3. Mesure d’audience Google Analytics (avec votre consentement)</h3>
      <p>Statistiques de fréquentation du site public (pages vues, provenance), adresse IP anonymisée. Ces cookies ne sont déposés qu’après votre accord, et jamais dans l’application.</p>
      <ul>
        <li><strong>Noms</strong> : <code>_ga</code>, <code>_ga_&lt;identifiant&gt;</code></li>
        <li><strong>Durée</strong> : 13 mois maximum</li>
        <li><strong>Finalité</strong> : mesure d’audience · <strong>Émetteur</strong> : Google (voir notre <a href="/confidentialite">politique de confidentialité</a>)</li>
      </ul>

      <h3>4. Origine de la visite</h3>
      <p>Déposé uniquement si vous arrivez sur le site par un lien personnalisé reçu dans un e-mail d’Assokit, pour savoir que la visite vient de ce message.</p>
      <ul>
        <li><strong>Nom</strong> : <code>ak_src</code></li>
        <li><strong>Durée</strong> : 30 jours</li>
        <li><strong>Finalité</strong> : suivi de nos propres e-mails, sans aucun tiers</li>
      </ul>

      <h3>5. Réponse à un événement public</h3>
      <p>Déposé seulement si vous répondez à l’invitation publique d’un événement, pour vous permettre de modifier votre réponse depuis le même appareil.</p>
      <ul>
        <li><strong>Nom</strong> : <code>ak_rsvp_&lt;numéro de l’événement&gt;</code></li>
        <li><strong>Durée</strong> : 6 mois</li>
        <li><strong>Finalité</strong> : modification de votre réponse, sans aucun tiers</li>
      </ul>

      <h3>6. Préférences d’affichage dans l’application</h3>
      <p>L’application retient par exemple la rubrique du menu que vous avez ouverte. Cette préférence est gardée dans le stockage local de votre navigateur (ce n’est pas un cookie) et n’est jamais transmise.</p>

      <h2>Ce que nous n’utilisons pas</h2>
      <ul>
        <li>❌ Cookies publicitaires</li>
        <li>❌ Meta Pixel ou autre traceur publicitaire</li>
        <li>❌ Cookies de profilage marketing</li>
        <li>❌ Cookies de partage social</li>
      </ul>

      <h2 id="gerer">Comment gérer les cookies ?</h2>
      <p>Vous pouvez changer d’avis à tout moment, aussi simplement que vous avez donné votre choix : le lien <strong>« Gérer mes cookies »</strong>, ici ou en bas de chaque page, rouvre le bandeau pour accepter ou refuser la mesure d’audience. Si vous la refusez, les cookies Google Analytics déjà déposés sont effacés.</p>
      <p><button type="button" class="pub-btn pub-btn-primary" onclick="if(window.akShowCookieBanner)akShowCookieBanner();">Gérer mes cookies</button></p>
      <p>Vous pouvez aussi configurer ou supprimer les cookies via les paramètres de votre navigateur. Sachez que la désactivation du cookie de session vous empêchera d’utiliser votre compte Assokit.</p>

      <h2>Contact</h2>
      <p>Pour toute question : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>

      <?php /* Date de version : à changer seulement quand le texte change */ ?>
      <p style="margin-top:30px;color:var(--c-text-3);font-size:14px;">Version du 12/10/2026</p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
