<?php
/**
 * logiciel-facturation.php — Page SEO ciblée « logiciel de facturation » / « logiciel devis facture »
 * (+ facturation électronique). Route : /logiciel-facturation. Premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel de facturation', 'url' => '/logiciel-facturation'],
]);

$faqs = [
    ['Quel logiciel de facturation choisir pour une association ou une TPE ?', "Un bon logiciel de facturation crée devis et factures conformes en quelques clics, relance les impayés automatiquement et se relie à votre comptabilité. Assokit fait tout ça, avec votre logo, la numérotation automatique et l'export comptable — pensé pour les associations et les TPE françaises."],
    ['Le logiciel gère-t-il devis et factures ?', "Oui : devis (avec signature électronique), factures HT/TVA/TTC, factures récurrentes, avoirs, numérotation automatique et export PDF avec votre logo. Le tout suivi en temps réel."],
    ['Assokit est-il prêt pour la facturation électronique ?', "Oui, Assokit vous prépare à la réforme de la facturation électronique : émission de factures conformes et centralisation de vos pièces, pour aborder l'échéance sereinement."],
    ['Peut-on automatiser les relances d\'impayés ?', "Oui. Les relances de paiement partent automatiquement selon le calendrier que vous définissez. Vous êtes payé plus vite, sans courir après vos clients ou adhérents."],
    ['La facturation est-elle reliée à la comptabilité ?', "Oui : chaque facture émise ou encaissée alimente automatiquement votre comptabilité et votre bilan analytique. Aucune double saisie."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Assokit', 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web, iOS, Android', 'description' => "Logiciel de facturation pour association et TPE : devis, factures, relances automatiques, export comptable, prêt pour la facturation électronique.", 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'], 'inLanguage' => 'fr-FR'];

render_public_head([
    'title'       => 'Logiciel de facturation pour association & TPE · Assokit',
    'description' => 'Assokit, le logiciel de facturation pour association et TPE : devis, factures, relances automatiques, factures récurrentes, export comptable et prêt pour la facturation électronique. Offre gratuite, hébergé en France.',
    'path'        => '/logiciel-facturation',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel de facturation</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Devis &amp; factures en 2 minutes</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de facturation</span> simple pour assos &amp; TPE</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Créez devis et factures pro avec votre logo, envoyez les <strong>relances automatiquement</strong>, suivez vos encaissements en temps réel. Export comptable inclus et prêt pour la facturation électronique.</p>
        <div class="lp-hero-cta reveal">
          <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Devis signés</span><span>✓ Relances auto</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Facturation</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Facture #2026-142</div><div class="s">Client Dupont · logo inclus</div></div><span class="v">1 250 €</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">🔔</span><div style="flex:1"><div class="t">Relance automatique</div><div class="s">Échéance dépassée · J+7</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">Auto</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">✍️</span><div style="flex:1"><div class="t">Devis signé en ligne</div><div class="s">Signature électronique</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Signé</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">📊</span><div style="flex:1"><div class="t">Export comptable</div><div class="s">PDF / Excel · lié à la compta</div></div><span class="lp-chip" style="background:#DCFCE7;color:#166534">1 clic</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Tout pour facturer</span><h2 class="pub-h2">Du devis à l'encaissement, <em>sans effort</em></h2></div>
    <div class="lp-grid">
      <?php foreach ([
        ['🧾','Devis &amp; factures','HT / TVA / TTC, votre logo, numérotation automatique, export PDF.'],
        ['✍️','Signature électronique','Vos devis signés en ligne, sans impression ni scan.'],
        ['🔁','Factures récurrentes','Abonnements et cotisations facturés automatiquement, chaque mois.'],
        ['🔔','Relances automatiques','Les impayés relancés tout seuls. Vous êtes payé plus vite.'],
        ['🇪🇺','Facture électronique','Prêt pour la réforme : factures conformes, pièces centralisées.'],
        ['📊','Export comptable','Chaque facture alimente votre comptabilité analytique.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Choisir son <em>logiciel de facturation</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/pour-tpe" style="color:var(--c-emeraude-dark);font-weight:700;">Pour les TPE/PME →</a>
      <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">Pour les associations →</a>
    </p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Facturez proprement, dès aujourd'hui</h2>
      <p>Offre gratuite pour démarrer. Devis, factures et relances en quelques clics.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
