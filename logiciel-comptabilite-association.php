<?php
/**
 * logiciel-comptabilite-association.php — Page SEO ciblée « logiciel comptabilité association ».
 * Route : /logiciel-comptabilite-association. Design premium + FAQPage + Breadcrumb + SoftwareApplication.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Logiciel comptabilité association', 'url' => '/logiciel-comptabilite-association'],
]);

$faqs = [
    ['Quel logiciel de comptabilité pour une association ?', "Le meilleur logiciel de comptabilité pour une association loi 1901 relie la compta au quotidien : factures, cotisations et dépenses se comptabilisent automatiquement, avec un plan comptable adapté et une comptabilité analytique par projet. C'est exactement ce que fait Assokit."],
    ['La comptabilité analytique est-elle incluse ?', "Oui, dès l'offre Pro : bilan par projet et par poste, en temps réel, exportable en PDF et Excel. Cela peut remplacer une prestation externalisée souvent estimée autour de 900 € par an. Votre expert-comptable n'a plus qu'à valider."],
    ['Faut-il des connaissances comptables pour l\'utiliser ?', "Non. Assokit est pensé pour les trésoriers bénévoles : catégorisation guidée, justificatifs centralisés, préparation automatique du bilan. Pas besoin d'être comptable."],
    ['Peut-on exporter les comptes pour l\'expert-comptable ?', "Oui : exports PDF et Excel du bilan analytique avec les pièces justificatives rattachées. Vous transmettez un dossier propre et daté, prêt à valider."],
    ['Le logiciel gère-t-il aussi la facturation et les cotisations ?', "Oui, tout est lié : devis, factures, relances de paiement, encaissement des cotisations en ligne — chaque opération alimente automatiquement votre comptabilité."],
    ['Quel plan comptable pour une association ?', "Une association loi 1901 suit le plan comptable associatif issu du règlement ANC n° 2018-06, adapté aux subventions, cotisations et fonds dédiés. Assokit intègre ce plan comptable adapté et pré-catégorise vos opérations, sans que le trésorier ait à mémoriser les numéros de compte."],
    ['Une association doit-elle tenir une comptabilité ?', "Toute association doit tenir une comptabilité, au minimum un suivi des recettes et dépenses. Elle devient plus formelle (comptes annuels, parfois commissaire aux comptes au-delà de certains seuils) dès qu'elle perçoit des subventions publiques importantes, exerce une activité économique soumise à la TVA, ou émet des reçus fiscaux ouvrant droit à réduction d'impôt."],
    ['Comment faire le bilan d\'une association loi 1901 ?', "Le bilan présente à une date donnée le patrimoine de l'association : actif (trésorerie, créances) et passif (dettes, fonds propres, fonds dédiés). Avec Assokit, le bilan et le compte de résultat se construisent automatiquement au fil des écritures ; vous les exportez en PDF ou Excel pour l'assemblée générale et l'expert-comptable."],
    ['Faut-il un expert-comptable pour une association ?', "Ce n'est obligatoire que pour les grandes associations (au-delà de certains seuils de subventions ou d'activité, un commissaire aux comptes peut être requis). Pour la plupart des petites structures, un trésorier bénévole outillé suffit : Assokit prépare un dossier propre et daté que l'expert-comptable n'a plus qu'à valider si vous en sollicitez un."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
$soft_schema = [
    '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
    'name' => 'Assokit', 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web, iOS, Android',
    'description' => "Logiciel de comptabilité pour association loi 1901 : facturation, cotisations, comptabilité analytique par projet, exports pour l'expert-comptable.",
    'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '29.99', 'highPrice' => '49.99', 'priceCurrency' => 'EUR', 'offerCount' => '3'], 'inLanguage' => 'fr-FR',
];

render_public_head([
    'title'       => 'Logiciel de comptabilité pour association loi 1901 · Assokit',
    'description' => 'Logiciel de comptabilité pour association loi 1901 : compta analytique par projet, reçus, export expert-comptable. Essai gratuit, hébergé en France.',
    'path'        => '/logiciel-comptabilite-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema, $soft_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Logiciel comptabilité association</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Comptabilité d'association simplifiée</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:620px;">Le <span class="lp-grad">logiciel de comptabilité</span> pensé pour les associations</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Factures, cotisations, dépenses et <strong>comptabilité analytique par projet</strong> se construisent automatiquement au fil de votre activité. Un dossier propre pour votre expert-comptable, sans être comptable vous-même.</p>
        <div class="lp-hero-cta reveal">
          <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Compta analytique incluse</span><span>🔒 Conforme RGPD</span><span>🇫🇷 Hébergé en France</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Assokit — Comptabilité</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📊</span><div style="flex:1"><div class="t">Bilan par projet</div><div class="s">Recettes / dépenses ventilées</div></div><span class="v">Temps réel</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🧾</span><div style="flex:1"><div class="t">Factures &amp; cotisations</div><div class="s">Comptabilisées automatiquement</div></div><span class="lp-chip" style="background:#D1FAE5;color:#047857">Auto</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">📁</span><div style="flex:1"><div class="t">Pièces justificatives</div><div class="s">Rattachées &amp; centralisées</div></div><span class="lp-chip" style="background:#EDE9FE;color:#6D28D9">Prêt</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">✅</span><div style="flex:1"><div class="t">Export expert-comptable</div><div class="s">PDF &amp; Excel en 1 clic</div></div><span class="v">1 clic</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ce que fait le logiciel</span><h2 class="pub-h2">Une comptabilité qui se tient <em>toute seule</em></h2>
      <p class="pub-section-lead">Fini la ressaisie en fin d'année : chaque opération de votre association alimente directement vos comptes.</p></div>
    <div class="lp-grid">
      <?php foreach ([
        ['🧾','Facturation intégrée','Devis, factures, relances de paiement — comptabilisés automatiquement.'],
        ['💸','Cotisations en ligne','Encaissement, reçus et suivi ; tout remonte dans la compta.'],
        ['📊','Comptabilité analytique','Bilan par projet et par poste, en temps réel, incluse dès l\'offre Pro.'],
        ['📁','Justificatifs centralisés','Chaque pièce rattachée à la bonne opération, exportable en archive.'],
        ['📅','Échéances &amp; alertes','Ne manquez plus une date fiscale ou administrative clé.'],
        ['✅','Prêt pour le comptable','Export PDF / Excel daté — votre expert-comptable n\'a plus qu\'à valider.'],
      ] as $c): ?>
        <div class="lp-card reveal"><div class="lp-ic"><?= $c[0] ?></div><h3><?= $c[1] ?></h3><p><?= $c[2] ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal">
      <span class="pub-section-eyebrow" style="position:relative;color:#A7F3D0;">L'économie</span>
      <div class="big">Incluse dès Pro</div>
      <p>La comptabilité analytique par projet, souvent externalisée à prix fort, est <strong>incluse dès l'offre Pro</strong> — l'argent retourne à vos projets, pas à la paperasse.</p>
      <a href="/comptabilite-analytique" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Comment ça marche
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comment ça marche</span><h2 class="pub-h2">De la première dépense au <em>bilan validé</em></h2>
      <p class="pub-section-lead">Un logiciel de comptabilité d'association pensé pour le trésorier bénévole : chaque étape prépare la suivante, sans double saisie.</p></div>
    <div class="lp-grid">
      <div class="lp-card reveal"><div class="lp-ic">1</div><h3>Enregistrez recettes &amp; dépenses</h3><p>Cotisations, subventions, ventes et frais s'enregistrent en quelques secondes. Chaque opération est rattachée au bon compte du <strong>plan comptable associatif</strong> et au projet concerné, sans jargon.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">2</div><h3>Suivez subventions &amp; fonds dédiés</h3><p>Une <strong>subvention</strong> affectée non consommée est automatiquement isolée en <strong>fonds dédiés</strong>, comme l'exige le plan comptable des associations. Vous savez à tout moment ce qui reste engagé sur chaque action financée.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">3</div><h3>Éditez reçus fiscaux &amp; TVA</h3><p>Générez les <strong>reçus fiscaux</strong> (Cerfa) pour vos donateurs et suivez la <strong>TVA</strong> si votre association exerce une activité assujettie. Tout reste tracé et justifié, pièce à l'appui.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">4</div><h3>Sortez le bilan pour le comptable</h3><p>En fin d'exercice, le <strong>bilan</strong> et le compte de résultat sont déjà prêts. Le <strong>trésorier</strong> exporte un dossier daté que l'<strong>expert-comptable</strong> n'a plus qu'à contrôler et valider.</p></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Comparatif logiciel comptabilité association</span><h2 class="pub-h2">Tableur, logiciel classique ou <em>Assokit</em> ?</h2>
      <p class="pub-section-lead">Pourquoi un tableur ou un logiciel comptable généraliste montrent vite leurs limites pour une association loi 1901.</p></div>
    <div class="reveal" style="overflow-x:auto;background:#fff;border:1px solid var(--c-border);border-radius:16px;">
      <table style="width:100%;border-collapse:collapse;min-width:640px;font-size:15px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:16px 18px;border-bottom:2px solid var(--c-border);color:var(--c-encre);font-weight:700;">Critère</th>
            <th style="text-align:center;padding:16px 18px;border-bottom:2px solid var(--c-border);color:var(--c-encre);font-weight:600;">Tableur classique</th>
            <th style="text-align:center;padding:16px 18px;border-bottom:2px solid var(--c-border);color:var(--c-encre);font-weight:600;">Logiciel comptable classique</th>
            <th style="text-align:center;padding:16px 18px;border-bottom:2px solid #059669;background:#ECFDF5;color:#059669;font-weight:800;">Assokit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
            ['Comptabilité analytique par projet','Manuelle, fragile','En option, complexe','Incluse, en temps réel'],
            ['Cotisations liées automatiquement','Non','Non','Oui, en 1 clic'],
            ['Reçus fiscaux (Cerfa)','À rédiger à la main','Rarement','Générés automatiquement'],
            ['Export expert-comptable','Copier-coller','Oui, technique','PDF &amp; Excel datés'],
            ['Hébergement France / RGPD','Selon vos fichiers','Variable','Hébergé en France 🇫🇷'],
            ['Prise en main','Vous faites tout','Formation comptable','Pensé pour bénévoles'],
            ['Prix','« Gratuit » mais chronophage','Prestation externe','Gratuit pour démarrer'],
          ] as $r): ?>
          <tr>
            <td style="text-align:left;padding:14px 18px;border-bottom:1px solid var(--c-border);color:var(--c-encre);font-weight:600;"><?= $r[0] ?></td>
            <td style="text-align:center;padding:14px 18px;border-bottom:1px solid var(--c-border);color:#64748B;"><?= $r[1] ?></td>
            <td style="text-align:center;padding:14px 18px;border-bottom:1px solid var(--c-border);color:#64748B;"><?= $r[2] ?></td>
            <td style="text-align:center;padding:14px 18px;border-bottom:1px solid var(--c-border);background:#F0FDF4;color:#059669;font-weight:700;"><?= $r[3] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">En détail</span><h2 class="pub-h2">Un logiciel de comptabilité <em>vraiment</em> adapté aux associations</h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Un logiciel de comptabilité pour association ne se limite pas à additionner des recettes et des dépenses. Il doit parler le langage du secteur associatif : plan comptable associatif, ventilation des subventions, gestion des fonds dédiés et édition des reçus fiscaux. Assokit intègre nativement ces spécificités, là où un logiciel comptable généraliste oblige le trésorier à contourner un outil pensé pour les entreprises commerciales.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">La comptabilité analytique par projet est au cœur de la démarche. Chaque action — un événement, un atelier, une action financée par une subvention — dispose de son propre bilan recettes/dépenses en temps réel. Cette lecture par projet, souvent estimée autour de 900 € par an en prestation externalisée, est incluse dès l'offre Pro. Elle rassure les financeurs, simplifie l'assemblée générale et donne au bureau une vision claire de la santé financière de l'association.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Enfin, la comptabilité se relie au quotidien de la structure : cotisations encaissées en ligne, factures émises, justificatifs centralisés et échéances fiscales rappelées. Tout converge vers un dossier propre, exportable en PDF et Excel, prêt pour l'expert-comptable ou le contrôle d'un financeur. Le trésorier bénévole gagne du temps, réduit le risque d'erreur et garde une comptabilité à jour tout au long de l'exercice.</p>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Pour aller plus loin sur la <em>compta associative</em></h2>
      <p class="pub-section-lead">Nos guides pratiques pour maîtriser la comptabilité de votre association loi 1901.</p></div>
    <div class="lp-grid">
      <a class="lp-card reveal" href="/blog/comptabilite-association-savoir-absolument" style="text-decoration:none;color:inherit;"><div class="lp-ic">📘</div><h3>Comptabilité d'association : ce qu'il faut absolument savoir</h3><p>Les obligations, les bons réflexes et les erreurs à éviter pour bien démarrer.</p></a>
      <a class="lp-card reveal" href="/blog/plan-comptable-association-modele-simplifie" style="text-decoration:none;color:inherit;"><div class="lp-ic">🗂️</div><h3>Plan comptable association : le modèle simplifié</h3><p>Un plan comptable associatif clair, prêt à l'emploi pour votre trésorerie.</p></a>
      <a class="lp-card reveal" href="/blog/fonds-dedies-association-comment-comptabiliser-une-subvention-affectee" style="text-decoration:none;color:inherit;"><div class="lp-ic">🎯</div><h3>Fonds dédiés : comptabiliser une subvention affectée</h3><p>La méthode pour isoler et suivre correctement une subvention non consommée.</p></a>
      <a class="lp-card reveal" href="/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer" style="text-decoration:none;color:inherit;"><div class="lp-ic">📅</div><h3>Calendrier comptable 2026 : 12 échéances à ne jamais manquer</h3><p>Toutes les dates fiscales et administratives clés de l'année, mois par mois.</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:28px;display:flex;gap:20px;flex-wrap:wrap;justify-content:center;">
      <a href="/blog?categorie=comptabilite" style="color:var(--c-emeraude-dark);font-weight:700;">Tous nos articles compta →</a>
      <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de cotisation →</a>
      <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de gestion complet →</a>
    </p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Votre <em>comptabilité d'association</em>, simplement</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;"><a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">← Voir le logiciel de gestion complet pour associations</a></p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Simplifiez la compta de votre association</h2>
      <p>Essai gratuit pour démarrer, comptabilité analytique incluse dès l'offre Pro. Sans engagement.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
