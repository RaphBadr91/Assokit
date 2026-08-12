<?php
/**
 * guide-association-loi-1901.php — Page pilier / hub éditorial.
 * Cible SEO : « gérer une association loi 1901 », « créer une association loi 1901 », « guide association ».
 * Route : /guide-association-loi-1901. Contenu de fond + maillage interne (hub).
 * FAQPage + Breadcrumb. Liens statiques uniquement (aucune dépendance $pdo / blog_list).
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Guide association loi 1901', 'url' => '/guide-association-loi-1901'],
]);

$faqs = [
    ['Comment créer une association loi 1901 ?', "Il faut être au moins deux personnes, rédiger des statuts qui fixent l'objet et le fonctionnement, puis déclarer l'association à la préfecture (ou en ligne). La déclaration donne lieu à une publication au Journal officiel des associations (JOAFE), qui rend l'association officielle et lui confère la capacité juridique."],
    ['Quelles sont les obligations d\'une association loi 1901 ?', "Une association doit respecter ses statuts, tenir une assemblée générale selon les règles qu'elle s'est fixées et déclarer en préfecture tout changement important (dirigeants, siège, objet, statuts). Selon sa taille, son activité et ses financements, s'ajoutent des obligations comptables, fiscales et de protection des données (RGPD)."],
    ['Qui peut diriger une association ?', "Toute personne majeure, et sous conditions un mineur, peut occuper une fonction de direction, sauf disposition contraire des statuts. La gouvernance repose le plus souvent sur un bureau — président, trésorier, secrétaire — élu par l'assemblée générale ou le conseil d'administration selon ce que prévoient les statuts."],
    ['Une association doit-elle tenir une comptabilité ?', "La loi de 1901 n'impose pas de comptabilité à toutes les associations, mais tenir des comptes clairs est indispensable pour rendre des comptes aux adhérents et aux financeurs. Elle devient obligatoire dès qu'on reçoit des subventions publiques importantes, qu'on exerce une activité économique ou qu'on dépasse certains seuils réglementaires."],
    ['Faut-il déclarer les changements de bureau ?', "Oui. Tout changement dans la direction (nouveau président, trésorier, secrétaire), ainsi que les modifications de statuts, de siège social ou d'objet, doivent être déclarés en préfecture dans les trois mois. Ces décisions sont consignées dans le registre des délibérations de l'association, qui en garde la trace officielle."],
    ['Combien de personnes faut-il pour fonder une association ?', "Deux personnes suffisent pour créer une association loi 1901 (en Alsace-Moselle, le droit local en exige sept). Il n'y a pas de capital minimum : l'association naît d'un accord entre membres sur un objet commun, formalisé par des statuts."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => 'Gérer une association loi 1901 : le guide complet',
    'description' => 'Créer et gérer une association loi 1901 : statuts, déclaration en préfecture, bureau, assemblée générale, adhérents, cotisations, comptabilité et obligations légales. Guide complet à jour 2026.',
    'path'        => '/guide-association-loi-1901',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Guide association loi 1901</strong></div>
    <div style="max-width:820px;">
      <span class="lp-badge reveal"><span class="dot"></span> Guide complet · à jour 2026</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Gérer une association loi 1901 : <span class="lp-grad">le guide complet</span></h1>
      <p class="pub-tagline reveal" style="max-width:680px;">De la rédaction des statuts à la comptabilité, en passant par le bureau, l'assemblée générale et les cotisations : tout ce qu'il faut savoir pour <strong>créer et gérer une association loi 1901</strong>, expliqué simplement et à jour de la réglementation 2026.</p>
      <div class="lp-hero-cta reveal">
        <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <a href="/contact" class="lp-btn lp-btn-glass">Réserver une démo</a>
      </div>
      <div class="lp-trust reveal"><span>✓ Sans carte bancaire</span><span>✓ Sans engagement</span><span>🇫🇷 Hébergé en France</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">La <strong>loi du 1er juillet 1901</strong> est l'un des textes fondateurs de la vie associative française. Elle pose un principe simple : plusieurs personnes peuvent librement mettre en commun leurs connaissances ou leur activité dans un but autre que le partage de bénéfices. C'est le socle juridique de plus d'un million et demi d'associations en France, du club de sport de quartier à la grande fédération humanitaire. Ce guide passe en revue tout le cycle de vie d'une <strong>association loi 1901</strong> : sa création, sa gouvernance, sa vie démocratique, la gestion de ses membres et de ses finances, et ses obligations légales.</p>
      <p style="margin:0;">Que vous soyez sur le point de <strong>créer votre association</strong> ou déjà à sa tête, l'objectif est le même : sécuriser le fonctionnement, éviter les erreurs courantes et gagner du temps sur l'administratif pour vous concentrer sur votre projet. Chaque section renvoie vers des ressources plus détaillées et vers les outils qui vous aident à <strong>gérer une association loi 1901</strong> au quotidien.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 1 · Fondation</span><h2 class="pub-h2">Créer l'<em>association</em> : statuts, préfecture, JOAFE</h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;"><strong>Créer une association loi 1901</strong> commence toujours par la rédaction des <strong>statuts</strong>. Ce document fondateur définit le nom de l'association, son objet (le but qu'elle poursuit), l'adresse de son siège social, les conditions d'adhésion et de radiation des membres, la composition et les pouvoirs de ses organes de direction, ainsi que les règles de tenue de l'assemblée générale. Il faut être au minimum deux personnes pour fonder une association (sept en Alsace-Moselle, où s'applique le droit local). Aucun capital de départ n'est exigé : la liberté associative est la règle.</p>
      <p style="margin:0 0 18px;">Une fois les statuts adoptés lors d'une assemblée générale constitutive, l'étape suivante est la <strong>déclaration en préfecture</strong> (ou sous-préfecture), qui peut se faire en ligne. On y joint les statuts signés, la liste des dirigeants et le procès-verbal de l'assemblée constitutive. Cette déclaration n'est pas obligatoire pour exister, mais elle l'est pour obtenir la <strong>capacité juridique</strong> : ouvrir un compte bancaire, recevoir des subventions, signer des contrats ou agir en justice.</p>
      <p style="margin:0;">La déclaration entraîne une <strong>publication au Journal officiel des associations (JOAFE)</strong>, désormais gratuite. Cette parution officialise la naissance de l'association et lui vaut l'attribution d'un numéro <strong>RNA</strong> (Répertoire national des associations). Si l'association perçoit des subventions, emploie des salariés ou exerce une activité économique, elle demandera aussi un numéro <strong>SIREN/SIRET</strong>. Pour bien distinguer ces identifiants, consultez notre article <a href="/blog/rna-siren-association-tout-comprendre" style="color:var(--c-emeraude-dark);font-weight:600;">RNA et SIREN : tout comprendre</a>.</p>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 2 · Gouvernance</span><h2 class="pub-h2">Le <em>bureau</em> et la gouvernance</h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">La loi de 1901 laisse une grande liberté d'organisation : ce sont les statuts qui fixent la <strong>gouvernance</strong>. Dans la pratique, la plupart des associations se dotent d'un <strong>bureau</strong>, organe exécutif restreint, souvent élu au sein d'un conseil d'administration lui-même désigné par l'assemblée générale. Le bureau réunit classiquement trois fonctions clés.</p>
      <p style="margin:0 0 18px;">Le <strong>président</strong> représente l'association vis-à-vis des tiers, met en œuvre les décisions collectives et engage l'association dans la limite des pouvoirs que lui donnent les statuts. Le <strong>trésorier</strong> tient les comptes, prépare le budget, encaisse les cotisations et les subventions, et rend compte de la situation financière lors de l'assemblée générale. Le <strong>secrétaire</strong> assure le suivi administratif : convocations, rédaction des procès-verbaux, tenue du <strong>registre des délibérations</strong> et gestion des déclarations en préfecture.</p>
      <p style="margin:0;">Aucune de ces fonctions n'est imposée par la loi : une association peut fonctionner en collégiale, sans président. Mais une répartition claire des rôles évite les blocages et les conflits. Pour composer un bureau efficace et bien répartir les responsabilités, lisez notre guide dédié : <a href="/blog/bureau-association-composition-role" style="color:var(--c-emeraude-dark);font-weight:600;">le bureau d'une association : composition et rôles</a>.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 3 · Vie démocratique</span><h2 class="pub-h2">L'<em>assemblée générale</em></h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">L'<strong>assemblée générale (AG)</strong> est le moment où les membres exercent collectivement leur pouvoir de décision. On distingue l'<strong>assemblée générale ordinaire (AGO)</strong>, réunie généralement une fois par an pour approuver les comptes, voter le budget, renouveler les dirigeants et fixer les grandes orientations, et l'<strong>assemblée générale extraordinaire (AGE)</strong>, convoquée pour les décisions lourdes comme la modification des statuts ou la dissolution.</p>
      <p style="margin:0 0 18px;">Les règles de convocation, de quorum et de majorité sont librement fixées par les statuts : délai de convocation, ordre du jour, possibilité de vote par procuration ou à distance. Le respect de ces règles est essentiel, car une AG mal convoquée peut voir ses décisions contestées. Chaque assemblée donne lieu à un <strong>procès-verbal</strong> signé, consigné dans le <strong>registre des délibérations</strong>, qui constitue la mémoire officielle des décisions de l'association.</p>
      <p style="margin:0;">Bien préparée, l'assemblée générale est aussi un temps fort de la vie associative : elle renforce la transparence, mobilise les adhérents et légitime les orientations du bureau. Un logiciel de gestion facilite les convocations, l'émargement et le suivi des votes, en s'appuyant sur une base d'adhérents toujours à jour.</p>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 4 · Membres</span><h2 class="pub-h2">Gérer les <em>adhérents</em> et les cotisations</h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">Les <strong>adhérents</strong> sont le cœur battant d'une association. Bien gérer les membres, c'est tenir un fichier à jour (coordonnées, date d'adhésion, statut), respecter les conditions d'admission prévues par les statuts et savoir, à tout moment, qui est à jour de sa cotisation. Une base d'adhérents fiable conditionne la validité des assemblées générales, la qualité de la communication et la santé financière de l'association. Nos bonnes pratiques sont détaillées dans <a href="/blog/adhesion-association-regles-bonnes-pratiques" style="color:var(--c-emeraude-dark);font-weight:600;">adhésion à une association : règles et bonnes pratiques</a>.</p>
      <p style="margin:0 0 18px;">La <strong>cotisation</strong>, lorsqu'elle est prévue par les statuts, est la principale ressource de nombreuses associations. Sa collecte peut vite devenir chronophage : appels à cotisation, relances des retardataires, reçus, suivi des paiements. Automatiser cette gestion — paiement en ligne, relances programmées, reçus générés automatiquement — libère un temps précieux au trésorier et sécurise les recettes. C'est précisément le rôle d'un <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de gestion des cotisations</a> couplé à un <a href="/logiciel-adherents" style="color:var(--c-emeraude-dark);font-weight:600;">outil de gestion des adhérents</a>.</p>
      <p style="margin:0;">La vie associative implique parfois de <strong>radier un membre</strong> : non-paiement de la cotisation, comportement contraire à l'objet de l'association, départ volontaire. Cette procédure doit impérativement respecter ce que prévoient les statuts et le principe du contradictoire, sous peine d'être annulée. Notre guide <a href="/blog/radier-membre-association-procedure" style="color:var(--c-emeraude-dark);font-weight:600;">radier un membre d'une association : la procédure</a> détaille chaque étape à sécuriser.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 5 · Finances</span><h2 class="pub-h2">La <em>comptabilité</em> et les subventions</h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">La loi de 1901 n'impose pas de <strong>comptabilité</strong> à toutes les associations, mais tenir des comptes clairs est indispensable pour rendre des comptes aux adhérents, aux financeurs et, le cas échéant, à l'administration. Une comptabilité devient <strong>obligatoire</strong> dans plusieurs cas : perception de subventions publiques importantes, exercice d'une activité économique, reconnaissance d'utilité publique, emploi de salariés, ou dépassement de certains seuils réglementaires (qui peuvent imposer la nomination d'un commissaire aux comptes).</p>
      <p style="margin:0 0 18px;">Pour la plupart des petites structures, une comptabilité de trésorerie (suivi des recettes et des dépenses) suffit, tandis que les associations plus importantes appliquent le <strong>plan comptable des associations</strong>. Adopter un plan comptable simplifié dès le départ facilite le suivi et la production des comptes annuels. Nous proposons un modèle prêt à l'emploi dans <a href="/blog/plan-comptable-association-modele-simplifie" style="color:var(--c-emeraude-dark);font-weight:600;">plan comptable d'association : modèle simplifié</a>.</p>
      <p style="margin:0;">Les <strong>subventions</strong> publiques répondent à un calendrier précis : dépôt des demandes, conventions d'objectifs, comptes rendus financiers à remettre dans les délais. Manquer une échéance peut coûter cher. Pour ne rien oublier, appuyez-vous sur notre <a href="/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer" style="color:var(--c-emeraude-dark);font-weight:600;">calendrier comptable 2026 : les 12 échéances à ne jamais manquer</a> et sur un <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de comptabilité pour association</a> qui relie automatiquement cotisations, dépenses et bilan.</p>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Étape 6 · Conformité</span><h2 class="pub-h2">Les <em>obligations légales</em> et le RGPD</h2></div>
    <div class="reveal" style="max-width:780px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">Une association déclarée doit maintenir à jour sa situation auprès de l'administration. Concrètement, tout <strong>changement</strong> important — nouveaux dirigeants, modification des statuts, transfert du siège social, évolution de l'objet — doit faire l'objet d'une <strong>déclaration en préfecture</strong> dans un délai de trois mois. Ces décisions sont retracées dans le <strong>registre des délibérations</strong>, qui rassemble les procès-verbaux des assemblées et des réunions du conseil. À noter : l'ancienne obligation de tenir un « registre spécial » a été supprimée en 2015 ; c'est bien le registre des délibérations et les déclarations en préfecture qui font foi aujourd'hui.</p>
      <p style="margin:0 0 18px;">S'ajoutent, selon l'activité, des obligations en matière fiscale (une association peut être soumise aux impôts commerciaux si son activité est lucrative), sociale (si elle emploie des salariés), d'assurance et de sécurité (notamment pour l'accueil du public ou l'organisation d'événements). Il est prudent de vérifier régulièrement quelles obligations s'appliquent à votre structure au fil de sa croissance.</p>
      <p style="margin:0;">Enfin, dès lors qu'elle gère des données personnelles — fichier d'adhérents, listes de diffusion, dons — l'association est soumise au <strong>RGPD</strong>. Cela implique de ne collecter que les données nécessaires, d'informer les personnes de l'usage qui en est fait, de sécuriser ces informations et de les conserver pour une durée limitée. Choisir des outils <strong>hébergés en France et conformes au RGPD</strong> simplifie grandement cette mise en conformité.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Le <em>hub</em> pour piloter votre association</h2><p class="pub-section-lead">Tous nos guides et outils pour créer, gérer et développer votre association loi 1901, réunis au même endroit.</p></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/blog/bureau-association-composition-role"><div class="lp-ic">👥</div><h3>Le bureau : composition et rôles</h3><p>Président, trésorier, secrétaire : qui fait quoi et comment répartir les responsabilités.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/adhesion-association-regles-bonnes-pratiques"><div class="lp-ic">🤝</div><h3>Adhésion : règles &amp; bonnes pratiques</h3><p>Organiser l'admission des membres et tenir un fichier d'adhérents fiable.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/radier-membre-association-procedure"><div class="lp-ic">⚖️</div><h3>Radier un membre : la procédure</h3><p>Exclure un adhérent en sécurisant chaque étape et le respect du contradictoire.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/rna-siren-association-tout-comprendre"><div class="lp-ic">🆔</div><h3>RNA &amp; SIREN : tout comprendre</h3><p>Les identifiants d'une association et à quoi sert chacun d'eux.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/plan-comptable-association-modele-simplifie"><div class="lp-ic">📗</div><h3>Plan comptable : modèle simplifié</h3><p>Un plan comptable prêt à l'emploi pour tenir des comptes clairs.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer"><div class="lp-ic">📅</div><h3>Calendrier comptable 2026</h3><p>Les 12 échéances à ne jamais manquer pour votre association.</p></a>
    </div>
    <p class="pub-text-center reveal" style="margin-top:22px;"><a href="/blog?categorie=associations" style="color:var(--c-emeraude-dark);font-weight:700;">Tous nos guides associations →</a></p>
    <p class="pub-text-center reveal" style="margin-top:14px;color:var(--c-text-3);font-size:14.5px;">Les outils Assokit : <a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:600;">logiciel de gestion complet</a>, gestion des <a href="/logiciel-adherents" style="color:var(--c-emeraude-dark);font-weight:600;">adhérents</a>, des <a href="/logiciel-cotisation-association" style="color:var(--c-emeraude-dark);font-weight:600;">cotisations</a> et de la <a href="/logiciel-comptabilite-association" style="color:var(--c-emeraude-dark);font-weight:600;">comptabilité</a>.</p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Créer et gérer une <em>association loi 1901</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="pub-text-center reveal" style="margin-top:24px;"><a href="/logiciel-association" style="color:var(--c-emeraude-dark);font-weight:700;">← Découvrir le logiciel de gestion complet pour associations</a></p>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Gérez votre association sans y passer vos soirées</h2>
      <p>Adhérents, cotisations, comptabilité et assemblées réunis dans un seul outil, hébergé en France. Essai gratuit 14 jours, sans carte bancaire, puis à partir de 29,99 €/mois.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Essayer gratuitement 14 jours</a>
        <a href="/contact" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Réserver une démo</a>
      </div>
      <p style="position:relative;margin-top:14px;font-size:13px;color:rgba(255,255,255,.75);">Sans carte bancaire · sans engagement</p>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
