<?php
/**
 * tarifs.php — PATCH 6.1
 * --------------------------------------------------------------
 * Refonte complète :
 * - Plan Démarrage : Gratuit (très limité)
 * - Plan Assokit : 49,99€ TTC
 * - Plan Sur-mesure : Sur devis
 * - Bandeau "100% adapté assos & TPE" (au lieu de réduction asso)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Tarifs',  'url' => '/tarifs'],
]);

render_public_head([
    'title'       => 'Tarifs · Des prix justes pour les associations et les TPE',
    'description' => 'Découvrez les tarifs Assokit adaptés aux associations loi 1901 et aux TPE. Plan Démarrage gratuit, plan Assokit complet, plan Sur-mesure sur devis.',
    'path'        => '/tarifs',
    'schema_jsonld' => [$breadcrumb],
]);

render_public_nav('tarifs');
?>

<section class="pub-hero" style="padding: 60px 0 30px;">
  <div class="pub-container pub-text-center">
    <div class="pub-breadcrumb" style="margin-bottom:30px;justify-content:center;display:flex;">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Tarifs</strong>
    </div>
    <span class="pub-eyebrow">💚 Tarifs transparents</span>
    <h1 class="pub-h1" style="max-width:780px;margin:0 auto 18px;">Des prix justes pour des structures <em>qui font</em>.</h1>
    <p class="pub-tagline" style="max-width:620px;margin:0 auto;">
      Pas de petits caractères. Pas de coûts cachés. <strong>Vous savez exactement ce que vous payez et pourquoi.</strong>
    </p>
  </div>
</section>

<!-- PRICING -->
<section class="pub-section ak-pricing-sec">
  <div class="pub-container">

    <div class="ak-billing-toggle" role="tablist">
      <button type="button" class="ak-bt-opt is-active" data-cycle="monthly" role="tab">Mensuel</button>
      <button type="button" class="ak-bt-opt" data-cycle="yearly" role="tab">Annuel <span class="ak-bt-save">−15%</span></button>
    </div>

    <div class="ak-pricing-grid">

      <!-- ESSENTIEL -->
      <article class="ak-pcard">
        <header class="ak-pcard-head">
          <div class="ak-pcard-name" id="offre-essentiel">Essentiel</div>
          <div class="ak-pcard-tag">Pour commencer</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-cur">€</span><span class="ak-pcard-amt" data-monthly="29,99" data-yearly="25,49">29,99</span>
          <span class="ak-pcard-per">/mois HT</span>
        </div>
        <p class="ak-pcard-yearly" data-yearly-note>soit <strong>305,90&nbsp;€&nbsp;HT</strong> facturé annuellement</p>
        <p class="ak-pcard-desc">L'offre simple pour centraliser la gestion quotidienne de votre association, votre TPE ou votre petite structure.</p>
        <a href="/contact" class="ak-pcard-cta ak-pcard-cta-ghost">Choisir Essentiel</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Inclus :</div>
        <ul class="ak-pcard-features">
          <li>Jusqu'à <strong>30 contacts / adhérents / clients</strong></li>
          <li>Jusqu'à <strong>2 utilisateurs</strong></li>
          <li><strong>20 factures ou devis</strong> / mois</li>
          <li><strong>20 générations IA</strong> / mois</li>
          <li><strong>100 emails</strong> / mois</li>
          <li>Tableau de bord basique</li>
          <li>Gestion simple des contacts</li>
          <li>Espace sécurisé</li>
          <li>Support email</li>
          <li><strong>1 bilan analytique</strong> (découverte)</li>
        </ul>
      </article>

      <!-- PRO (FEATURED) -->
      <article class="ak-pcard ak-pcard-featured">
        <div class="ak-pcard-badge">⭐ Le plus choisi</div>
        <header class="ak-pcard-head">
          <div class="ak-pcard-name" id="offre-pro">Pro</div>
          <div class="ak-pcard-tag">Le plus populaire</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-cur">€</span><span class="ak-pcard-amt" data-monthly="49,99" data-yearly="42,49">49,99</span>
          <span class="ak-pcard-per">/mois HT</span>
        </div>
        <p class="ak-pcard-yearly" data-yearly-note>soit <strong>509,90&nbsp;€&nbsp;HT</strong> facturé annuellement</p>
        <p class="ak-pcard-desc">L'offre complète pour gérer, automatiser et professionnaliser votre association ou votre TPE.</p>
        <a href="/contact" class="ak-pcard-cta ak-pcard-cta-primary">Démarrer l'essai gratuit</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout d'Essentiel, plus :</div>
        <ul class="ak-pcard-features">
          <li>Jusqu'à <strong>400 adhérents actifs</strong></li>
          <li><strong>Contacts / clients illimités</strong></li>
          <li><strong>Comptabilité analytique illimitée</strong> (bilan par projet &amp; poste)</li>
          <li>Jusqu'à <strong>20 utilisateurs</strong></li>
          <li><strong>Facturation illimitée</strong></li>
          <li><strong>Devis illimités</strong> avec signature</li>
          <li>Factures récurrentes</li>
          <li><strong>300 générations IA</strong> / mois</li>
          <li><strong>2 000 emails</strong> / mois</li>
          <li>Modèles IA prêts à l'emploi : emails, courriers, relances, comptes rendus</li>
          <li>Relances automatiques clients / adhérents</li>
          <li>Gestion projets, tâches et suivis internes</li>
          <li>Documents IA : convocations, comptes rendus, courriers</li>
          <li>Statistiques avancées</li>
          <li>Exports PDF &amp; Excel</li>
          <li>Sous-domaine personnalisé : <strong>tonasso.assokit.fr</strong></li>
          <li>Logo &amp; couleurs personnalisables</li>
          <li>Domaine personnel en option : <strong>+10&nbsp;€&nbsp;HT&nbsp;/mois</strong></li>
          <li>Support prioritaire &lt;24h</li>
        </ul>
      </article>

      <!-- SUR-MESURE -->
      <article class="ak-pcard">
        <header class="ak-pcard-head">
          <div class="ak-pcard-name" id="offre-sur-mesure">Sur-mesure</div>
          <div class="ak-pcard-tag">Besoins avancés</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-from">à partir de</span>
          <span class="ak-pcard-cur">€</span><span class="ak-pcard-amt" data-monthly="149" data-yearly="126,65">149</span>
          <span class="ak-pcard-per">/mois HT</span>
        </div>
        <p class="ak-pcard-yearly" data-yearly-note>soit <strong>1&nbsp;519,80&nbsp;€&nbsp;HT</strong> facturé annuellement</p>
        <p class="ak-pcard-desc">L'offre personnalisée pour les grandes associations, fédérations, réseaux, structures multi-sites ou entreprises avec des besoins spécifiques.</p>
        <a href="/contact" class="ak-pcard-cta ak-pcard-cta-dark">Nous contacter</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout de Pro, plus :</div>
        <ul class="ak-pcard-features">
          <li><strong>Contacts / adhérents / clients illimités</strong></li>
          <li><strong>Utilisateurs illimités</strong></li>
          <li><strong>Comptabilité analytique illimitée</strong></li>
          <li>Volume IA personnalisé ou <strong>illimité</strong></li>
          <li>Diffusion email sur volume personnalisé</li>
          <li><strong>Domaine personnel inclus</strong></li>
          <li><strong>White-label</strong> possible</li>
          <li>Tableau de bord avancé personnalisé</li>
          <li>Exports avancés PDF, Excel et données comptables</li>
          <li>Accompagnement à la mise en place</li>
          <li>Formation de votre équipe</li>
          <li>Support dédié</li>
          <li>Développements spécifiques sur demande</li>
        </ul>
      </article>
    </div>

    <p class="ak-pricing-foot">
      Tarifs HT · Paiement mensuel ou annuel (<strong>−15%</strong>) · Aucun engagement de durée
    </p>
  </div>

  <style>
  .ak-pricing-sec { padding: 30px 0 60px; }

  /* Toggle */
  .ak-billing-toggle {
    display: inline-flex; gap: 4px; padding: 5px;
    background: #fff; border: 1px solid #e5e7eb;
    border-radius: 999px; margin: 0 auto 44px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    position: relative; left: 50%; transform: translateX(-50%);
  }
  .ak-bt-opt {
    padding: 10px 24px; border: 0; background: transparent;
    border-radius: 999px; font-size: 14px; font-weight: 600;
    color: #6b7280; cursor: pointer; font-family: inherit;
    transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;
  }
  .ak-bt-opt:hover { color: #111827; }
  .ak-bt-opt.is-active {
    background: #10B981; color: #fff;
    box-shadow: 0 4px 12px rgba(16,185,129,0.25);
  }
  .ak-bt-save {
    background: rgba(255,255,255,0.25); color: inherit;
    padding: 2px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
  }
  .ak-bt-opt:not(.is-active) .ak-bt-save {
    background: #ECFDF5; color: #065F46;
  }

  /* Grid */
  .ak-pricing-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 18px; max-width: 1200px; margin: 0 auto;
    align-items: start;
  }

  /* Card */
  .ak-pcard {
    background: #fff; border: 1px solid #e5e7eb;
    border-radius: 20px; padding: 32px 28px;
    transition: all 0.25s; position: relative;
    display: flex; flex-direction: column;
  }
  .ak-pcard:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(0,0,0,0.06); }

  /* Featured */
  .ak-pcard-featured {
    background: linear-gradient(165deg, #fff 0%, #F0FDF4 100%);
    border: 2px solid #10B981;
    box-shadow: 0 20px 50px rgba(16,185,129,0.18), 0 4px 12px rgba(0,0,0,0.04);
    transform: scale(1.03);
    z-index: 2;
  }
  .ak-pcard-featured:hover { transform: scale(1.03) translateY(-3px); }
  .ak-pcard-badge {
    position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
    background: linear-gradient(135deg, #10B981, #059669); color: #fff;
    padding: 7px 18px; border-radius: 999px;
    font-size: 12px; font-weight: 700; letter-spacing: 0.02em;
    box-shadow: 0 6px 16px rgba(16,185,129,0.35);
    white-space: nowrap;
  }

  /* Head */
  .ak-pcard-head { margin-bottom: 22px; }
  .ak-pcard-name {
    font-size: 22px; font-weight: 800; color: #111827;
    letter-spacing: -0.01em; line-height: 1.1;
  }
  .ak-pcard-featured .ak-pcard-name { color: #065F46; }
  .ak-pcard-tag {
    font-size: 12px; color: #6b7280; margin-top: 4px;
    text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;
  }

  /* Price */
  .ak-pcard-price {
    display: flex; align-items: baseline; flex-wrap: wrap;
    gap: 4px; margin-bottom: 6px;
  }
  .ak-pcard-from {
    width: 100%; font-size: 12px; color: #6b7280;
    font-weight: 600; margin-bottom: 2px;
  }
  .ak-pcard-cur {
    font-size: 22px; font-weight: 700; color: #111827;
    margin-right: 2px;
  }
  .ak-pcard-amt {
    font-size: 48px; font-weight: 800; color: #111827;
    line-height: 1; letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
  }
  .ak-pcard-per {
    font-size: 13px; color: #6b7280; font-weight: 500;
    margin-left: 4px;
  }

  /* Yearly note */
  .ak-pcard-yearly {
    font-size: 12.5px; color: #10B981; font-weight: 600;
    margin: 0 0 16px; min-height: 18px;
    display: none;
  }
  .ak-pcard-yearly.is-visible { display: block; }

  /* Description */
  .ak-pcard-desc {
    font-size: 14px; color: #4b5563; line-height: 1.5;
    margin: 4px 0 22px; min-height: 70px;
  }

  /* CTA */
  .ak-pcard-cta {
    display: block; padding: 13px 18px; border-radius: 11px;
    text-align: center; font-size: 14px; font-weight: 700;
    text-decoration: none; transition: all 0.2s;
    margin-bottom: 22px; letter-spacing: 0.01em;
  }
  .ak-pcard-cta-ghost {
    background: #fff; color: #111827; border: 1.5px solid #e5e7eb;
  }
  .ak-pcard-cta-ghost:hover { border-color: #10B981; color: #10B981; }
  .ak-pcard-cta-primary {
    background: linear-gradient(135deg, #10B981, #059669); color: #fff;
    border: 0; box-shadow: 0 6px 18px rgba(16,185,129,0.35);
  }
  .ak-pcard-cta-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(16,185,129,0.45); }
  .ak-pcard-cta-dark {
    background: #111827; color: #fff; border: 0;
  }
  .ak-pcard-cta-dark:hover { background: #000; }

  /* Divider */
  .ak-pcard-divider {
    height: 1px; background: #f3f4f6;
    margin: 0 0 18px;
  }

  /* Features */
  .ak-pcard-feat-title {
    font-size: 12px; color: #6b7280; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 12px;
  }
  .ak-pcard-features {
    list-style: none; padding: 0; margin: 0;
    display: flex; flex-direction: column; gap: 11px;
  }
  .ak-pcard-features li {
    font-size: 13.5px; color: #374151; line-height: 1.5;
    padding-left: 26px; position: relative;
  }
  .ak-pcard-features li::before {
    content: ""; position: absolute; left: 0; top: 2px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #ECFDF5 url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 20 20\' fill=\'%2310B981\'><path d=\'M16.7 5.3a1 1 0 010 1.4l-7 7a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L9 11.6l6.3-6.3a1 1 0 011.4 0z\'/></svg>") center/12px no-repeat;
  }
  .ak-pcard-features li strong { color: #111827; font-weight: 700; }

  /* Foot */
  .ak-pricing-foot {
    text-align: center; margin-top: 40px;
    color: #6b7280; font-size: 14px;
  }
  .ak-pricing-foot strong { color: #10B981; }

  /* Responsive */
  @media (max-width: 1000px) {
    .ak-pricing-grid { grid-template-columns: 1fr; max-width: 480px; gap: 24px; }
    .ak-pcard-featured { transform: none; }
    .ak-pcard-featured:hover { transform: translateY(-3px); }
    .ak-pcard-desc { min-height: auto; }
  }
  @media (max-width: 480px) {
    .ak-pcard { padding: 28px 22px; }
    .ak-pcard-amt { font-size: 42px; }
  }
  </style>

  <script>
  (function() {
    const opts = document.querySelectorAll(".ak-bt-opt");
    const amounts = document.querySelectorAll(".ak-pcard-amt");
    const notes = document.querySelectorAll("[data-yearly-note]");
    opts.forEach(btn => btn.addEventListener("click", () => {
      opts.forEach(o => o.classList.remove("is-active"));
      btn.classList.add("is-active");
      const cycle = btn.dataset.cycle;
      amounts.forEach(a => a.textContent = cycle === "yearly" ? a.dataset.yearly : a.dataset.monthly);
      notes.forEach(n => n.classList.toggle("is-visible", cycle === "yearly"));
    }));
  })();
  </script>
</section>

<!-- BANDEAU 100% ADAPTÉ -->
<section class="pub-section pub-section-creme" style="padding-top:0;padding-bottom:60px;">
  <div class="pub-container">
    <div class="pub-adapted-banner">
      <div class="pub-adapted-banner-icon">🌿</div>
      <div class="pub-adapted-banner-content">
        <h3>100% adapté aux besoins des associations comme des TPE</h3>
        <p>Que vous soyez président·e d'asso, trésorier·ère, artisan·e ou indépendant·e, Assokit a été pensé <strong>par et pour vous</strong>. Mêmes outils, mêmes simplicité, mêmes prix justes. Pas de version « light » bridée. Pas de version « pro » inaccessible.</p>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Tout ce que vous voulez <em>savoir</em>.</h2>
    </div>

    <div class="pub-faq">
      <details class="pub-faq-item">
        <summary>Le plan Démarrage est vraiment gratuit ?</summary>
        <div class="pub-faq-item-body">Oui, vraiment. Gratuit, sans carte bancaire, sans limite de durée. Mais il est volontairement très limité (3 adhérents, 10 factures, 5 devis, 5 IA/mois) pour vous permettre de découvrir Assokit. Dès que votre activité grandit, le plan Assokit (49,99€ HT) prend le relais.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Y a-t-il un engagement de durée ?</summary>
        <div class="pub-faq-item-body">Non. Vous payez au mois, vous arrêtez quand vous voulez. L'abonnement annuel offre 15% de réduction mais reste résiliable à tout moment (avec proratisation pour les mois non utilisés).</div>
      </details>
      <details class="pub-faq-item">
        <summary>Que se passe-t-il si je dépasse mes générations IA ?</summary>
        <div class="pub-faq-item-body">Vous êtes prévenu·e à 80% et 100% du quota. Au-delà, vous pouvez attendre le mois suivant ou passer au plan supérieur. Aucun débit automatique non sollicité.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Puis-je changer de plan en cours d'abonnement ?</summary>
        <div class="pub-faq-item-body">Oui, à tout moment. Si vous montez en gamme, c'est immédiat (au prorata). Si vous descendez, le changement prend effet au prochain cycle de facturation.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Que se passe-t-il si j'atteins la limite d'adhérents ?</summary>
        <div class="pub-faq-item-body">Sur le plan Démarrage (3 adhérents) ou Assokit (400 adhérents), vous recevez une alerte à 80% puis à 100%. Au-delà, l'ajout de nouveaux adhérents est temporairement bloqué jusqu'à upgrade. Vos données existantes restent intactes.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Mes données sont-elles vraiment hébergées en France ?</summary>
        <div class="pub-faq-item-body">Oui, 100%. Nos serveurs sont chez O2Switch, un hébergeur français basé à Clermont-Ferrand. Aucune donnée ne sort du territoire européen. Conformité RGPD garantie par contrat.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Y a-t-il une démo gratuite ?</summary>
        <div class="pub-faq-item-body">Oui. Réservez 30 minutes en visio avec notre équipe. On vous fait découvrir Assokit en direct, en se concentrant sur vos besoins concrets. Aucun engagement.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Puis-je récupérer mes données si je quitte Assokit ?</summary>
        <div class="pub-faq-item-body">Évidemment. Vos données vous appartiennent. Export complet en un clic (CSV, JSON, PDF) à tout moment. Conservation 30 jours après résiliation pour vous laisser le temps de tout récupérer.</div>
      </details>
      <details class="pub-faq-item">
        <summary>Le support est-il vraiment humain ?</summary>
        <div class="pub-faq-item-body">100%. Aucun bot. Email et chat tenus par notre équipe française. Réponse en moins de 24h sur tous les plans, moins de 4h en Sur-mesure. Réponses utiles, jamais des copier-coller de FAQ.</div>
      </details>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Encore des questions ?</h2>
      <p>L'équipe d'Assokit répond en moins de 24h. Et c'est nous qui répondons, pas un bot.</p>
      <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Nous contacter</a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
