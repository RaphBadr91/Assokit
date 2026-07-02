<?php
require_once __DIR__ . '/includes-public.php';

render_public_head([
    'title'       => 'Mentions légales',
    'description' => 'Mentions légales du site Assokit, édité par RBPS.',
    'path'        => '/mentions-legales',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Mentions légales</strong></div>
    <h1 class="pub-h1" style="font-size:36px;">Mentions légales</h1>
    <p class="pub-tagline">Dernière mise à jour : <?= date('d/m/Y') ?></p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content">
      <h2>Éditeur du site</h2>
      <p>Le site <strong>assokit.fr</strong> est édité par <strong>RBPS</strong>, structure française basée à <strong>Évry</strong>.</p>
      <ul>
        <li>Forme juridique : en cours d'immatriculation en France</li>
        <li>Siège social : Évry, France</li>
        <li>Email : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></li>
      </ul>

      <h2>Directeur de la publication</h2>
      <p>Le directeur de la publication est le représentant légal de RBPS.</p>

      <h2>Hébergement</h2>
      <p>Le site est hébergé par :</p>
      <ul>
        <li><strong>O2Switch</strong></li>
        <li>Chemin des Pardiaux, 63000 Clermont-Ferrand</li>
        <li>France</li>
        <li>Site : <a href="https://www.o2switch.fr" target="_blank" rel="noopener">o2switch.fr</a></li>
      </ul>

      <h2>Propriété intellectuelle</h2>
      <p>L'ensemble des contenus présents sur le site (textes, images, code, marque) est la propriété exclusive de RBPS, sauf mention contraire. Toute reproduction, même partielle, est interdite sans autorisation écrite préalable.</p>

      <h2>Marque</h2>
      <p>« Assokit » est une marque déposée. Toute utilisation non autorisée est strictement interdite.</p>

      <h2>Limitation de responsabilité</h2>
      <p>RBPS met tout en œuvre pour assurer l'exactitude des informations diffusées sur ce site mais ne saurait être tenue responsable d'erreurs ou omissions. L'utilisateur est seul responsable de l'utilisation qu'il fait des informations.</p>

      <h2>Droit applicable</h2>
      <p>Le présent site est soumis au droit français. Tout litige relatif à son utilisation relève de la compétence des tribunaux français.</p>

      <h2>Contact</h2>
      <p>Pour toute question relative aux présentes mentions légales : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
