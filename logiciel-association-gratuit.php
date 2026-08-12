<?php
/**
 * logiciel-association-gratuit.php — Page SEO ciblée « logiciel association gratuit ».
 * Route : /logiciel-association-gratuit. Design premium (_landing-premium.php) + FAQPage + Breadcrumb + SoftwareApplication.
 * Ton honnête : Assokit = essai gratuit 14 jours sans CB (PAS un plan gratuit à vie).
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel association gratuit', 'url' => '/logiciel-association-gratuit'],
]);

$faqs = [
    ['Existe-t-il un logiciel de gestion d\'association gratuit ?', "Il existe des outils gratuits qui couvrent une partie du besoin : un tableur (adhérents, budget), une messagerie pour les relances, ou une plateforme de collecte comme HelloAsso, gratuite pour l'association, pour encaisser cotisations et dons en ligne. En revanche, un logiciel de gestion complet et gratuit à vie (adhérents, cotisations, comptabilité, projets et communication réunis) est rare. Assokit propose un essai gratuit de 14 jours, sans carte bancaire, pour tester l'ensemble des fonctionnalités avant de choisir une formule."],
    ['Assokit propose-t-il une offre gratuite ?', "Soyons transparents : Assokit n'est pas un logiciel gratuit à vie. Vous bénéficiez d'un essai gratuit de 14 jours, sans carte bancaire et sans engagement, qui donne accès à toutes les fonctionnalités. Au terme de l'essai, vous choisissez une formule payante, à partir de 29,99 €/mois. Aucun prélèvement automatique : tant que vous n'avez pas saisi de moyen de paiement, rien n'est débité."],
    ['Peut-on gérer une association gratuitement avec un tableur ?', "Oui, un tableur est gratuit et suffit pour une association qui démarre avec quelques adhérents. Ses limites apparaissent avec la croissance : ressaisie entre le fichier des membres, la facturation et la comptabilité, versions perdues, relances manuelles, absence d'historique fiable. Un logiciel dédié fait gagner du temps dès que le nombre d'adhérents, de cotisations ou de projets augmente."],
    ['Quelle est la différence entre un outil gratuit et un logiciel payant pour association ?', "Un outil gratuit couvre en général une brique isolée (un tableur pour les adhérents, une plateforme pour encaisser les paiements). Un logiciel payant comme Assokit réunit ces briques dans un seul espace : une cotisation encaissée met à jour l'adhérent et alimente automatiquement la comptabilité. Le coût de l'abonnement se compare surtout au temps bénévole économisé sur la ressaisie et les relances."],
    ['Combien coûte Assokit après l\'essai gratuit ?', "Après les 14 jours d'essai gratuit, les formules Assokit démarrent à 29,99 €/mois et vont jusqu'à 49,99 €/mois selon les fonctionnalités (comptabilité analytique par projet, options avancées). Le détail est disponible sur la page Tarifs. Vous restez libre d'arrêter à tout moment, sans engagement de durée."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion pour associations loi 1901 : adhérents, cotisations, comptabilité, projets et communication. Essai gratuit de 14 jours, sans carte bancaire.",
    'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '29.99', 'highPrice' => '49.99', 'priceCurrency' => 'EUR', 'offerCount' => '3'],
    'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Logiciel association gratuit : essai gratuit 14 jours',
    'description' => 'Logiciel de gestion d\'association gratuit : options réellement gratuites du marché et essai gratuit Assokit de 14 jours, sans carte bancaire. Le point honnête.',
    'path'        => '/logiciel-association-gratuit',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel association gratuit</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Essai gratuit · sans carte bancaire</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:640px;">Un <span class="lp-grad">logiciel d'association gratuit</span> à tester, en toute transparence</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">On vous dit la vérité : Assokit n'est pas gratuit à vie. Mais vous testez <strong>toutes les fonctionnalités gratuitement pendant 14 jours</strong>, sans carte bancaire ni engagement — adhérents, cotisations, comptabilité, projets. Décidez ensuite en connaissance de cause.</p>
        <div class="lp-hero-cta reveal">
          <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ 14 jours gratuits</span><span>✓ Sans carte bancaire</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Essai gratuit 14 jours</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#D1FAE5">🎁</span><div style="flex:1"><div class="t">Essai gratuit</div><div class="s">14 jours · sans carte bancaire</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Actif</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#E0F2FE">👥</span><div style="flex:1"><div class="t">Adhérents</div><div class="s">Annuaire &amp; import de fichier</div></div><span class="v">✓</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FCE7F3">💸</span><div style="flex:1"><div class="t">Cotisations</div><div class="s">Relances &amp; paiement en ligne</div></div><span class="v">✓</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Comptabilité</div><div class="s">Suivi par projet · exports</div></div><span class="v">✓</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- La vérité sur le gratuit -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Parlons franchement</span>
      <h2 class="pub-h2">« Logiciel association gratuit » : ce que ça veut <em>vraiment</em> dire</h2>
      <p class="pub-section-lead">Beaucoup d'associations cherchent un outil gratuit. C'est légitime — les budgets bénévoles sont serrés. Voici, sans marketing, ce qui existe réellement.</p>
    </div>
    <div class="pub-section-lead reveal" style="max-width:820px;margin:0 auto;text-align:left;">
      <p style="margin:0 0 18px;"><strong>Des outils gratuits existent, mais couvrent chacun une brique.</strong> Un tableur (Google Sheets, LibreOffice Calc) est gratuit et parfait pour tenir la liste des adhérents d'une petite association qui démarre. Une messagerie gratuite permet d'envoyer les convocations. Et une plateforme de collecte comme <strong>HelloAsso</strong>, gratuite pour l'association, permet d'encaisser cotisations et dons en ligne — elle se finance grâce aux contributions volontaires laissées par les donateurs et les payeurs. Chacun de ces outils rend service, gratuitement.</p>
      <p style="margin:0 0 18px;"><strong>Ce qui est rare, c'est un logiciel de gestion complet et gratuit à vie.</strong> Réunir dans un seul espace les adhérents, les cotisations, la comptabilité, les projets, les assemblées générales et la communication a un coût de développement et d'hébergement. Les solutions qui proposent tout cela « gratuitement » se financent généralement d'une autre manière (publicité, frais sur les transactions, fonctionnalités bridées). L'honnêteté commande de le dire clairement.</p>
      <p style="margin:0;"><strong>Assokit joue cartes sur table.</strong> Notre logiciel n'est pas gratuit à vie. Il propose un <strong>essai gratuit de 14 jours, sans carte bancaire</strong>, qui donne accès à toutes les fonctionnalités. Vous testez avec vos vraies données, puis vous choisissez une formule à partir de 29,99 €/mois — ou vous arrêtez, sans aucun prélèvement. Pas de piège, pas de carte demandée à l'inscription.</p>
    </div>
  </div>
</section>

<!-- Les options gratuites du marché -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Panorama</span>
      <h2 class="pub-h2">Les vraies options <em>gratuites</em> pour gérer une association</h2>
      <p class="pub-section-lead">Avant de payer quoi que ce soit, connaissez ce qui est réellement gratuit — et ses limites, en toute honnêteté.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ([
        ['📄','Tableurs (Sheets, Calc)','Gratuits et flexibles pour la liste des adhérents et un budget simple. Limite : ressaisie, versions perdues, relances manuelles quand l\'association grandit.'],
        ['💳','Plateformes de collecte','Des outils comme HelloAsso sont gratuits pour l\'association et encaissent cotisations et dons en ligne. Ils se concentrent sur le paiement, pas sur la gestion complète.'],
        ['✉️','Messagerie &amp; agenda gratuits','Email et calendrier partagés suffisent pour convoquer et informer. Limite : aucun lien avec les adhérents, les cotisations ou la comptabilité.'],
        ['🎁','Essai gratuit d\'un logiciel dédié','Assokit offre 14 jours gratuits, sans carte bancaire, pour tester la gestion tout-en-un avant de décider. Ni piège, ni engagement.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Pourquoi un outil complet fait gagner du temps -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Le vrai calcul</span>
      <h2 class="pub-h2">Gratuit ne veut pas dire <em>sans coût</em></h2>
      <p class="pub-section-lead">Le coût le plus lourd d'une association n'est pas l'abonnement d'un logiciel : ce sont les heures bénévoles passées à recopier les mêmes données d'un outil gratuit à l'autre.</p>
    </div>
    <ul class="lp-checks reveal">
      <?php foreach ([
        'Fini la ressaisie entre le tableur, la facturation et la compta',
        'Une cotisation encaissée met à jour l\'adhérent et la comptabilité',
        'Relances de cotisations automatiques, plus de suivi manuel',
        'AG préparées : convocations, émargement, procès-verbaux',
        'Communication (newsletters, comptes-rendus) rédigée par l\'IA',
        'Hébergement en France · conforme RGPD · export à tout moment',
      ] as $f): ?>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= $f ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/logiciel-association" class="lp-btn lp-btn-glass">Le logiciel association</a>
      <a href="/logiciel-cotisation-association" class="lp-btn lp-btn-glass">Gérer les cotisations</a>
      <a href="/logiciel-adherents" class="lp-btn lp-btn-glass">Gérer les adhérents</a>
      <a href="/tarifs" class="lp-btn lp-btn-glass">Voir les tarifs</a>
    </div>
  </div>
</section>

<!-- Comment bien démarrer -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Bien démarrer</span>
      <h2 class="pub-h2">Testez gratuitement, décidez <em>ensuite</em></h2>
      <p class="pub-section-lead">L'essai gratuit de 14 jours est fait pour ça : vérifier que l'outil vous convient avant tout paiement.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ([
        ['1','Créez votre espace gratuit','Inscription en quelques minutes, sans carte bancaire. Vous accédez immédiatement à toutes les fonctionnalités pendant 14 jours.'],
        ['2','Importez vos données','Ajoutez ou importez vos adhérents, paramétrez vos cotisations et vos projets. Testez avec la réalité de votre association, pas des exemples.'],
        ['3','Mesurez le temps gagné','Encaissez une cotisation, envoyez une relance, générez un compte-rendu par l\'IA. Comparez honnêtement avec vos outils gratuits actuels.'],
        ['4','Choisissez librement','À la fin de l\'essai, prenez une formule dès 29,99 €/mois si l\'outil vous convient — ou arrêtez, sans aucun prélèvement ni engagement.'],
      ] as $s): ?>
        <div class="lp-card reveal"><div class="lp-ic" style="font-weight:800;font-size:20px;color:var(--c-emeraude-dark)"><?= $s[0] ?></div><h3><?= $s[1] ?></h3><p><?= $s[2] ?></p></div>
      <?php endforeach; ?>
    </div>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/guide-association-loi-1901" class="lp-btn lp-btn-glass">Guide association loi 1901</a>
      <a href="/alternative-helloasso" class="lp-btn lp-btn-glass">Assokit &amp; HelloAsso</a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Logiciel d'association <em>gratuit</em> : vos questions</h2></div>
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
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">14 jours pour vous faire votre avis</h2>
      <p>Essayez Assokit gratuitement, sans carte bancaire et sans engagement. Vous ne payez que si l'outil vous fait vraiment gagner du temps.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
      <p style="position:relative;margin-top:14px;font-size:13px;color:rgba(255,255,255,.75);">Sans carte bancaire · sans engagement</p>
    </div>
  </div>
</section>

<?php
render_public_footer();
