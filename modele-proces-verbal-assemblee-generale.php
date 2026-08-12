<?php
/**
 * modele-proces-verbal-assemblee-generale.php — Page SEO
 * « modèle procès-verbal assemblée générale association ».
 * Aimant à backlinks : explications + modèle de PV d'AG complet, prêt à copier.
 * Route : /modele-proces-verbal-assemblee-generale. Breadcrumb + FAQPage. Aucune dépendance $pdo.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Modèles gratuits', 'url' => '/modeles-gratuits-association'],
    ['name' => "Modèle de procès-verbal d'AG", 'url' => '/modele-proces-verbal-assemblee-generale'],
]);

$faqs = [
    ["Le PV d'assemblée générale est-il obligatoire ?", "La loi 1901 ne l'impose pas systématiquement, mais le procès-verbal est indispensable en pratique : c'est la preuve écrite des décisions prises (approbation des comptes, élection des dirigeants, modification des statuts). Il est réclamé par la banque, l'administration, les financeurs et la préfecture. Concrètement, aucune association sérieuse ne s'en passe."],
    ["Qui signe le procès-verbal d'AG ?", "Le procès-verbal est généralement signé par le président de séance et le secrétaire de séance. Selon les statuts ou l'usage de l'association, il peut aussi être contresigné par les membres du bureau. Ce sont ces signatures qui donnent au document sa valeur probante."],
    ["Comment rédiger un procès-verbal d'assemblée générale ?", "Reprenez l'ordre du jour point par point : indiquez la date, le lieu, le nombre de membres présents et représentés, la vérification du quorum, puis, pour chaque résolution soumise au vote, le texte exact de la résolution et le résultat du vote (voix pour, contre, abstentions). Terminez par l'heure de clôture et les signatures du président et du secrétaire de séance."],
    ["Quelle différence entre AG ordinaire et AG extraordinaire ?", "L'assemblée générale ordinaire (AGO) traite de la vie courante : rapport moral, comptes, budget, élection des dirigeants. L'assemblée générale extraordinaire (AGE) est réservée aux décisions qui touchent aux statuts eux-mêmes : modification des statuts, changement d'objet, fusion ou dissolution. Les règles de quorum et de majorité de l'AGE sont souvent plus strictes."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => "Modèle de procès-verbal d'assemblée générale (gratuit)",
    'description' => "Modèle de procès-verbal d'assemblée générale d'association gratuit et complet : en-tête, quorum, ordre du jour, résolutions, votes, signatures. Prêt à copier.",
    'path'        => '/modele-proces-verbal-assemblee-generale',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';

/* Modèle de PV en NOWDOC : aucune interpolation, aucun échappement d'apostrophe requis. */
$modele_pv = <<<'AK_MODELE'
PROCÈS-VERBAL DE L'ASSEMBLÉE GÉNÉRALE ORDINAIRE
Association « [NOM DE L'ASSOCIATION] »
Association régie par la loi du 1er juillet 1901
Siège social : [ADRESSE DU SIÈGE]


Le [DATE], à [HEURE], les membres de l'association « [NOM DE L'ASSOCIATION] » se sont
réunis en assemblée générale ordinaire à [LIEU DE LA RÉUNION], sur convocation du
président adressée le [DATE D'ENVOI DE LA CONVOCATION], conformément aux statuts.


PRÉSENCE ET QUORUM
Nombre de membres de l'association à jour de cotisation : [NOMBRE].
Nombre de membres présents : [NOMBRE].
Nombre de membres représentés (pouvoirs) : [NOMBRE].
Total des membres présents ou représentés : [NOMBRE].

Le quorum requis par les statuts ([RAPPELER LA RÈGLE, par exemple : un tiers des membres])
étant atteint, l'assemblée peut valablement délibérer.


BUREAU DE L'ASSEMBLÉE
L'assemblée est présidée par [PRÉNOM NOM], président(e) de l'association.
Les fonctions de secrétaire de séance sont assurées par [PRÉNOM NOM].


ORDRE DU JOUR
1. Rapport moral et d'activité de l'année écoulée.
2. Rapport financier et approbation des comptes de l'exercice clos le [DATE].
3. Affectation du résultat et vote du budget prévisionnel.
4. Fixation du montant de la cotisation annuelle.
5. Renouvellement des membres du conseil d'administration.
6. Questions diverses.


DÉLIBÉRATIONS ET RÉSOLUTIONS

Point 1 — Rapport moral et d'activité
Le (la) président(e) présente le rapport moral et le bilan des activités de l'année.
Après échange, ce rapport est soumis au vote.
PREMIÈRE RÉSOLUTION : « L'assemblée générale approuve le rapport moral et d'activité
présenté par le président. »
Résultat du vote — Pour : [NOMBRE] · Contre : [NOMBRE] · Abstentions : [NOMBRE].
La résolution est ADOPTÉE (ou REJETÉE).

Point 2 — Rapport financier et approbation des comptes
Le (la) trésorier(ère) présente les comptes de l'exercice clos le [DATE] : recettes,
dépenses et résultat. Le rapport financier est soumis au vote.
DEUXIÈME RÉSOLUTION : « L'assemblée générale approuve les comptes de l'exercice clos le
[DATE] tels qu'ils lui ont été présentés et donne quitus au trésorier de sa gestion. »
Résultat du vote — Pour : [NOMBRE] · Contre : [NOMBRE] · Abstentions : [NOMBRE].
La résolution est ADOPTÉE (ou REJETÉE).

Point 3 — Affectation du résultat et budget prévisionnel
TROISIÈME RÉSOLUTION : « L'assemblée générale décide d'affecter le résultat de l'exercice
en [report à nouveau / réserves] et approuve le budget prévisionnel présenté pour l'année
[ANNÉE]. »
Résultat du vote — Pour : [NOMBRE] · Contre : [NOMBRE] · Abstentions : [NOMBRE].
La résolution est ADOPTÉE (ou REJETÉE).

Point 4 — Montant de la cotisation
QUATRIÈME RÉSOLUTION : « L'assemblée générale fixe le montant de la cotisation annuelle à
[MONTANT] euros pour l'année [ANNÉE]. »
Résultat du vote — Pour : [NOMBRE] · Contre : [NOMBRE] · Abstentions : [NOMBRE].
La résolution est ADOPTÉE (ou REJETÉE).

Point 5 — Renouvellement du conseil d'administration
Après appel à candidatures et vote, sont élu(e)s / réélu(e)s au conseil d'administration :
[PRÉNOM NOM], [PRÉNOM NOM], [PRÉNOM NOM].
CINQUIÈME RÉSOLUTION : « L'assemblée générale procède à l'élection des membres du conseil
d'administration désignés ci-dessus. »
Résultat du vote — Pour : [NOMBRE] · Contre : [NOMBRE] · Abstentions : [NOMBRE].
La résolution est ADOPTÉE (ou REJETÉE).

Point 6 — Questions diverses
[Résumer les questions abordées et les éventuelles décisions prises.]


CLÔTURE
L'ordre du jour étant épuisé et personne ne demandant plus la parole, la séance est levée
à [HEURE].

De tout ce qui précède, il a été dressé le présent procès-verbal, signé par le président
et le secrétaire de séance.


Fait à [VILLE], le [DATE].


Le (la) Président(e) de séance                    Le (la) Secrétaire de séance
[PRÉNOM NOM]                                       [PRÉNOM NOM]
Signature                                          Signature
AK_MODELE;
?>
<style>
.ak-cta{position:relative;overflow:hidden;background:linear-gradient(135deg,#0f172a,#065f46);color:#fff;border-radius:22px;padding:34px 32px;margin:0 auto;max-width:900px;text-align:center}
.ak-cta h2{color:#fff;font-size:clamp(21px,3.4vw,28px);margin:0 0 10px}
.ak-cta p{color:rgba(255,255,255,.86);max-width:620px;margin:0 auto 20px;line-height:1.6;font-size:15.5px}
.ak-cta .lp-btn-glass{background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)}
.ak-cta small{display:block;margin-top:12px;font-size:12.5px;color:rgba(255,255,255,.7)}
.ak-prose{max-width:760px;margin:0 auto;font-size:16px;line-height:1.72;color:var(--c-text-2)}
.ak-prose h3{color:var(--c-encre);font-size:20px;margin:34px 0 12px}
.ak-prose p{margin:0 0 16px}
.ak-prose ul{margin:0 0 16px;padding-left:22px}
.ak-prose li{margin-bottom:8px}
.ak-model-wrap{max-width:860px;margin:0 auto}
.ak-model-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(90deg,#0b3b2a,#065f46);color:#fff;padding:14px 20px;border-radius:16px 16px 0 0}
.ak-model-head strong{font-size:15px}
.ak-copy-btn{background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.28);border-radius:9px;padding:8px 15px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s}
.ak-copy-btn:hover{background:rgba(255,255,255,.28)}
.ak-model{margin:0;background:#fff;border:1px solid var(--c-border);border-top:none;border-radius:0 0 16px 16px;padding:26px 24px;overflow-x:auto;font-family:ui-monospace,"SFMono-Regular",Menlo,Consolas,monospace;font-size:13px;line-height:1.65;color:var(--c-encre);white-space:pre-wrap;word-wrap:break-word}
.ak-note{max-width:860px;margin:16px auto 0;font-size:13.5px;color:var(--c-text-3);background:#F5F2EC;border:1px solid var(--c-border-soft);border-radius:12px;padding:14px 18px}
</style>

<section class="lp-hero">
  <span class="lp-orb o1"></span><span class="lp-orb o2"></span>
  <div class="pub-container">
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><a href="/modeles-gratuits-association">Modèles gratuits</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Procès-verbal d'AG</strong></div>
    <div style="max-width:760px;">
      <span class="lp-badge reveal"><span class="dot"></span> Modèle gratuit</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Modèle de <span class="lp-grad">procès-verbal d'assemblée générale</span></h1>
      <p class="pub-tagline reveal" style="max-width:640px;">Un modèle de <strong>procès-verbal d'assemblée générale d'association</strong> complet et gratuit : en-tête, quorum, ordre du jour, résolutions, résultats des votes, clôture et signatures. Copiez-le, remplacez les champs entre crochets, et votre PV est prêt à signer.</p>
      <div class="lp-hero-cta reveal">
        <a href="#modele" class="lp-btn lp-btn-primary">Voir le modèle de PV
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg></a>
        <a href="/signup" class="lp-btn lp-btn-glass">Générer mes PV automatiquement</a>
      </div>
      <div class="lp-trust reveal"><span>✓ 100% gratuit</span><span>✓ AGO &amp; AGE</span><span>✓ Prêt à copier</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="ak-prose reveal">
      <p>Le <strong>procès-verbal d'assemblée générale</strong> — souvent abrégé « PV d'AG » — est le compte rendu officiel des décisions prises par les membres de l'association réunis en assemblée. C'est un document court mais capital : c'est lui qui fait foi, des mois ou des années plus tard, de ce que l'assemblée a réellement voté. Approbation des comptes, élection des dirigeants, changement de cotisation, modification des statuts : sans procès-verbal, ces décisions ne laissent aucune trace opposable.</p>

      <h3>À quoi sert vraiment le PV</h3>
      <p>Au-delà de la mémoire interne de l'association, le procès-verbal est réclamé par un grand nombre d'interlocuteurs. La banque l'exige pour mettre à jour les mandataires du compte après une élection. La préfecture le demande à l'appui d'une modification des statuts ou d'un changement de dirigeants. Les financeurs et les partenaires publics le joignent aux dossiers de subvention pour vérifier que les comptes ont bien été approuvés. En cas de litige interne, c'est la pièce que produira chaque partie. Autrement dit, un PV bien tenu protège l'association et ses dirigeants.</p>

      <h3>Les éléments qui doivent y figurer</h3>
      <p>Un procès-verbal complet suit toujours la même ossature, du plus formel au plus détaillé :</p>
      <ul>
        <li><strong>L'en-tête :</strong> le nom de l'association, la référence à la loi 1901, l'adresse du siège, ainsi que la nature (ordinaire ou extraordinaire), la date, l'heure et le lieu de l'assemblée.</li>
        <li><strong>Les convocations :</strong> la mention que les membres ont été régulièrement convoqués, avec la date d'envoi de la convocation.</li>
        <li><strong>La présence et le quorum :</strong> le nombre de membres présents et représentés, et la constatation que le quorum prévu par les statuts est atteint — condition de validité des délibérations.</li>
        <li><strong>Le bureau de séance :</strong> l'identité du président et du secrétaire de séance.</li>
        <li><strong>L'ordre du jour :</strong> la liste des points soumis à l'assemblée, dans l'ordre où ils seront traités.</li>
        <li><strong>Les résolutions et les votes :</strong> pour chaque point, le texte exact de la résolution et le résultat chiffré du vote (pour, contre, abstentions), suivi de la mention « adoptée » ou « rejetée ».</li>
        <li><strong>La clôture :</strong> l'heure de fin de séance.</li>
        <li><strong>Les signatures :</strong> celles du président et du secrétaire de séance, qui authentifient le document.</li>
      </ul>

      <h3>Comment utiliser ce modèle</h3>
      <p>Le modèle ci-dessous est calibré pour une assemblée générale ordinaire type — rapport moral, comptes, budget, cotisation, renouvellement du conseil. Copiez-le, remplacez chaque champ entre crochets par les données réelles de votre assemblée, et complétez les résultats de vote au fil de la séance (ou juste après, à partir de vos notes). Pour une assemblée générale extraordinaire, conservez la même structure mais adaptez les résolutions aux décisions concernées (modification des statuts, dissolution) et vérifiez les règles de quorum et de majorité renforcées prévues par vos statuts. Le procès-verbal doit refléter fidèlement ce qui s'est passé : ne pré-remplissez jamais les résultats à l'avance. Pour bien préparer l'assemblée elle-même, appuyez-vous sur nos <a href="/modele-statuts-association">statuts d'association</a> (qui fixent les règles de convocation et de vote), sur notre article dédié à <a href="/blog/bureau-association-composition-role">la composition et au rôle du bureau</a>, et sur nos conseils relatifs à <a href="/blog/adhesion-association-regles-bonnes-pratiques">l'adhésion des membres</a>. Une vue complète des démarches figure dans notre <a href="/guide-association-loi-1901">guide de l'association loi 1901</a>.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="modele">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Le modèle</span><h2 class="pub-h2">Modèle de procès-verbal <em>prêt à copier</em></h2><p class="pub-section-lead">Copiez ce texte, remplacez les champs entre crochets, complétez les votes, et votre PV est prêt à signer.</p></div>
    <div class="ak-model-wrap reveal">
      <div class="ak-model-head">
        <strong>🗳️ PV d'assemblée générale — association loi 1901</strong>
        <button type="button" class="ak-copy-btn" id="akCopyPv">Copier le texte</button>
      </div>
      <pre class="ak-model" id="akModelPv"><?= pub_h($modele_pv) ?></pre>
    </div>
    <p class="ak-note reveal">💡 Ce modèle correspond à une assemblée générale ordinaire. Pour une assemblée générale extraordinaire (modification des statuts, dissolution), conservez la structure mais adaptez les résolutions et vérifiez les règles de quorum et de majorité prévues par vos statuts.</p>
    <script>
      (function(){
        var btn=document.getElementById('akCopyPv'),src=document.getElementById('akModelPv');
        if(btn&&src){btn.addEventListener('click',function(){
          var t=src.innerText;
          if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(function(){btn.textContent='Copié ✓';setTimeout(function(){btn.textContent='Copier le texte';},2000);});}
        });}
      })();
    </script>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="ak-cta reveal">
      <h2>Gagnez du temps : générez ces documents automatiquement avec Assokit</h2>
      <p>Assokit prépare vos convocations, enregistre les présences et les pouvoirs, calcule le quorum et génère le procès-verbal de votre assemblée à partir des votes saisis — puis l'archive avec vos autres documents officiels. Fini le PV rédigé à la main la veille de l'échéance bancaire.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <a href="/signup" class="lp-btn lp-btn-primary">Essai gratuit, sans carte bancaire</a>
        <a href="/logiciel-association" class="lp-btn lp-btn-glass">Découvrir le logiciel de gestion</a>
      </div>
      <small>Sans engagement · Hébergé en France · Conforme RGPD</small>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources liées</span><h2 class="pub-h2">Les <em>documents</em> qui vont avec</h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/modele-statuts-association"><div class="lp-ic">📜</div><h3>Modèle de statuts d'association</h3><p>Les statuts fixent les règles de convocation, de quorum et de vote de vos assemblées.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/modeles-gratuits-association"><div class="lp-ic">📂</div><h3>Tous les modèles gratuits</h3><p>Statuts, PV, convocation, reçu fiscal : retrouvez tous nos modèles au même endroit.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/guide-association-loi-1901"><div class="lp-ic">📖</div><h3>Guide association loi 1901</h3><p>Toutes les démarches pour créer et gérer votre association, expliquées simplement.</p></a>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Le <em>procès-verbal</em> d'assemblée générale</h2></div>
    <div class="pub-faq reveal">
      <?php foreach ($faqs as $i => $qa): ?>
        <details class="pub-faq-item"<?= $i === 0 ? ' open' : '' ?>><summary><?= pub_h($qa[0]) ?></summary><div class="pub-faq-item-body"><?= pub_h($qa[1]) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
