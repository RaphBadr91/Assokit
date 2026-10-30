<?php
/**
 * tarifs.php — offre publique
 * --------------------------------------------------------------
 * Trois cartes, aucune formule gratuite :
 * - Essai PRO : gratuit 14 jours, sans carte bancaire, toutes les fonctionnalités
 * - Assokit : prix HT de la formule « assokit » (asso_plans) + TVA de la plateforme
 *   (ak_offer_price, includes-pricing.php : le même calcul que la page de paiement)
 * - Sur-mesure : sur devis (réseaux, fédérations, grandes structures, collectivités)
 * Abonnement mensuel uniquement, sans engagement. Aucun avis, chiffre ou logo inventé.
 * --------------------------------------------------------------
 */
// config.php : prix et TVA lus en base (comme blog.php et contact.php, pages publiques elles aussi)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/app-context.php';
require_once __DIR__ . '/includes-pricing.php';

// Page publique, mais c'est la vitrine tarifaire : elle n'a rien à faire dans
// l'app (Apple 3.1.1). Depuis un navigateur, rien ne change : la session y est
// désormais ouverte (en-tête « Mon tableau de bord », includes-public.php), mais
// un client ou un prospect connecté à la démo doit toujours pouvoir lire les tarifs.
if (ak_is_mobile_app()) ak_block_billing_page();

$offer = ak_offer_price($pdo ?? null);
$trial_days = defined('AK_TRIAL_DAYS') ? (int)AK_TRIAL_DAYS : 14;
// « 59,99 € TTC » ou « TVA non applicable » : jamais écrit en dur
$offer_tax_line = $offer['has_vat']
    ? 'Prix HT · TVA ' . $offer['rate_label'] . ' en sus, soit ' . $offer['ttc'] . ' TTC par mois'
    : ($offer['vat_known'] ? 'TVA non applicable, art. 293 B du CGI' : 'Prix HT');

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Tarifs',  'url' => '/tarifs'],
]);

// Schema SoftwareApplication sans 'offers' : un 'offers' fait classer la page en
// « extrait de produit » par Google, qui réclame alors review/aggregateRating. Sans
// avis notés réels (fabriquer = non conforme), les prix restent en contenu visible.
$product_schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'SoftwareApplication',
    'name'        => 'Assokit — Logiciel de gestion association & TPE',
    'description' => "Logiciel tout-en-un pour associations loi 1901 et TPE : adhérents, cotisations, facturation, devis, comptabilité, assemblées générales, subventions, communication et IA.",
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web, iOS, Android',
    'inLanguage'  => 'fr-FR',
];

// FAQ : source unique de la section visible ET du schema FAQPage (rich results Google)
$tarifs_faqs = [
    ['L’essai est-il vraiment gratuit ?', 'Oui. Pendant ' . $trial_days . ' jours, vous utilisez toutes les fonctionnalités PRO d’Assokit gratuitement, sans carte bancaire et sans engagement. À la fin de l’essai, vous choisissez de continuer avec Assokit, ou vous vous arrêtez : comme aucune carte n’a été demandée, rien n’est prélevé.'],
    ['Puis-je m’abonner pendant l’essai ?', 'Oui, et vous gardez tous vos jours d’essai : votre carte (ou votre mandat de prélèvement SEPA) est enregistrée, mais rien n’est prélevé avant la fin de l’essai. Le premier mois est prélevé automatiquement ce jour-là, et vous pouvez annuler sans frais d’ici là.'],
    ['Combien coûte Assokit après l’essai ?', 'Une seule formule, tout inclus : ' . $offer['label'] . ', sans engagement. Les réseaux, fédérations, grandes structures et collectivités peuvent demander une offre Sur-mesure, sur devis.'],
    ['Y a-t-il un engagement de durée ?', 'Non. L’abonnement est mensuel et résiliable à tout moment depuis votre espace, en quelques clics. La résiliation prend effet à la fin de la période en cours, déjà payée : pas de frais de résiliation, et pas de remboursement au prorata du mois entamé.'],
    ['Existe-t-il une formule gratuite ?', 'Non. Plutôt qu’une version gratuite bridée, nous vous offrons ' . $trial_days . ' jours d’essai complet, puis un seul abonnement qui comprend tout. Vous savez exactement ce que vous payez.'],
    ['Que deviennent mes données à la fin de l’essai ?', 'Elles sont conservées. Vous choisissez votre formule quand vous le souhaitez et vous retrouvez votre espace tel que vous l’avez laissé : adhérents, factures, projets, réglages.'],
    ['Comment se passe le paiement ?', 'Par carte bancaire, en paiement sécurisé par Stripe, avec un prélèvement automatique chaque mois. Vos factures sont disponibles dans votre espace, rubrique « Mes factures ».' . ($offer['has_vat'] ? ' Les prix sont affichés HT, la TVA (' . $offer['rate_label'] . ') s’y ajoute.' : '')],
    ['Mes données sont-elles hébergées en France ?', 'Oui. Assokit est hébergé en France chez O2Switch, à Clermont-Ferrand, et conforme au RGPD. Vos données vous appartiennent : vous pouvez exporter vos adhérents, vos factures et votre fichier des écritures comptables (FEC) à tout moment.'],
    ['Le support est-il humain ?', 'Oui. Notre équipe vous répond en français, par e-mail, en moins de 24 h ouvrées. Pas de robot, de vraies réponses à vos questions.'],
    ['Pour qui est l’offre Sur-mesure ?', 'Pour les réseaux, fédérations, grandes structures et collectivités qui ont des besoins spécifiques : domaine personnel, volumes adaptés, accompagnement à la mise en place, formation de vos équipes. Laissez-nous vos coordonnées, nous vous rappelons pour construire votre devis.'],
    ['Peut-on voir Assokit avant de s’inscrire ?', 'Oui. Demandez une démonstration : nous vous présentons Assokit en visio, en partant de vos besoins concrets. Sans engagement.'],
];
$faq_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => [
        '@type'          => 'Question',
        'name'           => $qa[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $tarifs_faqs),
];

render_public_head([
    'title'       => 'Tarifs Assokit : essai gratuit, puis ' . $offer['per_month'],
    'description' => 'Assokit : essai PRO gratuit ' . $trial_days . ' jours sans carte bancaire, puis ' . $offer['label'] . ' tout inclus, sans engagement. Sur-mesure sur devis.',
    'path'        => '/tarifs',
    'schema_jsonld' => [$breadcrumb, $product_schema, $faq_schema],
]);

render_public_nav('tarifs');

$included = [
    ['👥', 'Adhérents & cotisations', 'Fichier des membres, campagnes de cotisation, suivi des règlements.'],
    ['📁', 'Projets & bilans', 'Projets, tâches, budgets et bilans prêts pour vos financeurs.'],
    ['🗳️', 'Agenda & assemblées générales', 'Convocations, émargement, votes et procès-verbal.'],
    ['🧾', 'Facturation & devis', 'Devis, factures et relances, factures électroniques Factur-X.'],
    ['📊', 'Comptabilité & FEC', 'Trésorerie, comptabilité analytique par projet, export FEC.'],
    ['🤝', 'Subventions', 'Suivi de vos dossiers de subvention, échéances et justificatifs.'],
    ['📣', 'Communication & e-mailings', 'Diffusion d’e-mails ciblés à vos adhérents et contacts.'],
    ['📬', 'Boîte mail Gmail', 'Votre boîte Gmail dans Assokit, triée et rattachée à vos dossiers.'],
    ['✨', 'Assistant IA', 'Courriers, comptes rendus, relances et publications rédigés pour vous.'],
    ['📱', 'Application mobile', 'Votre association dans la poche, sur iPhone et Android.'],
    ['💬', 'Support en français', 'Une équipe humaine qui répond sous 24 h ouvrées.'],
    ['🔒', 'Hébergé en France', 'Chez O2Switch, à Clermont-Ferrand, conforme au RGPD.'],
];
?>

<section class="pub-hero" style="padding: 60px 0 30px;">
  <div class="pub-container pub-text-center">
    <div class="pub-breadcrumb" style="margin-bottom:30px;justify-content:center;display:flex;">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Tarifs</strong>
    </div>
    <span class="pub-eyebrow">💚 Un prix simple, tout compris</span>
    <h1 class="pub-h1" style="max-width:820px;margin:0 auto 18px;">Toute la gestion de votre structure, <em>un seul abonnement</em>.</h1>
    <p class="pub-tagline" style="max-width:660px;margin:0 auto;">
      Adhérents, factures, comptabilité, assemblées générales, subventions, communication : tout est inclus.
      <strong>Testez gratuitement pendant <?= $trial_days ?> jours, sans carte bancaire.</strong>
    </p>
    <div class="ak-hero-ctas">
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer mon essai gratuit</a>
      <a href="/contact?subject=demo" class="pub-btn pub-btn-ghost pub-btn-lg">Demander une démo</a>
    </div>
    <ul class="ak-trust-row">
      <li>✓ Sans carte bancaire</li>
      <li>✓ Sans engagement</li>
      <li>✓ Données conservées</li>
      <li>🇫🇷 Hébergé en France</li>
      <li>💬 Support en français</li>
    </ul>
  </div>
</section>

<!-- PRICING -->
<section class="pub-section ak-pricing-sec">
  <div class="pub-container">

    <div class="ak-pricing-grid">

      <!-- ESSAI PRO -->
      <article class="ak-pcard" id="offre-essai">
        <header class="ak-pcard-head">
          <div class="ak-pcard-name">Essai PRO</div>
          <div class="ak-pcard-tag">Pour découvrir</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt ak-pcard-amt-word">Gratuit</span>
          <span class="ak-pcard-per">pendant <?= $trial_days ?> jours</span>
        </div>
        <p class="ak-pcard-sub">Sans carte bancaire · aucun prélèvement</p>
        <p class="ak-pcard-desc">Toutes les fonctionnalités d’Assokit, activées dès l’inscription. Vous testez sur votre vraie activité, sans rien payer.</p>
        <a href="/signup" class="ak-pcard-cta ak-pcard-cta-ghost">Démarrer mon essai gratuit</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Pendant <?= $trial_days ?> jours :</div>
        <ul class="ak-pcard-features">
          <li><strong>Toutes les fonctionnalités</strong> de la formule Assokit</li>
          <li>Espace prêt en quelques minutes</li>
          <li>Aucune carte bancaire demandée</li>
          <li>Rien ne se renouvelle automatiquement</li>
          <li>Vos données conservées à la fin de l’essai</li>
          <li>Support en français inclus</li>
        </ul>
        <p class="ak-pcard-fine">Pendant l’essai, les envois d’e-mails et les imports sont plafonnés.</p>
      </article>

      <!-- ASSOKIT (FEATURED) -->
      <article class="ak-pcard ak-pcard-featured" id="offre-assokit">
        <span id="offre-pro" class="ak-anchor"></span><span id="offre-essentiel" class="ak-anchor"></span>
        <div class="ak-pcard-badge">⭐ Le plus choisi</div>
        <header class="ak-pcard-head">
          <div class="ak-pcard-name">Assokit</div>
          <div class="ak-pcard-tag">Tout inclus</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt"><?= pub_h(str_replace("\u{00A0}€", '', $offer['ht'])) ?></span><span class="ak-pcard-cur">€</span>
          <span class="ak-pcard-per"><?= $offer['has_vat'] || !$offer['vat_known'] ? 'HT ' : '' ?>/ mois</span>
        </div>
        <p class="ak-pcard-sub"><?= pub_h($offer['ttc_note'] !== '' ? $offer['ttc_note'] . ' · sans engagement' : 'Sans engagement') ?></p>
        <p class="ak-pcard-desc">Un seul abonnement pour gérer, automatiser et professionnaliser votre association ou votre TPE. Pas d’options cachées.</p>
        <a href="/signup" class="ak-pcard-cta ak-pcard-cta-primary">Essayer gratuitement <?= $trial_days ?> jours</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout est inclus :</div>
        <ul class="ak-pcard-features">
          <li><strong>Adhérents &amp; cotisations</strong></li>
          <li><strong>Projets &amp; bilans</strong></li>
          <li><strong>Agenda &amp; AG</strong> avec vote et émargement</li>
          <li><strong>Facturation &amp; devis</strong>, factures Factur-X</li>
          <li><strong>Comptabilité</strong> analytique &amp; export FEC</li>
          <li><strong>Subventions</strong> : dossiers et échéances</li>
          <li><strong>Communication</strong> &amp; e-mailings</li>
          <li><strong>Boîte mail Gmail</strong> intégrée</li>
          <li><strong>Assistant IA</strong> de rédaction</li>
          <li><strong>Application mobile</strong> iPhone &amp; Android</li>
          <li>Sous-domaine <strong>votre-asso.assokit.fr</strong></li>
          <li><strong>Support</strong> en français</li>
        </ul>
        <p class="ak-pcard-fine">Domaine personnel en option : +<?= pub_h(ak_price_eur(AK_ADDON_DOMAIN_HT_CENTS)) ?><?= $offer['has_vat'] || !$offer['vat_known'] ? ' HT' : '' ?>/mois.</p>
      </article>

      <!-- SUR-MESURE -->
      <article class="ak-pcard" id="offre-sur-mesure">
        <header class="ak-pcard-head">
          <div class="ak-pcard-name">Sur-mesure</div>
          <div class="ak-pcard-tag">Grandes structures</div>
        </header>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt ak-pcard-amt-word">Sur devis</span>
        </div>
        <p class="ak-pcard-sub">Réseaux · fédérations · collectivités</p>
        <p class="ak-pcard-desc">Pour les réseaux, fédérations, grandes structures et collectivités qui ont besoin d’un accompagnement et d’une configuration adaptés.</p>
        <a href="/contact?plan=sur-mesure" class="ak-pcard-cta ak-pcard-cta-dark">Être rappelé</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout Assokit, plus :</div>
        <ul class="ak-pcard-features">
          <li><strong>Domaine personnel inclus</strong></li>
          <li>Volumes d’envoi et d’IA adaptés</li>
          <li><strong>Marque blanche</strong> possible</li>
          <li>Accompagnement à la mise en place</li>
          <li>Formation de vos équipes</li>
          <li>Support dédié</li>
          <li>Développements spécifiques sur demande</li>
        </ul>
      </article>
    </div>

    <p class="ak-pricing-foot">
      <?= pub_h($offer_tax_line) ?> · Paiement mensuel par carte · <strong>Sans engagement</strong>, résiliable à tout moment
    </p>
  </div>

  <style>
  .ak-pricing-sec { padding: 30px 0 60px; }
  .ak-hero-ctas { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin: 28px 0 0; }
  .ak-trust-row {
    list-style: none; padding: 0; margin: 22px auto 0;
    display: flex; gap: 10px 22px; justify-content: center; flex-wrap: wrap;
    font-size: 14px; font-weight: 600; color: #065F46;
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
  .ak-anchor { position: absolute; top: -90px; }

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
    gap: 4px; margin-bottom: 6px; min-height: 52px;
  }
  .ak-pcard-cur {
    font-size: 26px; font-weight: 700; color: #111827;
    margin-left: 2px;
  }
  .ak-pcard-amt {
    font-size: 48px; font-weight: 800; color: #111827;
    line-height: 1; letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
  }
  .ak-pcard-amt-word { font-size: 40px; }
  .ak-pcard-per {
    font-size: 13px; color: #6b7280; font-weight: 500;
    margin-left: 4px;
  }
  .ak-pcard-sub {
    font-size: 13px; color: #059669; font-weight: 700;
    margin: 0 0 14px; min-height: 18px;
  }

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
  .ak-pcard-fine { font-size: 12px; color: #6b7280; margin: 16px 0 0; line-height: 1.5; }

  /* Foot */
  .ak-pricing-foot {
    text-align: center; margin-top: 40px;
    color: #6b7280; font-size: 14px;
  }
  .ak-pricing-foot strong { color: #10B981; }

  /* Tout inclus */
  .ak-incl-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
    max-width: 1200px; margin: 0 auto;
  }
  .ak-incl {
    background: #fff; border: 1px solid #E7EDEA; border-radius: 16px; padding: 20px 18px;
  }
  .ak-incl-ico { font-size: 24px; line-height: 1; margin-bottom: 10px; }
  .ak-incl h3 { font-size: 15.5px; margin: 0 0 6px; color: #064E3B; letter-spacing: -0.01em; }
  .ak-incl p { font-size: 13.5px; color: #4b5563; margin: 0; line-height: 1.55; }
  .ak-sec-cta { text-align: center; margin-top: 34px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

  /* Sans risque */
  .ak-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; max-width: 1000px; margin: 0 auto; }
  .ak-step { background: #fff; border: 1px solid #E7EDEA; border-radius: 16px; padding: 24px 22px; }
  .ak-step-num {
    width: 34px; height: 34px; border-radius: 50%; background: #ECFDF5; color: #047857;
    display: flex; align-items: center; justify-content: center; font-weight: 800; margin-bottom: 12px;
  }
  .ak-step h3 { font-size: 16.5px; margin: 0 0 6px; color: #111827; }
  .ak-step p { font-size: 14px; color: #4b5563; margin: 0; line-height: 1.6; }
  .ak-promise {
    max-width: 1000px; margin: 26px auto 0; display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px 26px;
    list-style: none; padding: 0;
  }
  .ak-promise li { font-size: 14.5px; color: #374151; line-height: 1.55; padding-left: 26px; position: relative; }
  .ak-promise li::before { content: "✓"; position: absolute; left: 0; color: #059669; font-weight: 800; }

  /* Responsive */
  @media (max-width: 1000px) {
    .ak-pricing-grid { grid-template-columns: 1fr; max-width: 480px; gap: 24px; }
    .ak-pcard-featured { transform: none; }
    .ak-pcard-featured:hover { transform: translateY(-3px); }
    .ak-pcard-desc { min-height: auto; }
    .ak-incl-grid { grid-template-columns: repeat(2, 1fr); }
    .ak-steps { grid-template-columns: 1fr; max-width: 520px; }
  }
  @media (max-width: 560px) {
    .ak-incl-grid, .ak-promise { grid-template-columns: 1fr; }
  }
  @media (max-width: 480px) {
    .ak-pcard { padding: 28px 22px; }
    .ak-pcard-amt { font-size: 42px; }
    .ak-pcard-amt-word { font-size: 34px; }
  }
  </style>
</section>

<!-- TOUT INCLUS -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Tout inclus</span>
      <h2 class="pub-h2">Un seul outil, <em>tout compris</em>.</h2>
      <p class="pub-section-lead">Plus de tableurs dispersés ni d’abonnements empilés : avec Assokit, chaque information est saisie une fois et sert partout.</p>
    </div>
    <div class="ak-incl-grid">
      <?php foreach ($included as [$ico, $title, $text]): ?>
      <div class="ak-incl">
        <div class="ak-incl-ico" aria-hidden="true"><?= $ico ?></div>
        <h3><?= pub_h($title) ?></h3>
        <p><?= pub_h($text) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ak-sec-cta">
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Essayer gratuitement <?= $trial_days ?> jours</a>
      <a href="/fonctionnalites" class="pub-btn pub-btn-ghost pub-btn-lg">Voir toutes les fonctionnalités</a>
    </div>
  </div>
</section>

<!-- SANS RISQUE -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Zéro risque</span>
      <h2 class="pub-h2">Essayez d’abord. <em>Décidez ensuite.</em></h2>
      <p class="pub-section-lead">Aucune carte bancaire pour l’essai, aucun engagement ensuite : vous restez parce que l’outil vous fait gagner du temps, pas parce qu’un contrat vous retient.</p>
    </div>
    <div class="ak-steps">
      <div class="ak-step">
        <div class="ak-step-num">1</div>
        <h3>Créez votre espace</h3>
        <p>Inscription en ligne, sans carte bancaire. Votre essai PRO démarre aussitôt, avec toutes les fonctionnalités.</p>
      </div>
      <div class="ak-step">
        <div class="ak-step-num">2</div>
        <h3>Testez sur votre vraie activité</h3>
        <p>Pendant <?= $trial_days ?> jours, importez vos adhérents, émettez vos factures, préparez votre AG. Notre équipe répond à vos questions.</p>
      </div>
      <div class="ak-step">
        <div class="ak-step-num">3</div>
        <h3>Continuez… ou pas</h3>
        <p>Assokit vous convient ? Abonnez-vous à <?= pub_h($offer['label']) ?>. Sinon, vous n’avez rien à faire : rien n’est prélevé.</p>
      </div>
    </div>
    <ul class="ak-promise">
      <li><strong>Sans engagement</strong> : abonnement mensuel, résiliable à tout moment, effet en fin de période en cours.</li>
      <li><strong>Sans carte bancaire</strong> pour l’essai : aucun prélèvement surprise.</li>
      <li><strong>Vos données conservées</strong> à la fin de l’essai, et exportables à tout moment.</li>
      <li><strong>Résiliation en quelques clics</strong> depuis votre espace, sans frais.</li>
      <li><strong>Hébergé en France</strong> chez O2Switch, conforme au RGPD.</li>
      <li><strong>Support en français</strong>, humain, sous 24 h ouvrées.</li>
    </ul>
    <div class="ak-sec-cta">
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer mon essai gratuit</a>
    </div>
  </div>
</section>

<!-- BANDEAU 100% ADAPTÉ -->
<section class="pub-section pub-section-creme" style="padding-top:40px;padding-bottom:60px;">
  <div class="pub-container">
    <div class="pub-adapted-banner">
      <div class="pub-adapted-banner-icon">🌿</div>
      <div class="pub-adapted-banner-content">
        <h3>Pensé pour les associations comme pour les TPE</h3>
        <p>Que vous soyez président·e d’association, trésorier·ère, artisan·e ou indépendant·e, Assokit a été pensé <strong>par et pour vous</strong>. Un seul abonnement, les mêmes outils pour tous : pas de version « light » bridée, pas d’options qui s’additionnent.</p>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section" id="faq">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Tout ce que vous voulez <em>savoir</em>.</h2>
    </div>

    <div class="pub-faq">
      <?php foreach ($tarifs_faqs as [$q, $a]): ?>
      <details class="pub-faq-item">
        <summary><?= pub_h($q) ?></summary>
        <div class="pub-faq-item-body"><?= pub_h($a) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Prêt·e à gagner du temps dès cette semaine ?</h2>
      <p><?= $trial_days ?> jours d’essai PRO gratuits, sans carte bancaire. Une question avant de vous lancer ? Notre équipe vous répond.</p>
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer mon essai gratuit</a>
      <a href="/contact" class="pub-btn pub-btn-ghost pub-btn-lg">Nous contacter</a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
