<?php
/**
 * a-propos.php — PATCH 6.1
 * --------------------------------------------------------------
 * Sans mention Latitude91 → "société en cours d'immatriculation à Évry"
 * Édité par RBPS
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',   'url' => '/'],
    ['name' => 'À propos',  'url' => '/a-propos'],
]);

render_public_head([
    'title'       => 'À propos · L\'histoire d\'Assokit',
    'description' => 'Découvrez la mission d\'Assokit : redonner du temps aux associations loi 1901 et aux petites entreprises grâce à un outil pensé en France.',
    'path'        => '/a-propos',
    'schema_jsonld' => [$breadcrumb],
]);

render_public_nav('a-propos');
?>

<section class="pub-hero" style="padding: 60px 0 30px;">
  <div class="pub-container">
    <div class="pub-breadcrumb">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">À propos</strong>
    </div>
    <span class="pub-eyebrow">🌿 Notre histoire</span>
    <h1 class="pub-h1" style="max-width:780px;">Pour celles et ceux qui font, <em>discrètement, le monde de demain</em>.</h1>
    <p class="pub-tagline" style="max-width:680px;">
      Assokit est né d'un constat simple : <strong>les associations et les petites entreprises portent énormément, et sont mal outillées pour ça.</strong>
    </p>
  </div>
</section>

<!-- HISTOIRE -->
<section class="pub-section">
  <div class="pub-container-narrow">
    <article style="font-size:17px;line-height:1.8;color:var(--c-text);">
      <h2 style="font-size:28px;color:var(--c-encre);margin-top:0;">Notre constat</h2>
      <p>
        Vous êtes président·e d'une asso. Trésorier·ère. Bénévole. Artisan·e. Indépendant·e.
        Vous avez choisi un projet. Une cause. Une équipe.
      </p>
      <p>
        Et au fil du temps, vous avez vu le temps de la <em>vraie</em> mission diminuer, grignoté par les tableurs cassés,
        les relances oubliées, les newsletters jamais envoyées, les comptes-rendus qui s'éternisent.
      </p>
      <p>
        Vous n'êtes pas seul·e. <strong>Aujourd'hui en France, 1,5 million d'associations et 4 millions de TPE
        partagent le même problème : trop d'admin, pas assez d'humain.</strong>
      </p>

      <h2 style="font-size:28px;color:var(--c-encre);">Notre mission</h2>
      <p>
        Faire d'Assokit l'outil <strong>le plus simple, le plus humain et le plus respectueux possible</strong>
        pour celles et ceux qui veulent passer plus de temps sur leur projet et moins sur la paperasse.
      </p>
      <p>
        On ne veut pas remplacer votre équipe. On ne veut pas non plus prétendre que l'IA va tout faire.
        On veut juste vous donner <em>un outil de bonne facture</em>, pensé en France, accompagné par des humains.
      </p>

      <blockquote style="border-left:4px solid var(--c-emeraude);padding:16px 24px;background:var(--c-emeraude-light);border-radius:8px;margin:30px 0;color:var(--c-encre);font-style:italic;">
        « Notre obsession : que vous oubliez Assokit. Qu'il s'efface derrière ce qui compte vraiment.<br>Pas qu'il devienne votre nouvelle corvée. »
      </blockquote>

      <h2 style="font-size:28px;color:var(--c-encre);">Édité par RBPS</h2>
      <p>
        Assokit est un produit édité par <strong>RBPS</strong>, structure française basée à <strong>Évry</strong>,
        actuellement <strong>en cours d'immatriculation</strong>.
      </p>
      <p>
        Nous sommes une <strong>petite équipe</strong>, avec une exigence haute sur trois choses :
      </p>
      <ul>
        <li><strong>La qualité du produit</strong> — chaque détail compte, chaque clic doit avoir un sens.</li>
        <li><strong>La proximité humaine</strong> — vous nous écrivez, nous vous répondons. Vraiment.</li>
        <li><strong>Le respect des utilisateurs</strong> — pas de dark patterns, pas de revente de données, pas d'engagement piégé.</li>
      </ul>

      <h2 style="font-size:28px;color:var(--c-encre);">Nos engagements</h2>
      <p>Pour chaque utilisateur d'Assokit, on s'engage explicitement à :</p>
      <ul>
        <li>🇫🇷 <strong>Hébergement en France</strong> · serveurs O2Switch (Clermont-Ferrand)</li>
        <li>🔒 <strong>Conformité RGPD native</strong> · vos données restent les vôtres</li>
        <li>🚫 <strong>Aucune revente de données</strong> · jamais, à personne</li>
        <li>💬 <strong>Support humain &lt;24h</strong> · pas de bot, pas de FAQ tournante</li>
        <li>📦 <strong>Export libre à tout moment</strong> · vos données sont exportables en un clic</li>
        <li>📅 <strong>Pas d'engagement piégé</strong> · vous arrêtez quand vous voulez</li>
      </ul>

      <h2 style="font-size:28px;color:var(--c-encre);">Et après ?</h2>
      <p>
        Ce que vous voyez aujourd'hui n'est qu'une étape. <strong>Assokit grandit avec ses utilisateurs.</strong>
        Stripe pour les paiements en ligne, comptabilité avancée, génération d'images, espaces membres publics,
        intégrations bancaires… Beaucoup de choses arrivent.
      </p>
      <p>
        Mais à chaque ajout, la même obsession : <em>est-ce que ça vous fait gagner du temps ? Est-ce que ça reste simple ?
        Est-ce que c'est respectueux ?</em>
      </p>
      <p style="margin-top:30px;">
        <strong>Bienvenue chez Assokit. Et merci d'être là.</strong> 🌿
      </p>
    </article>
  </div>
</section>

<!-- VALEURS -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Nos valeurs</span>
      <h2 class="pub-h2">Trois mots qui <em>nous tiennent debout</em>.</h2>
    </div>

    <div class="pub-features">
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#D1FAE5;color:#059669;">🤝</div>
        <h3>Proximité</h3>
        <p>On répond. Personnellement. Rapidement. On préfère un email humain qu'un help-center froid.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FEF3C7;color:#92400E;">🌱</div>
        <h3>Sobriété</h3>
        <p>Une fonctionnalité doit gagner sa place. Pas d'options à gogo, pas de paramètres pour rien.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#F3E8FF;color:#7E22CE;">🔓</div>
        <h3>Transparence</h3>
        <p>Vous savez ce que vous payez. Vous savez où sont vos données. Vous savez qui développe l'outil.</p>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Une question, une remarque, une idée ?</h2>
      <p>On répond toujours. Et avec plaisir.</p>
      <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Nous écrire</a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
