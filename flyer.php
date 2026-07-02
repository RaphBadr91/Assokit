<?php
/**
 * flyer.php - Dépliant Assokit 4 faces (A5 portrait)
 * À imprimer : 2 feuilles A4 recto-verso, plier en 2
 */
require_once __DIR__ . '/config.php';

// Charge mPDF
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('mPDF non installé.');
}
require_once $autoload;

// Init mPDF en A5 portrait
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A5',
    'orientation' => 'P',
    'margin_left' => 0,
    'margin_right' => 0,
    'margin_top' => 0,
    'margin_bottom' => 0,
    'margin_header' => 0,
    'margin_footer' => 0,
    'default_font' => 'helvetica',
]);
$mpdf->SetTitle('Assokit - Dépliant');
$mpdf->SetAuthor('Assokit / RBPS');

// CSS commun
$css = <<<'CSS'
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family: helvetica, Arial, sans-serif; }
.page { width: 148mm; height: 210mm; position: relative; overflow: hidden; }
.bleed { padding: 10mm; height: 100%; }

/* ===== FACE 1 — COUVERTURE ===== */
.cover { background: #064E3B; color: #fff; }
.cover-top { padding: 14mm 10mm 0; }
.cover-badge { display: inline-block; background: rgba(167,243,208,0.18); color: #A7F3D0; padding: 3pt 9pt; border-radius: 30pt; font-size: 8pt; font-weight: 700; letter-spacing: 1pt; margin-bottom: 10mm; }
.cover-logo { font-size: 32pt; font-weight: 800; letter-spacing: -1pt; margin-bottom: 4mm; }
.cover-logo span { color: #A7F3D0; }
.cover-claim { font-size: 18pt; font-weight: 700; line-height: 1.2; margin-bottom: 5mm; letter-spacing: -0.5pt; }
.cover-claim em { color: #A7F3D0; font-style: normal; }
.cover-sub { font-size: 11pt; color: #D1FAE5; line-height: 1.45; margin-bottom: 8mm; font-weight: 400; }
.cover-mid { background: rgba(255,255,255,0.06); padding: 6mm 8mm; border-radius: 6pt; margin: 0 10mm; }
.cover-mid-title { color: #A7F3D0; font-size: 8pt; font-weight: 700; letter-spacing: 1.2pt; text-transform: uppercase; margin-bottom: 3mm; }
.cover-points { color: #ECFDF5; font-size: 9.5pt; line-height: 1.7; }
.cover-points li { list-style: none; padding-left: 0; margin-bottom: 1.5mm; }
.cover-bottom { position: absolute; bottom: 0; left: 0; right: 0; padding: 8mm 10mm; text-align: center; background: rgba(0,0,0,0.18); }
.cover-bottom-claim { font-size: 11pt; font-weight: 700; margin-bottom: 2mm; }
.cover-bottom-claim em { color: #A7F3D0; font-style: normal; }
.cover-bottom-url { font-size: 12pt; color: #fff; font-weight: 700; letter-spacing: 0.5pt; }
.cover-bottom-tel { font-size: 9pt; color: #A7F3D0; margin-top: 1mm; }

/* ===== FACE 2 — ASSOCIATIONS ===== */
.face2 { background: #FAF8F5; color: #064E3B; }
.f-head { padding: 12mm 10mm 6mm; }
.f-eyebrow { display: inline-block; background: #ECFDF5; color: #047857; padding: 2pt 8pt; border-radius: 30pt; font-size: 7.5pt; font-weight: 700; letter-spacing: 0.8pt; margin-bottom: 3mm; }
.f-title { font-size: 19pt; font-weight: 800; line-height: 1.15; letter-spacing: -0.5pt; color: #064E3B; margin-bottom: 2.5mm; }
.f-title em { color: #10B981; font-style: normal; }
.f-sub { font-size: 9.5pt; color: #4b5563; line-height: 1.5; }
.f-body { padding: 0 10mm; }

.module-card { background: #fff; border-radius: 6pt; padding: 5mm 6mm; margin-bottom: 4mm; border-left: 3pt solid #10B981; }
.module-card.violet { border-left-color: #8B5CF6; }
.module-card.amber { border-left-color: #F59E0B; }
.module-card.blue { border-left-color: #3B82F6; }
.module-title { font-size: 11pt; font-weight: 800; color: #064E3B; margin-bottom: 1.5mm; }
.module-title em { color: #10B981; font-style: normal; font-size: 13pt; }
.module-desc { font-size: 8.5pt; color: #4b5563; line-height: 1.55; margin-bottom: 2mm; }
.module-feats { font-size: 8pt; color: #047857; font-weight: 600; }

/* ===== FACE 3 — TPE ===== */
.face3 { background: #fff; color: #1E3A8A; }
.face3 .f-eyebrow { background: #DBEAFE; color: #1E40AF; }
.face3 .f-title em { color: #3B82F6; }
.face3 .module-card { border-left-color: #3B82F6; background: #F8FAFC; }
.face3 .module-card.purple { border-left-color: #6366F1; }
.face3 .module-card.amber { border-left-color: #F59E0B; }
.face3 .module-card.green { border-left-color: #10B981; }
.face3 .module-title { color: #1E3A8A; }
.face3 .module-title em { color: #3B82F6; }
.face3 .module-feats { color: #1E40AF; }

/* ===== FACE 4 — TARIFS + CONTACT ===== */
.face4 { background: #064E3B; color: #fff; padding: 0; }
.f4-head { padding: 12mm 10mm 6mm; text-align: center; }
.f4-eyebrow { display: inline-block; background: rgba(167,243,208,0.20); color: #A7F3D0; padding: 2pt 9pt; border-radius: 30pt; font-size: 7.5pt; font-weight: 700; letter-spacing: 0.8pt; margin-bottom: 3mm; }
.f4-title { font-size: 19pt; font-weight: 800; line-height: 1.15; color: #fff; margin-bottom: 2.5mm; }
.f4-title em { color: #A7F3D0; font-style: normal; }
.f4-sub { font-size: 9pt; color: #D1FAE5; line-height: 1.5; }

.plans { padding: 0 8mm; }
.plan { background: rgba(255,255,255,0.06); border-radius: 5pt; padding: 4mm 5mm; margin-bottom: 3mm; border: 1px solid rgba(167,243,208,0.18); }
.plan.featured { background: #fff; color: #064E3B; border: 2pt solid #A7F3D0; }
.plan-row { width: 100%; }
.plan-row td { vertical-align: top; }
.plan-name { font-size: 11pt; font-weight: 800; color: #fff; }
.plan.featured .plan-name { color: #064E3B; }
.plan-tag { font-size: 7.5pt; color: #A7F3D0; font-weight: 700; }
.plan.featured .plan-tag { color: #10B981; }
.plan-price { font-size: 16pt; font-weight: 800; color: #A7F3D0; text-align: right; line-height: 1; }
.plan.featured .plan-price { color: #047857; }
.plan-unit { font-size: 7pt; color: #D1FAE5; text-align: right; }
.plan.featured .plan-unit { color: #6b7280; }
.plan-feats { font-size: 8pt; color: #ECFDF5; margin-top: 2mm; line-height: 1.55; }
.plan.featured .plan-feats { color: #4b5563; }

.f4-bottom { position: absolute; bottom: 0; left: 0; right: 0; padding: 7mm 10mm; background: rgba(0,0,0,0.22); text-align: center; }
.cta-line { font-size: 10pt; font-weight: 700; color: #fff; margin-bottom: 2mm; }
.cta-tel { font-size: 16pt; font-weight: 800; color: #A7F3D0; letter-spacing: 0.5pt; margin: 2mm 0; }
.cta-url { font-size: 9pt; color: #ECFDF5; }
.cta-url strong { color: #fff; font-weight: 800; }
.editor { font-size: 6.5pt; color: rgba(255,255,255,0.5); margin-top: 3mm; }

/* ===== Utilities ===== */
.row { width: 100%; }
.row td { vertical-align: top; }
</style>
CSS;

// Page 1 - COUVERTURE
$mpdf->AddPage();
$mpdf->WriteHTML($css . <<<'HTML'
<div class="page cover">
  <div class="cover-top">
    <div class="cover-badge">✨ NOUVEAU · AVEC IA</div>
    <div class="cover-logo">Asso<span>kit</span></div>
    <div class="cover-claim">Le logiciel de gestion <em>moderne avec IA</em> pour associations &amp; TPE.</div>
    <div class="cover-sub">Toute votre gestion. Un seul espace. Zéro complexité.</div>
  </div>

  <div class="cover-mid">
    <div class="cover-mid-title">CE QU'ASSOKIT FAIT POUR VOUS</div>
    <ul class="cover-points">
      <li>✓ Adhérents, cotisations, AG, émargements</li>
      <li>✓ Devis, factures, relances clients</li>
      <li>✓ Subventions avec rappels automatiques</li>
      <li>✓ Bilans &amp; documents générés par IA</li>
      <li>✓ Emails, communication, projets</li>
    </ul>
  </div>

  <div class="cover-bottom">
    <div class="cover-bottom-claim">Essai gratuit <em>14 jours</em> · sans carte bancaire</div>
    <div class="cover-bottom-url">assokit.fr</div>
    <div class="cover-bottom-tel">📞 +33 7 56 89 73 36</div>
  </div>
</div>
HTML);

// Page 2 - ASSOCIATIONS
$mpdf->AddPage();
$mpdf->WriteHTML($css . <<<'HTML'
<div class="page face2">
  <div class="f-head">
    <div class="f-eyebrow">🏛️ POUR LES ASSOCIATIONS</div>
    <div class="f-title">Moins de paperasse. <em>Plus d'impact.</em></div>
    <div class="f-sub">Adhérents, projets, AG, émargements, subventions, documents : tout est centralisé, avec l'IA en plus.</div>
  </div>

  <div class="f-body">
    <div class="module-card">
      <div class="module-title">🏛️ Assemblées Générales</div>
      <div class="module-desc">Convocations email, votes en ligne, signature électronique, PV PDF auto.</div>
      <div class="module-feats">✓ Quorum live · Votes anonymes · PV en 1 clic</div>
    </div>

    <div class="module-card blue">
      <div class="module-title">✍️ Émargement QR Code</div>
      <div class="module-desc">Vos participants scannent et signent en 5s. Feuille PDF prête.</div>
      <div class="module-feats">✓ Signature tactile · Anti-doublon · Export officiel</div>
    </div>

    <div class="module-card amber">
      <div class="module-title">💶 Subventions &amp; rappels</div>
      <div class="module-desc">Suivez vos demandes de A à Z avec rappels J-7 et J-30 automatiques.</div>
      <div class="module-feats">✓ État · Région · EPCI · Fondations</div>
    </div>

    <div class="module-card violet">
      <div class="module-title">✨ Coach Assokit IA</div>
      <div class="module-desc">Rapport hebdo personnalisé chaque lundi, comme un vrai consultant.</div>
      <div class="module-feats">✓ Analyse projets · 3 actions prioritaires · Alertes</div>
    </div>

    <div class="module-card violet">
      <div class="module-title">📊 Bilans IA en 1 seconde</div>
      <div class="module-desc">Bilan annuel, par projet, financier ou événementiel. PDF prêt pour l'AG.</div>
      <div class="module-feats">✓ Graphiques · KPI · Analyse IA · Recommandations</div>
    </div>
  </div>
</div>
HTML);

// Page 3 - TPE
$mpdf->AddPage();
$mpdf->WriteHTML($css . <<<'HTML'
<div class="page face3">
  <div class="f-head">
    <div class="f-eyebrow">🛠️ POUR LES TPE &amp; INDÉPENDANTS</div>
    <div class="f-title">Gestion simple. <em>Image pro.</em></div>
    <div class="f-sub">Devis, factures, relances clients, suivi commercial : automatisez tout avec l'IA.</div>
  </div>

  <div class="f-body">
    <div class="module-card">
      <div class="module-title">📄 Devis &amp; factures</div>
      <div class="module-desc">Logo, signature électronique, statut temps réel. Modèles personnalisables.</div>
      <div class="module-feats">✓ HT/TVA/TTC · Récurrentes · PDF auto</div>
    </div>

    <div class="module-card amber">
      <div class="module-title">⚡ Relances clients automatiques</div>
      <div class="module-desc">J+15, J+30, J+45. Emails IA personnalisés. +35% de recouvrement.</div>
      <div class="module-feats">✓ Suivi paiements · Historique · Export comptable</div>
    </div>

    <div class="module-card green">
      <div class="module-title">📊 Tableau de bord commercial</div>
      <div class="module-desc">CA, encours, devis en cours, top clients. Mis à jour en temps réel.</div>
      <div class="module-feats">✓ Pipeline live · Graphiques · Top clients</div>
    </div>

    <div class="module-card purple">
      <div class="module-title">✨ Emails IA pro</div>
      <div class="module-desc">15+ modèles. Devis, relances, propositions, suivis. 1h+ économisée/jour.</div>
      <div class="module-feats">✓ Personnalisation client · Newsletter · Taux d'ouverture</div>
    </div>

    <div class="module-card">
      <div class="module-title">👥 Gestion clients</div>
      <div class="module-desc">Annuaire, historique factures, projets liés, communication centralisée.</div>
      <div class="module-feats">✓ Annuaire complet · Tags · Historique</div>
    </div>
  </div>
</div>
HTML);

// Page 4 - TARIFS + CONTACT
$mpdf->AddPage();
$mpdf->WriteHTML($css . <<<'HTML'
<div class="page face4">
  <div class="f4-head">
    <div class="f4-eyebrow">💎 TARIFS SIMPLES</div>
    <div class="f4-title">Choisissez votre <em>plan</em>.</div>
    <div class="f4-sub">Sans engagement. Essai gratuit 14 jours. Sans carte bancaire.</div>
  </div>

  <div class="plans">
    <div class="plan">
      <table class="plan-row"><tr>
        <td>
          <div class="plan-name">Essentiel</div>
          <div class="plan-tag">DÉMARRER</div>
        </td>
        <td style="text-align:right;">
          <div class="plan-price">29,99€</div>
          <div class="plan-unit">/mois HT</div>
        </td>
      </tr></table>
      <div class="plan-feats">30 contacts · 2 utilisateurs · 20 IA/mois · Support email</div>
    </div>

    <div class="plan featured">
      <table class="plan-row"><tr>
        <td>
          <div class="plan-name">Pro ⭐</div>
          <div class="plan-tag">LE PLUS CHOISI</div>
        </td>
        <td style="text-align:right;">
          <div class="plan-price">49,99€</div>
          <div class="plan-unit">/mois HT</div>
        </td>
      </tr></table>
      <div class="plan-feats">400 adhérents · 20 utilisateurs · 300 IA/mois · Pack Asso · Sous-domaine inclus</div>
    </div>

    <div class="plan">
      <table class="plan-row"><tr>
        <td>
          <div class="plan-name">Sur-mesure</div>
          <div class="plan-tag">GRANDS COMPTES</div>
        </td>
        <td style="text-align:right;">
          <div class="plan-price">149€</div>
          <div class="plan-unit">/mois HT</div>
        </td>
      </tr></table>
      <div class="plan-feats">Illimité · Domaine perso · White-label · Support dédié</div>
    </div>
  </div>

  <div class="f4-bottom">
    <div class="cta-line">📞 Parlons de votre projet</div>
    <div class="cta-tel">+33 7 56 89 73 36</div>
    <div class="cta-url">www.<strong>assokit.fr</strong> · contact@assokit.fr</div>
    <div class="editor">🇫🇷 Édité par RBPS · Hébergé en France · Conforme RGPD</div>
  </div>
</div>
HTML);

// Sortie
$mode = $_GET['mode'] ?? 'D';
$filename = 'assokit-depliant.pdf';
if ($mode === 'I') {
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
} else {
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
