<?php
/**
 * fonctionnalites.php — PATCH 6.1.b
 * --------------------------------------------------------------
 * Refonte complète :
 * - Module 1 : Projets (NOUVEAU gros bloc)
 * - Module 2 : Facturation
 * - Module 3 : IA Communication + Emailing (sans SMS)
 * - Module 4 : Tableau de bord intelligent (NOUVEAU gros bloc)
 * - 4 carrés : Adhérents, Trésorerie, Diffusion, Sécurité
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',         'url' => '/'],
    ['name' => 'Fonctionnalités', 'url' => '/fonctionnalites'],
]);

// FAQ (contenu factuel, repris dans la section visible plus bas) + schéma FAQPage pour Google
$feat_faqs = [
    ['Assokit convient-il aux associations comme aux TPE ?', "Oui. Assokit réunit dans un seul outil les besoins des associations loi 1901 (adhérents, cotisations, bénévoles, projets) et ceux des TPE, PME et indépendants (devis, factures, suivi des paiements, comptabilité analytique). Mêmes fonctionnalités, mêmes prix justes."],
    ['Puis-je gérer adhérents, cotisations et facturation au même endroit ?', "Oui. Annuaire des adhérents avec rôles personnalisés, relances de cotisations automatiques, devis et factures directement dans la plateforme, suivi des dépenses par projet et export comptable : tout est réuni, sans tableur à maintenir."],
    ['La comptabilité analytique est-elle vraiment incluse ?', "Oui, la comptabilité analytique est incluse dès l'offre Pro : bilan par projet et par poste, en temps réel, exportable en PDF et Excel. Cela représente environ 900 € d'économie par an par rapport à une prestation externalisée. Votre expert-comptable n'intervient plus que pour valider les comptes."],
    ['Puis-je utiliser mon propre nom de domaine (marque blanche) ?', "Oui. Avec l'option white-label, vos adhérents accèdent à votre plateforme via votre propre adresse, avec vos couleurs et votre logo, pour un rendu 100 % professionnel."],
    ['Mes données sont-elles hébergées en France ?', "Oui, 100 %. L'hébergement est français (O2Switch, Clermont-Ferrand), avec double authentification, journal des actions et conformité RGPD. Vos données restent les vôtres et ne sortent pas du territoire européen."],
    ['Existe-t-il une application mobile ?', "Oui. Assokit s'installe sur iPhone et Android en quelques secondes depuis assokit.fr : icône sur l'écran d'accueil, plein écran, accès rapide à vos projets, adhérents et factures — même hors-ligne."],
    ['Faut-il des compétences techniques pour utiliser Assokit ?', "Non. Assokit est pensé pour être pris en main sans formation. Et si vous avez une question, notre équipe française répond en moins de 24 h — de vraies réponses, jamais un bot."],
];
$feat_faq_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => [
        '@type'          => 'Question',
        'name'           => $qa[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $feat_faqs),
];

render_public_head([
    'title'       => 'Fonctionnalités du logiciel Assokit pour associations & TPE',
    'description' => 'Toutes les fonctionnalités du logiciel Assokit pour gérer votre association loi 1901 ou votre TPE : adhérents, cotisations, facturation, comptabilité analytique, projets, emailing et IA. Essai gratuit, hébergé en France.',
    'path'        => '/fonctionnalites',
    'schema_jsonld' => [$breadcrumb, $feat_faq_schema],
]);

render_public_nav('fonctionnalites');
?>

<section class="pub-hero" style="padding: 60px 0 40px;">
  <div class="pub-container">
    <div class="pub-breadcrumb">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Fonctionnalités</strong>
    </div>
    <span class="pub-eyebrow">📦 4 modules clés · 1 outil</span>
    <h1 class="pub-h1" style="max-width:780px;">Tout ce qu'il faut pour gérer votre <em>association</em> ou votre <em>TPE</em>.</h1>
    <p class="pub-tagline" style="max-width:680px;">
      Quatre modules clés, pensés pour s'effacer derrière vos vraies priorités. <strong>Pas d'usine à gaz, pas de jargon.</strong>
    </p>
  </div>
</section>

<!-- ============================================================ -->
<!-- TOGGLE : Vue Asso / Vue TPE (switch via ancres) -->
<!-- ============================================================ -->
<section class="ak-toggle-sec">
  <div class="pub-container">
    <div class="ak-toggle-row">
      <span class="ak-toggle-lbl">Filtrer la vue :</span>
      <a href="#asso" class="ak-toggle-chip is-active" id="ak-chip-asso">🏛️ Vue Association</a>
      <a href="#tpe" class="ak-toggle-chip" id="ak-chip-tpe">🛠️ Vue TPE / Indé</a>
    </div>
    <div class="ak-toggle-links">
      <a href="/pour-associations" class="ak-toggle-page" id="ak-page-asso">🏛️ Voir la page dédiée aux associations <span aria-hidden="true">→</span></a>
      <a href="/pour-tpe" class="ak-toggle-page" id="ak-page-tpe">🛠️ Voir la page dédiée aux TPE&nbsp;/&nbsp;PME <span aria-hidden="true">→</span></a>
    </div>
  </div>
  <style>
  .ak-toggle-sec { padding: 14px 0 0; }
  .ak-toggle-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: center; }
  .ak-toggle-lbl { font-size: 13px; color: #6b7280; font-weight: 600; }
  .ak-toggle-chip { padding: 9px 18px; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 999px; font-size: 13.5px; font-weight: 600; color: #4b5563; text-decoration: none; transition: all 0.2s; }
  .ak-toggle-chip:hover { border-color: #10B981; color: #10B981; }
  .ak-toggle-chip.is-active { background: #10B981; color: #fff; border-color: #10B981; }
  .ak-toggle-links { display: flex; gap: 10px 20px; flex-wrap: wrap; justify-content: center; margin-top: 12px; }
  .ak-toggle-page { font-size: 13.5px; font-weight: 600; color: #6b7280; text-decoration: none; padding: 6px 12px; border-radius: 999px; transition: all 0.18s; border: 1px solid transparent; }
  .ak-toggle-page:hover { color: #059669; background: #ECFDF5; }
  .ak-toggle-page.is-hot { color: #059669; background: #ECFDF5; border-color: #A7F3D0; }
  </style>
  <script>
  (function(){
    function update(){
      var hash = location.hash === "#tpe" ? "tpe" : "asso";
      document.querySelectorAll(".ak-toggle-chip").forEach(c=>c.classList.remove("is-active"));
      var el = document.getElementById("ak-chip-" + hash);
      if (el) el.classList.add("is-active");
      var pa = document.getElementById("ak-page-asso"), pt = document.getElementById("ak-page-tpe");
      if (pa && pt){ pa.classList.toggle("is-hot", hash==="asso"); pt.classList.toggle("is-hot", hash==="tpe"); }
    }
    window.addEventListener("hashchange", update);
    update();
  })();
  </script>
</section>

<!-- ============================================================ -->
<!-- VUE TPE : 4 modules avec mockups orientés business -->
<!-- ============================================================ -->
<div id="tpe">

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#1E40AF;">Module 1 · Facturation</span>
        <h2 class="pub-h2" style="text-align:left;">Devis &amp; factures, <em>en quelques clics</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Créez devis et factures avec votre logo, suivez les statuts en temps réel, signature électronique pour les devis. Modèles personnalisables, numérotation auto, exports PDF prêts à envoyer.</p>
        <ul class="pub-features-checklist">
          <li>✓ Devis avec signature électronique</li>
          <li>✓ Facturation HT / TVA / TTC</li>
          <li>✓ Factures récurrentes</li>
          <li>✓ Numérotation automatique</li>
          <li>✓ Export PDF avec votre logo</li>
        </ul>
      </div>
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Mes factures · vue résumée</div>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#D1FAE5;color:#065F46;">📄</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">FAC-2026-0142 · Cabinet Dupont</div>
              <div class="pub-hero-row-sub">1 250 € HT · Payée le 08/05</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#D1FAE5;color:#065F46;">Payée</span>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#FEF3C7;color:#92400E;">📄</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">FAC-2026-0141 · SARL Martin</div>
              <div class="pub-hero-row-sub">580 € HT · Échéance dans 12j</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEF3C7;color:#92400E;">En attente</span>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#DBEAFE;color:#1E40AF;">✍️</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">DEV-2026-0089 · Mme Bernard</div>
              <div class="pub-hero-row-sub">2 100 € HT · Signé le 09/05</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#DBEAFE;color:#1E40AF;">Signé</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Relances clients · Auto</div>
          </div>
          <div style="padding:14px;background:linear-gradient(135deg,#FEF3C7,#FED7AA);border-radius:10px;margin-bottom:12px;">
            <div style="font-size:11px;color:#92400E;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">⚡ Relances automatiques</div>
            <div style="font-size:18px;font-weight:800;color:#7C2D12;margin-top:4px;">3 factures impayées</div>
            <div style="font-size:12px;color:#9A3412;margin-top:2px;">Total à recouvrer : 1 850 € HT</div>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#FECACA;color:#991B1B;">⏰</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Mme Lefèvre · FAC-0138</div>
              <div class="pub-hero-row-sub">850 € HT · J+15 · 2 relances envoyées</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FECACA;color:#991B1B;">Retard</span>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#FED7AA;color:#9A3412;">⏰</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">M. Garcia · FAC-0135</div>
              <div class="pub-hero-row-sub">420 € HT · J+30 · Relance auto J+45</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FED7AA;color:#9A3412;">J+30</span>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#92400E;">Module 2 · Relances</span>
        <h2 class="pub-h2" style="text-align:left;">Relances clients <em>automatiques</em>, fini les impayés.</h2>
        <p class="pub-section-lead" style="text-align:left;">Programmez vos relances : J+7, J+15, J+30. Emails personnalisés générés par IA, avec ton ferme ou amical selon votre choix. Taux de recouvrement +35% en moyenne.</p>
        <ul class="pub-features-checklist">
          <li>✓ Scénarios J+7 / J+15 / J+30 / J+45</li>
          <li>✓ Emails IA personnalisés par client</li>
          <li>✓ Suivi des paiements en temps réel</li>
          <li>✓ Historique des relances par facture</li>
          <li>✓ Export comptable (CSV / Excel)</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#065F46;">Module 3 · Pilotage</span>
        <h2 class="pub-h2" style="text-align:left;">Votre tableau de bord <em>commercial en direct</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">CA du mois, encours clients, devis en cours, taux de transformation. Tous vos indicateurs en un coup d'œil, mis à jour en temps réel. Décidez, ne devinez plus.</p>
        <ul class="pub-features-checklist">
          <li>✓ CA mensuel / trimestriel / annuel</li>
          <li>✓ Encours clients par échéance</li>
          <li>✓ Pipeline devis (en cours / signés / refusés)</li>
          <li>✓ Top clients par CA</li>
          <li>✓ Graphiques exportables</li>
        </ul>
      </div>
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Tableau de bord · Mai 2026</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
            <div style="padding:14px;background:#ECFDF5;border-radius:10px;">
              <div style="font-size:10.5px;color:#065F46;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">CA du mois</div>
              <div style="font-size:22px;font-weight:800;color:#064E3B;margin-top:4px;">12 450 €</div>
              <div style="font-size:11px;color:#10B981;font-weight:600;margin-top:2px;">↑ +18% vs avril</div>
            </div>
            <div style="padding:14px;background:#FEF3C7;border-radius:10px;">
              <div style="font-size:10.5px;color:#92400E;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Encours</div>
              <div style="font-size:22px;font-weight:800;color:#7C2D12;margin-top:4px;">4 200 €</div>
              <div style="font-size:11px;color:#9A3412;font-weight:600;margin-top:2px;">5 factures ouvertes</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="padding:14px;background:#DBEAFE;border-radius:10px;">
              <div style="font-size:10.5px;color:#1E40AF;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Devis en cours</div>
              <div style="font-size:22px;font-weight:800;color:#1E3A8A;margin-top:4px;">8</div>
              <div style="font-size:11px;color:#3B82F6;font-weight:600;margin-top:2px;">14 800 € potentiel</div>
            </div>
            <div style="padding:14px;background:#F3E8FF;border-radius:10px;">
              <div style="font-size:10.5px;color:#7E22CE;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Top client</div>
              <div style="font-size:14px;font-weight:800;color:#581C87;margin-top:6px;">Cabinet Dupont</div>
              <div style="font-size:11px;color:#9333EA;font-weight:600;margin-top:2px;">3 200 € ce mois</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">IA · Génération email pro</div>
          </div>
          <div style="padding:12px;background:#F5F3FF;border-radius:8px;margin-bottom:10px;font-size:12px;color:#5B21B6;">
            <strong>✨ Prompt :</strong> Email de relance pour facture impayée à un client fidèle, ton respectueux mais ferme.
          </div>
          <div style="padding:14px;background:#fff;border:1px solid #E0E7FF;border-radius:10px;font-size:12.5px;line-height:1.55;color:#1F2937;">
            <strong>Objet :</strong> Suivi de votre facture FAC-0135<br><br>
            Bonjour M. Garcia,<br><br>
            Sauf erreur de notre part, votre facture du mois dernier reste en attente de règlement. Pourriez-vous régulariser sous 8 jours ?<br><br>
            <span style="color:#94A3B8;">Bien cordialement,<br>[Votre signature]</span>
          </div>
          <div style="margin-top:10px;display:flex;gap:8px;">
            <span style="font-size:11px;padding:4px 10px;background:#EDE9FE;color:#5B21B6;border-radius:999px;font-weight:600;">✨ Généré en 2,3s</span>
            <span style="font-size:11px;padding:4px 10px;background:#ECFDF5;color:#065F46;border-radius:999px;font-weight:600;">📋 Copier</span>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#5B21B6;">Module 4 · Communication IA</span>
        <h2 class="pub-h2" style="text-align:left;">Tous vos emails clients, <em>écrits par IA</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Devis, relances, propositions commerciales, suivis projet, newsletters : Assokit génère vos emails en 2 secondes, dans votre ton. Vous validez, vous envoyez. Gain de temps quotidien : 1h+.</p>
        <ul class="pub-features-checklist">
          <li>✓ 15+ modèles IA prêts à l'emploi</li>
          <li>✓ Personnalisation par client (historique)</li>
          <li>✓ Ton adaptable (formel / amical)</li>
          <li>✓ Newsletter mensuelle automatique</li>
          <li>✓ Suivi des taux d'ouverture</li>
        </ul>
      </div>
    </div>
  </div>
</section>

</div><!-- /tpe -->

<!-- ============================================================ -->
<!-- VUE ASSO : sections existantes ci-dessous -->
<!-- ============================================================ -->
<div id="asso">

<!-- ============================================================ -->
<!-- MODULE 1 : PROJETS (NOUVEAU GROS BLOC) -->
<!-- ============================================================ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#991B1B;">Module 1 · Projets</span>
        <h2 class="pub-h2" style="text-align:left;">Vos projets, <em>enfin sous contrôle</em>.</h2>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 18px;">
          <strong>Fini les groupes WhatsApp pas pro</strong> et les Excel partagés en pagaille. Avec Assokit, chaque projet a son espace dédié :
          équipes assignées, échéances visibles, progression mesurée. <strong>Tout le monde voit où on en est</strong>.
        </p>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 24px;">
          Que ce soit un festival, une AG, un tournage vidéo, un gros chantier ou une campagne d'adhésions — votre équipe avance ensemble, sereinement.
        </p>
        <ul style="list-style:none;padding:0;margin:0;">
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#991B1B;font-weight:700;">✓</span> <strong>Tableaux de progression</strong> visuels par projet</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#991B1B;font-weight:700;">✓</span> Équipes assignées avec rôles et responsabilités</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#991B1B;font-weight:700;">✓</span> Échéances et alertes automatiques</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#991B1B;font-weight:700;">✓</span> Suivi budgétaire intégré (recettes/dépenses)</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#991B1B;font-weight:700;">✓</span> <strong>Visibilité claire pour tous</strong> les adhérents et collaborateurs</li>
        </ul>
      </div>
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Mes projets · vue résumée</div>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#FEE2E2;color:#991B1B;">🎬</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Tournage Vidéo Lycée Pierre Mendès</div>
              <div class="pub-hero-row-sub">Échéance 15j · Équipe de 4 · 60% complété</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEF3C7;color:#92400E;">Actif</span>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#E0F2FE;color:#0C4A6E;">🎥</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Accompagnement Vidéo</div>
              <div class="pub-hero-row-sub">Avancement 45% · 4 étapes restantes</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#D1FAE5;color:#065F46;">En cours</span>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#D1FAE5;color:#059669;">🎉</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Festival d'été 2026</div>
              <div class="pub-hero-row-sub">Préparation · 8 bénévoles · 1 250 €</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#F3E8FF;color:#7E22CE;">Préparation</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- MODULE 2 : FACTURATION -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Factures · vue résumée</div>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#D1FAE5;color:#059669;">📄</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">assokit-2026-000042</div>
              <div class="pub-hero-row-sub">Mairie de Lyon · 1 850 €</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#D1FAE5;color:#065F46;">Payée</span>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#FEF3C7;color:#92400E;">⏳</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">assokit-2026-000041</div>
              <div class="pub-hero-row-sub">Région · 4 200 €</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEF3C7;color:#92400E;">En attente</span>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#FEE2E2;color:#991B1B;">🔔</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">assokit-2026-000038</div>
              <div class="pub-hero-row-sub">Relance auto J+30 envoyée ✨</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEE2E2;color:#991B1B;">Retard</span>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#059669;">Module 2 · Facturation</span>
        <h2 class="pub-h2" style="text-align:left;">Fini les <em>impayés qui dorment</em>.</h2>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 18px;">
          <strong>Assokit relance vos clients à votre place</strong>, automatiquement. À J+15, J+30, J+45 — vos factures impayées
          ne sont plus jamais oubliées. Vous récupérez votre argent. Vous vous concentrez sur ce qui compte vraiment :
          <strong>votre projet</strong>.
        </p>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 24px;">
          De la création du devis à l'encaissement, tout est fluide. PDF générés automatiquement, signature électronique légale, factures récurrentes.
        </p>
        <ul style="list-style:none;padding:0;margin:0;">
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#059669;font-weight:700;">✓</span> <strong>Relances automatiques</strong> à J+15, J+30, J+45</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#059669;font-weight:700;">✓</span> Numérotation automatique aux normes françaises</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#059669;font-weight:700;">✓</span> Devis avec <strong>signature électronique légale</strong></li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#059669;font-weight:700;">✓</span> Factures récurrentes (mensuelles, trimestrielles, annuelles)</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#059669;font-weight:700;">✓</span> Export comptable et PDF professionnels</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- MODULE 3 : IA COMMUNICATION + EMAILING -->
<!-- ============================================================ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#7E22CE;">Module 3 · IA & Emailing</span>
        <h2 class="pub-h2" style="text-align:left;">Une <em>équipe IA</em> dans votre poche, et vos emails ciblés.</h2>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 18px;">
          19 outils IA répartis en 6 thématiques : convocations AG, appels aux dons, posts réseaux sociaux, communiqués
          de presse, demandes de subvention… <strong>Vous donnez les infos, l'IA rédige. Vous validez.</strong>
        </p>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 24px;">
          Et pour diffuser : <strong>emailing ciblé</strong> par rôle, par projet, ou par liste personnalisée. Suivi détaillé : reçu, ouvert, échec. Limite anti-spam intégrée.
        </p>
        <ul style="list-style:none;padding:0;margin:0;">
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#7E22CE;font-weight:700;">✓</span> 19 outils IA dans 6 dossiers thématiques</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#7E22CE;font-weight:700;">✓</span> Convocations AG, comptes-rendus, rapports moraux</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#7E22CE;font-weight:700;">✓</span> Posts LinkedIn, Instagram, Facebook adaptés</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#7E22CE;font-weight:700;">✓</span> <strong>Emailing ciblé</strong> par rôle, projet, liste libre</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#7E22CE;font-weight:700;">✓</span> Suivi détaillé : taux d'ouverture, échecs, délivrabilité</li>
        </ul>
      </div>
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Assokit IA · 6 dossiers</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
            <div style="padding:12px;background:#F3E8FF;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#7E22CE;">📋 Vie associative<br><span style="color:#94A3B8;font-weight:400;">4 outils</span></div>
            <div style="padding:12px;background:#FEE2E2;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#DC2626;">💝 Dons<br><span style="color:#94A3B8;font-weight:400;">3 outils</span></div>
            <div style="padding:12px;background:#E0F2FE;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#0EA5E9;">👥 Adhérents<br><span style="color:#94A3B8;font-weight:400;">3 outils</span></div>
            <div style="padding:12px;background:#FCE7F3;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#EC4899;">📱 Réseaux<br><span style="color:#94A3B8;font-weight:400;">3 outils</span></div>
            <div style="padding:12px;background:#D1FAE5;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#059669;">📊 Rapports<br><span style="color:#94A3B8;font-weight:400;">3 outils</span></div>
            <div style="padding:12px;background:#FEF3C7;border-radius:8px;text-align:center;font-size:12px;font-weight:600;color:#F59E0B;">✉️ Courrier<br><span style="color:#94A3B8;font-weight:400;">3 outils</span></div>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#FCE7F3;color:#9D174D;">📨</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Diffusion Email · Newsletter Mai</div>
              <div class="pub-hero-row-sub">320 destinataires · 78% d'ouverture</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#D1FAE5;color:#065F46;">Envoyée</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- MODULE 4 : TABLEAU DE BORD INTELLIGENT (NOUVEAU GROS) -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Tableau de bord · Assokit</div>
          </div>
          <div class="pub-hero-stat-grid">
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Adhérents</div>
              <div class="pub-hero-stat-value">247</div>
              <div class="pub-hero-stat-trend">↑ +12 ce mois</div>
            </div>
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Trésorerie</div>
              <div class="pub-hero-stat-value">14,2K€</div>
              <div class="pub-hero-stat-trend">↑ +8%</div>
            </div>
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Projets</div>
              <div class="pub-hero-stat-value">8</div>
              <div class="pub-hero-stat-trend">3 actifs</div>
            </div>
          </div>
          <div style="background:var(--c-creme);border-radius:var(--radius-md);padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
            <div>
              <div style="color:var(--c-text-3);text-transform:uppercase;font-size:10px;letter-spacing:0.05em;margin-bottom:4px;">Top client</div>
              <strong style="color:var(--c-encre);">Mairie de Lyon</strong>
              <div style="color:var(--c-emeraude-dark);font-weight:600;">8 450 €</div>
            </div>
            <div>
              <div style="color:var(--c-text-3);text-transform:uppercase;font-size:10px;letter-spacing:0.05em;margin-bottom:4px;">Cotisations à relancer</div>
              <strong style="color:var(--c-encre);">12 adhérents</strong>
              <div style="color:#F59E0B;font-weight:600;">1 anomalie détectée</div>
            </div>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#92400E;">Module 4 · Tableau de bord intelligent</span>
        <h2 class="pub-h2" style="text-align:left;">Le pouls de votre structure, <em>d'un coup d'œil</em>.</h2>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 18px;">
          Plus besoin d'aller chercher l'info dans 5 onglets. <strong>Tout est sur votre tableau de bord</strong> : adhérents, trésorerie,
          projets en cours, top clients, cotisations à relancer, alertes automatiques.
        </p>
        <p style="color:var(--c-text-2);font-size:16px;line-height:1.7;margin:0 0 24px;">
          Filtrez par période, par projet, par responsable. Les <strong>graphiques de tendances</strong> vous montrent ce qui marche, ce qui décroche, ce qu'il faut surveiller.
        </p>
        <ul style="list-style:none;padding:0;margin:0;">
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#92400E;font-weight:700;">✓</span> KPIs en temps réel (adhérents, trésorerie, projets)</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#92400E;font-weight:700;">✓</span> <strong>Graphiques de tendances</strong> mensuels et annuels</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#92400E;font-weight:700;">✓</span> Top clients, top donateurs, top contributeurs</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#92400E;font-weight:700;">✓</span> Alertes automatiques (impayés, anomalies, échéances)</li>
          <li style="padding:8px 0;display:flex;gap:10px;"><span style="color:#92400E;font-weight:700;">✓</span> Filtres par projet, par période, par responsable</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- 4 CARRÉS — Adhérents, Trésorerie, Diffusion, Sécurité -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#5B21B6;">Module 5 · Bilans IA</span>
        <h2 class="pub-h2" style="text-align:left;">Vos bilans <em>en 1 seconde</em>, sans Excel.</h2>
        <p class="pub-section-lead" style="text-align:left;">
          Plus besoin de passer le week-end sur des tableaux. <strong>Assokit génère vos bilans automatiquement</strong> avec graphiques, KPI, analyses IA et recommandations. Cliquez, exportez, présentez.
        </p>
        <ul class="pub-features-checklist">
          <li>📊 <strong>Bilan annuel complet</strong> · prêt pour l'AG</li>
          <li>🎯 <strong>Bilan par projet</strong> · objectifs, ressources, ROI</li>
          <li>💶 <strong>Bilan financier</strong> · recettes, dépenses, trésorerie</li>
          <li>🎉 <strong>Bilan d'événement</strong> · participants, retombées, budget</li>
          <li>✨ <strong>Analyse IA</strong> · ce qui a marché, ce qu'il faut améliorer</li>
          <li>📄 Export PDF avec votre logo · 1 clic</li>
        </ul>
      </div>
      <div>
        <div class="pub-hero-card" style="margin:0;">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">✨ Génération bilan · Festival 2025</div>
          </div>

          <!-- Loader simulé -->
          <div style="padding:12px 14px;background:linear-gradient(90deg,#F5F3FF,#EDE9FE);border-radius:10px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#8B5CF6,#EC4899);display:flex;align-items:center;justify-content:center;font-size:16px;">✨</div>
            <div style="flex:1;">
              <div style="font-size:11.5px;font-weight:700;color:#5B21B6;">Bilan IA généré en 0,8s</div>
              <div style="font-size:10.5px;color:#7C3AED;">12 pages · graphiques inclus</div>
            </div>
            <span style="font-size:10.5px;padding:3px 8px;background:#10B981;color:#fff;border-radius:999px;font-weight:700;">PDF prêt</span>
          </div>

          <!-- Aperçu KPI -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;">
            <div style="padding:10px;background:#ECFDF5;border-radius:8px;">
              <div style="font-size:9.5px;color:#065F46;font-weight:700;text-transform:uppercase;">Participants</div>
              <div style="font-size:18px;font-weight:800;color:#064E3B;">847</div>
              <div style="font-size:10px;color:#10B981;">↑ +24%</div>
            </div>
            <div style="padding:10px;background:#DBEAFE;border-radius:8px;">
              <div style="font-size:9.5px;color:#1E40AF;font-weight:700;text-transform:uppercase;">Budget</div>
              <div style="font-size:18px;font-weight:800;color:#1E3A8A;">3 240€</div>
              <div style="font-size:10px;color:#3B82F6;">−4% prévu</div>
            </div>
            <div style="padding:10px;background:#FEF3C7;border-radius:8px;">
              <div style="font-size:9.5px;color:#92400E;font-weight:700;text-transform:uppercase;">Bénévoles</div>
              <div style="font-size:18px;font-weight:800;color:#7C2D12;">28</div>
              <div style="font-size:10px;color:#F59E0B;">↑ +6</div>
            </div>
          </div>

          <!-- Analyse IA -->
          <div style="padding:12px 14px;background:#fff;border:1px solid #E0E7FF;border-radius:10px;font-size:12.5px;line-height:1.55;color:#1F2937;">
            <strong style="color:#5B21B6;">✨ Analyse IA :</strong> Édition réussie. Affluence record (+24% vs 2024), budget maîtrisé. <strong>Recommandation</strong> : prévoir 35 bénévoles l'an prochain pour l'accueil.
          </div>
        </div>
      </div>
    </div>
  </div>
  <style>
  .pub-features-checklist { list-style:none; padding:0; margin:18px 0 0; display:flex; flex-direction:column; gap:9px; }
  .pub-features-checklist li { font-size:14px; color:#374151; line-height:1.5; }
  </style>
</section>

<!-- ============================================================ -->
<!-- MODULE 5 ASSO : Bilans IA en 1 seconde -->
<!-- ============================================================ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Et tous les essentiels</span>
      <h2 class="pub-h2">Et tout le reste, <em>évidemment</em>.</h2>
    </div>

    <div class="pub-features-4">
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#E0F2FE;color:#0C4A6E;">👥</div>
        <h3>Adhérents & bénévoles</h3>
        <p>Annuaire intelligent, rôles personnalisés (admin, coordinateur, member, follower), espaces membres dédiés, relances cotisations automatiques.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FCE7F3;color:#9D174D;">💰</div>
        <h3>Trésorerie & comptabilité</h3>
        <p>Devis et factures dans la plateforme, relances auto, suivi des dépenses par projet, export comptable. La <a href="/comptabilite-analytique">comptabilité analytique</a> est incluse dès l'offre Pro. Plus jamais de tableur cassé.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FEF3C7;color:#92400E;">📨</div>
        <h3>Diffusion email ciblée</h3>
        <p>Envoyez à un rôle entier, à une équipe projet, ou à une liste libre. Suivi détaillé : reçu, ouvert, échec.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#F3E8FF;color:#7E22CE;">🔐</div>
        <h3>Sécurité & RGPD</h3>
        <p>Hébergement français, double authentification, journal des actions, suppression à la demande. La conformité, sans concession.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- WHITE-LABEL — Domaine personnalisé -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow" style="color:#047857;">🌐 White-label</span>
      <h2 class="pub-h2">Votre marque, votre domaine, <em>partout</em>.</h2>
      <p class="pub-section-lead">
        <strong>Adieu les URL génériques.</strong> Vos adhérents accèdent à votre plateforme via votre propre adresse, avec vos couleurs et votre logo. <em>Effet pro garanti.</em>
      </p>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:24px; max-width:980px; margin:0 auto;">

      <!-- Carte 1 : Sous-domaine -->
      <div style="background:white; border:1px solid var(--c-border); border-radius:var(--radius-xl); padding:32px; box-shadow:0 4px 14px rgba(15, 23, 42, 0.04);">
        <div style="font-size:42px; margin-bottom:14px;">🌍</div>
        <h3 style="margin:0 0 8px; font-size:20px; color:var(--c-encre);">Sous-domaine personnalisé</h3>
        <div style="background:#F0FDF4; color:#047857; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; display:inline-block; margin-bottom:14px; letter-spacing:0.04em;">INCLUS PLAN ASSOKIT</div>
        <p style="color:var(--c-text-2); line-height:1.65; margin:0 0 18px; font-size:14.5px;">
          Vos adhérents accèdent à votre plateforme via une adresse à votre nom :
        </p>
        <div style="background:var(--c-encre); color:#A7F3D0; padding:14px 18px; border-radius:10px; font-family:'Geist Mono', ui-monospace, monospace; font-size:14px; margin-bottom:16px;">
          🔗 <strong style="color:white;">crewhiphop</strong>.assokit.fr
        </div>
        <ul style="padding-left:20px; color:var(--c-text-2); font-size:13.5px; line-height:1.8; margin:0; list-style:none;">
          <li>✅ Activation immédiate</li>
          <li>✅ HTTPS sécurisé automatique</li>
          <li>✅ Logo &amp; couleurs personnalisables</li>
          <li>✅ Inclus dans le plan <strong>Assokit (49,99€/mois)</strong></li>
        </ul>
      </div>

      <!-- Carte 2 : Domaine personnalisé (Premium) -->
      <div style="background:linear-gradient(135deg, #FAF8F5 0%, white 100%); border:2px solid #FCD34D; border-radius:var(--radius-xl); padding:32px; box-shadow:0 8px 24px rgba(252, 211, 77, 0.20); position:relative;">
        <div style="position:absolute; top:-12px; right:24px; background:linear-gradient(135deg, #FCD34D, #F59E0B); color:#78350F; font-size:11px; font-weight:700; padding:5px 12px; border-radius:999px; letter-spacing:0.05em;">⭐ PREMIUM</div>
        <div style="font-size:42px; margin-bottom:14px;">🏆</div>
        <h3 style="margin:0 0 8px; font-size:20px; color:var(--c-encre);">Votre propre domaine</h3>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
          <div style="background:#FEF3C7; color:#92400E; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.04em;">INCLUS SUR-MESURE</div>
          <div style="background:#DBEAFE; color:#1E40AF; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.04em;">+10€/MOIS SUR PLAN ASSOKIT</div>
        </div>
        <p style="color:var(--c-text-2); line-height:1.65; margin:0 0 18px; font-size:14.5px;">
          Plateforme 100% à votre image, sans aucune mention Assokit visible :
        </p>
        <div style="background:var(--c-encre); color:#A7F3D0; padding:14px 18px; border-radius:10px; font-family:'Geist Mono', ui-monospace, monospace; font-size:14px; margin-bottom:16px;">
          🔗 adherents.<strong style="color:white;">crewhiphop.fr</strong>
        </div>
        <ul style="padding-left:20px; color:var(--c-text-2); font-size:13.5px; line-height:1.8; margin:0 0 18px; list-style:none;">
          <li>✅ Domaine 100% à votre nom</li>
          <li>✅ Emails depuis votre adresse <code style="background:#F1F5F9; padding:1px 6px; border-radius:4px; font-size:12px;">contact@crewhiphop.fr</code></li>
          <li>✅ Aucune mention Assokit visible</li>
          <li>✅ White-label total + support prioritaire</li>
        </ul>

        <!-- Add-on Assokit -->
        <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:10px; padding:12px 14px; font-size:13px; line-height:1.55; color:#1E40AF;">
          💡 <strong>Disponible aussi en option sur le plan Assokit pour <strong>+10€/mois</strong></strong> · soit 59,99€/mois total avec votre propre domaine.
        </div>
      </div>
    </div>

    <div style="text-align:center; margin-top:40px;">
      <a href="/contact?subject=demo" class="pub-btn pub-btn-primary">Réserver une démo →</a>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- COMPARATIF -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Comparatif</span>
      <h2 class="pub-h2">Pourquoi pas un <em>tableur</em> ? Ou plusieurs outils ?</h2>
      <p class="pub-section-lead">Parce qu'à un moment, ça ne tient plus.</p>
    </div>

    <div class="pub-comparison-wrapper">

      <!-- ============ VERSION DESKTOP (≥720px) ============ -->
      <div class="pub-comparison-table-desktop" style="background:white;border:1px solid var(--c-border);border-radius:var(--radius-xl);padding:30px;max-width:880px;margin:0 auto;">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:14px;font-size:14px;">
          <div></div>
          <div style="text-align:center;font-weight:700;color:var(--c-text-3);">Tableurs</div>
          <div style="text-align:center;font-weight:700;color:var(--c-text-3);">Multi-outils</div>
          <div style="text-align:center;font-weight:700;color:var(--c-emeraude-dark);">Assokit</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">Suivi de projets centralisé</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">❌</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">IA intégrée pour rédaction</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">❌</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">Relances impayés automatiques</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">❌</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">Diffusion email ciblée</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">❌</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">✅</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">Conformité RGPD native</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);">Support humain &lt;24h</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">❌</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">⚠️</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;">✅</div>

          <div style="padding:12px 0;border-top:1px solid var(--c-border-soft);font-weight:700;">Coût total</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">Gratuit mais chaotique</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;">100€+/mois cumulé</div>
          <div style="text-align:center;border-top:1px solid var(--c-border-soft);padding:12px 0;color:#059669;font-weight:700;">À partir de 49,99€/mois</div>
        </div>
      </div>

      <!-- ============ VERSION MOBILE (<720px) ============ -->
      <div class="pub-comparison-table-mobile" style="max-width:540px;margin:0 auto;">

        <!-- Card Tableurs -->
        <div class="pub-comparison-mobile-card">
          <h4>📊 Tableurs</h4>
          <div class="pub-comparison-mobile-row">
            <div class="label">Suivi de projets centralisé</div>
            <div class="value">❌</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">IA intégrée pour rédaction</div>
            <div class="value">❌</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Relances impayés auto</div>
            <div class="value">❌</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Diffusion email ciblée</div>
            <div class="value">❌</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Conformité RGPD native</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Support humain &lt;24h</div>
            <div class="value">❌</div>
          </div>
          <div class="pub-comparison-mobile-row" style="border-top:1px solid var(--c-border);padding-top:14px;margin-top:6px;">
            <div class="label" style="font-weight:700;">Coût total</div>
            <div class="value" style="color:var(--c-text-2);font-size:13px;">Gratuit mais<br>chaotique</div>
          </div>
        </div>

        <!-- Card Multi-outils -->
        <div class="pub-comparison-mobile-card">
          <h4>🧩 Multi-outils</h4>
          <div class="pub-comparison-mobile-row">
            <div class="label">Suivi de projets centralisé</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">IA intégrée pour rédaction</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Relances impayés auto</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Diffusion email ciblée</div>
            <div class="value">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Conformité RGPD native</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Support humain &lt;24h</div>
            <div class="value">⚠️</div>
          </div>
          <div class="pub-comparison-mobile-row" style="border-top:1px solid var(--c-border);padding-top:14px;margin-top:6px;">
            <div class="label" style="font-weight:700;">Coût total</div>
            <div class="value" style="color:var(--c-text-2);font-size:13px;">100€+/mois<br>cumulé</div>
          </div>
        </div>

        <!-- Card Assokit (featured) -->
        <div class="pub-comparison-mobile-card featured">
          <h4>🌿 Assokit</h4>
          <div class="pub-comparison-mobile-row">
            <div class="label">Suivi de projets centralisé</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">IA intégrée pour rédaction</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Relances impayés auto</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Diffusion email ciblée</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Conformité RGPD native</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row">
            <div class="label">Support humain &lt;24h</div>
            <div class="value" style="color:#059669;">✅</div>
          </div>
          <div class="pub-comparison-mobile-row" style="border-top:1px solid var(--c-emeraude-light);padding-top:14px;margin-top:6px;">
            <div class="label" style="font-weight:700;">Coût total</div>
            <div class="value" style="color:#059669;font-weight:700;font-size:13px;">À partir de<br>49,99€/mois</div>
          </div>
        </div>

      </div>

    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Vos questions sur <em>les fonctionnalités</em>.</h2>
    </div>
    <div class="pub-faq">
<?php foreach ($feat_faqs as $i => $qa): ?>
      <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= pub_h($qa[0]) ?></summary>
        <div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div>
      </details>
<?php endforeach; ?>
    </div>
    <div class="pub-text-center" style="margin-top:26px;">
      <a href="/tarifs" class="pub-btn pub-btn-ghost">Voir les tarifs</a>
      <a href="/comptabilite-analytique" class="pub-btn pub-btn-ghost">La compta analytique incluse</a>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Prêt·e à découvrir Assokit en action ?</h2>
      <p>30 minutes en visio, on regarde ensemble si c'est fait pour vous.</p>
      <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo</a>
    </div>
  </div>
</section>

<style>
/* Grille 4 carrés (au lieu de 6) */
.pub-features-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 22px;
}
.pub-features-4 .pub-feature {
  background: white;
  padding: 26px;
  border-radius: var(--radius-lg);
  border: 1px solid var(--c-border);
  transition: all 0.2s;
}
.pub-features-4 .pub-feature:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-medium);
  border-color: var(--c-emeraude-light);
}
.pub-features-4 .pub-feature h3 {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 700;
  color: var(--c-encre);
}
.pub-features-4 .pub-feature p {
  margin: 0;
  color: var(--c-text-2);
  font-size: 13px;
  line-height: 1.55;
}
.pub-features-4 .pub-feature-ico {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  margin-bottom: 14px;
}
@media (max-width: 1024px) {
  .pub-features-4 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .pub-features-4 { grid-template-columns: 1fr; }
}

/* Responsive 2 colonnes modules → 1 colonne sur mobile/tablette */
@media (max-width: 920px) {
  .pub-features-grid-2 {
    grid-template-columns: 1fr !important;
    gap: 36px !important;
  }
}

/* === FIX MOBILE : Mockups dans les modules === */
@media (max-width: 920px) {
  /* Force tous les éléments du module à passer en pleine largeur */
  .pub-features-grid-2 > div {
    width: 100%;
    min-width: 0;
  }
  /* Le mockup passe sous le texte sur mobile (ordre naturel) */
  .pub-features-grid-2 > div:nth-child(2) {
    order: 2;
  }
  /* Force les images/mockups à ne pas dépasser */
  .pub-features-grid-2 img,
  .pub-features-grid-2 svg {
    max-width: 100%;
    height: auto;
  }
}

@media (max-width: 600px) {
  /* Padding mobile sur les modules */
  .pub-features-grid-2 {
    gap: 24px !important;
  }
  /* Réduire les grilles internes des mockups (stats par exemple) */
  .pub-features-grid-2 > div > div[style*="grid-template-columns:1fr 1fr"] {
    grid-template-columns: 1fr 1fr;
    gap: 6px !important;
  }
}

/* === FIX MOBILE : Tableau comparatif === */
.pub-comparison-wrapper {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.pub-comparison-table-mobile { display: none; }

@media (max-width: 720px) {
  /* Cache le tableau desktop */
  .pub-comparison-table-desktop { display: none !important; }
  /* Affiche les cartes mobiles */
  .pub-comparison-table-mobile { display: block; }
}

.pub-comparison-mobile-card {
  background: white;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-lg);
  padding: 20px;
  margin-bottom: 14px;
}
.pub-comparison-mobile-card.featured {
  border: 2px solid var(--c-emeraude);
  background: linear-gradient(135deg, #F0FDF4 0%, white 100%);
  box-shadow: 0 4px 14px rgba(5, 150, 105, 0.10);
}
.pub-comparison-mobile-card h4 {
  margin: 0 0 14px;
  font-size: 17px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
}
.pub-comparison-mobile-card.featured h4 {
  color: var(--c-emeraude-dark);
}
.pub-comparison-mobile-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid var(--c-border-soft);
  font-size: 14px;
}
.pub-comparison-mobile-row:last-child { border-bottom: none; }
.pub-comparison-mobile-row .label {
  color: var(--c-text-2);
  flex: 1;
  padding-right: 12px;
}
.pub-comparison-mobile-row .value {
  font-weight: 600;
  flex-shrink: 0;
}
</style>

<?php
render_public_footer();
render_public_foot();
