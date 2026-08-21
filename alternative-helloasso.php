<?php
/**
 * alternative-helloasso.php — Page SEO ciblée « alternative HelloAsso ».
 * Route : /alternative-helloasso. Design premium (_landing-premium.php) + FAQPage + Breadcrumb + SoftwareApplication.
 * Ton FACTUEL et HONNÊTE (publicité comparative, art. L122-1 C. conso) :
 *   - HelloAsso = plateforme française de paiement/collecte pour les associations, gratuite pour l'association
 *     (financée par les contributions volontaires des donateurs/payeurs). Faits notoires uniquement, aucun dénigrement.
 *   - Assokit = gestion complète (adhérents, cotisations, compta, projets, communication).
 *   - Positionnement honnête : complémentarité possible ; beaucoup d'associations utilisent les deux.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Alternative à HelloAsso', 'url' => '/alternative-helloasso'],
]);

$faqs = [
    ['HelloAsso est-il gratuit ?', "Oui : HelloAsso est une plateforme française de paiement et de collecte gratuite pour l'association. Elle ne prélève pas de commission sur les montants collectés et se finance grâce aux contributions volontaires (pourboires) laissées par les donateurs et les payeurs au moment du paiement. C'est un modèle notoire et transparent, adapté à la collecte de dons, aux adhésions payantes et à la billetterie."],
    ['Assokit est-il une alternative à HelloAsso ?', "Cela dépend de votre besoin. HelloAsso est spécialisé dans la collecte et le paiement en ligne. Assokit, lui, est un logiciel de gestion complet de l'association : adhérents, cotisations, comptabilité, projets, assemblées générales et communication. Si vous cherchez surtout à gérer toute la vie de votre association dans un seul outil, Assokit est une alternative pertinente. Si votre seul besoin est d'encaisser des dons ponctuels, une plateforme de collecte peut suffire."],
    ['Peut-on utiliser Assokit et HelloAsso ensemble ?', "Oui, et beaucoup d'associations le font. Les deux outils sont complémentaires : HelloAsso pour organiser une campagne de dons ou une billetterie d'événement, Assokit pour gérer au quotidien les adhérents, les cotisations, la comptabilité et les projets. Vous pouvez tout à fait encaisser via une plateforme de collecte et centraliser la gestion et le suivi dans Assokit."],
    ['Quelle est la différence entre Assokit et HelloAsso ?', "La différence tient au périmètre. HelloAsso couvre le paiement et la collecte (dons, adhésions en ligne, billetterie), gratuitement pour l'association. Assokit couvre la gestion complète : annuaire des adhérents, suivi et relances de cotisations, comptabilité analytique par projet, préparation des AG et communication assistée par IA. L'un se concentre sur l'encaissement, l'autre sur le pilotage global de l'association."],
    ['Assokit est-il gratuit comme HelloAsso ?', "Non, le modèle est différent. HelloAsso est gratuit pour l'association et financé par les contributions volontaires des payeurs. Assokit est un logiciel de gestion sur abonnement, à partir de 29,99 €/mois, avec un essai gratuit de 14 jours sans carte bancaire pour le tester. Les deux modèles répondent à des besoins distincts : la collecte d'un côté, la gestion complète de l'autre."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion complet pour associations loi 1901 : adhérents, cotisations, comptabilité, projets et communication. Complémentaire d'une plateforme de collecte comme HelloAsso.",
    // 'offers' retiré (évite l'exigence Google review/aggregateRating sans avis notés réels) 
    'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Alternative à HelloAsso : Assokit, la gestion complète',
    'description' => 'Alternative à HelloAsso pour la gestion complète de votre association : adhérents, cotisations, comptabilité, projets. Les deux outils sont complémentaires.',
    'path'        => '/alternative-helloasso',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Alternative à HelloAsso</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Collecte &amp; gestion : deux besoins</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:640px;">Assokit, l'<span class="lp-grad">alternative complète</span> pour gérer toute votre association</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">HelloAsso est une plateforme française de paiement et de collecte, gratuite pour l'association. Assokit va plus loin sur la <strong>gestion complète</strong> : adhérents, cotisations, comptabilité, projets et communication. Les deux se complètent — beaucoup d'associations utilisent les deux.</p>
        <div class="lp-hero-cta reveal">
          <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Essai gratuit 14 jours</span><span>✓ Sans carte bancaire</span><span>🇫🇷 Hébergé en France</span><span>🔒 RGPD</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Gestion complète</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#E0F2FE">👥</span><div style="flex:1"><div class="t">248 adhérents</div><div class="s">Annuaire · rôles &amp; espaces</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">+12</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FCE7F3">💸</span><div style="flex:1"><div class="t">Cotisations</div><div class="s">Relances &amp; suivi en temps réel</div></div><span class="v">94%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Comptabilité</div><div class="s">Bilan par projet · export compta</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">À jour</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🗳️</span><div style="flex:1"><div class="t">Assemblées générales</div><div class="s">Convocations, votes, PV</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Prêt</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Deux outils, deux rôles -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Comprendre chaque outil</span>
      <h2 class="pub-h2">Collecte de dons ou <em>gestion complète</em> : deux besoins différents</h2>
      <p class="pub-section-lead">Avant de choisir, il faut savoir ce que chaque solution fait de mieux. Voici un positionnement factuel et honnête, sans jugement de valeur.</p>
    </div>
    <div class="pub-section-lead reveal" style="max-width:820px;margin:0 auto;text-align:left;">
      <p style="margin:0 0 18px;"><strong>HelloAsso : le paiement et la collecte.</strong> HelloAsso est une plateforme française bien connue des associations. Son cœur de métier est le paiement en ligne : campagnes de dons, adhésions payantes, billetterie d'événements, crowdfunding. Elle est <strong>gratuite pour l'association</strong> et se finance grâce aux contributions volontaires (pourboires) que les donateurs et les payeurs choisissent de laisser au moment de régler. C'est un modèle transparent et adapté quand le besoin est avant tout d'encaisser.</p>
      <p style="margin:0 0 18px;"><strong>Assokit : la gestion complète de l'association.</strong> Assokit ne se limite pas à l'encaissement. C'est un logiciel qui réunit l'annuaire des adhérents, le suivi et les relances de cotisations, la comptabilité analytique par projet, la préparation des assemblées générales et la communication assistée par IA. Là où une plateforme de collecte encaisse un paiement, Assokit tient à jour l'adhérent concerné, alimente la comptabilité et conserve l'historique — tout au même endroit.</p>
      <p style="margin:0;"><strong>Les deux sont complémentaires.</strong> Chercher une « alternative à HelloAsso » ne veut pas toujours dire remplacer : selon votre besoin, Assokit peut <strong>compléter</strong> une plateforme de collecte (vous encaissez d'un côté, vous pilotez de l'autre) ou la <strong>remplacer</strong> si vous préférez centraliser paiements et gestion dans un seul outil. Beaucoup d'associations utilisent les deux, sans que l'un exclue l'autre.</p>
    </div>
  </div>
</section>

<!-- Comparatif neutre -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Positionnement</span>
      <h2 class="pub-h2">À quoi sert <em>chaque outil</em> ?</h2>
      <p class="pub-section-lead">Un repère factuel du périmètre de chaque solution. L'objectif : vous aider à choisir selon votre besoin réel, pas à désigner un « gagnant ».</p>
    </div>
    <div class="reveal" style="overflow-x:auto;border:1px solid var(--c-border);border-radius:20px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.05);">
      <table style="width:100%;border-collapse:collapse;min-width:640px;font-size:14px;">
        <thead>
          <tr>
            <th scope="col" style="text-align:left;padding:16px 18px;font-weight:700;color:var(--c-text-2);border-bottom:2px solid var(--c-border);">Besoin</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:700;color:var(--c-text-2);border-bottom:2px solid var(--c-border);">HelloAsso<br><span style="font-weight:500;font-size:12px;color:var(--c-text-3);">Collecte de dons / paiements</span></th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:800;color:#fff;background:#059669;border-bottom:2px solid #059669;">Assokit<br><span style="font-weight:500;font-size:12px;color:rgba(255,255,255,.85);">Gestion complète de l'association</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Paiement &amp; collecte de dons en ligne','Cœur de l\'offre','Cotisations en ligne intégrées'],
            ['Billetterie d\'événements','Oui','Suivi des événements &amp; participants'],
            ['Annuaire &amp; gestion des adhérents','—','Annuaire complet, rôles &amp; espaces'],
            ['Cotisations : suivi &amp; relances','—','Relances automatiques &amp; suivi en temps réel'],
            ['Comptabilité de l\'association','—','Comptabilité analytique par projet · exports'],
            ['Projets, subventions &amp; budget','—','Suivi étape par étape, budget alloué / dépensé'],
            ['Assemblées générales &amp; PV','—','Convocations, émargement, votes, procès-verbaux'],
            ['Communication assistée par IA','—','Newsletters &amp; comptes-rendus rédigés par l\'IA'],
            ['Modèle','Gratuit pour l\'association (contributions volontaires des payeurs)','Abonnement dès 29,99 €/mois · essai 14 j sans CB'],
          ] as $r): ?>
          <tr>
            <th scope="row" style="text-align:left;padding:14px 18px;font-weight:700;color:var(--c-encre);border-bottom:1px solid var(--c-border);"><?= $r[0] ?></th>
            <td style="text-align:center;padding:14px 18px;color:var(--c-text-2);border-bottom:1px solid var(--c-border);"><?= $r[1] ?></td>
            <td style="text-align:center;padding:14px 18px;font-weight:700;color:var(--c-emeraude-dark);background:rgba(5,150,105,.06);border-bottom:1px solid var(--c-border);"><?= $r[2] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="pub-text-center reveal" style="margin-top:14px;font-size:13px;color:var(--c-text-3);max-width:720px;margin-left:auto;margin-right:auto;">Tableau indicatif du périmètre de chaque solution, établi à partir d'éléments publics et notoires. Un tiret « — » signale qu'un besoin ne relève pas du rôle premier de l'outil, sans porter de jugement sur sa qualité. HelloAsso et Assokit peuvent être utilisés conjointement.</p>
    <div class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours</a>
      <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
    </div>
    <p class="pub-text-center" style="margin-top:10px;font-size:13px;color:var(--c-text-3);">Sans carte bancaire · sans engagement</p>
  </div>
</section>

<!-- Quand choisir Assokit -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Faire le bon choix</span>
      <h2 class="pub-h2">Quand Assokit est-il la <em>bonne alternative</em> ?</h2>
      <p class="pub-section-lead">Assokit prend tout son sens dès que votre besoin dépasse le simple encaissement de paiements.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ([
        ['👥','Vous gérez beaucoup d\'adhérents','Annuaire, rôles, espaces dédiés et historique : de quoi suivre chaque membre au-delà d\'un simple paiement.'],
        ['🔁','Vous suivez des cotisations récurrentes','Relances automatiques, statut de paiement en temps réel et reçus, sans ressaisie dans un tableur.'],
        ['📊','Vous avez besoin d\'une vraie comptabilité','Comptabilité analytique par projet et exports prêts pour l\'expert-comptable, utiles pour justifier les subventions.'],
        ['🎯','Vous pilotez des projets &amp; des AG','Budgets par projet, convocations, émargement et procès-verbaux dans le même espace que le reste de la gestion.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/logiciel-association" class="lp-btn lp-btn-glass">Le logiciel association</a>
      <a href="/logiciel-cotisation-association" class="lp-btn lp-btn-glass">Gérer les cotisations</a>
      <a href="/logiciel-adherents" class="lp-btn lp-btn-glass">Gérer les adhérents</a>
      <a href="/tarifs" class="lp-btn lp-btn-glass">Voir les tarifs</a>
    </div>
  </div>
</section>

<!-- Utiliser les deux -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Complémentarité</span>
      <h2 class="pub-h2">Encaissez d'un côté, <em>pilotez de l'autre</em></h2>
      <p class="pub-section-lead">Vous n'êtes pas obligé de choisir. Voici une façon courante et honnête de combiner une plateforme de collecte et un logiciel de gestion.</p>
    </div>
    <ul class="lp-checks reveal">
      <?php foreach ([
        'Lancez une campagne de dons ou une billetterie via une plateforme de collecte',
        'Gérez adhérents, cotisations et comptabilité au quotidien dans Assokit',
        'Suivez vos projets et subventions avec un budget par projet',
        'Préparez vos assemblées générales sans outils séparés',
        'Communiquez avec vos membres grâce à l\'IA intégrée',
        'Gardez toutes vos données de gestion hébergées en France, conformes RGPD',
      ] as $f): ?>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= $f ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="pub-text-center reveal" style="margin-top:30px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
      <a href="/logiciel-association-gratuit" class="lp-btn lp-btn-glass">Logiciel association gratuit ?</a>
      <a href="/guide-association-loi-1901" class="lp-btn lp-btn-glass">Guide association loi 1901</a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Assokit &amp; <em>HelloAsso</em> : vos questions</h2></div>
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
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Passez à la gestion complète</h2>
      <p>Testez Assokit gratuitement pendant 14 jours et voyez ce que la gestion tout-en-un change au quotidien — en complément ou en remplacement de votre outil de collecte.</p>
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
render_public_foot();
