<?php
/**
 * modele-convocation-assemblee-generale.php — Page SEO « modèle convocation assemblée générale association ».
 * Route : /modele-convocation-assemblee-generale. Modèle gratuit + explications + FAQPage + Breadcrumb.
 * Aucune dépendance $pdo.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Modèles gratuits', 'url' => '/modeles-gratuits-association'],
    ['name' => 'Convocation assemblée générale', 'url' => '/modele-convocation-assemblee-generale'],
]);

$faqs = [
    ["Comment convoquer une assemblée générale d'association ?", "La convocation est envoyée par la personne habilitée par les statuts (le plus souvent le président ou le bureau) à l'ensemble des membres ayant le droit de voter. Elle peut être adressée par courrier, par e-mail ou par tout moyen prévu par les statuts, et doit comporter la date, l'heure, le lieu et l'ordre du jour de la réunion. Le mieux est de conserver une preuve d'envoi. Un logiciel comme Assokit permet de convoquer tous les adhérents à jour en un clic et de suivre les réponses."],
    ["Quel délai pour convoquer une AG d'association ?", "Aucun délai n'est fixé par la loi de 1901 : ce sont les statuts (ou le règlement intérieur) qui déterminent le délai de convocation. En l'absence de mention, l'usage est de prévoir un délai raisonnable, souvent de 15 jours avant la réunion, afin que chaque membre puisse s'organiser et éventuellement donner pouvoir. Vérifiez toujours ce que prévoient vos propres statuts, qui priment."],
    ["Quelles mentions doivent figurer sur une convocation à l'AG ?", "Une convocation doit indiquer le nom de l'association, la date, l'heure et le lieu de l'assemblée, le type d'assemblée (ordinaire ou extraordinaire), l'ordre du jour détaillé, ainsi que les modalités de vote et de représentation (pouvoir/procuration). Il est utile d'y joindre un modèle de pouvoir et, le cas échéant, les documents soumis au vote (rapport moral, rapport financier)."],
    ["Peut-on convoquer une assemblée générale par e-mail ?", "Oui, à condition que les statuts ne l'interdisent pas et que les membres disposent bien d'une adresse e-mail connue de l'association. L'e-mail est aujourd'hui le moyen le plus courant et le plus économique. Pensez à conserver une trace de l'envoi et, pour les décisions importantes, à demander un accusé de réception."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => "Modèle de convocation à l'assemblée générale d'association",
    'description' => "Modèle gratuit de convocation à l'assemblée générale d'association loi 1901 : mentions obligatoires, délai, ordre du jour et texte prêt à copier.",
    'path'        => '/modele-convocation-assemblee-generale',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';
?>
<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><a href="/modeles-gratuits-association">Modèles gratuits</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Convocation à l'assemblée générale</strong></div>
    <div style="max-width:760px;">
      <span class="lp-badge reveal"><span class="dot"></span> Modèle gratuit · Association loi 1901</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Modèle de <span class="lp-grad">convocation à l'assemblée générale</span> d'association</h1>
      <p class="pub-tagline reveal" style="max-width:640px;">Un modèle de convocation prêt à copier, conforme aux usages de la loi 1901, avec toutes les mentions obligatoires : date, lieu, ordre du jour, modalités de vote et de pouvoir. Gratuit, sans inscription.</p>
      <div class="lp-hero-cta reveal">
        <a href="#modele" class="lp-btn lp-btn-primary">Voir le modèle
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg></a>
        <a href="/signup" class="lp-btn lp-btn-glass">Convoquer avec Assokit</a>
      </div>
      <div class="lp-trust reveal"><span>✓ Prêt à copier</span><span>✓ Mentions obligatoires</span><span>🇫🇷 Loi 1901</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">L'essentiel</span><h2 class="pub-h2">Bien convoquer une <em>assemblée générale</em></h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2);">
      <p style="margin:0 0 18px;">L'assemblée générale (AG) est le moment où les membres d'une association loi 1901 se réunissent pour prendre les décisions importantes : approbation des comptes, vote du rapport moral, renouvellement du bureau ou du conseil d'administration, modification des statuts. Pour qu'une AG soit valablement tenue et que ses décisions ne puissent pas être contestées, la <strong>convocation</strong> doit être régulière. En pratique, cela signifie respecter ce que prévoient vos statuts et informer clairement tous les membres.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Qui convoque l'assemblée&nbsp;?</h3>
      <p style="margin:0 0 18px;">Ce sont les <strong>statuts</strong> qui désignent la personne ou l'organe habilité à convoquer : le plus souvent le président, parfois le bureau ou le conseil d'administration. Dans certaines associations, une fraction des membres peut également demander la tenue d'une assemblée générale extraordinaire. Avant d'envoyer votre convocation, vérifiez donc toujours la clause correspondante de vos statuts&nbsp;: c'est elle qui prime sur les usages.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Quel délai respecter&nbsp;?</h3>
      <p style="margin:0 0 18px;">La loi du 1er juillet 1901 ne fixe <strong>aucun délai légal</strong> de convocation. Là encore, ce sont les statuts (ou le règlement intérieur) qui définissent le délai à respecter. Lorsqu'aucun délai n'est précisé, l'usage retient un délai raisonnable, généralement d'environ <strong>15 jours</strong> avant la date de la réunion, afin que chaque membre puisse s'organiser, poser ses questions ou donner pouvoir à un autre membre.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Les mentions obligatoires</h3>
      <p style="margin:0 0 12px;">Pour être claire et incontestable, une convocation doit comporter&nbsp;:</p>
      <ul style="margin:0 0 18px;padding-left:22px;line-height:1.8;">
        <li>le <strong>nom et l'adresse</strong> de l'association&nbsp;;</li>
        <li>la <strong>nature de l'assemblée</strong> (ordinaire ou extraordinaire)&nbsp;;</li>
        <li>la <strong>date, l'heure et le lieu</strong> de la réunion (ou le lien de connexion en cas d'AG à distance)&nbsp;;</li>
        <li>l'<strong>ordre du jour</strong> détaillé&nbsp;;</li>
        <li>les <strong>modalités de vote</strong> et de <strong>représentation</strong> (pouvoir / procuration)&nbsp;;</li>
        <li>la <strong>signature</strong> de la personne habilitée.</li>
      </ul>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Soigner l'ordre du jour</h3>
      <p style="margin:0 0 18px;">L'ordre du jour liste précisément les points qui seront débattus et soumis au vote. Il est important&nbsp;: en principe, l'assemblée ne peut délibérer valablement que sur les questions inscrites à l'ordre du jour. Rédigez-le donc de façon détaillée (approbation du rapport moral, du rapport financier, quitus au trésorier, élections, questions diverses…) et joignez si possible les documents utiles au vote.</p>

      <h3 style="font-size:19px;color:var(--c-encre);margin:26px 0 10px;">Le mode de convocation</h3>
      <p style="margin:0;">La convocation peut être adressée par <strong>courrier</strong>, par <strong>e-mail</strong> ou par tout autre moyen prévu par les statuts (affichage, espace membre, etc.). L'e-mail est aujourd'hui le moyen le plus courant, rapide et économique&nbsp;; veillez simplement à conserver une preuve d'envoi. Pour les décisions les plus sensibles (modification des statuts, dissolution), un envoi avec accusé de réception est recommandé.</p>
    </div>
  </div>
</section>

<section id="modele" class="pub-section pub-section-creme">
  <div class="pub-container-narrow">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">À copier-coller</span><h2 class="pub-h2">Le <em>modèle</em> de convocation</h2><p class="pub-section-lead">Remplacez simplement les champs entre crochets (<code>[NOM]</code>, <code>[DATE]</code>, <code>[LIEU]</code>…) par vos informations.</p></div>
    <div class="reveal" style="background:#fff;border:1px solid var(--c-border);border-radius:16px;overflow:hidden;max-width:760px;margin:0 auto;box-shadow:0 10px 40px rgba(15,23,42,.06);">
      <div style="display:flex;align-items:center;gap:8px;padding:12px 18px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;">
        <span style="width:9px;height:9px;border-radius:50%;background:#F87171;"></span>
        <span style="width:9px;height:9px;border-radius:50%;background:#FBBF24;"></span>
        <span style="width:9px;height:9px;border-radius:50%;background:#34D399;"></span>
        <span style="margin-left:6px;">Modèle de convocation — à personnaliser</span>
      </div>
      <div style="padding:28px 30px;font-family:'Georgia',serif;font-size:15px;line-height:1.75;color:var(--c-encre);white-space:normal;">
        <p style="margin:0 0 4px;"><strong>[NOM DE L'ASSOCIATION]</strong></p>
        <p style="margin:0 0 4px;">Association régie par la loi du 1<sup>er</sup> juillet 1901</p>
        <p style="margin:0 0 4px;">Siège social&nbsp;: [ADRESSE DE L'ASSOCIATION]</p>
        <p style="margin:0 0 22px;">N° RNA / SIREN&nbsp;: [NUMÉRO] (le cas échéant)</p>

        <p style="margin:0 0 22px;text-align:right;">Fait à [LIEU], le [DATE D'ENVOI]</p>

        <p style="margin:0 0 18px;"><strong>Objet&nbsp;: convocation à l'assemblée générale ordinaire</strong></p>

        <p style="margin:0 0 16px;">Madame, Monsieur, Cher(e) adhérent(e),</p>

        <p style="margin:0 0 16px;">En qualité de [FONCTION — ex&nbsp;: président(e)] de l'association <strong>[NOM DE L'ASSOCIATION]</strong>, et conformément à nos statuts, j'ai le plaisir de vous convoquer à l'<strong>assemblée générale ordinaire</strong> qui se tiendra&nbsp;:</p>

        <p style="margin:0 0 16px;padding:14px 18px;background:#F0FDF4;border-left:3px solid #34D399;border-radius:8px;">
          <strong>Le [DATE] à [HEURE]</strong><br>
          <strong>Au [LIEU]</strong> — [ADRESSE COMPLÈTE]<br>
          <span style="color:var(--c-text-3);font-size:13.5px;">(ou en visioconférence via&nbsp;: [LIEN], le cas échéant)</span>
        </p>

        <p style="margin:0 0 8px;"><strong>Ordre du jour&nbsp;:</strong></p>
        <ol style="margin:0 0 18px;padding-left:22px;">
          <li>Émargement et vérification du quorum&nbsp;;</li>
          <li>Rapport moral et d'activité de l'année écoulée&nbsp;;</li>
          <li>Rapport financier et présentation des comptes&nbsp;;</li>
          <li>Approbation des comptes et quitus au trésorier&nbsp;;</li>
          <li>Vote du budget prévisionnel&nbsp;;</li>
          <li>Renouvellement des membres du bureau / du conseil d'administration&nbsp;;</li>
          <li>Questions diverses.</li>
        </ol>

        <p style="margin:0 0 16px;"><strong>Modalités de vote et de représentation&nbsp;:</strong> chaque membre à jour de sa cotisation dispose d'une voix. En cas d'empêchement, vous pouvez vous faire représenter par un autre membre en lui remettant le pouvoir ci-joint, dûment complété et signé (dans la limite de [NOMBRE] pouvoirs par membre, conformément aux statuts).</p>

        <p style="margin:0 0 22px;">Comptant sur votre présence ou votre représentation, je vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations associatives.</p>

        <p style="margin:0 0 2px;">[PRÉNOM NOM]</p>
        <p style="margin:0 0 2px;">[FONCTION — ex&nbsp;: président(e)]</p>
        <p style="margin:0;">Signature</p>
      </div>
    </div>
    <p class="pub-text-center reveal" style="margin-top:16px;font-size:13.5px;color:var(--c-text-3);">Ce modèle est fourni à titre indicatif&nbsp;: vérifiez toujours qu'il est conforme à vos statuts avant de l'utiliser.</p>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="lp-stat reveal" style="background:linear-gradient(135deg,#0f172a,#065f46);">
      <h2 style="position:relative;color:#fff;font-size:clamp(24px,4vw,34px);margin:0 0 10px;">Générez et envoyez ces documents automatiquement avec Assokit</h2>
      <p>Convoquez tous vos adhérents à jour en un clic, joignez l'ordre du jour et le pouvoir, et suivez les réponses en temps réel. Essai gratuit, sans carte bancaire.</p>
      <div style="position:relative;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Commencer gratuitement</a>
        <a href="/logiciel-adherents" class="lp-btn lp-btn-glass" style="background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)">Gérer mes adhérents</a>
      </div>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Convoquer une <em>assemblée générale</em></h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources</span><h2 class="pub-h2">Aller plus loin</h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/modeles-gratuits-association"><div class="lp-ic">📎</div><h3>Tous les modèles gratuits</h3><p>Statuts, PV d'AG, convocations, reçus… Retrouvez tous nos modèles pour associations loi 1901.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/guide-association-loi-1901"><div class="lp-ic">📗</div><h3>Guide de l'association loi 1901</h3><p>Créer, gérer et faire vivre son association&nbsp;: le guide complet, de la déclaration à l'AG.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/logiciel-adherents"><div class="lp-ic">👥</div><h3>Gestion des adhérents</h3><p>Centralisez vos membres et convoquez-les à l'AG en un clic, avec suivi des réponses.</p></a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
