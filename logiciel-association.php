<?php
/**
 * logiciel-association.php — Page SEO ciblée « logiciel association » / « logiciel gestion association loi 1901 ».
 * Route : /logiciel-association. Design premium (_landing-premium.php) + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel association', 'url' => '/logiciel-association'],
]);

$faqs = [
    ['Quel logiciel choisir pour gérer une association loi 1901 ?', "Un bon logiciel d'association doit réunir adhérents, cotisations, comptabilité, projets et communication dans un seul outil, sans compétence technique. Assokit est conçu exactement pour ça : pensé pour les associations françaises, hébergé en France et conforme RGPD."],
    ['Existe-t-il un logiciel de gestion d\'association gratuit ?', "Oui : Assokit propose une offre Démarrage gratuite, sans carte bancaire ni limite de durée, pour gérer votre association (adhérents, cotisations, factures). Vous passez à une offre supérieure uniquement quand votre association grandit."],
    ['Un logiciel association remplace-t-il Excel et les autres outils ?', "Complètement. Assokit remplace le tableur des adhérents, l'outil de facturation, l'agenda, la messagerie et le logiciel de compta — un seul outil, plus de ressaisie ni de fichiers perdus."],
    ['Le logiciel gère-t-il la comptabilité de l\'association ?', "Oui. Assokit inclut la facturation, le suivi des cotisations et la comptabilité analytique par projet (incluse dès l'offre Pro), exportable pour votre expert-comptable — soit environ 900 € d'économie par an."],
    ['Mes données sont-elles hébergées en France ?', "Oui, 100 %. Serveurs en France (Clermont-Ferrand), conformité RGPD, double authentification et export de vos données à tout moment. Vos données restent les vôtres."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion tout-en-un pour les associations loi 1901 : adhérents, cotisations, comptabilité, projets et communication.",
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Logiciel de gestion d\'association loi 1901 · Assokit',
    'description' => 'Assokit, le logiciel de gestion pour association loi 1901 : adhérents, cotisations, comptabilité, projets, événements et communication réunis dans un seul outil. Offre gratuite, hébergé en France, conforme RGPD.',
    'path'        => '/logiciel-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel association</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Logiciel association loi 1901</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de gestion</span> pensé pour votre association</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Adhérents, cotisations, comptabilité, projets, événements et communication — <strong>un seul logiciel</strong> pour toute la vie de votre association loi 1901. Français, hébergé en France, conforme RGPD, avec l'IA intégrée.</p>
        <div class="lp-hero-cta reveal">
          <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Offre gratuite</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span><span>🔒 RGPD</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Logiciel association</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#E0F2FE">👥</span><div style="flex:1"><div class="t">248 adhérents</div><div class="s">Annuaire à jour · rôles &amp; espaces</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">+12</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FCE7F3">💸</span><div style="flex:1"><div class="t">Cotisations</div><div class="s">Relances &amp; paiement en ligne</div></div><span class="v">94%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Comptabilité</div><div class="s">Bilan par projet · export compta</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">À jour</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">📣</span><div style="flex:1"><div class="t">Communication IA</div><div class="s">Newsletter rédigée en 30s</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">IA</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Pourquoi un logiciel dédié -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Pourquoi un logiciel dédié</span>
      <h2 class="pub-h2">Arrêtez de gérer votre association avec <em>10 outils différents</em></h2>
      <p class="pub-section-lead">Tableur des adhérents, mails de relance, agenda papier, logiciel de compta… Un logiciel de gestion d'association réunit tout, sans ressaisie ni fichier perdu.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ([
        ['👥','Adhérents &amp; bénévoles','Annuaire intelligent, rôles (bureau, membres), espaces dédiés, historique.'],
        ['💸','Cotisations','Relances automatiques, paiement en ligne, reçus, suivi en temps réel.'],
        ['📊','Comptabilité','Facturation, dépenses, comptabilité analytique par projet, exports.'],
        ['🎯','Projets &amp; subventions','Suivi étape par étape, budget alloué vs dépensé, factures rattachées.'],
        ['🗳️','Assemblées générales','Convocations, émargement, procès-verbaux, votes.'],
        ['📣','Communication IA','Newsletters, comptes-rendus et emails ciblés rédigés par l\'IA.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Pourquoi Assokit -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi Assokit</span><h2 class="pub-h2">Le logiciel association <em>français</em>, simple et complet</h2></div>
    <ul class="lp-checks reveal">
      <?php foreach (['Pensé pour les associations loi 1901','Offre gratuite, sans carte bancaire','Hébergé en France · conforme RGPD','Comptabilité analytique incluse','IA intégrée pour la communication','Application mobile iOS &amp; Android','Support humain français','Export de vos données à tout moment'] as $f): ?>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= $f ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/pour-associations" class="lp-btn lp-btn-glass">Assokit pour les associations</a>
      <a href="/logiciel-cotisation-association" class="lp-btn lp-btn-glass">Gérer les cotisations</a>
      <a href="/logiciel-comptabilite-association" class="lp-btn lp-btn-glass">La comptabilité</a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Choisir son <em>logiciel d'association</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Testez le logiciel gratuitement</h2>
      <p>Créez votre espace association en quelques minutes. Offre gratuite, sans carte bancaire, sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
