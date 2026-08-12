<?php
/**
 * logiciel-gestion-tpe.php — Page SEO ciblée « logiciel gestion TPE » / « logiciel TPE » / « logiciel PME ».
 * Route : /logiciel-gestion-tpe. Premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel de gestion TPE', 'url' => '/logiciel-gestion-tpe'],
]);

$faqs = [
    ['Quel logiciel de gestion pour une TPE ou un indépendant ?', "Un bon logiciel de gestion TPE réunit devis, factures, clients, projets/chantiers, trésorerie et agenda dans un seul outil, sans logiciel comptable en plus. Assokit est conçu pour ça : simple, français, hébergé en France, utilisable sur mobile comme au bureau."],
    ['Existe-t-il un logiciel de gestion TPE gratuit ?', "Oui : Assokit propose une offre gratuite pour démarrer (devis, factures, suivi), sans carte bancaire. Vous montez en gamme uniquement quand votre activité se développe."],
    ['Le logiciel gère-t-il les chantiers et projets ?', "Oui. Chaque client ou chantier devient un projet : étapes, budget alloué vs dépensé, factures rattachées, collègues assignés. Vous savez en un coup d'œil où en est chaque affaire."],
    ['Puis-je suivre ma trésorerie et mes rendez-vous ?', "Oui : suivi des encaissements en temps réel, relances de paiement automatiques, et agenda intégré pour vos rendez-vous — tout au même endroit."],
    ['Assokit fonctionne-t-il sur mobile ?', "Oui, application iOS et Android : gérez devis, factures, projets et RDV depuis votre téléphone, même sur le terrain."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android', 'description' => "Logiciel de gestion tout-en-un pour TPE, PME et indépendants : devis, factures, projets/chantiers, trésorerie, agenda et comptabilité analytique.", 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'], 'inLanguage' => 'fr-FR'];

render_public_head([
    'title'       => 'Logiciel de gestion pour TPE & indépendants · Assokit',
    'description' => 'Assokit, le logiciel de gestion tout-en-un pour TPE, PME et indépendants : devis, factures, relances, projets/chantiers, trésorerie, agenda et comptabilité analytique. Offre gratuite, appli mobile, hébergé en France.',
    'path'        => '/logiciel-gestion-tpe',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel gestion TPE</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> TPE, PME &amp; indépendants</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de gestion</span> tout-en-un de votre entreprise</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Devis, factures, relances, <strong>chantiers, trésorerie et agenda</strong> dans un seul outil. Arrêtez de jongler entre Excel, mails et applis — pilotez votre TPE simplement, sur mobile comme au bureau.</p>
        <div class="lp-hero-cta reveal">
          <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Offre gratuite</span><span>📱 Appli mobile</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Mon entreprise</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Factures du mois</div><div class="s">6 payées · 2 en attente</div></div><span class="v">8 400 €</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🏗️</span><div style="flex:1"><div class="t">Chantier Villa Sud</div><div class="s">Budget suivi · 3 factures</div></div><span class="lp-chip" style="background:#DBEAFE;color:#1D4ED8">62%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">💶</span><div style="flex:1"><div class="t">Trésorerie</div><div class="s">Relances auto activées</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Sain</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">📅</span><div style="flex:1"><div class="t">RDV 14h30</div><div class="s">Rappel activé</div></div><span class="lp-chip" style="background:#F1F5F9;color:#475569">Auj.</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Un seul outil</span><h2 class="pub-h2">Le logiciel qui remplace <em>vos 10 applis</em></h2></div>
    <div class="lp-grid">
      <?php foreach ([
        ['🧾','Devis &amp; factures','Créés en 2 minutes, relances automatiques, export comptable.'],
        ['🏗️','Projets &amp; chantiers','Budget, étapes, factures rattachées, équipe assignée.'],
        ['💶','Trésorerie','Encaissements suivis en temps réel, impayés relancés tout seuls.'],
        ['📊','Comptabilité analytique','La rentabilité réelle de chaque chantier, poste par poste.'],
        ['📅','Agenda &amp; RDV','Vos rendez-vous au même endroit que vos clients et vos affaires.'],
        ['📱','Application mobile','Gérez tout depuis votre téléphone, même sur le terrain.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Choisir son <em>logiciel de gestion TPE</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/pour-tpe" style="color:var(--c-emeraude-dark);font-weight:700;">Assokit pour les TPE/PME →</a>
      <a href="/logiciel-facturation" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de facturation →</a>
    </p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Pilotez votre TPE sereinement</h2>
      <p>Offre gratuite pour démarrer, application mobile incluse. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
