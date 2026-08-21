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
    ['Comment collecter les cotisations d\'une association en ligne ?', "Vous créez votre appel à cotisation dans Assokit, définissez le ou les tarifs et l'échéancier, puis partagez un lien de paiement en ligne. Chaque adhérent règle sa cotisation par carte bancaire ou virement, reçoit son reçu, et l'encaissement remonte dans la trésorerie sans ressaisie. Vous suivez en temps réel qui a payé."],
    ['Peut-on payer sa cotisation par carte bancaire ?', "Oui. Le paiement en ligne par carte bancaire (CB) est intégré : l'adhérent règle en quelques clics depuis son téléphone ou son ordinateur, la transaction est sécurisée et tracée, et la cotisation est marquée comme réglée automatiquement. Le virement reste également possible pour ceux qui le préfèrent."],
    ['Comment relancer les adhérents qui n\'ont pas payé ?', "Vous n'avez rien à faire manuellement : Assokit envoie des relances automatiques aux adhérents non à jour, selon l'échéancier et le calendrier que vous fixez (avant l'échéance, à date, puis rappels). Chaque relance contient le lien de paiement, ce qui fait grimper le taux d'encaissement sans que le trésorier ait à courir après personne."],
    ['Assokit génère-t-il les reçus de cotisation ?', "Oui. Un reçu est généré et envoyé automatiquement à l'adhérent dès que sa cotisation est encaissée. Les reçus sont archivés, retrouvables par adhérent, et adaptés à chaque tarif — pratique pour la trésorerie comme pour les justificatifs demandés par vos membres."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de gestion et de collecte des cotisations pour association loi 1901 : paiement en ligne, relances automatiques, reçus, suivi en temps réel.",
    // 'offers' retiré (évite l'exigence Google review/aggregateRating sans avis notés réels)  'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Gestion des cotisations d\'association en ligne · Assokit',
    'description' => 'Cotisations d\'association en ligne : paiement par carte, relances automatiques, reçus et suivi en temps réel. Logiciel simple, essai gratuit, hébergé en France.',
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
          <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement
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
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comment ça marche</span><h2 class="pub-h2">De l'<em>appel à cotisation</em> au reçu, en 4 étapes</h2><p class="pub-section-lead">Mettez la collecte de vos cotisations en pilote automatique. Vous configurez une fois, le logiciel encaisse, relance et classe pour vous.</p></div>
    <div class="lp-grid">
      <div class="lp-card reveal"><div class="lp-ic">1️⃣</div><h3>Définir cotisations &amp; tarifs</h3><p>Créez votre appel à cotisation et vos tarifs (étudiant, famille, bienfaiteur…), fixez le montant et l'échéancier. Chaque adhérent sait exactement quoi payer, et quand.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">2️⃣</div><h3>Encaisser en ligne</h3><p>Partagez le lien de paiement en ligne : l'adhérent règle par carte bancaire ou par virement, en quelques clics. L'encaissement est sécurisé, tracé et rattaché au bon adhérent.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">3️⃣</div><h3>Relances automatiques</h3><p>Les retardataires reçoivent une relance automatique selon votre calendrier, lien de paiement inclus. Le trésorier ne court plus après personne et le taux d'encaissement grimpe.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">4️⃣</div><h3>Reçus &amp; suivi</h3><p>Un reçu part automatiquement à chaque paiement. Le tableau de bord montre qui est à jour, combien est collecté, et l'argent remonte directement dans votre trésorerie.</p></div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comparatif</span><h2 class="pub-h2">Tableur, cagnotte ou <em>logiciel de cotisation</em> ?</h2><p class="pub-section-lead">Un tableur et des chèques, ou une cagnotte en ligne, dépannent au début. Pour gérer vraiment les cotisations d'une association, il faut relances, reçus et rapprochement comptable réunis.</p></div>
    <div class="pub-comparison-wrapper reveal">
      <table style="width:100%;min-width:640px;border-collapse:collapse;background:#fff;border:1px solid var(--c-border);border-radius:16px;overflow:hidden;font-size:14.5px;margin:0 auto;max-width:900px;">
        <thead>
          <tr style="background:#ECFDF5;">
            <th style="text-align:left;padding:16px 18px;font-weight:700;color:var(--c-encre);">Fonction</th>
            <th style="text-align:center;padding:16px 14px;font-weight:700;color:var(--c-text-3);">Tableur + chèques</th>
            <th style="text-align:center;padding:16px 14px;font-weight:700;color:var(--c-text-3);">Cagnotte en ligne</th>
            <th style="text-align:center;padding:16px 14px;font-weight:800;color:#059669;">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Paiement en ligne CB',        '❌',                 '✅',                  '✅'],
            ['Relances automatiques',       '❌',                 '❌',                  '✅'],
            ['Reçus générés',               '❌ (manuel)',        '⚠️ limité',           '✅'],
            ['Suivi par adhérent',          '⚠️ à la main',       '❌',                  '✅'],
            ['Rapprochement compta',        '❌',                 '❌',                  '✅'],
            ['Hébergement en France',       '—',                  'Variable',            '🇫🇷 Oui'],
            ['Frais',                       'Temps &amp; erreurs', 'Commission + retrait', 'Inclus dans l\'abonnement'],
          ] as $row): ?>
          <tr style="border-top:1px solid var(--c-border-soft);">
            <td style="padding:13px 18px;color:var(--c-text)<?= $row[0] === 'Frais' ? ';font-weight:700' : '' ?>;"><?= $row[0] ?></td>
            <td style="text-align:center;padding:13px 14px;color:var(--c-text-2);"><?= $row[1] ?></td>
            <td style="text-align:center;padding:13px 14px;color:var(--c-text-2);"><?= $row[2] ?></td>
            <td style="text-align:center;padding:13px 14px;color:#059669;font-weight:700;"><?= $row[3] ?></td>
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
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi Assokit</span><h2 class="pub-h2">Arrêtez de <em>courir après</em> les cotisations</h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">Dans beaucoup d'associations loi 1901, la collecte des cotisations repose encore sur un tableur, une pile de chèques et la mémoire du trésorier. Résultat : des relances oubliées, des adhérents perdus de vue et une trésorerie difficile à projeter. Un <strong>logiciel de gestion des cotisations</strong> change la donne : chaque appel à cotisation est suivi, chaque adhérent a un statut clair (à jour, en retard, exonéré) et vous savez à tout instant combien il reste à encaisser.</p>
      <p style="margin:0 0 18px;">L'<strong>encaissement en ligne</strong> supprime le plus gros frein au paiement. Plutôt que d'attendre une réunion pour remettre un chèque, l'adhérent clique sur un lien et règle sa cotisation par carte bancaire en trente secondes — ou par virement s'il préfère. Le paiement est immédiat, le reçu part tout seul, et l'écriture remonte directement dans votre comptabilité analytique, sans double saisie ni rapprochement manuel en fin d'exercice.</p>
      <p style="margin:0;">Enfin, les <strong>relances automatiques</strong> font le travail ingrat à votre place. Vous définissez un échéancier une fois, et le logiciel rappelle les retardataires au bon moment, lien de paiement à l'appui. En pratique, un simple rappel automatisé suffit souvent à récupérer une large part des cotisations en souffrance. Le trésorier gagne des heures chaque trimestre, et l'association sécurise ses recettes.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Aller plus loin sur les <em>cotisations</em></h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/blog/cotisation-adherent-association-gerer"><div class="lp-ic">📘</div><h3>Gérer la cotisation d'un adhérent</h3><p>Le guide complet pour organiser, suivre et encaisser les cotisations de vos membres.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/augmentation-cotisation-association-7-strategies-pour-preserver-vos-adherents"><div class="lp-ic">📈</div><h3>Augmenter la cotisation sans perdre d'adhérents</h3><p>7 stratégies pour ajuster vos tarifs tout en préservant l'engagement de vos membres.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/adhesions-premium-en-association-creer-3-niveaux-tarifaires-qui-triplent-vos"><div class="lp-ic">🎟️</div><h3>Adhésions premium : 3 niveaux tarifaires</h3><p>Créez des paliers de cotisation qui augmentent vos recettes sans effort.</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:22px;"><a href="/blog?categorie=associations" style="color:var(--c-emeraude-dark);font-weight:700;">Tous nos articles pour les associations →</a></p>
    <p class="pub-text-center reveal" style="margin-top:14px;color:var(--c-text-3);font-size:14.5px;">À combiner avec la gestion des <a href="/logiciel-adherents" style="color:var(--c-emeraude-dark);font-weight:600;">adhérents</a>, la <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:600;">comptabilité d'association</a> et le <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de gestion complet</a>.</p>
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
      <p>Mettez la collecte en pilote automatique. Essai gratuit pour démarrer, sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
