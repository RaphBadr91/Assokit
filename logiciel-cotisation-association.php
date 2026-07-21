<?php
/**
 * logiciel-cotisation-association.php — Page SEO ciblée « cotisation association en ligne » /
 * « gestion des cotisations » / « collecte cotisation loi 1901 » (cluster identifié via Search Console).
 * Route : /logiciel-cotisation-association. Premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Gestion des cotisations', 'url' => '/logiciel-cotisation-association'],
]);

$faqs = [
    ['Comment gérer les cotisations d\'une association en ligne ?', "Avec un logiciel dédié comme Assokit : chaque adhérent règle sa cotisation en ligne par carte bancaire, reçoit un reçu automatique, et le trésorier suit les paiements en temps réel. Les relances des retardataires partent toutes seules."],
    ['Peut-on encaisser les cotisations par carte bancaire ?', "Oui. Assokit permet le paiement en ligne des cotisations : l'adhérent paie en quelques clics, l'argent est tracé, et la cotisation est comptabilisée automatiquement. Fini les chèques à courir après."],
    ['Comment automatiser les relances de cotisation ?', "Le logiciel envoie des relances automatiques aux adhérents non à jour, selon un calendrier que vous définissez. Vous ne courez plus après personne, et le taux de paiement grimpe."],
    ['Peut-on créer plusieurs niveaux ou tarifs de cotisation ?', "Oui : tarifs différents par catégorie de membre (étudiant, famille, bienfaiteur…), cotisations annuelles ou ponctuelles, avec reçus adaptés. Tout est paramétrable."],
    ['Les cotisations alimentent-elles la comptabilité ?', "Oui, automatiquement : chaque cotisation encaissée remonte dans votre comptabilité et votre bilan analytique. Aucune double saisie."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion et de collecte des cotisations pour association loi 1901 : paiement en ligne, relances automatiques, reçus, suivi en temps réel.",
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'], 'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Gestion & collecte des cotisations d\'association en ligne · Assokit',
    'description' => 'Gérez et collectez les cotisations de votre association en ligne : paiement par carte bancaire, relances automatiques, reçus, plusieurs tarifs et suivi en temps réel. Logiciel simple, hébergé en France.',
    'path'        => '/logiciel-cotisation-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Gestion des cotisations</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Cotisations d'association</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Collectez vos <span class="lp-grad">cotisations en ligne</span>, sans courir après personne</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Paiement par carte bancaire, <strong>relances automatiques</strong>, reçus générés, plusieurs tarifs et suivi en temps réel. Vos cotisations rentrent toutes seules — et alimentent directement votre comptabilité.</p>
        <div class="lp-hero-cta reveal">
          <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Paiement en ligne</span><span>✓ Relances auto</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Cotisations</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#D1FAE5">✅</span><div style="flex:1"><div class="t">231 / 248 à jour</div><div class="s">Suivi en temps réel</div></div><span class="v">94%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">💳</span><div style="flex:1"><div class="t">Paiement en ligne</div><div class="s">Carte bancaire · reçu auto</div></div><span class="lp-chip" style="background:#DBEAFE;color:#1D4ED8">CB</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">🔔</span><div style="flex:1"><div class="t">Relances automatiques</div><div class="s">17 retardataires relancés</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">Auto</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🎟️</span><div style="flex:1"><div class="t">3 tarifs</div><div class="s">Étudiant · Normal · Bienfaiteur</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Flexible</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Tout pour vos cotisations</span><h2 class="pub-h2">De l'appel à cotisation au <em>reçu</em>, en automatique</h2></div>
    <div class="lp-grid">
      <?php foreach ([
        ['💳','Paiement en ligne','Vos adhérents règlent par carte bancaire en quelques clics. L\'argent est tracé.'],
        ['🔔','Relances automatiques','Les retardataires sont relancés tout seuls, selon votre calendrier.'],
        ['🧾','Reçus générés','Chaque cotisation génère un reçu envoyé automatiquement à l\'adhérent.'],
        ['🎟️','Plusieurs tarifs','Étudiant, famille, bienfaiteur… créez autant de tarifs que nécessaire.'],
        ['📊','Suivi en temps réel','Tableau de bord : qui est à jour, qui doit encore payer, combien collecté.'],
        ['🔗','Lié à la compta','Chaque encaissement alimente directement votre comptabilité analytique.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Gérer les <em>cotisations</em> de votre association</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;"><a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">← Découvrir le logiciel de gestion complet pour associations</a></p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Fini de courir après les cotisations</h2>
      <p>Mettez la collecte en pilote automatique. Offre gratuite pour démarrer, sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
