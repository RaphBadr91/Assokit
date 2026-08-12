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

      <h3>1. Cookies de session (obligatoires)</h3>
      <p>Permettent de maintenir votre connexion au compte Assokit pendant votre navigation.</p>
      <ul>
        <li><strong>Nom</strong> : <code>PHPSESSID</code></li>
        <li><strong>Durée</strong> : durée de la session</li>
        <li><strong>Finalité</strong> : authentification, sécurité</li>
      </ul>

      <h3>2. Cookies de sécurité CSRF (obligatoires)</h3>
      <p>Empêchent les attaques de type "cross-site request forgery".</p>
      <ul>
        <li><strong>Nom</strong> : <code>csrf_token</code></li>
        <li><strong>Durée</strong> : session</li>
        <li><strong>Finalité</strong> : sécurité</li>
      </ul>

      <h3>3. Cookies de préférences (optionnels)</h3>
      <p>Mémorisent vos préférences d'affichage (mode sombre, taille de texte, etc.).</p>
      <ul>
        <li><strong>Durée</strong> : 1 an</li>
        <li><strong>Finalité</strong> : confort d'utilisation</li>
      </ul>

      <h2>Cookies que nous N'utilisons PAS</h2>
      <ul>
        <li>❌ Cookies publicitaires</li>
        <li>✅ Mesure d'audience Google Analytics (IP anonymisée) — uniquement après consentement · ❌ Pas de Meta Pixel ni de tracking publicitaire</li>
        <li>❌ Cookies de profilage marketing</li>
        <li>❌ Cookies de partage social</li>
      </ul>

      <h2>Comment gérer les cookies ?</h2>
      <p>Vous pouvez à tout moment configurer ou supprimer les cookies via les paramètres de votre navigateur. Sachez que la désactivation des cookies de session vous empêchera d'utiliser votre compte Assokit.</p>

      <h2>Contact</h2>
      <p>Pour toute question : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>

      <p style="margin-top:30px;color:var(--c-text-3);font-size:14px;">Dernière mise à jour : <?= date('d/m/Y') ?></p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
