<?php
/**
 * guide-comptabilite-association.php — Page PILIER (hub) « comptabilité association loi 1901 ».
 * Route : /guide-comptabilite-association. Design premium + FAQPage + Breadcrumb.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Guide comptabilité association', 'url' => '/guide-comptabilite-association'],
]);

$faqs = [
    ['Une association doit-elle tenir une comptabilité ?', "Oui. Même la plus petite association loi 1901 doit tenir une comptabilité, au minimum un suivi organisé des recettes et des dépenses. Cette obligation découle des statuts et de la nécessité de rendre des comptes aux membres en assemblée générale. Les obligations se renforcent (comptes annuels complets, voire commissaire aux comptes au-delà de certains seuils réglementaires) lorsque l'association perçoit des subventions publiques importantes, reçoit des dons ouvrant droit à reçu fiscal, exerce une activité économique assujettie à la TVA ou est reconnue d'utilité publique."],
    ['Quel plan comptable pour une association ?', "Une association applique le plan comptable des associations et fondations issu du règlement ANC n° 2018-06, homologué et applicable depuis les exercices ouverts à compter du 1er janvier 2020. Il reprend l'architecture du plan comptable général mais y ajoute les spécificités du secteur : comptabilisation des cotisations, des subventions, des fonds dédiés et des contributions volontaires en nature. Un logiciel adapté pré-catégorise vos opérations pour vous éviter de mémoriser les numéros de compte."],
    ['Comment faire le bilan d\'une association ?', "Le bilan présente à la date de clôture le patrimoine de l'association : à l'actif, ce qu'elle possède (trésorerie, créances, matériel) ; au passif, ses ressources et engagements (fonds propres, dettes, fonds dédiés). Il se construit à partir de la comptabilité tenue tout au long de l'exercice, en parallèle du compte de résultat qui retrace recettes et dépenses. En tenant vos écritures au fil de l'eau, le bilan et le compte de résultat sont prêts pour l'assemblée générale et, le cas échéant, l'expert-comptable."],
    ['Qu\'est-ce qu\'un fonds dédié ?', "Un fonds dédié correspond à la part d'une subvention ou d'un don affecté à un projet précis, mais non encore consommée à la clôture de l'exercice. Le règlement ANC n° 2018-06 impose de l'isoler au passif du bilan pour montrer que cette ressource reste engagée sur l'action financée et n'est pas un excédent libre. L'année suivante, à mesure que la dépense est réalisée, le fonds dédié est repris en produits. Ce mécanisme rassure les financeurs et donne une image fidèle de la situation."],
    ['Faut-il un expert-comptable pour une association ?', "Ce n'est pas obligatoire pour la plupart des associations : un trésorier bénévole outillé peut tenir la comptabilité. L'intervention d'un expert-comptable devient utile, voire recommandée, quand l'association grandit, gère plusieurs subventions ou emploie des salariés. Attention à ne pas confondre avec le commissaire aux comptes, dont la désignation peut être imposée au-delà de certains seuils de subventions ou de ressources. Un dossier comptable propre et daté réduit le coût de ces interventions."],
    ['Quel est le seuil qui déclenche une comptabilité formelle ?', "Il n'existe pas de seuil unique à partir duquel une association « bascule » dans une comptabilité formelle. L'obligation dépend du cumul de plusieurs critères : montant et nature des subventions publiques reçues, émission de reçus fiscaux, activité lucrative soumise aux impôts commerciaux, agréments ou reconnaissance d'utilité publique. Plusieurs seuils réglementaires distincts existent selon ces situations : en cas de doute, rapprochez-vous de votre financeur public ou d'un expert-comptable plutôt que de vous fier à un chiffre isolé."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => 'Comptabilité association loi 1901 : le guide complet',
    'description' => 'Guide complet de la comptabilité d\'une association loi 1901 : obligations, plan comptable ANC 2018-06, fonds dédiés, reçus fiscaux, bilan et expert-comptable.',
    'path'        => '/guide-comptabilite-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Guide comptabilité association</strong></div>
    <div class="lp-hero-grid" style="display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center;">
      <div>
        <span class="lp-badge reveal"><span class="dot"></span> Le guide de référence · à jour 2026</span>
        <h1 class="pub-h1 reveal" style="margin-top:20px;max-width:640px;">Comptabilité d'une <span class="lp-grad">association loi 1901</span> : le guide complet</h1>
        <p class="pub-tagline reveal" style="max-width:560px;">Obligations comptables, plan comptable associatif, subventions et fonds dédiés, reçus fiscaux, bilan et assemblée générale : tout ce qu'un <strong>trésorier bénévole</strong> doit savoir pour <strong>tenir la comptabilité de son association</strong>, expliqué simplement et à jour.</p>
        <div class="lp-hero-cta reveal">
          <a href="#obligations" class="lp-btn lp-btn-primary">Lire le guide
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
          <a href="/logiciel-comptabilite-association" class="lp-btn lp-btn-glass">Voir le logiciel</a>
        </div>
        <div class="lp-trust reveal"><span>✓ Conforme ANC 2018-06</span><span>📚 ~10 min de lecture</span><span>🇫🇷 Cadre français</span></div>
      </div>
      <div class="lp-mock-wrap reveal">
        <div class="lp-mock lp-glass" style="transform:perspective(1400px) rotateY(-7deg) rotateX(3deg);">
          <div class="lp-mock-bar"><span class="lp-mock-dot" style="background:#F87171"></span><span class="lp-mock-dot" style="background:#FBBF24"></span><span class="lp-mock-dot" style="background:#34D399"></span><span class="lp-mock-title">Sommaire du guide</span></div>
          <div class="lp-mock-body">
            <div class="lp-mrow"><span class="ic" style="background:#FEF3C7">📋</span><div style="flex:1"><div class="t">Obligations comptables</div><div class="s">Selon la taille &amp; les subventions</div></div><span class="v">§1</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DBEAFE">🗂️</span><div style="flex:1"><div class="t">Plan comptable associatif</div><div class="s">Règlement ANC n° 2018-06</div></div><span class="v">§2</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#EDE9FE">🎯</span><div style="flex:1"><div class="t">Subventions &amp; fonds dédiés</div><div class="s">Ressources affectées</div></div><span class="v">§5</span></div>
            <div class="lp-mrow"><span class="ic" style="background:#DCFCE7">🧾</span><div style="flex:1"><div class="t">Reçus fiscaux &amp; bilan</div><div class="s">Dons, AG, expert-comptable</div></div><span class="v">§6</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="obligations">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§1 · Le cadre</span><h2 class="pub-h2">Quelles obligations comptables pour une association ?</h2>
      <p class="pub-section-lead">La comptabilité d'une association loi 1901 n'est pas un luxe : c'est le moyen de rendre des comptes à ses membres, ses financeurs et l'administration.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Contrairement à une idée répandue, <strong>toute association doit tenir une comptabilité</strong>. Pour une petite structure sans salarié ni subvention importante, une comptabilité dite « de trésorerie » suffit généralement : on enregistre les <strong>recettes et dépenses</strong> au fil de l'eau, on conserve les justificatifs et on présente un compte de résultat simplifié en assemblée générale. C'est le socle minimal, imposé autant par les statuts que par le bon sens de la gestion.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Les obligations se renforcent au fur et à mesure que l'association grandit ou change de nature. On passe alors d'un simple suivi recettes-dépenses à une <strong>comptabilité d'engagement</strong> avec <strong>comptes annuels</strong> complets (bilan, compte de résultat et annexe). Plusieurs situations déclenchent ces obligations renforcées : la perception de <strong>subventions publiques</strong> au-delà de certains montants, l'émission de <strong>reçus fiscaux</strong> pour les dons, l'exercice d'une <strong>activité économique</strong> soumise aux impôts commerciaux et à la TVA, l'emploi de salariés, ou encore la reconnaissance d'utilité publique et certains agréments.</p>
      <div class="reveal" style="background:#fff;border:1px solid var(--c-border);border-left:4px solid var(--c-emeraude-dark, #059669);border-radius:14px;padding:20px 22px;margin:0 0 20px;">
        <p style="margin:0;color:var(--c-encre-2);line-height:1.7;font-size:15px;"><strong>À savoir :</strong> on lit parfois qu'un seuil unique (souvent cité à tort) ferait « basculer » l'association dans la comptabilité formelle. C'est inexact. Il n'existe pas de chiffre magique : <strong>plusieurs seuils réglementaires distincts</strong> coexistent selon qu'il s'agit de subventions, de dons, d'activité lucrative ou de la désignation d'un commissaire aux comptes. En cas de doute, vérifiez auprès de votre financeur public ou d'un expert-comptable plutôt que de vous fier à un montant isolé.</p>
      </div>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Dans tous les cas, une comptabilité claire protège les dirigeants bénévoles, facilite l'obtention et le renouvellement des <strong>subventions</strong>, et donne au bureau une vision honnête de la santé financière de la structure. Le passage d'une comptabilité de trésorerie à une comptabilité d'engagement se prépare d'ailleurs d'autant plus facilement qu'on s'est équipé tôt d'un outil adapté. Pour un tour d'horizon accessible, notre article <a href="/blog/comptabilite-association-savoir-absolument" style="color:var(--c-emeraude-dark);font-weight:600;">ce qu'il faut absolument savoir sur la comptabilité d'association</a> complète ce panorama.</p>
    </div>
  </div>
</section>

<section class="pub-section" id="plan-comptable">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§2 · Le référentiel</span><h2 class="pub-h2">Le plan comptable associatif (règlement ANC n° 2018-06)</h2>
      <p class="pub-section-lead">Depuis les exercices ouverts en 2020, les associations tenant des comptes annuels appliquent le règlement ANC n° 2018-06.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Le <strong>plan comptable des associations et fondations</strong> est fixé par le <strong>règlement n° 2018-06 de l'Autorité des normes comptables (ANC)</strong>, homologué et applicable depuis le 1er janvier 2020. Il a remplacé l'ancien règlement CRC n° 99-01. Sa logique reprend l'architecture du plan comptable général — les classes de comptes numérotées de 1 à 7 — tout en intégrant les particularités du monde associatif.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Concrètement, ce référentiel définit comment enregistrer les <strong>cotisations</strong>, les <strong>subventions d'exploitation et d'investissement</strong>, les <strong>fonds dédiés</strong>, les <strong>legs et donations</strong>, mais aussi les <strong>contributions volontaires en nature</strong> (bénévolat, dons en nature, mises à disposition) présentées au pied du compte de résultat. Chaque type d'opération est rattaché à un compte précis, ce qui garantit la comparabilité des comptes d'une association à l'autre et d'une année sur l'autre.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Pas besoin de mémoriser les numéros de compte pour bien démarrer. Un <a href="/blog/plan-comptable-association-modele-simplifie" style="color:var(--c-emeraude-dark);font-weight:600;">modèle de plan comptable simplifié</a> suffit à la plupart des petites structures, et un <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de comptabilité pour association</a> pré-catégorise automatiquement chaque opération sur le bon compte. Le trésorier saisit une dépense ou une recette en langage courant ; l'outil se charge de la traduction comptable.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="au-quotidien">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§3 · La pratique</span><h2 class="pub-h2">Tenir sa comptabilité au quotidien</h2>
      <p class="pub-section-lead">La qualité des comptes de fin d'exercice se joue chaque semaine, dans de bons réflexes de saisie.</p></div>
    <div class="lp-grid">
      <div class="lp-card reveal"><div class="lp-ic">🧾</div><h3>Enregistrer au fil de l'eau</h3><p>Saisissez chaque recette et chaque dépense dès qu'elle survient, plutôt que d'accumuler pour la fin d'année. Une compta à jour évite les oublis et les erreurs de mémoire.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">📁</div><h3>Conserver les justificatifs</h3><p>Facture, ticket, relevé, convention de subvention : chaque écriture doit être appuyée par une pièce justificative datée, idéalement rattachée numériquement à l'opération.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">🏦</div><h3>Rapprocher la banque</h3><p>Confrontez régulièrement votre comptabilité au relevé bancaire. Le rapprochement bancaire détecte les écarts, les doublons et les oublis avant qu'ils ne s'accumulent.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">🗂️</div><h3>Séparer les projets</h3><p>Dès la saisie, rattachez l'opération au bon projet ou à la bonne action. Cette ventilation prépare la comptabilité analytique et la justification des subventions.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">📅</div><h3>Suivre les échéances</h3><p>Déclarations, renouvellement d'agréments, dépôt des comptes : un calendrier évite les retards coûteux. Notre <a href="/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer" style="color:var(--c-emeraude-dark);font-weight:600;">calendrier 2026</a> liste les dates clés.</p></div>
      <div class="lp-card reveal"><div class="lp-ic">🔒</div><h3>Sécuriser les données</h3><p>Sauvegardez et centralisez vos données comptables. Un outil hébergé évite la perte d'un fichier local et facilite la passation entre trésoriers successifs.</p></div>
    </div>
  </div>
</section>

<section class="pub-section" id="analytique">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§4 · Piloter</span><h2 class="pub-h2">La comptabilité analytique par projet</h2>
      <p class="pub-section-lead">Au-delà de l'obligation légale, la comptabilité analytique transforme vos comptes en véritable outil de pilotage.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">La comptabilité générale répond à la question « combien l'association a-t-elle dépensé et reçu ? ». La <strong>comptabilité analytique</strong>, elle, répond à « combien chaque projet a-t-il coûté et rapporté ? ». En ventilant recettes et dépenses par action — un événement, un atelier, une activité financée par une subvention — vous obtenez un <strong>bilan par projet</strong> qui éclaire vraiment les décisions du bureau.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Cette lecture est précieuse à trois moments : lors de l'<strong>assemblée générale</strong>, pour présenter des comptes lisibles action par action ; lors des <strong>demandes de subvention</strong>, pour justifier précisément l'emploi des fonds reçus ; et tout au long de l'année, pour arbitrer entre les projets en connaissance de cause. Une prestation analytique externalisée peut représenter un budget non négligeable pour une petite association ; l'automatiser dans son outil de gestion permet d'y consacrer moins de ressources.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Pour approfondir la méthode et voir comment ventiler concrètement vos opérations, consultez notre page dédiée à la <a href="/comptabilite-analytique" style="color:var(--c-emeraude-dark);font-weight:600;">comptabilité analytique</a>.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="subventions">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§5 · Les ressources affectées</span><h2 class="pub-h2">Les subventions et les fonds dédiés</h2>
      <p class="pub-section-lead">Une subvention affectée à un projet ne se traite pas comme une recette ordinaire : c'est l'un des points les plus spécifiques de la compta associative.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Lorsqu'un financeur verse une <strong>subvention affectée</strong> à une action précise, l'association s'engage à l'utiliser conformément à cet objet. Si, à la clôture de l'exercice, une partie de cette subvention n'a pas encore été consommée, le règlement ANC n° 2018-06 impose de l'isoler en <strong>fonds dédiés</strong> au passif du bilan. Ce mécanisme montre que la ressource reste engagée sur le projet financé et n'est pas un excédent librement disponible.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">L'exercice suivant, à mesure que les dépenses prévues sont réalisées, le fonds dédié est « repris » en produits, en miroir des charges engagées. Ce suivi rigoureux est essentiel : il donne une <strong>image fidèle</strong> de la situation, rassure les financeurs sur le respect de l'affectation, et évite qu'une subvention non consommée ne gonfle artificiellement le résultat de l'année.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">C'est un point technique où beaucoup de trésoriers hésitent. Notre guide détaillé <a href="/blog/fonds-dedies-association-comment-comptabiliser-une-subvention-affectee" style="color:var(--c-emeraude-dark);font-weight:600;">comment comptabiliser une subvention affectée en fonds dédiés</a> déroule la méthode pas à pas, avec les écritures correspondantes.</p>
    </div>
  </div>
</section>

<section class="pub-section" id="recus-fiscaux">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§6 · Les dons</span><h2 class="pub-h2">Reçus fiscaux et dons</h2>
      <p class="pub-section-lead">Émettre un reçu fiscal engage l'association : mieux vaut en maîtriser les conditions et la comptabilisation.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Certaines associations d'intérêt général peuvent délivrer à leurs donateurs un <strong>reçu fiscal</strong> (formulaire Cerfa) ouvrant droit à une réduction d'impôt. Cette faculté est encadrée : l'association doit remplir des conditions d'intérêt général et de gestion désintéressée, et elle est tenue de déclarer chaque année le montant global des dons ayant donné lieu à reçu. Émettre un reçu à tort expose à des sanctions, d'où l'importance de vérifier son éligibilité, au besoin via une demande de rescrit fiscal.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Sur le plan comptable, les <strong>dons</strong> sont enregistrés en produits, en distinguant les dons manuels des dons affectés (qui peuvent, comme les subventions, générer des fonds dédiés s'ils ne sont pas consommés). Les <strong>cotisations</strong> des membres, elles, constituent une ressource distincte et sont suivies séparément — un <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de cotisation</a> qui alimente directement la comptabilité fait gagner un temps précieux.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Si votre association exerce une activité économique (ventes, prestations, buvette, billetterie), la question de la <strong>TVA</strong> et des impôts commerciaux se pose. Notre article <a href="/blog/association-tva-quand-assujetti" style="color:var(--c-emeraude-dark);font-weight:600;">association et TVA : quand est-on assujetti ?</a> aide à situer votre structure.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="bilan-ag">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§7 · La clôture</span><h2 class="pub-h2">Le bilan et l'assemblée générale</h2>
      <p class="pub-section-lead">La fin d'exercice est le moment où la comptabilité tenue toute l'année prend tout son sens.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">À la clôture, l'association établit ses <strong>comptes annuels</strong>. Le <strong>compte de résultat</strong> retrace l'ensemble des produits et des charges de l'exercice et fait apparaître l'excédent ou le déficit. Le <strong>bilan</strong> présente à une date donnée le <strong>patrimoine</strong> de l'association : à l'actif, ce qu'elle possède (trésorerie, créances, immobilisations) ; au passif, ses ressources et engagements (fonds propres, dettes, fonds dédiés). Pour les structures tenant une comptabilité d'engagement, une <strong>annexe</strong> complète et commente ces documents.</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Ces comptes sont ensuite présentés en <strong>assemblée générale</strong>, où les membres les approuvent et donnent quitus au bureau. C'est un moment de transparence démocratique : des comptes clairs, appuyés par une lecture analytique par projet, renforcent la confiance des adhérents et des partenaires. Lorsque la comptabilité a été tenue au fil de l'eau, le bilan et le compte de résultat sont déjà prêts — il ne reste qu'à les commenter.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Un <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de comptabilité pour association</a> construit ces documents automatiquement et les exporte en PDF et Excel, prêts pour l'AG comme pour un éventuel contrôle de financeur.</p>
    </div>
  </div>
</section>

<section class="pub-section" id="expert-comptable">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">§8 · Se faire accompagner</span><h2 class="pub-h2">Faut-il un expert-comptable ?</h2>
      <p class="pub-section-lead">Entre le trésorier bénévole seul et l'expert-comptable, il existe une voie médiane : l'outil bien choisi.</p></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;">
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Pour la grande majorité des associations, le recours à un <strong>expert-comptable</strong> n'est pas obligatoire : un <strong>trésorier bénévole</strong> correctement outillé peut tenir la comptabilité de bout en bout. Son intervention devient pertinente quand l'association grandit, jongle avec plusieurs subventions, emploie des salariés ou aborde des sujets techniques (fiscalité, sectorisation d'activités lucratives).</p>
      <p style="margin:0 0 20px;color:var(--c-encre-2);line-height:1.7;">Attention à ne pas confondre l'expert-comptable, qui accompagne et produit les comptes, avec le <strong>commissaire aux comptes</strong>, dont la désignation peut être <strong>imposée par la loi</strong> au-delà de certains seuils de subventions publiques ou de ressources. Ce sont deux métiers distincts, avec des obligations distinctes.</p>
      <p style="margin:0;color:var(--c-encre-2);line-height:1.7;">Dans tous les cas, plus votre comptabilité est propre et à jour, moins l'accompagnement externe coûte cher : l'expert-comptable valide un dossier déjà structuré au lieu de tout reconstituer. C'est précisément le rôle d'un bon outil de gestion. Découvrez le <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de gestion complet pour associations</a> qui relie adhérents, cotisations, projets et comptabilité.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal">
      <span class="pub-section-eyebrow" style="position:relative;color:#A7F3D0;">Le raccourci</span>
      <div class="big">Une compta qui se tient toute seule</div>
      <p>Reliez cotisations, factures, subventions et projets : chaque opération alimente votre comptabilité et prépare le bilan. Comptabilité analytique incluse dès l'offre Pro.</p>
      <a href="/logiciel-comptabilite-association" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Découvrir le logiciel
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Pour aller plus loin sur la <em>compta associative</em></h2>
      <p class="pub-section-lead">Nos guides pratiques et nos outils pour maîtriser chaque aspect de la comptabilité de votre association loi 1901.</p></div>
    <div class="lp-grid">
      <a class="lp-card reveal" href="/blog/comptabilite-association-savoir-absolument" style="text-decoration:none;color:inherit;"><div class="lp-ic">📘</div><h3>Comptabilité d'association : ce qu'il faut absolument savoir</h3><p>Les obligations, les bons réflexes et les erreurs à éviter pour bien démarrer.</p></a>
      <a class="lp-card reveal" href="/blog/plan-comptable-association-modele-simplifie" style="text-decoration:none;color:inherit;"><div class="lp-ic">🗂️</div><h3>Plan comptable association : le modèle simplifié</h3><p>Un plan comptable associatif clair, prêt à l'emploi pour votre trésorerie.</p></a>
      <a class="lp-card reveal" href="/blog/fonds-dedies-association-comment-comptabiliser-une-subvention-affectee" style="text-decoration:none;color:inherit;"><div class="lp-ic">🎯</div><h3>Fonds dédiés : comptabiliser une subvention affectée</h3><p>La méthode pour isoler et suivre correctement une subvention non consommée.</p></a>
      <a class="lp-card reveal" href="/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer" style="text-decoration:none;color:inherit;"><div class="lp-ic">📅</div><h3>Calendrier comptable 2026 : 12 échéances à ne jamais manquer</h3><p>Toutes les dates fiscales et administratives clés de l'année, mois par mois.</p></a>
      <a class="lp-card reveal" href="/blog/association-tva-quand-assujetti" style="text-decoration:none;color:inherit;"><div class="lp-ic">🧮</div><h3>Association et TVA : quand est-on assujetti ?</h3><p>Comprendre quand une activité associative devient soumise à la TVA.</p></a>
      <a class="lp-card reveal" href="/comptabilite-analytique" style="text-decoration:none;color:inherit;"><div class="lp-ic">📊</div><h3>Comptabilité analytique par projet</h3><p>Le bilan action par action pour piloter et justifier vos financements.</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:28px;display:flex;gap:20px;flex-wrap:wrap;justify-content:center;">
      <a href="/blog?categorie=comptabilite" style="color:var(--c-emeraude-dark);font-weight:700;">Tous nos articles compta →</a>
      <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de comptabilité →</a>
      <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de cotisation →</a>
      <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">Logiciel de gestion complet →</a>
    </p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">La <em>comptabilité d'association</em>, vos questions</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;"><a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:700;">→ Voir le logiciel de comptabilité pour associations</a></p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Passez de la théorie à la pratique</h2>
      <p>Mettez ce guide en application avec un outil pensé pour les trésoriers bénévoles. Créez votre association et tenez votre comptabilité dès aujourd'hui.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Poser une question</a>
      </div>
      <p style="position:relative;margin-top:14px;font-size:13px;color:rgba(255,255,255,.75);">Sans carte bancaire · sans engagement</p>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
