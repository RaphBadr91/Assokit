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
    ['Existe-t-il un logiciel de gestion d\'association gratuit ?', "Oui : Assokit propose un essai gratuit, sans carte bancaire, pour gérer votre association (adhérents, cotisations, factures). Vous testez toutes les fonctionnalités gratuitement pendant 14 jours, sans carte bancaire, puis vous choisissez votre formule."],
    ['Un logiciel association remplace-t-il Excel et les autres outils ?', "Complètement. Assokit remplace le tableur des adhérents, l'outil de facturation, l'agenda, la messagerie et le logiciel de compta — un seul outil, plus de ressaisie ni de fichiers perdus."],
    ['Le logiciel gère-t-il la comptabilité de l\'association ?', "Oui. Assokit inclut la facturation, le suivi des cotisations et la comptabilité analytique par projet (incluse dès l'offre Pro), exportable pour votre expert-comptable — soit jusqu'à ~900 € d'économie par an estimée."],
    ['Mes données sont-elles hébergées en France ?', "Oui, 100 %. Serveurs en France (Clermont-Ferrand), conformité RGPD, double authentification et export de vos données à tout moment. Vos données restent les vôtres."],
    ['Comment gérer une association loi 1901 ?', "Gérer une association loi 1901 repose sur quelques piliers : des statuts à jour, un bureau (président, trésorier, secrétaire) qui prend les décisions, une assemblée générale annuelle, la déclaration des changements en préfecture, le suivi des adhérents et de leurs cotisations, et une comptabilité claire pour les subventions. Un logiciel comme Assokit centralise tout cela : vous tenez l'annuaire, encaissez les cotisations, préparez vos AG et suivez le budget depuis un seul espace, sans ressaisie ni fichiers éparpillés."],
    ['Quel logiciel pour gérer une petite association ?', "Pour une petite association, mieux vaut un outil simple et abordable qui couvre l'essentiel sans usine à gaz. Assokit propose un essai gratuit, sans carte bancaire, qui gère déjà adhérents, cotisations et factures. Vous testez tout gratuitement pendant 14 jours, sans carte bancaire, puis vous choisissez votre formule ; idéal ce qui en fait un choix idéal pour un club, une amicale ou une association de quartier gérée par des bénévoles."],
    ['Assokit est-il adapté aux associations sportives ou culturelles ?', "Oui. Assokit s'adapte aussi bien à un club sportif (licences, équipes, cotisations saisonnières, événements) qu'à une association culturelle (ateliers, adhésions, billetterie d'événements, subventions). La gestion des adhérents par rôles et espaces, le suivi des projets et la comptabilité analytique conviennent à tous les objets associatifs relevant de la loi 1901."],
    ['Peut-on gérer les adhérents et la comptabilité au même endroit ?', "C'est précisément l'intérêt d'un logiciel tout-en-un. Dans Assokit, une cotisation encaissée met à jour l'adhérent et alimente automatiquement la comptabilité : plus besoin de reporter les montants d'un tableur vers un logiciel de compta. Adhérents, cotisations, factures et comptabilité analytique par projet vivent dans le même outil, avec un export prêt pour votre expert-comptable."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion tout-en-un pour les associations loi 1901 : adhérents, cotisations, comptabilité, projets et communication.",
    'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '29.99', 'highPrice' => '49.99', 'priceCurrency' => 'EUR', 'offerCount' => '3'],
    'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Logiciel de gestion d\'association loi 1901 · Assokit',
    'description' => 'Logiciel de gestion pour association loi 1901 : adhérents, cotisations, comptabilité, projets et communication en un seul outil. Essai gratuit, hébergé en France.',
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
          <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Essai gratuit</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span><span>🔒 RGPD</span></div>
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

<!-- Comment ça marche -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Comment ça marche</span>
      <h2 class="pub-h2">Votre association gérée en <em>4 étapes</em></h2>
      <p class="pub-section-lead">De la création de votre espace au pilotage quotidien, le logiciel accompagne toute la vie de votre association loi 1901 — sans compétence technique.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ([
        ['1','Créez votre association','Ouvrez votre espace en quelques minutes : nom, objet, statuts et composition du bureau (président, trésorier, secrétaire). Vos informations légales et vos statuts sont centralisés et prêts pour les démarches.'],
        ['2','Importez vos adhérents','Ajoutez vos adhérents un par un ou importez votre fichier existant. Chacun retrouve son espace, son rôle et son historique. L\'annuaire remplace définitivement le tableur partagé.'],
        ['3','Gérez cotisations, projets &amp; AG','Encaissez les cotisations en ligne avec relances automatiques, suivez le budget de vos projets et de vos subventions, préparez vos assemblées générales : convocations, émargement et procès-verbaux.'],
        ['4','Pilotez au quotidien','Suivez adhésions, trésorerie et communication depuis un tableau de bord unique. La comptabilité se met à jour à chaque cotisation et vos comptes-rendus se rédigent grâce à l\'IA intégrée.'],
      ] as $s): ?>
        <div class="lp-card reveal"><div class="lp-ic" style="font-weight:800;font-size:20px;color:var(--c-emeraude-dark)"><?= $s[0] ?></div><h3><?= $s[1] ?></h3><p><?= $s[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Comparatif -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Comparatif</span>
      <h2 class="pub-h2">Tableurs, logiciel classique ou <em>Assokit</em> ?</h2>
      <p class="pub-section-lead">Ce que vous gagnez à réunir toute la gestion de votre association dans un seul logiciel plutôt que d'empiler les outils.</p>
    </div>
    <div class="reveal" style="overflow-x:auto;border:1px solid var(--c-border);border-radius:20px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.05);">
      <table style="width:100%;border-collapse:collapse;min-width:640px;font-size:14px;">
        <thead>
          <tr>
            <th scope="col" style="text-align:left;padding:16px 18px;font-weight:700;color:var(--c-text-2);border-bottom:2px solid var(--c-border);">Fonction</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:700;color:var(--c-text-2);border-bottom:2px solid var(--c-border);">Tableurs + outils séparés</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:700;color:var(--c-text-2);border-bottom:2px solid var(--c-border);">Logiciel asso classique</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:800;color:#fff;background:#059669;border-bottom:2px solid #059669;">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Adhérents &amp; cotisations','Manuel, ressaisie','Oui','Oui, avec paiement en ligne'],
            ['Projets &amp; budget','Non','Partiel','Suivi étape par étape'],
            ['AG &amp; registres','Fichiers séparés','Rarement','Convocations, votes, PV'],
            ['Communication / IA','Non','Non','Emails &amp; comptes-rendus par IA'],
            ['Comptabilité analytique','Non','Option payante','Incluse par projet (offre Pro)'],
            ['Hébergement en France','Variable','Selon l\'éditeur','100 % France · RGPD'],
            ['Application mobile','Non','Rare','iOS &amp; Android'],
            ['Prix','« Gratuit » mais chronophage','Abonnement annuel','Dès 29,99 €/mois · essai 14 j'],
          ] as $r): ?>
          <tr>
            <th scope="row" style="text-align:left;padding:14px 18px;font-weight:700;color:var(--c-encre);border-bottom:1px solid var(--c-border);"><?= $r[0] ?></th>
            <td style="text-align:center;padding:14px 18px;color:var(--c-text-3);border-bottom:1px solid var(--c-border);"><?= $r[1] ?></td>
            <td style="text-align:center;padding:14px 18px;color:var(--c-text-2);border-bottom:1px solid var(--c-border);"><?= $r[2] ?></td>
            <td style="text-align:center;padding:14px 18px;font-weight:700;color:var(--c-emeraude-dark);background:rgba(5,150,105,.06);border-bottom:1px solid var(--c-border);"><?= $r[3] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Pourquoi Assokit -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi Assokit</span><h2 class="pub-h2">Le logiciel association <em>français</em>, simple et complet</h2></div>
    <ul class="lp-checks reveal">
      <?php foreach (['Pensé pour les associations loi 1901','Essai gratuit, sans carte bancaire','Hébergé en France · conforme RGPD','Comptabilité analytique (offre Pro)','IA intégrée pour la communication','Application mobile iOS &amp; Android','Support humain français','Export de vos données à tout moment'] as $f): ?>
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

<!-- Bénéfices développés -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Les bénéfices</span>
      <h2 class="pub-h2">Moins de paperasse, <em>plus de vie associative</em></h2>
    </div>
    <div class="pub-section-lead reveal" style="max-width:820px;margin:0 auto;text-align:left;">
      <p style="margin:0 0 18px;"><strong>Une gouvernance sans paperasse.</strong> Diriger une association loi 1901 impose un cadre : statuts à jour, décisions du bureau, tenue de l'assemblée générale et du registre des délibérations, justification des subventions. Un bon logiciel de gestion d'association transforme ces obligations en gestes simples. Les convocations partent en un clic, l'émargement et les votes sont horodatés, les procès-verbaux s'archivent automatiquement. Vous restez conforme sans y passer vos soirées, et vous retrouvez chaque document quand un financeur ou un contrôle le demande.</p>
      <p style="margin:0 0 18px;"><strong>Un seul outil, plus de ressaisie.</strong> Le vrai coût d'une association, ce sont les dizaines d'heures perdues à recopier les mêmes informations d'un tableur d'adhérents vers un outil de facturation, puis vers un logiciel de comptabilité. Assokit réunit adhérents, cotisations, projets et compta au même endroit : une cotisation encaissée met à jour l'adhérent et alimente directement la comptabilité analytique. Fini les fichiers en double, les versions perdues et les écarts de trésorerie inexpliqués — vos chiffres sont justes en permanence.</p>
      <p style="margin:0;"><strong>Du temps bénévole rendu à l'essentiel.</strong> Dans une association, chaque heure administrative est une heure prise sur les projets, les adhérents et le terrain. En automatisant les relances de cotisations, la communication (newsletters et comptes-rendus rédigés par l'IA) et le suivi budgétaire, le logiciel libère vos bénévoles des tâches répétitives. Résultat : un bureau plus serein, des adhérents mieux suivis et une association qui consacre son énergie à sa mission plutôt qu'à sa gestion.</p>
    </div>
  </div>
</section>

<!-- Ressources -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Ressources</span>
      <h2 class="pub-h2">Pour aller plus loin dans la <em>gestion d'association</em></h2>
      <p class="pub-section-lead">Nos guides pratiques pour diriger votre association loi 1901, et les outils Assokit dédiés à chaque besoin.</p>
    </div>
    <div class="lp-grid">
      <a href="/blog/bureau-association-composition-role" class="lp-card reveal" style="text-decoration:none;"><div class="lp-ic">🏛️</div><h3>Le bureau d'une association</h3><p>Composition, rôles du président, du trésorier et du secrétaire, et bonnes pratiques de gouvernance.</p></a>
      <a href="/blog/adhesion-association-regles-bonnes-pratiques" class="lp-card reveal" style="text-decoration:none;"><div class="lp-ic">🤝</div><h3>Adhésion : règles &amp; pratiques</h3><p>Conditions d'adhésion, cotisations, droits des membres : ce que prévoit la loi 1901.</p></a>
      <a href="/blog/radier-membre-association-procedure" class="lp-card reveal" style="text-decoration:none;"><div class="lp-ic">📋</div><h3>Radier un membre</h3><p>La procédure pour exclure ou radier un adhérent dans le respect des statuts.</p></a>
      <a href="/blog/rna-siren-association-tout-comprendre" class="lp-card reveal" style="text-decoration:none;"><div class="lp-ic">🔎</div><h3>RNA &amp; SIREN de l'association</h3><p>Numéro RNA, SIREN, immatriculation : tout comprendre pour les démarches et subventions.</p></a>
    </div>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/blog?categorie=associations" class="lp-btn lp-btn-glass">Tous nos articles</a>
      <a href="/logiciel-adherents" class="lp-btn lp-btn-glass">Logiciel adhérents</a>
      <a href="/logiciel-cotisation-association" class="lp-btn lp-btn-glass">Logiciel cotisations</a>
      <a href="/logiciel-comptabilite-association" class="lp-btn lp-btn-glass">Logiciel comptabilité</a>
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
      <p>Créez votre espace association en quelques minutes. Essai gratuit, sans carte bancaire, sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
