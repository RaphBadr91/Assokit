<?php
/**
 * logiciel-adherents.php — Page SEO ciblée « logiciel gestion adhérents » / « logiciel adhérents association ».
 * Route : /logiciel-adherents. Premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel gestion des adhérents', 'url' => '/logiciel-adherents'],
]);

$faqs = [
    ['Quel logiciel pour gérer les adhérents d\'une association ?', "Un logiciel de gestion des adhérents doit centraliser l'annuaire, les rôles, les cotisations et la communication. Assokit réunit tout ça : fiche complète par adhérent, rôles personnalisés, espaces membres, relances de cotisation et emailing ciblé — pensé pour les associations loi 1901."],
    ['Peut-on gérer différents rôles et niveaux d\'adhérents ?', "Oui : bureau, coordinateurs, membres, sympathisants… avec des droits et des espaces adaptés à chacun. Vous gardez la maîtrise de qui voit et fait quoi."],
    ['Le logiciel relance-t-il les adhérents pour les cotisations ?', "Oui, automatiquement : les adhérents non à jour reçoivent des relances, et chaque paiement met la fiche à jour en temps réel. Fini les fichiers éparpillés et les doublons."],
    ['Les adhérents ont-ils un espace dédié ?', "Oui : chaque adhérent peut accéder à son espace (informations, documents, paiement de cotisation), ce qui allège le travail du bureau."],
    ['Peut-on importer sa liste d\'adhérents existante ?', "Oui : import de votre fichier (CSV/Excel) en quelques minutes, sans perdre vos données. Et export à tout moment — vos données restent les vôtres."],
    ['Comment gérer le fichier des adhérents d\'une association ?', "Le fichier des adhérents se gère dans une base centralisée plutôt que dans un tableur fragile et vite dépassé. Chaque adhérent a une fiche unique (coordonnées, rôle, historique, cotisations, documents) tenue à jour en temps réel. Assokit centralise ce fichier adhérents, évite la double-saisie, trace chaque modification et permet l'export à tout moment — pour un suivi fiable des adhésions, des renouvellements et de la communication."],
    ['Un logiciel de gestion des adhérents est-il conforme au RGPD ?', "Oui, à condition qu'il encadre les données personnelles. Assokit est hébergé en France, chiffre les données, journalise les accès et vous permet de répondre aux droits des adhérents (accès, rectification, effacement, export). Vous restez responsable de traitement, mais l'outil facilite la conformité RGPD : durées de conservation, radiation d'un membre et purge des anciens adhérents sont maîtrisées."],
    ['Peut-on importer sa liste d\'adhérents depuis Excel ?', "Oui : l'import depuis un fichier Excel ou CSV se fait en quelques minutes. Vous téléversez votre tableur, faites correspondre les colonnes (nom, email, rôle, statut de cotisation) et vos adhérents sont créés sans ressaisie. Aucune donnée n'est perdue, et vous pouvez ré-exporter votre base quand vous le souhaitez."],
    ['Comment relancer les adhésions à renouveler ?', "Assokit repère automatiquement les adhésions arrivant à échéance et envoie des relances de renouvellement par email aux adhérents concernés. Chaque paiement met la fiche à jour en temps réel, et vous pilotez la communication ciblée (par rôle, par équipe ou par statut) sans lister manuellement les retardataires — un gain de temps direct pour les bénévoles du bureau."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android', 'description' => "Logiciel de gestion des adhérents pour association loi 1901 : annuaire, rôles, cotisations, espaces membres et communication ciblée.", 'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '29.99', 'highPrice' => '49.99', 'priceCurrency' => 'EUR', 'offerCount' => '3'], 'inLanguage' => 'fr-FR'];

render_public_head([
    'title'       => 'Logiciel de gestion des adhérents pour association · Assokit',
    'description' => 'Logiciel de gestion des adhérents pour association loi 1901 : annuaire, cotisations, relances et communication ciblée. Essai gratuit, hébergé en France.',
    'path'        => '/logiciel-adherents',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel gestion adhérents</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Adhérents &amp; membres</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de gestion des adhérents</span> de votre association</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Annuaire intelligent, <strong>rôles personnalisés</strong>, cotisations, espaces membres et communication ciblée. Un fichier adhérents vivant, toujours à jour — sans fichiers éparpillés.</p>
        <div class="lp-hero-cta reveal">
          <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Import de votre fichier</span><span>✓ Espaces membres</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Adhérents</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#E0F2FE">👥</span><div style="flex:1"><div class="t">248 adhérents</div><div class="s">Annuaire à jour · +12 ce mois</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">+12</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🎭</span><div style="flex:1"><div class="t">Rôles &amp; droits</div><div class="s">Bureau · membres · sympathisants</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Perso</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FCE7F3">💸</span><div style="flex:1"><div class="t">Cotisations</div><div class="s">Statut à jour par adhérent</div></div><span class="v">94%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📣</span><div style="flex:1"><div class="t">Email ciblé</div><div class="s">Par rôle ou par équipe</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">Envoyé</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Vos adhérents, maîtrisés</span><h2 class="pub-h2">Un fichier adhérents <em>vivant</em>, jamais dépassé</h2></div>
    <div class="lp-grid">
      <?php foreach ([
        ['👤','Fiche complète','Coordonnées, historique, cotisations, documents — tout au même endroit.'],
        ['🎭','Rôles personnalisés','Bureau, coordinateurs, membres, sympathisants, avec droits adaptés.'],
        ['🔑','Espaces membres','Chaque adhérent accède à son espace : infos, docs, paiement.'],
        ['💸','Cotisations liées','Statut à jour automatiquement, relances des retardataires.'],
        ['📣','Communication ciblée','Emails à un rôle, une équipe ou une liste — suivi des ouvertures.'],
        ['📥','Import / export','Reprenez votre fichier existant, exportez quand vous voulez.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comment ça marche</span><h2 class="pub-h2">Votre <em>logiciel de gestion des adhérents</em> en 4 étapes</h2>
      <p class="pub-section-lead">De la reprise de votre fichier adhérents jusqu'à la fidélisation, chaque étape est pensée pour les bénévoles — sans double-saisie ni fichiers éparpillés.</p></div>
    <div class="lp-grid">
      <?php foreach ([
        ['1','Centraliser la base adhérents','Importez votre fichier adhérents depuis Excel ou CSV et rassemblez tout dans une base centralisée et conforme RGPD : coordonnées, rôles, documents et historique par adhérent, hébergés en France.'],
        ['2','Suivre adhésions &amp; cotisations','Chaque adhésion, renouvellement et cotisation se met à jour en temps réel. Le statut « à jour / en retard » est visible d\'un coup d\'œil, et l\'export reste possible à tout moment.'],
        ['3','Communiquer (email / IA)','Lancez une communication ciblée par rôle, équipe ou statut. L\'assistant IA vous aide à rédiger relances de renouvellement, convocations et newsletters en quelques secondes.'],
        ['4','Fidéliser vos membres','Espaces membres, historique complet et relances automatiques renforcent le lien : moins d\'adhérents perdus, un bureau soulagé et des bénévoles concentrés sur l\'essentiel.'],
      ] as $s): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $s[0] ?></div><h3><?= $s[1] ?></h3><p><?= $s[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comparatif</span><h2 class="pub-h2">Tableur, CRM générique ou <em>logiciel adhérents</em> ?</h2>
      <p class="pub-section-lead">Pourquoi un outil pensé pour les associations loi 1901 fait la différence face à un simple tableur ou un CRM générique.</p></div>
    <div class="reveal" style="max-width:920px;margin:0 auto;overflow-x:auto;border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 18px 44px rgba(6,78,59,.08);">
      <table style="width:100%;border-collapse:collapse;font-size:14.5px;min-width:640px;">
        <thead>
          <tr style="background:linear-gradient(135deg,#059669,#047857);color:#fff;">
            <th style="text-align:left;padding:14px 18px;font-weight:700;">Critère</th>
            <th style="text-align:center;padding:14px 14px;font-weight:700;">Tableur classique</th>
            <th style="text-align:center;padding:14px 14px;font-weight:700;">CRM générique</th>
            <th style="text-align:center;padding:14px 14px;font-weight:800;background:rgba(255,255,255,.12);">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Base centralisée &amp; RGPD', 'Fichier isolé, risqué', 'Oui, mais générique', 'Oui, conçue pour les assos'],
            ['Suivi des cotisations', 'Manuel, source d\'erreurs', 'Partiel', 'Automatique &amp; en temps réel'],
            ['Relances de renouvellement', 'Non', 'À configurer soi-même', 'Automatiques'],
            ['Communication ciblée', 'Copier-coller d\'emails', 'Oui, complexe', 'Par rôle, équipe ou statut'],
            ['Historique par adhérent', 'Non', 'Oui', 'Complet &amp; daté'],
            ['Application mobile', 'Non', 'Selon l\'éditeur', 'iOS &amp; Android'],
            ['Hébergement en France', 'Selon votre stockage', 'Selon l\'éditeur', '🇫🇷 Oui'],
            ['Prix', 'Gratuit mais chronophage', '€€ par utilisateur', 'Essai gratuit pour démarrer'],
          ] as $j => $r): ?>
            <tr style="border-top:1px solid var(--c-border-soft);<?= $j % 2 ? 'background:var(--c-creme);' : '' ?>">
              <td style="padding:13px 18px;font-weight:600;color:var(--c-encre);"><?= $r[0] ?></td>
              <td style="padding:13px 14px;text-align:center;color:var(--c-text-3);"><?= $r[1] ?></td>
              <td style="padding:13px 14px;text-align:center;color:var(--c-text-2);"><?= $r[2] ?></td>
              <td style="padding:13px 14px;text-align:center;font-weight:700;color:var(--c-emeraude-dark);background:rgba(5,150,105,.06);"><?= $r[3] ?></td>
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
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi c'est décisif</span><h2 class="pub-h2">Un fichier adhérents à jour, une association qui <em>fidélise</em></h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;color:var(--c-text-2);line-height:1.8;font-size:16px;">
      <p style="margin:0 0 20px;"><strong style="color:var(--c-encre);">Une base à jour et conforme au RGPD.</strong> Le premier bénéfice d'un logiciel de gestion des adhérents, c'est d'avoir un fichier adhérents unique, vivant et fiable. Fini les versions d'un tableur qui circulent par email : la base est centralisée, chaque adhésion et chaque radiation de membre sont tracées, et les données personnelles sont encadrées. Hébergement en France, journalisation des accès, gestion des durées de conservation et de l'effacement : vous répondez sereinement aux obligations RGPD et aux droits de vos adhérents, tout en gardant la maîtrise de l'export de vos données.</p>
      <p style="margin:0 0 20px;"><strong style="color:var(--c-encre);">Une communication qui fidélise vraiment.</strong> Un adhérent bien informé est un adhérent qui reste. En reliant le suivi des cotisations à la communication ciblée, vous adressez le bon message à la bonne personne : relance de renouvellement à ceux dont l'adhésion arrive à échéance, convocation aux membres du bureau, newsletter aux bénévoles actifs. L'assistant IA rédige ces messages en quelques secondes, et le suivi des ouvertures vous montre ce qui fonctionne. Résultat : moins d'adhérents perdus faute de relance, et un lien entretenu tout au long de l'année.</p>
      <p style="margin:0;"><strong style="color:var(--c-encre);">Zéro double-saisie, plus de temps pour l'essentiel.</strong> Chaque paiement de cotisation met la fiche à jour automatiquement ; chaque nouvel adhérent s'ajoute à la base sans ressaisie ; chaque export part propre et daté. Les bénévoles ne recopient plus les mêmes informations d'un tableur à un logiciel de comptabilité : l'information saisie une fois circule partout. C'est autant d'heures rendues aux projets de l'association plutôt qu'à l'administratif.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Pour aller plus loin sur la <em>gestion des adhérents</em></h2></div>
    <div class="lp-grid reveal">
      <a href="/blog/adhesion-association-regles-bonnes-pratiques" class="lp-card" style="text-decoration:none;"><div class="lp-ic">📘</div><h3>Adhésion : règles &amp; bonnes pratiques</h3><p>Ce que dit la loi 1901 et comment cadrer les adhésions de votre association.</p></a>
      <a href="/blog/cotisation-adherent-association-gerer" class="lp-card" style="text-decoration:none;"><div class="lp-ic">💸</div><h3>Gérer la cotisation des adhérents</h3><p>Montant, encaissement, suivi et relances : la cotisation sans prise de tête.</p></a>
      <a href="/blog/radier-membre-association-procedure" class="lp-card" style="text-decoration:none;"><div class="lp-ic">📄</div><h3>Radier un membre : la procédure</h3><p>Étapes, conformité et traçabilité pour radier un adhérent dans les règles.</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:26px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/blog?categorie=associations" style="color:var(--c-emeraude-dark);font-weight:700;">Tous nos articles associations →</a>
      <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de cotisation →</a>
      <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">Le logiciel association complet →</a>
      <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de comptabilité →</a>
    </p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Gérer les <em>adhérents</em> de votre association</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:700;">Gérer les cotisations →</a>
      <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">Le logiciel complet →</a>
    </p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Vos adhérents, enfin bien gérés</h2>
      <p>Importez votre fichier et démarrez gratuitement. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
