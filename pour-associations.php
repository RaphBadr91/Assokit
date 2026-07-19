<?php
/**
 * pour-associations.php — Landing marketing premium dédiée aux associations loi 1901.
 * Route : /pour-associations. SEO + FAQPage + Breadcrumb. Style glass/4D via _landing-premium.php.
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
require __DIR__ . '/_landing-premium.php';
?>
<!-- ===== HERO ===== -->
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Pour les associations</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Pensé pour les associations loi 1901</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:600px;">Gérez votre association <span class="lp-grad">sans paperasse</span>, et retrouvez du temps pour l'essentiel.</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Adhérents, cotisations, assemblées générales, projets, subventions, communication : <strong>tout au même endroit</strong>, main dans la main avec l'IA. Fini les tableurs et les relances oubliées.</p>
        <div class="lp-hero-cta reveal">
          <a href="/contact" class="lp-btn lp-btn-primary">Réserver une démo gratuite
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/tarifs" class="lp-btn lp-btn-glass">Voir les tarifs</a>
        </div>
        <div class="lp-trust reveal">
          <span>✓ Essai gratuit</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span><span>🔒 RGPD</span>
        </div>
        <a href="/comptabilite-analytique" class="lp-glass reveal" style="display:flex;align-items:center;gap:14px;margin-top:20px;padding:15px 18px;border-radius:16px;text-decoration:none;max-width:520px;">
          <span style="width:44px;height:44px;flex:none;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;background:linear-gradient(135deg,#FEF3C7,#FDE68A);box-shadow:0 8px 18px rgba(245,158,11,.2);">📊</span>
          <span style="min-width:0;">
            <span style="display:block;font-size:14.5px;font-weight:800;color:var(--c-encre);">Comptabilité analytique incluse dès le départ</span>
            <span style="display:block;font-size:13px;color:var(--c-text-2);margin-top:1px;">Bilan par projet · ~900 €/an économisés · votre comptable n'a plus qu'à valider</span>
          </span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--c-emeraude-dark)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-left:auto;"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      </div>
      <!-- Mockup produit -->
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Mon association</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#E0F2FE">👥</span><div style="flex:1"><div class="t">Adhérents à jour</div><div class="s">248 membres · 12 nouveaux ce mois</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">+12</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FCE7F3">💸</span><div style="flex:1"><div class="t">Cotisations</div><div class="s">Relances envoyées automatiquement</div></div><span class="v">94% payées</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🗳️</span><div style="flex:1"><div class="t">Assemblée générale</div><div class="s">Émargement · PV · votes</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Prête</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Compta analytique</div><div class="s">Bilan par projet · incluse</div></div><span class="v">-900€/an</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== DOULEURS → SOLUTIONS ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Ce qui change</span>
      <h2 class="pub-h2">Vous connaissez ces galères. <em>On les fait disparaître.</em></h2>
    </div>
    <div class="lp-grid">
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
        <div class="lp-card reveal">
          <div class="lp-ic"><?= $p[0] ?></div>
          <h3><?= pub_h($p[1]) ?></h3>
          <p><?= pub_h($p[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== TOUT INCLUS ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Tout inclus</span>
      <h2 class="pub-h2">Un seul outil pour <em>toute la vie associative</em></h2>
      <p class="pub-section-lead">Pas de version « light » bridée. Toutes les associations méritent les mêmes outils.</p>
    </div>
    <ul class="lp-checks reveal">
      <?php
      $feats = ['Comptabilité analytique incluse', 'Adhérents & bénévoles avec rôles', 'Cotisations & relances auto', 'Assemblées générales & PV', 'Projets & suivi de subventions', 'Devis & factures', 'Diffusion email ciblée', 'IA de rédaction intégrée', 'Espace membre dédié', 'Application mobile', 'Hébergement France · RGPD', 'Export de vos données'];
      foreach ($feats as $f): ?>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= pub_h($f) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="pub-text-center reveal" style="margin-top:30px;"><a href="/fonctionnalites" class="lp-btn lp-btn-glass">Découvrir toutes les fonctionnalités</a></div>
  </div>
</section>

<!-- ===== TYPES D'ASSOCIATIONS ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Toutes les associations</span>
      <h2 class="pub-h2">Quel que soit votre <em>domaine</em>, Assokit s'adapte</h2>
      <p class="pub-section-lead">Sportive, culturelle, sociale, environnementale… la nomenclature officielle du Répertoire National des Associations compte des dizaines de familles. Assokit les gère toutes, avec les mêmes outils.</p>
    </div>
    <style>
    .lp-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;max-width:1000px;margin:0 auto}
    .lp-cat{display:flex;align-items:flex-start;gap:12px;background:#fff;border:1px solid var(--c-border);border-radius:15px;padding:15px 16px;transition:transform .18s,box-shadow .18s,border-color .18s}
    .lp-cat:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(6,78,59,.10);border-color:rgba(5,150,105,.28)}
    .lp-cat .e{font-size:22px;line-height:1;flex:none;margin-top:1px}
    .lp-cat .n{font-size:14px;font-weight:800;color:var(--c-encre);line-height:1.2}
    .lp-cat .b{font-size:12px;color:var(--c-text-2);margin-top:3px;line-height:1.4}
    </style>
    <div class="lp-cats reveal">
      <?php
      $cats = [
        ['🏅', 'Sportives', 'Licences, plannings, convocations, cotisations'],
        ['🎭', 'Culturelles & artistiques', 'Adhérents, événements, spectacles, com'],
        ['🎵', 'Musique & spectacle', 'Membres, répétitions, billetterie'],
        ['🤝', 'Sociales & caritatives', 'Bénévoles, dons, projets, suivi'],
        ['🌍', 'Solidarité internationale', 'Projets, dons, subventions'],
        ['🌱', 'Environnement & nature', 'Projets, mobilisation, subventions'],
        ['📚', 'Éducation & jeunesse', 'Membres, ateliers, plannings'],
        ['🎓', 'Amicales & anciens élèves', 'Annuaire, cotisations, événements'],
        ['🎲', 'Loisirs & clubs', 'Adhérents, activités, agenda'],
        ['🏥', 'Santé & entraide', 'Membres, prévention, événements'],
        ['👨‍👩‍👧', 'Familles & parents d\'élèves', 'Adhérents, événements, cotisations'],
        ['🎖️', 'Anciens combattants & mémoire', 'Adhérents, cérémonies, cotisations'],
        ['🎣', 'Chasse & pêche', 'Cartes, licences, cotisations'],
        ['⚖️', 'Défense de droits', 'Adhérents, actions, communication'],
        ['🏘️', 'Comités de quartier', 'Adhérents, événements, com locale'],
        ['🚑', 'Secourisme & protection civile', 'Membres, formations, plannings'],
        ['🎨', 'Patrimoine & histoire', 'Membres, événements, projets'],
        ['💼', 'Professionnelles & économie', 'Membres, cotisations, réseau'],
      ];
      foreach ($cats as $c): ?>
        <div class="lp-cat">
          <span class="e"><?= $c[0] ?></span>
          <span style="min-width:0;"><span class="n"><?= pub_h($c[1]) ?></span><span class="b" style="display:block;"><?= pub_h($c[2]) ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:22px;color:var(--c-text-3);font-size:13.5px;">…et toutes les autres. Une association, un besoin&nbsp;? <a href="/contact" style="color:var(--c-emeraude-dark);font-weight:700;">Parlons-en</a>.</p>
  </div>
</section>

<!-- ===== ÉCONOMIE ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal">
      <span class="pub-section-eyebrow" style="position:relative;color:#A7F3D0;">De l'argent rendu à vos actions</span>
      <div class="big">−900 € / an</div>
      <p>C'est ce que coûte une comptabilité analytique chez un prestataire. Chez vous, elle est <strong>incluse dès l'offre Pro</strong> — soit près de <strong>2&nbsp;700&nbsp;€ sur 3&nbsp;ans</strong> qui repartent dans vos projets, vos bénévoles et vos adhérents. <span style="opacity:.85;">Pas dans la paperasse.</span></p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin:4px 0 22px;">
        <span class="lp-badge" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.22);color:#fff;"><span style="text-decoration:line-through;opacity:.75;">Externalisée&nbsp;: ~900&nbsp;€/an</span></span>
        <span class="lp-badge" style="background:rgba(255,255,255,.16);border-color:rgba(167,243,208,.5);color:#fff;">✓ Avec Assokit&nbsp;: 0&nbsp;€, incluse</span>
      </div>
      <a href="/comptabilite-analytique" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Comment on fait ça
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
  </div>
</section>

<!-- ===== FAQ ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Vos questions, <em>nos réponses</em>.</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= pub_h($qa[0]) ?></summary>
          <div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== CTA ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Prêt·e à simplifier la vie de votre association ?</h2>
      <p>30 minutes en visio, on regarde ensemble si Assokit est fait pour vous. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/contact" class="lp-btn lp-btn-primary">Réserver une démo</a>
        <a href="/avis" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Lire les avis clients</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
