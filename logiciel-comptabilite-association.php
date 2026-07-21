<?php
/**
 * logiciel-comptabilite-association.php — Page SEO ciblée « logiciel comptabilité association ».
 * Route : /logiciel-comptabilite-association. Design premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel comptabilité association', 'url' => '/logiciel-comptabilite-association'],
]);

$faqs = [
    ['Quel logiciel de comptabilité pour une association ?', "Le meilleur logiciel de comptabilité pour une association loi 1901 relie la compta au quotidien : factures, cotisations et dépenses se comptabilisent automatiquement, avec un plan comptable adapté et une comptabilité analytique par projet. C'est exactement ce que fait Assokit."],
    ['La comptabilité analytique est-elle incluse ?', "Oui, dès l'offre Pro : bilan par projet et par poste, en temps réel, exportable en PDF et Excel. Cela remplace une prestation externalisée d'environ 900 € par an. Votre expert-comptable n'a plus qu'à valider."],
    ['Faut-il des connaissances comptables pour l\'utiliser ?', "Non. Assokit est pensé pour les trésoriers bénévoles : catégorisation guidée, justificatifs centralisés, préparation automatique du bilan. Pas besoin d'être comptable."],
    ['Peut-on exporter les comptes pour l\'expert-comptable ?', "Oui : exports PDF et Excel du bilan analytique avec les pièces justificatives rattachées. Vous transmettez un dossier propre et daté, prêt à valider."],
    ['Le logiciel gère-t-il aussi la facturation et les cotisations ?', "Oui, tout est lié : devis, factures, relances de paiement, encaissement des cotisations en ligne — chaque opération alimente automatiquement votre comptabilité."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de comptabilité pour association loi 1901 : facturation, cotisations, comptabilité analytique par projet, exports pour l'expert-comptable.",
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'], 'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Logiciel de comptabilité pour association loi 1901 · Assokit',
    'description' => 'Assokit, le logiciel de comptabilité pour association : facturation, cotisations, comptabilité analytique par projet, exports PDF/Excel pour l\'expert-comptable. Simple pour les trésoriers bénévoles, hébergé en France.',
    'path'        => '/logiciel-comptabilite-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel comptabilité association</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Comptabilité d'association simplifiée</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de comptabilité</span> pensé pour les associations</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Factures, cotisations, dépenses et <strong>comptabilité analytique par projet</strong> se construisent automatiquement au fil de votre activité. Un dossier propre pour votre expert-comptable, sans être comptable vous-même.</p>
        <div class="lp-hero-cta reveal">
          <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/comptabilite-analytique" class="lp-btn lp-btn-glass">La compta analytique incluse</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Compta analytique incluse</span><span>💶 ~900 €/an économisés</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Comptabilité</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Bilan par projet</div><div class="s">Recettes / dépenses ventilées</div></div><span class="v">Temps réel</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Factures &amp; cotisations</div><div class="s">Comptabilisées automatiquement</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Auto</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">📁</span><div style="flex:1"><div class="t">Pièces justificatives</div><div class="s">Rattachées &amp; centralisées</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Prêt</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">✅</span><div style="flex:1"><div class="t">Export expert-comptable</div><div class="s">PDF &amp; Excel en 1 clic</div></div><span class="v">-900€/an</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ce que fait le logiciel</span><h2 class="pub-h2">Une comptabilité qui se tient <em>toute seule</em></h2>
      <p class="pub-section-lead">Fini la ressaisie en fin d'année : chaque opération de votre association alimente directement vos comptes.</p></div>
    <div class="lp-grid">
      <?php foreach ([
        ['🧾','Facturation intégrée','Devis, factures, relances de paiement — comptabilisés automatiquement.'],
        ['💸','Cotisations en ligne','Encaissement, reçus et suivi ; tout remonte dans la compta.'],
        ['📊','Comptabilité analytique','Bilan par projet et par poste, en temps réel, incluse dès l\'offre Pro.'],
        ['📁','Justificatifs centralisés','Chaque pièce rattachée à la bonne opération, exportable en archive.'],
        ['📅','Échéances &amp; alertes','Ne manquez plus une date fiscale ou administrative clé.'],
        ['✅','Prêt pour le comptable','Export PDF / Excel daté — votre expert-comptable n\'a plus qu\'à valider.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal">
      <span class="pub-section-eyebrow" style="position:relative;color:#A7F3D0;">L'économie</span>
      <div class="big">−900 € / an</div>
      <p>Le coût d'une comptabilité analytique externalisée. Avec Assokit, elle est <strong>incluse dès l'offre Pro</strong> — l'argent retourne à vos projets, pas à la paperasse.</p>
      <a href="/comptabilite-analytique" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Comment ça marche
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Votre <em>comptabilité d'association</em>, simplement</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;"><a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">← Voir le logiciel de gestion complet pour associations</a></p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Simplifiez la compta de votre association</h2>
      <p>Offre gratuite pour démarrer, comptabilité analytique incluse dès l'offre Pro. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/tarifs" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
