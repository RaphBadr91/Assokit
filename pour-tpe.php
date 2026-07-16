<?php
/**
 * pour-tpe.php — Landing marketing dédiée aux TPE, PME et indépendants.
 * Route : /pour-tpe (301 retiré dans .htaccess). SEO + FAQPage + Breadcrumb.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',       'url' => '/'],
    ['name' => 'Pour les TPE & PME', 'url' => '/pour-tpe'],
]);

$faqs = [
    ['Assokit est-il adapté à une TPE ou un indépendant ?', "Oui, totalement. Artisans, commerçants, indépendants, petites entreprises : vous pilotez devis, factures, paiements, projets/chantiers et comptabilité depuis un seul outil, sans logiciel comptable en plus."],
    ['Puis-je faire mes devis et factures avec ?', "Oui : devis et factures professionnels, relances de paiement automatiques, suivi des encaissements en temps réel, et scan de facture par IA pour la saisie. Conforme à la facturation française."],
    ['Comment ça marche pour suivre mes chantiers / projets ?', "Chaque client ou chantier devient un projet : étapes, budget alloué vs dépensé, factures rattachées, collègues assignés. Vous savez en un coup d'œil où en est chaque affaire."],
    ['La comptabilité analytique, c\'est utile pour moi ?', "Beaucoup : vous voyez la rentabilité réelle par chantier/projet, poste par poste. Incluse dès l'offre Pro — environ 900 € d'économie par an face à une prestation externalisée."],
    ['Mes rendez-vous et mon agenda sont-ils gérés ?', "Oui : notez vos rendez-vous directement dans l'outil, avec rappels. Plus besoin de jongler entre dix applis — tout est centralisé et visible."],
];
$faq_schema = [
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs),
];

render_public_head([
    'title'       => 'Logiciel de gestion pour TPE, PME & indépendants · Assokit',
    'description' => 'Assokit, le logiciel tout-en-un des TPE, PME et indépendants : devis, factures, relances, suivi de chantiers/projets, comptabilité analytique, agenda et scan de factures par IA. Essai gratuit, hébergé en France.',
    'path'        => '/pour-tpe',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding:60px 0 34px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Pour les TPE &amp; PME</strong></div>
    <span class="pub-eyebrow">🛠️ Pensé pour les TPE, PME &amp; indépendants</span>
    <h1 class="pub-h1" style="max-width:840px;">Pilotez votre entreprise <em>sans paperasse</em> — devis, factures, chantiers et compta réunis.</h1>
    <p class="pub-tagline" style="max-width:680px;">Artisan, commerçant, indépendant, petite entreprise : arrêtez de jongler entre dix outils. <strong>Devis, factures, projets, rendez-vous et comptabilité analytique</strong> dans une seule appli, boostée par l'IA.</p>
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
      <h2 class="pub-h2">Moins d'administratif, <em>plus de chiffre d'affaires.</em></h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:980px;margin:0 auto;">
      <?php
      $pains = [
        ['🧾', 'Les devis et factures qui traînent', 'Devis et factures pro en 2 minutes, relances de paiement automatiques, suivi des encaissements.'],
        ['📸', 'La saisie des factures fournisseurs', 'Scannez, l\'IA lit et pré-remplit. Vos justificatifs sont rattachés au bon projet.'],
        ['🏗️', 'Les chantiers difficiles à suivre', 'Un projet par client/chantier : étapes, budget, factures, collègues assignés. Tout est clair.'],
        ['📊', 'La rentabilité qu\'on devine', 'Comptabilité analytique par chantier : vous savez ce qui rapporte vraiment. ~900 €/an économisés.'],
        ['📅', 'Les rendez-vous dispersés', 'Agenda intégré : vos RDV notés au même endroit que vos clients et vos affaires.'],
        ['📱', 'Le bureau qu\'on n\'a jamais sur soi', 'Application mobile : gérez tout depuis votre téléphone, même sur le terrain.'],
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
      <h2 class="pub-h2">Le tout-en-un qui remplace <em>vos 10 outils</em></h2>
    </div>
    <ul class="pub-features-checklist" style="max-width:720px;margin:0 auto;columns:2;column-gap:32px;">
      <li>✓ Devis &amp; factures pro</li>
      <li>✓ Relances de paiement auto</li>
      <li>✓ Scan de factures par IA</li>
      <li>✓ Projets / chantiers</li>
      <li>✓ Comptabilité analytique</li>
      <li>✓ Suivi de trésorerie</li>
      <li>✓ Agenda &amp; rendez-vous</li>
      <li>✓ Clients &amp; contacts</li>
      <li>✓ Équipe &amp; rôles</li>
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
    <span class="pub-section-eyebrow">Le bon calcul</span>
    <h2 class="pub-h2">Connaissez la rentabilité <em>réelle</em> de chaque chantier</h2>
    <p class="pub-section-lead" style="max-width:620px;margin:0 auto 24px;">La comptabilité analytique par projet est incluse dès l'offre Pro. Jusqu'à 900 € par an économisés sur votre comptabilité, et une vision claire de ce qui vous rapporte.</p>
    <a href="/comptabilite-analytique" class="pub-btn pub-btn-primary pub-btn-lg">Voir comment</a>
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
      <h2>Prêt à piloter votre entreprise sereinement ?</h2>
      <p>30 minutes en visio, on regarde ensemble si Assokit est fait pour votre activité. Sans engagement.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:6px;">
        <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo</a>
        <a href="/avis" class="pub-btn pub-btn-ghost pub-btn-lg">Lire les avis clients</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
