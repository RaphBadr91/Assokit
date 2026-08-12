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
    ['Existe-t-il un logiciel de gestion TPE gratuit ?', "Oui : Assokit propose un essai gratuit pour démarrer (devis, factures, suivi), sans carte bancaire. Vous testez toutes les fonctionnalités gratuitement pendant 14 jours, sans carte bancaire, puis vous choisissez votre formule."],
    ['Le logiciel gère-t-il les chantiers et projets ?', "Oui. Chaque client ou chantier devient un projet : étapes, budget alloué vs dépensé, factures rattachées, collègues assignés. Vous savez en un coup d'œil où en est chaque affaire."],
    ['Puis-je suivre ma trésorerie et mes rendez-vous ?', "Oui : suivi des encaissements en temps réel, relances de paiement automatiques, et agenda intégré pour vos rendez-vous — tout au même endroit."],
    ['Assokit fonctionne-t-il sur mobile ?', "Oui, application iOS et Android : gérez devis, factures, projets et RDV depuis votre téléphone, même sur le terrain."],
    ['Quel logiciel de gestion pour une TPE ?', "Le meilleur logiciel de gestion pour une TPE réunit devis, factures, clients, chantiers, trésorerie et rentabilité dans un seul outil, sans suite comptable lourde à côté. Privilégiez une solution simple à prendre en main, dotée d'une appli mobile et hébergée en France : c'est exactement le parti pris d'Assokit, avec un essai gratuit pour démarrer."],
    ['Un indépendant a-t-il besoin d\'un logiciel de gestion ?', "Oui. Même seul, un indépendant ou un artisan doit éditer des devis et des factures conformes, relancer les impayés et suivre sa trésorerie. Un logiciel de gestion évite les tableurs dispersés et les oublis de relance, peut faire gagner plusieurs heures par semaine et donne une vision claire de la rentabilité de chaque client."],
    ['Peut-on suivre la rentabilité par chantier ou par client ?', "Oui, c'est le cœur d'Assokit. Chaque dépense et chaque facture est rattachée à un projet, un chantier ou un client. La comptabilité analytique intégrée calcule automatiquement la marge réelle, poste par poste : vous savez immédiatement quelle affaire rapporte et laquelle vous coûte de l'argent."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android', 'description' => "Logiciel de gestion tout-en-un pour TPE, PME et indépendants : devis, factures, projets/chantiers, trésorerie, agenda et comptabilité analytique.", 'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '29.99', 'highPrice' => '49.99', 'priceCurrency' => 'EUR', 'offerCount' => '3'], 'inLanguage' => 'fr-FR'];

render_public_head([
    'title'       => 'Logiciel de gestion pour TPE & indépendants · Assokit',
    'description' => 'Logiciel de gestion pour TPE, PME et indépendants : devis, factures, trésorerie, rentabilité par chantier. Essai gratuit, appli mobile, hébergé en France.',
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
          <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Essai gratuit</span><span>📱 Appli mobile</span><span>🇫🇷 Hébergé en France</span></div>
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
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comment ça marche</span><h2 class="pub-h2">Votre <em>logiciel de gestion TPE</em> en 4 étapes</h2></div>
    <div class="lp-grid">
      <?php foreach ([
        ['1','Centralisez clients &amp; projets','Regroupez chaque client, chantier et projet au même endroit. Fini les fichiers éparpillés : l\'indépendant comme l\'artisan retrouvent en un clic les coordonnées, l\'historique et les documents de chaque affaire.'],
        ['2','Éditez devis &amp; factures','Créez un devis en deux minutes, transformez-le en facture d\'un clic et laissez le logiciel envoyer les relances. Chaque document reste rattaché au bon client et au bon chantier.'],
        ['3','Suivez trésorerie &amp; rentabilité','Visualisez vos encaissements en temps réel et mesurez la rentabilité réelle de chaque chantier ou client, poste par poste. Vous savez enfin quelle affaire rapporte vraiment à votre TPE ou PME.'],
        ['4','Pilotez depuis le mobile','Application iOS et Android : consultez trésorerie, projets et factures et validez un devis depuis le terrain. Votre gestion vous suit partout, sans ordinateur.'],
      ] as $s): ?>
        <div class="lp-card reveal"><div class="lp-ic" style="font-weight:900;font-size:22px;color:#059669;"><?= $s[0] ?></div><h3><?= $s[1] ?></h3><p><?= $s[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comparatif</span><h2 class="pub-h2">Tableurs, suite lourde ou <em>Assokit</em> ?</h2></div>
    <div class="pub-comparison-wrapper reveal">
      <table style="width:100%;max-width:920px;margin:0 auto;border-collapse:collapse;background:#fff;border:1px solid var(--c-border);border-radius:var(--radius-xl);overflow:hidden;font-size:14.5px;">
        <thead>
          <tr style="background:linear-gradient(135deg,#059669,#047857);color:#fff;">
            <th scope="col" style="text-align:left;padding:15px 18px;font-weight:700;">Critère</th>
            <th scope="col" style="text-align:center;padding:15px 14px;font-weight:600;">Tableurs séparés</th>
            <th scope="col" style="text-align:center;padding:15px 14px;font-weight:600;">Suite logicielle lourde</th>
            <th scope="col" style="text-align:center;padding:15px 14px;font-weight:800;background:rgba(255,255,255,.12);">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Devis &amp; factures','Saisie manuelle, erreurs fréquentes','Puissant mais complexe','Créés en 2 min, conformes'],
            ['Suivi de trésorerie','Calculs à la main','Module payant en plus','Encaissements en temps réel'],
            ['Rentabilité par projet/client','Quasi impossible','Paramétrage long','Comptabilité analytique intégrée'],
            ['Relances de paiement','Oubliées','Automatisables (option)','Automatiques, incluses'],
            ['Application mobile','Non','Rarement fluide','iOS &amp; Android'],
            ['Hébergement en France','Selon l\'outil','Selon l\'éditeur','🇫🇷 Oui, RGPD'],
            ['Prise en main','Rapide mais limité','Plusieurs jours','Immédiate, sans formation'],
            ['Prix','Faible mais chronophage','Élevé, engagement','Essai gratuit pour démarrer'],
          ] as $j => $row): ?>
            <tr style="border-top:1px solid var(--c-border);<?= $j % 2 ? 'background:#F8FBFA;' : '' ?>">
              <td style="padding:13px 18px;font-weight:700;color:var(--c-encre);"><?= $row[0] ?></td>
              <td style="padding:13px 14px;text-align:center;color:var(--c-text-3);"><?= $row[1] ?></td>
              <td style="padding:13px 14px;text-align:center;color:var(--c-text-3);"><?= $row[2] ?></td>
              <td style="padding:13px 14px;text-align:center;font-weight:700;color:#047857;background:rgba(5,150,105,.06);"><?= $row[3] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pub-text-center reveal" style="margin-top:28px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
      <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
    </div>
    <p class="pub-text-center" style="margin-top:10px;font-size:13px;color:var(--c-text-3);">Sans carte bancaire · sans engagement</p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container pub-container-narrow" style="max-width:760px;">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi Assokit</span><h2 class="pub-h2">Un <em>logiciel de gestion pour petite entreprise</em> qui va à l'essentiel</h2></div>
    <div class="reveal" style="color:var(--c-text-2);line-height:1.72;font-size:15.5px;">
      <p style="margin:0 0 18px;">La première question d'un dirigeant de TPE n'est pas « combien j'ai facturé ? » mais « <strong>quel chantier me rapporte vraiment ?</strong> ». Un simple chiffre d'affaires ne dit rien de la marge une fois déduits les achats, la sous-traitance et les heures passées. Assokit rattache chaque dépense et chaque facture à un projet ou à un client, puis calcule automatiquement la rentabilité. L'artisan comme l'indépendant voient enfin, poste par poste, où ils gagnent de l'argent et où ils en perdent — de quoi ajuster un devis avant de signer plutôt que de le regretter après.</p>
      <p style="margin:0 0 18px;">Le second gain est le temps. Entre un tableur pour les devis, un autre pour la trésorerie, une boîte mail pour les relances et une application d'agenda, une petite entreprise perd facilement <strong>plusieurs heures chaque semaine</strong> à ressaisir les mêmes informations. En centralisant devis, factures, relances et suivi dans un seul logiciel, ces heures reviennent à votre cœur de métier. Les relances de paiement, en particulier, partent toutes seules : vous encaissez plus vite sans jamais avoir à surveiller qui vous doit quoi.</p>
      <p style="margin:0;">Reste la simplicité. Beaucoup de suites de gestion pour PME sont de véritables usines à gaz : puissantes, mais si complexes qu'elles réclament une formation et un budget hors de portée d'une TPE ou d'un indépendant. Assokit prend le parti inverse — <strong>un outil tout-en-un que l'on prend en main en quelques minutes</strong>, hébergé en France, utilisable sur mobile comme au bureau, avec un essai gratuit pour démarrer. La bonne gestion ne devrait pas coûter une journée de paramétrage.</p>
    </div>

    <div class="reveal" style="margin-top:38px;background:#fff;border:1px solid var(--c-border);border-radius:var(--radius-xl);padding:26px 28px;">
      <span class="pub-section-eyebrow" style="display:block;margin-bottom:14px;">Ressources pour aller plus loin</span>
      <ul style="list-style:none;margin:0;padding:0;display:grid;gap:12px;">
        <li>📘 <a href="/blog/externaliser-sa-comptabilite-tpe-le-vrai-cout-compare-a-un-expert-comptable" style="color:var(--c-emeraude-dark);font-weight:700;">Externaliser sa comptabilité TPE : le vrai coût comparé à un expert-comptable</a></li>
        <li>🏦 <a href="/blog/compte-bancaire-association-quelle-banque-2026" style="color:var(--c-emeraude-dark);font-weight:700;">Compte bancaire association : quelle banque en 2026 ?</a></li>
        <li>🧾 <a href="/blog/devis-vs-bon-commande-difference" style="color:var(--c-emeraude-dark);font-weight:700;">Devis ou bon de commande : quelle différence ?</a></li>
      </ul>
      <p style="margin:18px 0 0;padding-top:16px;border-top:1px solid var(--c-border);display:flex;gap:18px;flex-wrap:wrap;">
        <a href="/logiciel-facturation" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de facturation →</a>
        <a href="/pour-tpe" style="color:var(--c-emeraude-dark);font-weight:700;">Assokit pour les TPE/PME →</a>
      </p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Choisir son <em>logiciel de gestion TPE</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/pour-tpe" style="color:var(--c-emeraude-dark);font-weight:700;">Découvrir Assokit pour les indépendants →</a>
      <a href="/logiciel-facturation" style="color:var(--c-emeraude-dark);font-weight:700;">Éditer devis &amp; factures →</a>
    </p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Pilotez votre TPE sereinement</h2>
      <p>Essai gratuit pour démarrer, application mobile incluse. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
