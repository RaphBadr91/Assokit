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
      <p><strong>Assokit</strong> — sa conception, son architecture, son code source, ses interfaces,
        ses textes, ses bases de données et son identité visuelle — est la propriété exclusive de
        <strong>Raphaël Badr Pujol-Siwane</strong>, qui en est l'auteur et le titulaire de l'ensemble
        des droits de propriété intellectuelle, y compris les droits attachés à l'innovation qu'il
        porte.</p>
      <p>Le code source est protégé au titre du droit d'auteur, les logiciels figurant parmi les
        œuvres de l'esprit énumérées à l'article L112-2 (13°) du Code de la propriété intellectuelle.
        Cette protection naît de la création elle-même, sans formalité de dépôt.</p>
      <p>Sont notamment interdites, sans autorisation écrite préalable : la reproduction totale ou
        partielle, l'adaptation, la traduction, la diffusion, la mise à disposition de tiers, la
        décompilation en dehors des cas prévus par la loi, ainsi que l'extraction ou la réutilisation
        substantielle des bases de données.</p>

      <h2>Marque</h2>
      <p>« Assokit » est une <strong>marque déposée</strong> auprès de l'Institut national de la
        propriété industrielle.</p>
      <ul>
        <li>Numéro national : <strong>26 5259962</strong></li>
        <li>Date de dépôt : <strong>20 mai 2026</strong></li>
        <li>Office : INPI (92)</li>
        <li>Titulaire : Raphaël Badr Pujol-Siwane</li>
      </ul>
      <p>Le logo et l'identité visuelle d'Assokit sont protégés au même titre. Toute reproduction ou
        usage de la marque, de sa dénomination ou de son logo sans autorisation engage la
        responsabilité de son auteur au sens de l'article L713-2 du Code de la propriété
        intellectuelle.</p>
      <p>L'exploitation du site et du service est assurée par <strong>RBPS</strong>, sur autorisation
        du titulaire des droits.</p>

      <h2>Limitation de responsabilité</h2>
      <p>RBPS met tout en œuvre pour assurer l'exactitude des informations diffusées sur ce site mais ne saurait être tenue responsable d'erreurs ou omissions. L'utilisateur est seul responsable de l'utilisation qu'il fait des informations.</p>

      <h2>Droit applicable</h2>
      <p>Assokit, sa marque, son logo et son code source sont régis par le <strong>droit français</strong>,
        et notamment par le Code de la propriété intellectuelle. Le présent site y est soumis.
        Tout litige relatif à son utilisation, à la marque ou aux droits d'auteur attachés au
        logiciel relève de la compétence des tribunaux français.</p>

      <h2>Contact</h2>
      <p>Pour toute question relative aux présentes mentions légales : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
