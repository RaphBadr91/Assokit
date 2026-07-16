<?php
/**
 * pour-associations.php — Landing marketing dédiée aux associations loi 1901.
 * Route : /pour-associations (301 retiré dans .htaccess). SEO + FAQPage + Breadcrumb.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',            'url' => '/'],
    ['name' => 'Pour les associations', 'url' => '/pour-associations'],
]);

$faqs = [
    ['Assokit convient-il aux petites associations ?', "Oui. Que vous ayez 5 ou 500 adhérents, Assokit s'adapte. L'offre Démarrage est gratuite pour découvrir, puis vous montez en gamme quand votre association grandit. Aucune compétence technique requise."],
    ['Peut-on gérer les adhérents et les cotisations ?', "Absolument : annuaire des adhérents avec rôles (bureau, bénévoles, membres), relances de cotisations automatiques, reçus, et espace membre dédié. Fini les tableurs qui se cassent."],
    ['Assokit aide-t-il pour l\'assemblée générale ?', "Oui. Convocations, émargement, procès-verbal, suivi des présents et des votes : votre AG se prépare et s'archive en quelques clics."],
    ['La comptabilité analytique est-elle incluse ?', "Oui, dès l'offre Pro : bilan par projet et par poste, exportable en PDF et Excel pour votre expert-comptable. Environ 900 € d'économie par an par rapport à une prestation externalisée."],
    ['Mes données sont-elles en sécurité ?', "100 %. Hébergement en France (Clermont-Ferrand), conformité RGPD, double authentification, export de vos données à tout moment. Vos données restent les vôtres."],
];
$faq_schema = [
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs),
];

render_public_head([
    'title'       => 'Logiciel de gestion pour association loi 1901 · Assokit',
    'description' => 'Assokit, le logiciel tout-en-un des associations loi 1901 : adhérents, cotisations, assemblées générales, projets, subventions, comptabilité analytique et communication. Essai gratuit, hébergé en France.',
    'path'        => '/pour-associations',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding:60px 0 34px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Pour les associations</strong></div>
    <span class="pub-eyebrow">🤝 Pensé pour les associations loi 1901</span>
    <h1 class="pub-h1" style="max-width:820px;">Gérez votre association <em>sans paperasse</em>, et retrouvez du temps pour l'essentiel.</h1>
    <p class="pub-tagline" style="max-width:680px;">Adhérents, cotisations, assemblées générales, projets, subventions, communication : <strong>tout au même endroit</strong>, main dans la main avec l'IA. Fini les tableurs, les fichiers perdus et les relances oubliées.</p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:22px;">
      <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo gratuite</a>
      <a href="/tarifs" class="pub-btn pub-btn-ghost pub-btn-lg">Voir les tarifs</a>
    </div>
    <p style="font-size:13.5px;color:var(--c-text-2);margin-top:14px;">✓ Essai gratuit &nbsp;·&nbsp; ✓ Sans engagement &nbsp;·&nbsp; ✓ Hébergé en France 🇫🇷</p>
  </div>
</section>

<!-- Douleurs → solutions -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Ce qui change</span>
      <h2 class="pub-h2">Vous connaissez ces galères. <em>On les fait disparaître.</em></h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:980px;margin:0 auto;">
      <?php
      $pains = [
        ['📋', 'Le fichier des adhérents dans tous les sens', 'Un annuaire vivant, à jour, avec rôles et relances de cotisation automatiques.'],
        ['💸', 'Les cotisations qu\'on court après', 'Relances automatiques, reçus générés, suivi des paiements en temps réel.'],
        ['🗳️', 'L\'AG qui vire au casse-tête', 'Convocations, émargement, PV, votes : préparés et archivés en quelques clics.'],
        ['📊', 'Le comptable qui coûte cher', 'Comptabilité analytique incluse dès l\'offre Pro. ~900 € économisés par an.'],
        ['📣', 'La com qui prend un temps fou', 'Diffusion email ciblée + rédaction assistée par l\'IA. Vos messages partent en 2 minutes.'],
        ['🎯', 'Les projets et subventions éparpillés', 'Chaque projet suivi étape par étape, budget alloué vs dépensé, factures rattachées.'],
      ];
      foreach ($pains as $p): ?>
        <div style="background:#fff;border:1px solid var(--c-border);border-radius:16px;padding:24px;">
          <div style="font-size:28px;margin-bottom:10px;"><?= $p[0] ?></div>
          <h3 style="font-size:16px;margin:0 0 6px;color:var(--c-encre);"><?= pub_h($p[1]) ?></h3>
          <p style="font-size:14px;color:var(--c-text-2);line-height:1.55;margin:0;"><?= pub_h($p[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Fonctionnalités clés -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Tout inclus</span>
      <h2 class="pub-h2">Un seul outil pour <em>toute la vie associative</em></h2>
      <p class="pub-section-lead">Pas de version « light » bridée. Toutes les associations méritent les mêmes outils.</p>
    </div>
    <ul class="pub-features-checklist" style="max-width:720px;margin:0 auto;columns:2;column-gap:32px;">
      <li>✓ Adhérents &amp; bénévoles avec rôles</li>
      <li>✓ Cotisations &amp; relances auto</li>
      <li>✓ Assemblées générales &amp; PV</li>
      <li>✓ Projets &amp; suivi de subventions</li>
      <li>✓ Comptabilité analytique</li>
      <li>✓ Devis &amp; factures</li>
      <li>✓ Diffusion email ciblée</li>
      <li>✓ IA de rédaction intégrée</li>
      <li>✓ Espace membre dédié</li>
      <li>✓ Application mobile</li>
      <li>✓ Hébergement France · RGPD</li>
      <li>✓ Export de vos données</li>
    </ul>
    <div class="pub-text-center" style="margin-top:28px;">
      <a href="/fonctionnalites" class="pub-btn pub-btn-ghost">Découvrir toutes les fonctionnalités</a>
    </div>
  </div>
</section>

<!-- Économie compta -->
<section class="pub-section pub-section-creme">
  <div class="pub-container" style="text-align:center;">
    <span class="pub-section-eyebrow">Le bon plan</span>
    <h2 class="pub-h2">Jusqu'à <em>900 € / an</em> économisés sur votre comptabilité</h2>
    <p class="pub-section-lead" style="max-width:620px;margin:0 auto 24px;">La comptabilité analytique par projet est incluse dès l'offre Pro. Votre expert-comptable n'intervient plus que pour valider — pas pour tout ressaisir.</p>
    <a href="/comptabilite-analytique" class="pub-btn pub-btn-primary pub-btn-lg">Comment on fait ça</a>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Vos questions, <em>nos réponses</em>.</h2></div>
    <div class="pub-faq">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= pub_h($qa[0]) ?></summary>
          <div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Prêt·e à simplifier la vie de votre association ?</h2>
      <p>30 minutes en visio, on regarde ensemble si Assokit est fait pour vous. Sans engagement.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:6px;">
        <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo</a>
        <a href="/avis" class="pub-btn pub-btn-ghost pub-btn-lg">Lire les avis clients</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
