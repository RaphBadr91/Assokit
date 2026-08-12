<?php
/**
 * modele-recu-fiscal-association.php — Page SEO « modèle reçu fiscal association » /
 * « reçu fiscal don association Cerfa ». Route : /modele-recu-fiscal-association.
 * Modèle gratuit + explications prudentes (art. 200 & 238 bis CGI, Cerfa n° 11580) + FAQPage + Breadcrumb.
 * Aucune dépendance $pdo.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Modèles gratuits', 'url' => '/modeles-gratuits-association'],
    ['name' => 'Reçu fiscal de don', 'url' => '/modele-recu-fiscal-association'],
]);

$faqs = [
    ["Une association peut-elle délivrer un reçu fiscal ?", "Seules les associations éligibles, c'est-à-dire d'intérêt général au sens des articles 200 et 238 bis du Code général des impôts (CGI), peuvent délivrer un reçu fiscal ouvrant droit à réduction d'impôt pour leurs donateurs. L'association doit notamment être à but non lucratif, avoir une gestion désintéressée et ne pas fonctionner au profit d'un cercle restreint de personnes. En cas de doute sur votre éligibilité, il est possible d'interroger l'administration fiscale (procédure de rescrit). Un reçu délivré à tort expose l'association à une amende."],
    ["Quelles mentions doivent figurer sur un reçu fiscal de don ?", "Le reçu suit le modèle du formulaire Cerfa n° 11580 et doit comporter : l'identité et l'adresse de l'association bénéficiaire ainsi que son objet, l'identité et l'adresse du donateur, le montant du don (en chiffres et en lettres), la date du versement, la nature du don (numéraire, chèque, virement, don en nature…), la forme du don, la mention des articles du CGI applicables (200 et/ou 238 bis), et la signature d'une personne habilitée de l'association."],
    ["Quel avantage fiscal ouvre un don à une association ?", "Pour un particulier, un don à une association d'intérêt général ouvre en principe droit à une réduction d'impôt sur le revenu au titre de l'article 200 du CGI ; pour une entreprise, au titre de l'article 238 bis. Les taux et plafonds sont fixés par la loi et peuvent évoluer chaque année. Le donateur doit conserver le reçu comme justificatif. Pour connaître le taux exact applicable à sa situation, il est recommandé de se référer aux informations officielles de l'administration fiscale."],
    ["Le reçu fiscal Cerfa n° 11580 est-il obligatoire ?", "L'association doit remettre au donateur un justificatif conforme pour que celui-ci puisse bénéficier de la réduction d'impôt. Le modèle officiel est le Cerfa n° 11580 ; un reçu reprenant fidèlement l'ensemble de ses mentions obligatoires est admis. En cas de situation particulière (dons en nature, abandon de frais, éligibilité incertaine), rapprochez-vous de l'administration fiscale ou d'un professionnel."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => "Modèle de reçu fiscal d'association (don, Cerfa 11580)",
    'description' => "Modèle gratuit de reçu fiscal pour don à une association : mentions obligatoires du Cerfa n° 11580, articles 200 et 238 bis du CGI, texte prêt à copier.",
    'path'        => '/modele-recu-fiscal-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><a href="/modeles-gratuits-association">Modèles gratuits</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Reçu fiscal de don</strong></div>
    <div style="max-width:760px;">
      <span class="lp-badge reveal"><span class="dot"></span> Modèle gratuit · Don &amp; défiscalisation</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Modèle de <span class="lp-grad">reçu fiscal</span> pour association</h1>
      <p class="pub-tagline reveal" style="max-width:640px;">Un modèle de reçu fiscal de don prêt à copier, reprenant les mentions obligatoires du <strong>Cerfa n° 11580</strong> et les articles 200 et 238 bis du CGI. Gratuit, avec des explications claires et prudentes.</p>
      <div class="lp-hero-cta reveal">
        <a href="#modele" class="lp-btn lp-btn-primary">Voir le modèle
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg></a>
        <a href="/signup" class="lp-btn lp-btn-glass">Éditer avec Assokit</a>
      </div>
      <div class="lp-trust reveal"><span>✓ Cerfa n° 11580</span><span>✓ Art. 200 &amp; 238 bis CGI</span><span>🇫🇷 Loi 1901</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="reveal" style="max-width:760px;margin:0 auto 34px;padding:16px 20px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;font-size:14.5px;line-height:1.7;color:#78350F;">
      <strong>À lire avant tout&nbsp;:</strong> ce contenu est fourni à titre informatif et ne constitue pas un conseil fiscal. Seules les associations réellement <strong>éligibles</strong> (d'intérêt général au sens du CGI) peuvent émettre un reçu fiscal. En cas de doute sur votre situation, rapprochez-vous de l'administration fiscale ou d'un professionnel.
    </div>
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">L'essentiel</span><h2 class="pub-h2">Comprendre le <em>reçu fiscal</em> de don</h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">Lorsqu'un particulier ou une entreprise fait un don à une association, il peut, sous conditions, bénéficier d'une <strong>réduction d'impôt</strong>. Pour en profiter, le donateur doit disposer d'un justificatif&nbsp;: le <strong>reçu fiscal</strong>. Ce document atteste de la réalité du don et reprend un ensemble de mentions précises. Encore faut-il que l'association soit en droit de l'émettre.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Quelles associations peuvent émettre un reçu&nbsp;?</h3>
      <p style="margin:0 0 18px;">Seules les associations <strong>d'intérêt général</strong> au sens des articles <strong>200</strong> (dons des particuliers) et <strong>238 bis</strong> (dons des entreprises) du Code général des impôts peuvent délivrer un reçu ouvrant droit à réduction d'impôt. Pour être considérée d'intérêt général, une association doit notamment être à but <strong>non lucratif</strong>, avoir une <strong>gestion désintéressée</strong>, ne pas profiter à un cercle restreint de personnes et exercer une activité entrant dans les domaines visés par la loi (caritatif, éducatif, culturel, sportif, social, etc.). Si vous avez un doute sur votre éligibilité, il est possible d'interroger l'administration via la procédure de <strong>rescrit fiscal</strong>.</p>

      <div style="margin:0 0 18px;padding:14px 18px;background:#FEF2F2;border-left:3px solid #F87171;border-radius:8px;font-size:14.5px;color:#7F1D1D;">
        <strong>Attention&nbsp;:</strong> émettre un reçu fiscal alors que l'association n'y est pas éligible expose celle-ci à une amende fiscale. Dans le doute, ne délivrez pas de reçu avant d'avoir vérifié votre situation.
      </div>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Le formulaire Cerfa n° 11580</h3>
      <p style="margin:0 0 18px;">Le modèle officiel de reçu est le <strong>Cerfa n° 11580</strong>. Vous pouvez utiliser ce formulaire ou un document qui en reprend fidèlement toutes les mentions. L'important est que rien ne manque&nbsp;: un reçu incomplet peut être refusé au donateur.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Les mentions obligatoires</h3>
      <ul style="margin:0 0 18px;padding-left:22px;line-height:1.8;">
        <li>l'<strong>identité de l'association bénéficiaire</strong>&nbsp;: nom, adresse, objet&nbsp;;</li>
        <li>l'<strong>identité du donateur</strong>&nbsp;: nom, prénom (ou raison sociale) et adresse&nbsp;;</li>
        <li>le <strong>montant du don</strong>, en chiffres et en lettres&nbsp;;</li>
        <li>la <strong>date</strong> du versement&nbsp;;</li>
        <li>la <strong>nature</strong> du don (numéraire, chèque, virement, don en nature, abandon de frais…)&nbsp;;</li>
        <li>la <strong>forme</strong> du don (don manuel, cotisation, etc.)&nbsp;;</li>
        <li>la mention des <strong>articles du CGI</strong> applicables (200 et/ou 238 bis)&nbsp;;</li>
        <li>la <strong>signature</strong> d'une personne habilitée de l'association.</li>
      </ul>
      <p style="margin:0;">Enfin, l'association doit conserver la trace des reçus émis. Un <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de gestion</a> facilite grandement l'édition, la numérotation et l'archivage de ces documents.</p>
    </div>
  </div>
</section>

<section id="modele" class="pub-section pub-section-creme">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">À copier-coller</span><h2 class="pub-h2">Le <em>modèle</em> de reçu fiscal</h2><p class="pub-section-lead">Remplacez les champs entre crochets (<code>[NOM]</code>, <code>[DATE]</code>, <code>[MONTANT]</code>…) par vos informations. Ce modèle reprend les mentions du Cerfa n° 11580.</p></div>
    <div class="reveal" style="background:#fff;border:1px solid var(--c-border);border-radius:16px;overflow:hidden;max-width:760px;margin:0 auto;box-shadow:0 10px 40px rgba(15,23,42,.06);">
      <div style="display:flex;align-items:center;gap:8px;padding:12px 18px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;">
        <span style="width:9px;height:9px;border-radius:50%;background:#F87171;"></span>
        <span style="width:9px;height:9px;border-radius:50%;background:#FBBF24;"></span>
        <span style="width:9px;height:9px;border-radius:50%;background:#34D399;"></span>
        <span style="margin-left:6px;">Reçu fiscal — à personnaliser (modèle Cerfa n° 11580)</span>
      </div>
      <div style="padding:28px 30px;font-family:'Georgia',serif;font-size:15px;line-height:1.7;color:var(--c-encre);">
        <p style="margin:0 0 4px;text-align:center;font-size:12px;letter-spacing:.05em;color:var(--c-text-3);">REÇU AU TITRE DES DONS À CERTAINS ORGANISMES D'INTÉRÊT GÉNÉRAL</p>
        <p style="margin:0 0 20px;text-align:center;font-size:12px;color:var(--c-text-3);">(Articles 200 et 238 bis du Code général des impôts) — Cerfa n° 11580</p>

        <p style="margin:0 0 6px;text-align:right;font-size:13.5px;color:var(--c-text-3);">Reçu n° [NUMÉRO DU REÇU] — Année [ANNÉE]</p>

        <p style="margin:0 0 6px;"><strong>Bénéficiaire du don</strong></p>
        <p style="margin:0 0 4px;">Association&nbsp;: <strong>[NOM DE L'ASSOCIATION]</strong></p>
        <p style="margin:0 0 4px;">Adresse&nbsp;: [ADRESSE COMPLÈTE DE L'ASSOCIATION]</p>
        <p style="margin:0 0 18px;">Objet&nbsp;: [OBJET DE L'ASSOCIATION] — association d'intérêt général à but non lucratif et à gestion désintéressée.</p>

        <p style="margin:0 0 6px;"><strong>Donateur</strong></p>
        <p style="margin:0 0 4px;">Nom et prénom (ou raison sociale)&nbsp;: [NOM DU DONATEUR]</p>
        <p style="margin:0 0 18px;">Adresse&nbsp;: [ADRESSE DU DONATEUR]</p>

        <p style="margin:0 0 6px;"><strong>Don</strong></p>
        <p style="margin:0 0 4px;">L'association reconnaît avoir reçu la somme de&nbsp;: <strong>[MONTANT EN CHIFFRES] €</strong> ([MONTANT EN LETTRES] euros).</p>
        <p style="margin:0 0 4px;">Date du versement&nbsp;: [DATE].</p>
        <p style="margin:0 0 4px;">Nature du don&nbsp;: [numéraire / chèque / virement / don en nature / abandon de frais].</p>
        <p style="margin:0 0 18px;">Forme du don&nbsp;: [don manuel / autre].</p>

        <p style="margin:0 0 16px;padding:12px 16px;background:#F0FDF4;border-left:3px solid #34D399;border-radius:8px;font-size:14px;">
          Ce don ouvre droit, dans les conditions et limites prévues par la loi, à une réduction d'impôt au titre de l'article&nbsp;<strong>200 du CGI</strong> (donateur particulier) ou de l'article&nbsp;<strong>238 bis du CGI</strong> (donateur entreprise).
        </p>

        <p style="margin:0 0 22px;font-size:14px;color:var(--c-text-2);">Le bénéficiaire certifie sur l'honneur que les dons et versements qu'il reçoit ouvrent droit à la réduction d'impôt prévue par les articles susvisés.</p>

        <p style="margin:0 0 18px;">Fait à [LIEU], le [DATE].</p>

        <p style="margin:0 0 2px;">[PRÉNOM NOM]</p>
        <p style="margin:0 0 2px;">[FONCTION — ex&nbsp;: trésorier(ère)]</p>
        <p style="margin:0;">Signature et cachet de l'association</p>
      </div>
    </div>
    <p class="pub-text-center reveal" style="margin-top:16px;font-size:13.5px;color:var(--c-text-3);">Modèle indicatif&nbsp;: n'émettez un reçu que si votre association est réellement éligible, et vérifiez les taux et plafonds en vigueur auprès de l'administration fiscale.</p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Générez et envoyez ces documents automatiquement avec Assokit</h2>
      <p>Éditez, numérotez et archivez vos reçus fiscaux à partir des dons et cotisations encaissés, et envoyez-les automatiquement à vos donateurs. Essai gratuit, sans carte bancaire.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/logiciel-cotisation-association" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Gérer cotisations &amp; dons</a>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Le <em>reçu fiscal</em> d'association</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Aller plus loin</h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/modeles-gratuits-association"><div class="lp-ic">📎</div><h3>Tous les modèles gratuits</h3><p>Statuts, PV d'AG, convocations, reçus fiscaux… Tous nos modèles pour associations loi 1901.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/guide-association-loi-1901"><div class="lp-ic">📗</div><h3>Guide de l'association loi 1901</h3><p>Créer et gérer son association&nbsp;: statuts, obligations, comptabilité et vie associative.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/cotisation-adherent-association-gerer"><div class="lp-ic">💶</div><h3>Gérer la cotisation d'un adhérent</h3><p>Organiser, suivre et encaisser les cotisations — et distinguer cotisation, don et reçu.</p></a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
