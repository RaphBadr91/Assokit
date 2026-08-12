<?php
/**
 * pour-tpe.php — Landing marketing premium dédiée aux TPE, PME et indépendants.
 * Route : /pour-tpe. SEO + FAQPage + Breadcrumb. Style glass/4D via _landing-premium.php.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',       'url' => '/'],
    ['name' => 'Pour les TPE & PME', 'url' => '/pour-tpe'],
]);

$faqs = [
    ['Assokit est-il adapté à une TPE ou un indépendant ?', "Oui, totalement. Artisans, commerçants, indépendants, petites entreprises : vous pilotez devis, factures, paiements, projets/chantiers et comptabilité depuis un seul outil, sans logiciel comptable en plus."],
    ['Puis-je faire mes devis et factures avec ?', "Oui : devis et factures professionnels, relances de paiement automatiques, suivi des encaissements en temps réel, et scan de facture par IA pour la saisie. Conforme à la facturation française."],
    ['Comment ça marche pour suivre mes chantiers / projets ?', "Chaque client ou chantier devient un projet : étapes, budget alloué vs dépensé, factures rattachées, collègues assignés. Vous savez en un coup d'œil où en est chaque affaire."],
    ['La comptabilité analytique, c\'est utile pour moi ?', "Énormément : vous voyez la rentabilité réelle de chaque chantier/projet, poste par poste, et vous savez quelles affaires vous rapportent. Incluse dès l'offre Pro. Elle ne remplace pas votre comptable — au contraire, elle lui prépare un dossier propre et daté, prêt à valider."],
    ['Mes rendez-vous et mon agenda sont-ils gérés ?', "Oui : notez vos rendez-vous directement dans l'outil, avec rappels. Plus besoin de jongler entre dix applis — tout est centralisé et visible."],
];
$faq_schema = [
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs),
];

render_public_head([
    'title'       => 'Gérer sa TPE & PME au quotidien sans paperasse · Assokit',
    'description' => 'Assokit, le logiciel tout-en-un des TPE, PME et indépendants : devis, factures, relances, suivi de chantiers/projets, comptabilité analytique, agenda et scan de factures par IA. Essai gratuit, hébergé en France.',
    'path'        => '/pour-tpe',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<!-- ===== HERO ===== -->
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Pour les TPE &amp; PME</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Pensé pour les TPE, PME &amp; indépendants</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Pilotez votre entreprise <span class="lp-grad">sans paperasse</span> — devis, factures, chantiers, compta réunis.</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Artisan, commerçant, indépendant : arrêtez de jongler entre dix outils. <strong>Devis, factures, projets, rendez-vous et comptabilité analytique</strong> dans une seule appli, boostée par l'IA.</p>
        <div class="lp-hero-cta reveal">
          <a href="/contact" class="lp-btn lp-btn-primary">Réserver une démo gratuite
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/tarifs" class="lp-btn lp-btn-glass">Voir les tarifs</a>
        </div>
        <div class="lp-trust reveal">
          <span>✓ Essai gratuit</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span><span>🔒 RGPD</span>
        </div>
      </div>
      <!-- Mockup produit -->
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Mon entreprise</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Facture #2026-041</div><div class="s">Client Dupont · échéance 15j</div></div><span class="v">1 250 €</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🏗️</span><div style="flex:1"><div class="t">Chantier Villa Sud</div><div class="s">Budget suivi · 3 factures liées</div></div><span class="lp-chip" style="background:#DBEAFE;color:#1D4ED8">62%</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📸</span><div style="flex:1"><div class="t">Scan facture fournisseur</div><div class="s">Lu &amp; pré-rempli par l'IA</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Auto</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">📅</span><div style="flex:1"><div class="t">RDV client 14h30</div><div class="s">Rappel activé</div></div><span class="lp-chip" style="background:#F1F5F9;color:#475569">Aujourd'hui</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== DOULEURS → SOLUTIONS ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Ce qui change</span>
      <h2 class="pub-h2">Moins d'administratif, <em>plus de chiffre d'affaires.</em></h2>
    </div>
    <div class="lp-grid">
      <?php
      $pains = [
        ['🧾', 'Les devis et factures qui traînent', 'Devis et factures pro en 2 minutes, relances de paiement automatiques, suivi des encaissements.'],
        ['📸', 'La saisie des factures fournisseurs', 'Scannez, l\'IA lit et pré-remplit. Vos justificatifs sont rattachés au bon projet.'],
        ['🏗️', 'Les chantiers difficiles à suivre', 'Un projet par client/chantier : étapes, budget, factures, collègues assignés. Tout est clair.'],
        ['📊', 'La rentabilité qu\'on devine', 'Comptabilité analytique par chantier : vous voyez enfin, poste par poste, ce que chaque affaire vous rapporte vraiment.'],
        ['📅', 'Les rendez-vous dispersés', 'Agenda intégré : vos RDV notés au même endroit que vos clients et vos affaires.'],
        ['📱', 'Le bureau qu\'on n\'a jamais sur soi', 'Application mobile : gérez tout depuis votre téléphone, même sur le terrain.'],
      ];
      foreach ($pains as $p): ?>
        <div class="lp-card reveal">
          <div class="lp-ic"><?= $p[0] ?></div>
          <h3><?= pub_h($p[1]) ?></h3>
          <p><?= pub_h($p[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== TOUT INCLUS ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal">
      <span class="pub-section-eyebrow">Tout inclus</span>
      <h2 class="pub-h2">Le tout-en-un qui remplace <em>vos 10 outils</em></h2>
    </div>
    <ul class="lp-checks reveal">
      <?php
      $feats = ['Devis & factures pro', 'Relances de paiement auto', 'Scan de factures par IA', 'Projets / chantiers', 'Comptabilité analytique', 'Suivi de trésorerie', 'Agenda & rendez-vous', 'Clients & contacts', 'Équipe & rôles', 'Application mobile', 'Hébergement France · RGPD', 'Export de vos données'];
      foreach ($feats as $f): ?>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= pub_h($f) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="pub-text-center reveal" style="margin-top:30px;"><a href="/fonctionnalites" class="lp-btn lp-btn-glass">Découvrir toutes les fonctionnalités</a></div>
  </div>
</section>

<!-- ===== ÉCONOMIE ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal">
      <span class="pub-section-eyebrow" style="position:relative;color:#A7F3D0;">Votre trésorerie, sous contrôle</span>
      <div class="big" style="font-size:clamp(34px,7vw,60px);">Soyez payé plus vite</div>
      <p>Relances de paiement <strong>automatiques</strong>, encaissements suivis en temps réel, et la <strong>rentabilité réelle de chaque chantier</strong> sous les yeux. Vous pilotez votre entreprise — pas juste sa paperasse. Et votre comptable reçoit un dossier propre et daté, prêt à valider.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin:4px 0 22px;">
        <span class="lp-badge" style="background:rgba(255,255,255,.14);border-color:rgba(167,243,208,.5);color:#fff;">💶 Moins d'impayés</span>
        <span class="lp-badge" style="background:rgba(255,255,255,.14);border-color:rgba(167,243,208,.5);color:#fff;">📈 Marges par chantier</span>
        <span class="lp-badge" style="background:rgba(255,255,255,.14);border-color:rgba(167,243,208,.5);color:#fff;">⏱️ Des heures gagnées</span>
      </div>
      <a href="/fonctionnalites" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Voir comment
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
  </div>
</section>

<!-- ===== FAQ ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Vos questions, <em>nos réponses</em>.</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= pub_h($qa[0]) ?></summary>
          <div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== CTA ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Prêt à piloter votre entreprise sereinement ?</h2>
      <p>30 minutes en visio, on regarde ensemble si Assokit est fait pour votre activité. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/contact" class="lp-btn lp-btn-primary">Réserver une démo</a>
        <a href="/avis" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Lire les avis clients</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
