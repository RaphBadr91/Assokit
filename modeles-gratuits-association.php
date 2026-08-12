<?php
/**
 * modeles-gratuits-association.php — HUB SEO « modèles gratuits association ».
 * Aimant à backlinks : liste des modèles gratuits téléchargeables/copiables pour associations loi 1901.
 * Route : /modeles-gratuits-association. Breadcrumb + FAQPage. Aucune dépendance $pdo.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Modèles gratuits pour association', 'url' => '/modeles-gratuits-association'],
]);

$faqs = [
    ['Les modèles Assokit sont-ils vraiment gratuits ?', "Oui. Tous les modèles présentés sur cette page sont librement consultables, copiables et réutilisables pour votre association loi 1901, sans inscription ni carte bancaire. Vous copiez le texte, vous remplacez les champs entre crochets, et le document est prêt."],
    ['Quels documents sont indispensables pour créer une association loi 1901 ?', "Deux documents sont incontournables au démarrage : les statuts (qui fixent l'objet, le siège et le fonctionnement) et le procès-verbal de l'assemblée générale constitutive. Viennent ensuite, selon l'activité, le règlement intérieur, les convocations aux assemblées et les reçus de cotisation."],
    ['Peut-on modifier librement ces modèles ?', "Absolument. Ces modèles sont des bases de travail : vous les adaptez à la réalité de votre association (nombre de dirigeants, mode de scrutin, durée des mandats, ressources…). Rien n'est figé, tant que vous respectez le cadre de la loi du 1er juillet 1901."],
    ["Faut-il un avocat pour rédiger les statuts d'une association ?", "Non, ce n'est pas obligatoire. La loi 1901 laisse une grande liberté de rédaction et la plupart des associations rédigent elles-mêmes leurs statuts à partir d'un modèle. Un accompagnement juridique reste utile pour les structures complexes (agrément, reconnaissance d'utilité publique, activités réglementées)."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => 'Modèles gratuits pour association loi 1901',
    'description' => "Modèles gratuits pour association loi 1901 : statuts, procès-verbal d'AG et bien d'autres à venir. Textes prêts à copier, à personnaliser en quelques minutes.",
    'path'        => '/modeles-gratuits-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<style>
.ak-cta{position:relative;overflow:hidden;background:linear-gradient(135deg,#0f172a,#065f46);color:#fff;border-radius:22px;padding:34px 32px;margin:0 auto;max-width:900px;text-align:center}
.ak-cta h2{color:#fff;font-size:clamp(21px,3.4vw,28px);margin:0 0 10px}
.ak-cta p{color:rgba(255,255,255,.86);max-width:620px;margin:0 auto 20px;line-height:1.6;font-size:15.5px}
.ak-cta .lp-btn-glass{background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)}
.ak-cta small{display:block;margin-top:12px;font-size:12.5px;color:rgba(255,255,255,.7)}
.ak-model-badge{display:inline-block;font-size:11.5px;font-weight:800;letter-spacing:.02em;padding:4px 10px;border-radius:999px;margin-bottom:12px}
.ak-badge-ready{background:#D1FAE5;color:#047857}
.ak-badge-soon{background:#FEF3C7;color:#B45309}
.ak-model-card{display:flex;flex-direction:column}
.ak-model-card .lp-btn{margin-top:auto;align-self:flex-start}
</style>

<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Modèles gratuits</strong></div>
    <div style="max-width:760px;">
      <span class="lp-badge reveal"><span class="dot"></span> Ressources gratuites</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Modèles gratuits pour <span class="lp-grad">association loi 1901</span></h1>
      <p class="pub-tagline reveal" style="max-width:640px;">Des <strong>modèles gratuits</strong>, prêts à copier et à personnaliser en quelques minutes : statuts, procès-verbal d'assemblée générale, et d'autres documents à venir. Chaque modèle est un vrai texte utilisable, pas un simple exemple vide — vous remplacez les champs entre crochets et c'est prêt.</p>
      <div class="lp-hero-cta reveal">
        <a href="/signup" class="lp-btn lp-btn-primary">Générer mes documents automatiquement
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <a href="/guide-association-loi-1901" class="lp-btn lp-btn-glass">Guide association loi 1901</a>
      </div>
      <div class="lp-trust reveal"><span>✓ 100% gratuits</span><span>✓ Sans inscription</span><span>🇫🇷 Conformes loi 1901</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Modèles disponibles</span><h2 class="pub-h2">Des documents <em>prêts à l'emploi</em> pour votre association</h2><p class="pub-section-lead">Copiez, personnalisez, utilisez. Chaque modèle est accompagné d'explications claires pour ne rien oublier au moment de la rédaction.</p></div>
    <div class="lp-grid">
      <div class="lp-card ak-model-card reveal">
        <span class="ak-model-badge ak-badge-ready">Disponible</span>
        <div class="lp-ic">📜</div>
        <h3>Modèle de statuts d'association</h3>
        <p style="margin-bottom:18px;">Un modèle de statuts complet et commenté pour une association loi 1901 : dénomination, objet, siège, membres, cotisations, conseil d'administration, assemblée générale, dissolution. 11 articles prêts à personnaliser.</p>
        <a href="/modele-statuts-association" class="lp-btn lp-btn-primary">Voir le modèle de statuts</a>
      </div>
      <div class="lp-card ak-model-card reveal">
        <span class="ak-model-badge ak-badge-ready">Disponible</span>
        <div class="lp-ic">🗳️</div>
        <h3>Modèle de procès-verbal d'AG</h3>
        <p style="margin-bottom:18px;">Un modèle de procès-verbal d'assemblée générale complet : en-tête, ordre du jour, quorum, résolutions, résultats des votes, clôture et signatures. Adapté aux AG ordinaires comme extraordinaires.</p>
        <a href="/modele-proces-verbal-assemblee-generale" class="lp-btn lp-btn-primary">Voir le modèle de PV</a>
      </div>
      <div class="lp-card ak-model-card reveal">
        <span class="ak-model-badge ak-badge-soon">Bientôt</span>
        <div class="lp-ic">✉️</div>
        <h3>Modèle de convocation à l'AG</h3>
        <p>Un modèle de lettre de convocation à l'assemblée générale, avec ordre du jour, date, lieu et modalités de vote et de représentation (pouvoir). En préparation.</p>
      </div>
      <div class="lp-card ak-model-card reveal">
        <span class="ak-model-badge ak-badge-soon">Bientôt</span>
        <div class="lp-ic">🧾</div>
        <h3>Modèle de reçu fiscal (don)</h3>
        <p>Un modèle de reçu fiscal Cerfa pour les dons ouvrant droit à réduction d'impôt, conforme aux mentions obligatoires. En préparation.</p>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="ak-cta reveal">
      <h2>Gagnez du temps : générez ces documents automatiquement avec Assokit</h2>
      <p>Plutôt que de copier-coller et de remplir à la main, laissez Assokit pré-remplir vos statuts, procès-verbaux et convocations à partir des informations de votre association. Vos documents sont générés, datés et archivés en quelques clics.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Essai gratuit, sans carte bancaire</a>
        <a href="/logiciel-association" class="lp-btn lp-btn-glass">Découvrir le logiciel de gestion</a>
      </div>
      <small>Sans engagement · Hébergé en France · Conforme RGPD</small>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Pour aller plus loin</span><h2 class="pub-h2">Comprendre le <em>fonctionnement</em> d'une association</h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/guide-association-loi-1901"><div class="lp-ic">📖</div><h3>Guide de l'association loi 1901</h3><p>Le guide complet pour créer et gérer une association : démarches, statuts, obligations et bonnes pratiques.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/bureau-association-composition-role"><div class="lp-ic">👥</div><h3>Le bureau d'une association</h3><p>Président, trésorier, secrétaire : composition, rôles et responsabilités de chacun.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/adhesion-association-regles-bonnes-pratiques"><div class="lp-ic">🤝</div><h3>L'adhésion en association</h3><p>Règles et bonnes pratiques pour organiser l'adhésion et la cotisation de vos membres.</p></a>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Les <em>modèles gratuits</em> pour association</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
