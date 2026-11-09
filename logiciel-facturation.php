<?php
/**
 * logiciel-facturation.php — Page SEO ciblée « logiciel de facturation » / « logiciel devis facture »
 * (+ facturation électronique). Route : /logiciel-facturation. Premium + FAQPage + Breadcrumb + SoftwareApplication.
 * Cible : « logiciel facturation association », « logiciel de facturation TPE ». Les articles du blog
 * sur la facture, l'avoir, Chorus Pro ou la facture électronique pointent ici (blog-article.php).
 */
require_once __DIR__ . '/includes-public.php';
// Grille Essentiel / Pro / Équipe (includes-pricing.php) : « dès 19,99 € HT/mois », lu comme sur /tarifs
require_once __DIR__ . '/includes-pricing.php';
require_once __DIR__ . '/_landing-seo.php';
$offer = ak_offer_price();

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel de facturation', 'url' => '/logiciel-facturation'],
]);

$faqs = [
    ['Quel logiciel de facturation choisir pour une association ou une TPE ?', "Un bon logiciel de facturation crée devis et factures en quelques clics, aide à relancer les impayés et se relie à votre comptabilité. Assokit le fait avec votre logo, la numérotation automatique et l'export comptable : relance en un clic avec toutes les formules, relances automatiques avec la formule Pro. Pensé pour les associations et les TPE françaises."],
    ['Le logiciel gère-t-il devis et factures ?', "Oui : devis, factures HT/TVA/TTC, avoirs, numérotation automatique et export PDF avec votre logo, le tout suivi en temps réel. Avec la formule Pro : devis signés en ligne (signature simple, horodatée) et factures récurrentes."],
    ['Assokit gère-t-il la facturation électronique ?', "Assokit génère vos factures au format Factur-X (PDF + XML, norme EN 16931) et centralise vos pièces. Le raccordement à une plateforme agréée, par laquelle les factures électroniques sont reçues puis émises, est en préparation."],
    ['Peut-on automatiser les relances d\'impayés ?', "Oui, avec la formule Pro, une fois l'option activée : jusqu'à trois relances graduées par e-mail, un rappel courtois dès le lendemain de l'échéance (J+1), une relance ferme à partir de J+15, puis une mise en demeure à partir de J+45. La cadence est fixe ; vous choisissez jusqu'où elles vont. Avec Essentiel, la relance se fait en un clic."],
    ['La facturation est-elle reliée à la comptabilité ?', "Oui : chaque facture émise ou encaissée alimente automatiquement votre comptabilité et votre bilan analytique. Aucune double saisie."],
    ['Comment faire une facture pour une association ?', "Une association qui facture une prestation, une vente ou une subvention conventionnée doit émettre une facture comportant les mentions légales obligatoires : identité et adresse de l'association, numéro RNA ou SIRET le cas échéant, date, numéro de facture séquentiel, désignation, montant HT, taux et montant de TVA (ou la mention « TVA non applicable, art. 293 B du CGI » si l'association n'y est pas assujettie) et montant TTC. Avec Assokit, vous partez d'un devis ou d'un modèle, le logiciel applique la numérotation automatique et les mentions, puis génère un PDF conforme à votre charte en quelques secondes."],
    ['La facturation électronique est-elle obligatoire en 2026 ?', "La réforme de la facturation électronique se déploie par étapes. Depuis le 1er septembre 2026, les entreprises et structures assujetties doivent être en capacité de recevoir des factures électroniques, l'obligation d'émission s'appliquant ensuite selon la taille de la structure. Les factures à destination du secteur public passent déjà par Chorus Pro, la plateforme de l'État (Assokit ne les y envoie pas). Assokit émet vos factures au format Factur-X et centralise vos pièces, y compris la correction d'une facture par avoir ; le raccordement à une plateforme agréée est en préparation."],
    ['Peut-on transformer un devis en facture automatiquement ?', "Oui. Une fois un devis accepté (et signé électroniquement), vous le convertissez en facture en un clic : lignes, quantités, TVA et coordonnées du client sont reprises à l'identique, un nouveau numéro de facture est attribué automatiquement et le devis reste archivé comme justificatif. Vous évitez la double saisie et les erreurs de recopie."],
    ['Assokit gère-t-il les relances de factures impayées ?', "Oui. Avec la formule Pro, une fois les relances automatiques activées, Assokit envoie jusqu'à trois relances graduées par e-mail, du rappel courtois (dès le lendemain de l'échéance) à la mise en demeure, jamais deux à moins de 7 jours d'écart, tant que la facture n'est pas soldée ; vous choisissez jusqu'où elles vont. Avec Essentiel, la relance se fait en un clic. Vous suivez en temps réel les factures payées, en attente et en retard, et vous pouvez émettre un avoir si nécessaire — sans jamais courir après vos clients ou adhérents."],
    ['Comment corriger une facture déjà émise ?', "Une facture émise ne se modifie pas : on l'annule par un avoir, puis on émet une nouvelle facture juste. Dans Assokit, l'avoir se crée depuis la facture concernée : il reprend ses lignes en négatif, prend son numéro dans la même séquence que vos factures et produit un PDF « AVOIR ». La facture d'origine est passée en « Annulée » si elle n'était pas encore réglée."],
];
$faq_schema = ak_landing_faq_jsonld($faqs);
$soft_schema = ak_landing_software_jsonld(
    "Logiciel de facturation pour association et TPE : devis, factures, relances (automatiques avec la formule Pro), export comptable, factures au format Factur-X.",
    ['Devis (signés en ligne avec la formule Pro)', 'Factures HT, TVA et TTC numérotées', 'Avoirs', 'Factures récurrentes (formule Pro)', 'Relances des impayés', 'Factures au format Factur-X'],
    'FinanceApplication'
);

render_public_head([
    'title'       => 'Logiciel de facturation pour association & TPE · Assokit',
    'description' => 'Logiciel de facturation pour association et TPE : devis, factures, avoirs, relances d\'impayés (automatiques avec Pro), Factur-X et export comptable. Essai gratuit.',
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
        <p class="pub-tagline reveal" style="max-width:560px;">Créez devis et factures pro avec votre logo, relancez vos impayés en un clic, ou <strong>automatiquement avec la formule Pro</strong>, suivez vos encaissements en temps réel. Export comptable inclus, factures au format Factur-X.</p>
        <div class="lp-hero-cta reveal">
          <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Devis et factures</span><span>✓ Relances (auto avec Pro)</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Facturation</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Facture #2026-142</div><div class="s">Client Dupont · logo inclus</div></div><span class="v">1 250 €</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">🔔</span><div style="flex:1"><div class="t">Relance automatique (Pro)</div><div class="s">Échéance dépassée · J+1</div></div><span class="lp-chip" style="background:#FEF3C7;color:#B45309">Auto</span></div>
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
        ['🔁','Factures récurrentes (Pro)','Créées à date et, si vous le choisissez, envoyées automatiquement.'],
        ['🔔','Relances automatiques (Pro)','Jusqu’à trois relances graduées, une fois l’option activée.'],
        ['🇪🇺','Facture électronique','Factures au format Factur-X (PDF + XML), pièces centralisées. Raccordement à une plateforme agréée en préparation.'],
        ['📊','Export comptable','Chaque facture alimente votre comptabilité analytique.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comment ça marche</span><h2 class="pub-h2">Du <em>devis</em> à l'encaissement en 4 étapes</h2></div>
    <div class="lp-grid">
      <div class="lp-card reveal"><div class="lp-ic">📝</div><h3>1. Créez devis &amp; factures</h3><p>Partez d'un modèle : lignes, quantités, TVA et <strong>mentions légales</strong> se remplissent tout seuls. Numérotation automatique, votre logo, un PDF conforme en quelques secondes — devis signé en ligne inclus.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">📤</div><h3>2. Envoyez &amp; suivez</h3><p>Transformez un <strong>devis</strong> accepté en <strong>facture</strong> en un clic, envoyez-la par e-mail et suivez son statut en temps réel : ouverte, payée, en attente ou en retard.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">🔔</div><h3>3. Relancez automatiquement</h3><p>Activez les <strong>relances</strong> automatiques (formule Pro) : Assokit rappelle les impayés par e-mail, du rappel courtois dès le lendemain de l'échéance à la mise en demeure, tant que la facture n'est pas soldée. Besoin d'annuler ? Émettez un <strong>avoir</strong> en deux clics.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">💶</div><h3>4. Encaissez &amp; exportez</h3><p>Pointez les encaissements, gérez la <strong>TVA</strong> collectée et exportez vers votre comptabilité en un clic. Chaque facture ou <strong>facture électronique</strong> alimente votre bilan analytique — zéro double saisie.</p></div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comparatif</span><h2 class="pub-h2">Traitement de texte, logiciel générique ou <em>Assokit</em> ?</h2></div>
    <div class="pub-comparison-wrapper reveal">
      <table style="width:100%;border-collapse:collapse;min-width:640px;background:#fff;border:1px solid var(--c-border);border-radius:var(--radius-xl);overflow:hidden;font-size:15px;">
        <thead>
          <tr style="background:var(--c-encre);color:#fff;">
            <th scope="col" style="text-align:left;padding:16px 18px;font-weight:700;">Fonction</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:600;">Traitement de texte / tableur</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:600;">Logiciel générique</th>
            <th scope="col" style="text-align:center;padding:16px 18px;font-weight:800;background:#059669;">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Devis → facture en 1 clic', '✗ Copier-coller manuel', '~ Selon l\'offre', 'Oui, natif'],
            ['Relances automatiques', '✗ À la main', '~ Souvent en option', 'Oui, avec la formule Pro (en un clic avec Essentiel)'],
            ['Factures au format Factur-X', '✗ Non', '~ Variable', 'Oui (plateforme agréée en préparation)'],
            ['Suivi des paiements', '✗ Aucun', 'Oui', 'Oui, temps réel'],
            ['Export comptable', '✗ Ressaisie', 'Oui', 'Oui, 1 clic'],
            ['Hébergement en France', '—', '~ Selon l\'éditeur', '🇫🇷 Oui'],
            ['Prix', 'Gratuit mais chronophage', 'Abonnement mensuel', ucfirst(ak_offer_from_price()) . ' · essai gratuit 14 j'],
          ] as $r): ?>
          <tr style="border-top:1px solid var(--c-border);">
            <td style="padding:14px 18px;font-weight:600;color:var(--c-encre);"><?= $r[0] ?></td>
            <td style="padding:14px 18px;text-align:center;color:var(--c-text-2);"><?= $r[1] ?></td>
            <td style="padding:14px 18px;text-align:center;color:var(--c-text-2);"><?= $r[2] ?></td>
            <td style="padding:14px 18px;text-align:center;font-weight:700;color:var(--c-emeraude-dark);background:rgba(5,150,105,.06);"><?= $r[3] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pub-text-center reveal" style="margin-top:28px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours</a>
      <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
    </div>
    <p class="pub-text-center" style="margin-top:10px;font-size:13px;color:var(--c-text-3);">Sans carte bancaire · sans engagement</p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container" style="max-width:820px;">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pourquoi changer</span><h2 class="pub-h2">Arrêtez de courir après vos <em>paiements</em></h2></div>
    <div class="reveal" style="color:var(--c-text-2);font-size:17px;line-height:1.75;">
      <p style="margin:0 0 20px;">Pour une association ou une TPE, l'impayé n'est pas qu'une ligne comptable : c'est de la trésorerie qui manque pour payer un prestataire, financer un projet ou rembourser une avance de bénévole. Le problème vient rarement du client, mais du suivi. Avec un tableur, personne ne sait vraiment quelle <strong>facture</strong> est en retard, ni qui doit relancer. Un <strong>logiciel de facturation</strong> qui suit les échéances change la donne : vous voyez d'un coup d'œil les factures en retard, et les <strong>relances</strong> partent au bon moment, poliment, en un clic ou automatiquement avec la formule Pro.</p>
      <p style="margin:0 0 20px;">La deuxième raison de s'équiper en 2026, c'est la <strong>conformité</strong>. La réforme de la facturation électronique impose progressivement l'émission et la réception de factures au format structuré, et les factures au secteur public se déposent déjà sur <strong>Chorus Pro</strong>, la plateforme de l'État. Continuer à envoyer des factures papier ou de simples PDF non conformes expose à des rejets et, à terme, à des sanctions. Assokit émet des <strong>factures</strong> au format Factur-X (raccordement à une plateforme agréée en préparation), gère la <strong>TVA</strong> et les <strong>mentions légales</strong> obligatoires, et permet de corriger une erreur proprement par un <strong>avoir</strong> plutôt que par une rature. Vous préparez les échéances réglementaires à l'avance, plutôt qu'à la dernière minute.</p>
      <p style="margin:0;">Enfin, facturer proprement, c'est piloter sa <strong>trésorerie</strong>. Quand chaque <strong>devis</strong>, chaque encaissement et chaque <strong>facture électronique</strong> alimente automatiquement la comptabilité, vous voyez en temps réel ce qui est signé, facturé, encaissé et en attente. Vous anticipez les creux, vous décidez avec des chiffres à jour plutôt qu'au feeling, et vous consacrez votre temps à votre projet — pas à la ressaisie.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Pour aller plus loin sur la <em>facturation</em></h2></div>
    <div class="lp-grid">
      <a class="lp-card reveal" href="/blog/factures-papier-apres-septembre-2026-ce-qui-reste-autorise" style="text-decoration:none;color:inherit;"><div class="lp-ic">📄</div><h3>Factures papier après septembre 2026</h3><p>Ce qui reste autorisé et ce qui bascule en facture électronique. →</p></a>
      <a class="lp-card reveal" href="/blog/erreur-sur-facture-electronique-comment-corriger-legalement-en-2026" style="text-decoration:none;color:inherit;"><div class="lp-ic">✏️</div><h3>Corriger une facture électronique</h3><p>La marche légale en 2026 : avoir, annulation et rectification. →</p></a>
      <a class="lp-card reveal" href="/blog/devis-vs-bon-commande-difference" style="text-decoration:none;color:inherit;"><div class="lp-ic">📋</div><h3>Devis vs bon de commande</h3><p>Comprendre la différence et la valeur juridique de chaque document. →</p></a>
      <a class="lp-card reveal" href="/blog/chorus-pro-association-inscription-et-premier-envoi-en-8-etapes" style="text-decoration:none;color:inherit;"><div class="lp-ic">🏛️</div><h3>Chorus Pro pour une association</h3><p>Inscription et premier envoi de facture au secteur public en 8 étapes. →</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <a href="/logiciel-gestion-tpe" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de gestion pour TPE →</a>
      <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de comptabilité association →</a>
    </p>
  </div>
</section>

<?php ak_landing_pricing(); ?>
<?php ak_landing_siblings('/logiciel-facturation'); ?>

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
      <p>14 jours d’essai gratuit, sans carte bancaire. Devis, factures et relances en quelques clics.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
