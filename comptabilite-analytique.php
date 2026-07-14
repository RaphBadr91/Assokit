<?php
/**
 * comptabilite-analytique.php
 * Landing "comptabilite analytique incluse" (economie ~900 euros/an).
 * Styles : .pub-* (assets/public.css) + .ake-* (ajoutes en fin de public.css).
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',                 'url' => '/'],
    ['name' => 'Comptabilité analytique', 'url' => '/comptabilite-analytique'],
]);

// FAQPage : reprend les questions visibles plus bas (rich results Google)
$ca_faqs = [
    ['Dois-je vraiment garder un expert-comptable ?', "Oui. AssoKit remplace la prestation de comptabilité analytique (la ventilation par projet et poste, que vous payiez en plus). Votre expert-comptable, lui, conserve son rôle : il contrôle et valide les comptes. Vous lui transmettez simplement un dossier déjà prêt."],
    ['Quelles offres incluent la compta analytique illimitée ?', "Les offres Pro et Sur-mesure incluent le bilan analytique illimité. L'offre Essentiel inclut 1 bilan pour découvrir la fonctionnalité."],
    ['Puis-je tout exporter pour mon comptable ?', "Oui : le bilan analytique s'exporte en PDF et Excel, avec les pièces justificatives rattachées. En Sur-mesure, vous obtenez aussi des exports de données comptables avancés."],
    ['Combien vais-je réellement économiser ?', "Une prestation de compta analytique externalisée tourne autour de 900 €/an. Avec l'offre Pro (~600 €/an), vous gardez jusqu'à 300 €/an — davantage si votre prestation actuelle coûte plus cher ou en facturation annuelle."],
];
$ca_faq_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => [
        '@type'          => 'Question',
        'name'           => $qa[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $ca_faqs),
];

render_public_head([
    'title'         => 'Comptabilité analytique incluse · AssoKit · sans comptable',
    'description'   => 'La comptabilité analytique coûte environ 900 euros par an chez un comptable. Avec AssoKit, elle est incluse dès l\'offre Pro : bilan par projet et par poste, export PDF et Excel, validation par votre expert-comptable.',
    'path'          => '/comptabilite-analytique',
    'schema_jsonld' => [$breadcrumb, $ca_faq_schema],
]);

render_public_nav('');
?>

<!-- HERO -->
<section class="pub-hero" style="padding:64px 0 40px;">
  <div class="pub-container pub-text-center">
    <div class="pub-breadcrumb ake-anim ake-d1" style="justify-content:center;margin-bottom:26px;">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Comptabilité analytique</strong>
    </div>
    <span class="pub-eyebrow ake-anim ake-d1">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
      Incluse dans votre abonnement
    </span>
    <h1 class="pub-h1 ake-anim ake-d2" style="max-width:820px;margin-left:auto;margin-right:auto;">Votre comptabilité analytique, <em>incluse — sans comptable</em></h1>
    <p class="pub-tagline ake-anim ake-d2" style="max-width:640px;">Suivez vos recettes et dépenses par projet et par poste, en temps réel. Dès l'offre Pro, la compta analytique est <strong>illimitée et incluse</strong> — votre expert-comptable n'intervient plus que pour valider les comptes.</p>
    <div class="ake-hero-cta ake-anim ake-d3">
      <a class="pub-btn pub-btn-primary pub-btn-lg" href="/contact">Réserver une démo gratuite
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      <a class="pub-btn pub-btn-ghost pub-btn-lg" href="/tarifs">Voir les tarifs</a>
    </div>
    <div class="ake-anim ake-d3"><span class="ake-hero-stat"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-emeraude-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Jusqu'à <b>900&nbsp;€&nbsp;/&nbsp;an</b> économisés sur votre compta analytique</span></div>
  </div>
</section>

<!-- COMPARATIF -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Le vrai coût</span>
      <h2 class="pub-h2">Ce que vous payez <em>aujourd'hui</em> — et après</h2>
      <p class="pub-section-lead">La ventilation analytique chez un comptable est une prestation à part. Avec AssoKit, elle est intégrée à l'outil.</p>
    </div>
    <div class="ake-compare">
      <article class="ake-card ake-card--sans ake-anim ake-d2">
        <div class="ake-card__top">
          <span class="ake-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg></span>
          <div><h3>Sans AssoKit</h3><span>Compta analytique externalisée</span></div>
        </div>
        <div class="ake-price"><b>~900&nbsp;€</b><em>/ an en moyenne</em></div>
        <ul class="ake-list">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Prestation de ventilation facturée chaque année</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Allers-retours de pièces, délais, ressaisie</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Aucune vision en temps réel sur vos projets</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Coût fixe, quel que soit votre budget</li>
        </ul>
      </article>
      <div class="ake-vs ake-anim ake-d2">VS</div>
      <article class="ake-card ake-card--avec ake-anim ake-d3">
        <span class="ake-tag">OFFRE PRO</span>
        <div class="ake-card__top">
          <span class="ake-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12A10 10 0 1 1 12 2"/><path d="M22 4 12 14.01l-3-3"/></svg></span>
          <div><h3>Avec AssoKit</h3><span>Incluse dès l'offre Pro</span></div>
        </div>
        <div class="ake-price"><b>Incluse</b><em><span class="ake-strike">~900&nbsp;€</span> dans votre abonnement</em></div>
        <ul class="ake-list">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Bilan analytique <strong>illimité</strong>, par projet & poste</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Mis à jour en temps réel, toute l'année</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Export PDF / Excel + pièces justificatives</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Votre expert-comptable valide en quelques clics</li>
        </ul>
      </article>
    </div>
  </div>
</section>

<!-- ECONOMIE + SLIDER -->
<section class="pub-section">
  <div class="pub-container">
    <div class="ake-saving ake-anim ake-d2">
      <div class="ake-saving__grid">
        <div>
          <p class="ake-saving__lbl">Votre économie</p>
          <p class="ake-big">jusqu'à <b>900&nbsp;€</b> <small>/ an</small></p>
          <p class="ake-saving__note">Le coût d'une compta analytique externalisée, désormais compris dans votre abonnement Pro.</p>
        </div>
        <div class="ake-calc">
          <p class="ake-calc__q">Combien vous coûte votre compta analytique aujourd'hui ?</p>
          <input id="akeRange" class="ake-slider" type="range" min="200" max="2400" step="50" value="900" aria-label="Coût annuel actuel">
          <div class="ake-slider__row"><span>200&nbsp;€</span><span>2&nbsp;400&nbsp;€</span></div>
          <div class="ake-cur"><b id="akeCur">900&nbsp;€</b><span>/ an aujourd'hui</span></div>
          <div class="ake-bars">
            <div><div class="ake-bar__top"><span>Aujourd'hui</span><b id="akeNowLbl">900&nbsp;€/an</b></div><div class="ake-bar ake-bar--now"><i id="akeNowBar" style="width:100%"></i></div></div>
            <div><div class="ake-bar__top"><span>Avec AssoKit Pro</span><b>≈ 600&nbsp;€/an</b></div><div class="ake-bar ake-bar--ak"><i id="akeAkBar" style="width:66%"></i></div></div>
          </div>
          <div class="ake-out"><span>Vous économisez</span><b id="akeSave">≈ 300&nbsp;€</b><span>par an *</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- COMMENT CA MARCHE -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">En 3 étapes</span>
      <h2 class="pub-h2">Simple pour vous, <em>carré</em> pour votre comptable</h2>
    </div>
    <div class="ake-steps">
      <div class="ake-step ake-anim ake-d2"><div class="ake-step__n">1</div><div class="ake-step__line"></div><h4>Affectez vos opérations</h4><p>Chaque recette et dépense est rattachée à un projet et à un poste comptable, au fil de l'eau.</p></div>
      <div class="ake-step ake-anim ake-d3"><div class="ake-step__n">2</div><div class="ake-step__line"></div><h4>AssoKit génère le bilan</h4><p>Le bilan analytique se construit tout seul, en temps réel — exportable en PDF ou Excel avec les pièces.</p></div>
      <div class="ake-step ake-anim ake-d4"><div class="ake-step__n">3</div><h4>L'expert-comptable valide</h4><p>Vous transmettez un dossier propre et daté. Il contrôle et valide — sans refaire la saisie.</p></div>
    </div>
  </div>
</section>

<!-- BENEFICES -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Ce que vous obtenez</span>
      <h2 class="pub-h2">Tout pour piloter, <em>rien à ressaisir</em></h2>
    </div>
    <div class="ake-benefits">
      <div class="ake-benefit ake-anim ake-d2"><div class="ake-bic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="7"/><rect x="12" y="6" width="3" height="11"/><rect x="17" y="13" width="3" height="4"/></svg></div><h4>Bilan par projet</h4><p>Recettes, dépenses et résultat ventilés sur chaque action et poste comptable.</p></div>
      <div class="ake-benefit ake-anim ake-d3"><div class="ake-bic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/></svg></div><h4>Export PDF & Excel</h4><p>Un dossier propre et daté, prêt à transmettre à votre expert-comptable.</p></div>
      <div class="ake-benefit ake-anim ake-d4"><div class="ake-bic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 2-2 2 2 3-2 3 2V2l-3 2-3-2-2 2-2-2-3 2z"/><path d="M8 8h8M8 12h6"/></svg></div><h4>Pièces justificatives</h4><p>Factures et reçus rattachés, exportables en une archive complète.</p></div>
      <div class="ake-benefit ake-anim ake-d5"><div class="ake-bic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg></div><h4>Validation experte</h4><p>L'expert-comptable contrôle et valide — sans refaire la moindre saisie.</p></div>
    </div>
  </div>
</section>

<!-- TEASER OFFRES -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Selon votre offre</span>
      <h2 class="pub-h2">Compta analytique <em>illimitée dès Pro</em></h2>
    </div>
    <div class="ake-tiers">
      <a href="/tarifs#offre-essentiel" class="ake-tier ake-anim ake-d2"><div class="ake-tier__name">Essentiel</div><div class="ake-tier__val">1 bilan</div><small>pour découvrir</small></a>
      <a href="/tarifs#offre-pro" class="ake-tier ake-tier--pro ake-anim ake-d3"><div class="ake-tier__name">Pro</div><div class="ake-tier__val"><em>Illimité</em></div><small>par projet & poste</small></a>
      <a href="/tarifs#offre-sur-mesure" class="ake-tier ake-anim ake-d4"><div class="ake-tier__name">Sur-mesure</div><div class="ake-tier__val"><em>Illimité</em></div><small>+ données comptables</small></a>
    </div>
    <div class="pub-text-center"><a class="pub-btn pub-btn-primary pub-btn-lg" href="/tarifs">Voir tous les tarifs</a></div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Ce qu'on nous demande <em>souvent</em></h2>
    </div>
    <div class="ake-faq ake-anim ake-d2">
      <details open><summary>Dois-je vraiment garder un expert-comptable ?</summary><div class="ake-faq__body">Oui. AssoKit remplace la <strong>prestation de comptabilité analytique</strong> (la ventilation par projet et poste, que vous payiez en plus). Votre expert-comptable, lui, conserve son rôle : il <strong>contrôle et valide</strong> les comptes. Vous lui transmettez simplement un dossier déjà prêt.</div></details>
      <details><summary>Quelles offres incluent la compta analytique illimitée ?</summary><div class="ake-faq__body">Les offres <strong>Pro</strong> et <strong>Sur-mesure</strong> incluent le bilan analytique <strong>illimité</strong>. L'offre <strong>Essentiel</strong> inclut <strong>1 bilan</strong> pour découvrir la fonctionnalité.</div></details>
      <details><summary>Puis-je tout exporter pour mon comptable ?</summary><div class="ake-faq__body">Oui : le bilan analytique s'exporte en <strong>PDF</strong> et <strong>Excel</strong>, avec les <strong>pièces justificatives</strong> rattachées. En Sur-mesure, vous obtenez aussi des exports de données comptables avancés.</div></details>
      <details><summary>Combien vais-je réellement économiser ?</summary><div class="ake-faq__body">Une prestation de compta analytique externalisée tourne autour de <strong>900&nbsp;€/an</strong>. Avec l'offre Pro (~600&nbsp;€/an), vous gardez <strong>jusqu'à 300&nbsp;€/an</strong> — davantage si votre prestation actuelle coûte plus cher ou en facturation annuelle.</div></details>
    </div>
  </div>
</section>

<!-- CTA FINAL -->
<section class="pub-section pub-section-encre ake-final">
  <div class="pub-container">
    <h2 class="pub-h2 ake-anim ake-d2">Prêt à économiser sur votre compta&nbsp;?</h2>
    <p class="ake-anim ake-d2">Réservez une démo gratuite : on vous montre le bilan analytique sur vos propres projets, en 20&nbsp;minutes.</p>
    <div class="ake-final__btns ake-anim ake-d3">
      <a class="pub-btn pub-btn-primary pub-btn-lg" href="/contact">Réserver une démo gratuite
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      <a class="pub-btn pub-btn-ghost pub-btn-lg" href="/tarifs">Voir les tarifs</a>
    </div>
    <p class="ake-anim ake-d4" style="font-size:13px;opacity:.6;margin-top:22px;max-width:64ch;margin-left:auto;margin-right:auto;">* Estimation moyenne pour une compta analytique externalisée (~900&nbsp;€/an), variable selon votre structure. AssoKit ne remplace pas votre expert-comptable, qui conserve la validation des comptes.</p>
  </div>
</section>

<script>
(function(){
  var PRO_AN = 600;
  var r=document.getElementById('akeRange'), cur=document.getElementById('akeCur'), now=document.getElementById('akeNowLbl'),
      nowBar=document.getElementById('akeNowBar'), akBar=document.getElementById('akeAkBar'), save=document.getElementById('akeSave');
  function fmt(n){ return Math.round(n).toLocaleString('fr-FR')+'\u00A0€'; }
  function render(){
    var v=parseInt(r.value,10), maxV=Math.max(v,PRO_AN);
    cur.innerHTML=fmt(v); now.innerHTML=fmt(v)+'/an';
    nowBar.style.width=(v/maxV*100).toFixed(1)+'%'; akBar.style.width=(PRO_AN/maxV*100).toFixed(1)+'%';
    var eco=Math.max(0,v-PRO_AN); save.innerHTML=(eco<=0?'0\u00A0€':'≈ '+fmt(eco));
  }
  r.addEventListener('input',render); setTimeout(render,120);
})();
</script>

<?php
render_public_footer();
