<?php
/**
 * modele-statuts-association.php — Page SEO « modèle statuts association loi 1901 ».
 * Aimant à backlinks : explications + modèle de statuts complet, prêt à copier.
 * Route : /modele-statuts-association. Breadcrumb + FAQPage. Aucune dépendance $pdo.
 */
require_once __DIR__ . '/includes-public.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Modèles gratuits', 'url' => '/modeles-gratuits-association'],
    ['name' => 'Modèle de statuts', 'url' => '/modele-statuts-association'],
]);

$faqs = [
    ["Les statuts d'une association sont-ils obligatoires ?", "Oui. Pour qu'une association loi 1901 acquière la personnalité morale (ouvrir un compte, recevoir des subventions, agir en justice), elle doit être déclarée en préfecture, et cette déclaration s'accompagne de statuts signés par au moins deux membres fondateurs. Une association « de fait » peut exister sans statuts, mais elle n'a alors aucune capacité juridique."],
    ["Comment rédiger les statuts d'une association loi 1901 ?", "Partez d'un modèle et adaptez-le : indiquez la dénomination, l'objet, le siège social, la durée, les catégories de membres, le montant ou le mode de fixation des cotisations, les ressources, la composition et les pouvoirs des organes dirigeants (bureau, conseil d'administration), les règles de l'assemblée générale et les modalités de dissolution. Faites relire, votez les statuts en assemblée constitutive, puis datez et signez."],
    ['Un modèle de statuts est-il gratuit ?', "Oui, le modèle proposé sur cette page est entièrement gratuit : vous le copiez, remplacez les champs entre crochets ([NOM], [OBJET], [ADRESSE]…) et l'adaptez à votre projet, sans inscription ni frais. C'est une base de travail conforme au cadre de la loi du 1er juillet 1901."],
    ['Quelles mentions sont obligatoires dans les statuts ?', "La loi impose peu de mentions strictement obligatoires, mais en pratique la préfecture attend au minimum : le titre exact de l'association, son objet, l'adresse de son siège social. Il est très fortement recommandé d'y ajouter la durée, les membres, les cotisations, les ressources, les organes dirigeants, l'assemblée générale et la dissolution — c'est ce que couvre le modèle ci-dessous."],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];

render_public_head([
    'title'       => 'Modèle de statuts association loi 1901 (gratuit)',
    'description' => "Modèle de statuts d'association loi 1901 gratuit et commenté : 11 articles prêts à copier et à personnaliser. Dénomination, objet, membres, AG, dissolution.",
    'path'        => '/modele-statuts-association',
    'schema_jsonld' => [$breadcrumb, $faq_schema],
]);
render_public_nav('');
require __DIR__ . '/_landing-premium.php';

/* Modèle de statuts en NOWDOC : aucune interpolation, aucun échappement d'apostrophe requis. */
$modele_statuts = <<<'AK_MODELE'
STATUTS DE L'ASSOCIATION « [NOM DE L'ASSOCIATION] »
Association régie par la loi du 1er juillet 1901 et le décret du 16 août 1901


ARTICLE 1 — DÉNOMINATION
Il est fondé entre les adhérents aux présents statuts une association régie par la
loi du 1er juillet 1901 et le décret du 16 août 1901, ayant pour dénomination :
« [NOM DE L'ASSOCIATION] ».


ARTICLE 2 — OBJET
Cette association a pour objet : [OBJET — décrire de façon claire et non lucrative le
but poursuivi, par exemple : « la promotion et la pratique de [ACTIVITÉ] ; l'organisation
d'événements, d'ateliers et de rencontres ; toute action concourant à la réalisation de
cet objet »].


ARTICLE 3 — SIÈGE SOCIAL
Le siège social est fixé à : [ADRESSE COMPLÈTE DU SIÈGE].
Il pourra être transféré par simple décision du conseil d'administration (ou du bureau).


ARTICLE 4 — DURÉE
La durée de l'association est illimitée.


ARTICLE 5 — MEMBRES
L'association se compose de :
  - Membres actifs (ou adhérents) : personnes qui participent régulièrement aux activités
    et versent la cotisation annuelle.
  - Membres bienfaiteurs : personnes qui versent une cotisation supérieure au montant fixé
    pour les membres actifs.
  - Membres d'honneur : personnes ayant rendu des services signalés à l'association ;
    ils sont dispensés de cotisation.

Admission : pour faire partie de l'association, il faut être agréé par le bureau, qui
statue sur les demandes d'admission présentées.

Perte de la qualité de membre — La qualité de membre se perd par :
  a) le décès ;
  b) la démission adressée par écrit au président de l'association ;
  c) le non-paiement de la cotisation ;
  d) la radiation prononcée par le conseil d'administration pour motif grave, l'intéressé
     ayant été invité, par lettre, à fournir des explications préalables.


ARTICLE 6 — COTISATIONS
Le montant des cotisations annuelles est fixé chaque année par l'assemblée générale, sur
proposition du conseil d'administration (ou du bureau). Les membres d'honneur en sont
dispensés.


ARTICLE 7 — RESSOURCES
Les ressources de l'association comprennent :
  - le montant des cotisations ;
  - les subventions de l'État, des collectivités territoriales et des établissements publics ;
  - les dons manuels et le mécénat ;
  - les recettes des manifestations, ventes et prestations de services autorisées ;
  - toutes autres ressources autorisées par les lois et règlements en vigueur.


ARTICLE 8 — CONSEIL D'ADMINISTRATION ET BUREAU
L'association est administrée par un conseil d'administration composé de [NOMBRE] membres,
élus pour [DURÉE, par exemple : trois ans] par l'assemblée générale. Les membres sortants
sont rééligibles.

Le conseil d'administration choisit parmi ses membres un bureau composé de :
  - un(e) président(e) ;
  - un(e) trésorier(ère) ;
  - un(e) secrétaire ;
  (et, le cas échéant, un(e) vice-président(e), un(e) trésorier(ère) adjoint(e) et un(e)
   secrétaire adjoint(e)).

Le conseil d'administration se réunit au moins [NOMBRE] fois par an et chaque fois qu'il
est convoqué par le président ou à la demande du quart de ses membres. Les décisions sont
prises à la majorité des voix ; en cas de partage, la voix du président est prépondérante.


ARTICLE 9 — ASSEMBLÉE GÉNÉRALE
L'assemblée générale ordinaire comprend tous les membres de l'association à jour de leur
cotisation. Elle se réunit au moins une fois par an.

Quinze jours au moins avant la date fixée, les membres sont convoqués par les soins du
secrétaire. L'ordre du jour figure sur les convocations.

Le président préside l'assemblée et expose la situation morale et l'activité de
l'association. Le trésorier rend compte de sa gestion et soumet les comptes à l'approbation
de l'assemblée. Les décisions sont prises à la majorité des membres présents ou représentés.

Assemblée générale extraordinaire — Si besoin est, ou sur demande de la moitié plus un des
membres, le président peut convoquer une assemblée générale extraordinaire, notamment pour
la modification des statuts ou la dissolution de l'association.


ARTICLE 10 — RÈGLEMENT INTÉRIEUR
Un règlement intérieur peut être établi par le conseil d'administration, qui le fait alors
approuver par l'assemblée générale. Ce règlement fixe les divers points non prévus par les
présents statuts, notamment ceux qui ont trait à l'administration interne de l'association.


ARTICLE 11 — DISSOLUTION
En cas de dissolution prononcée selon les modalités prévues à l'article 9, un ou plusieurs
liquidateurs sont nommés, et l'actif net, s'il y a lieu, est dévolu à une association ayant
un but similaire, conformément aux décisions de l'assemblée générale extraordinaire qui
statue sur la dissolution. L'actif net ne peut être dévolu à un membre de l'association,
même partiellement.


Fait à [VILLE], le [DATE].


Le (la) Président(e)                              Le (la) Secrétaire
[PRÉNOM NOM]                                      [PRÉNOM NOM]
Signature                                         Signature
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
    <div class="pub-breadcrumb reveal"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><a href="/modeles-gratuits-association">Modèles gratuits</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Statuts</strong></div>
    <div style="max-width:760px;">
      <span class="lp-badge reveal"><span class="dot"></span> Modèle gratuit</span>
      <h1 class="pub-h1 reveal" style="margin-top:20px;">Modèle de <span class="lp-grad">statuts d'association loi 1901</span></h1>
      <p class="pub-tagline reveal" style="max-width:640px;">Un modèle de statuts complet, commenté et gratuit pour créer votre <strong>association loi 1901</strong>. 11 articles prêts à copier : il ne vous reste qu'à remplacer les champs entre crochets — <code>[NOM]</code>, <code>[OBJET]</code>, <code>[ADRESSE]</code> — pour obtenir des statuts conformes.</p>
      <div class="lp-hero-cta reveal">
        <a href="#modele" class="lp-btn lp-btn-primary">Voir le modèle de statuts
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg></a>
        <a href="/signup" class="lp-btn lp-btn-glass">Générer mes statuts automatiquement</a>
      </div>
      <div class="lp-trust reveal"><span>✓ 100% gratuit</span><span>✓ Conforme loi 1901</span><span>✓ Prêt à copier</span></div>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container-narrow">
    <div class="ak-prose reveal">
      <p>Les <strong>statuts</strong> sont l'acte fondateur de toute association loi 1901. C'est le contrat qui lie les membres entre eux : il fixe le nom de l'association, son objet, son siège, la façon dont elle est dirigée et la manière dont les décisions sont prises. Sans statuts déclarés en préfecture, une association ne possède pas la personnalité morale et ne peut ni ouvrir un compte bancaire, ni recevoir de subvention, ni employer de salarié, ni agir en justice. Autant dire que bien rédiger ses statuts n'est pas une formalité : c'est poser les fondations de votre projet.</p>

      <h2>Ce que la loi impose (et ce qu'elle laisse libre)</h2>
      <p>La loi du 1<sup>er</sup> juillet 1901 est remarquablement souple. Elle n'impose qu'un très petit socle de mentions — en pratique, le titre exact de l'association, son objet et l'adresse de son siège social, que la préfecture réclame lors de la déclaration. Tout le reste relève de votre liberté d'organisation. C'est un atout, mais aussi un piège : des statuts trop vagues ou muets sur des points essentiels (mode de scrutin, durée des mandats, gestion de la dissolution) provoquent des blocages dès que survient un désaccord. Le modèle proposé plus bas couvre volontairement les onze points qui reviennent dans la quasi-totalité des associations, pour vous éviter ces angles morts.</p>

      <h2>Les 11 articles indispensables</h2>
      <p>Un jeu de statuts solide s'articule autour de onze articles. Chacun répond à une question précise que se posera, tôt ou tard, un adhérent, un financeur ou un juge :</p>
      <ul>
        <li><strong>Article 1 — Dénomination :</strong> le nom exact et la référence à la loi de 1901. C'est l'identité juridique de l'association.</li>
        <li><strong>Article 2 — Objet :</strong> le but poursuivi. Il doit être non lucratif, licite, et suffisamment large pour couvrir vos activités présentes et futures, sans être flou au point d'être inutile.</li>
        <li><strong>Article 3 — Siège social :</strong> l'adresse administrative. Prévoir qu'il peut être transféré par simple décision des dirigeants évite une modification statutaire à chaque déménagement.</li>
        <li><strong>Article 4 — Durée :</strong> presque toujours illimitée.</li>
        <li><strong>Article 5 — Membres :</strong> les catégories de membres (actifs, bienfaiteurs, d'honneur), les conditions d'admission et les cas de perte de la qualité de membre.</li>
        <li><strong>Article 6 — Cotisations :</strong> qui fixe le montant et selon quelles modalités. Renvoyer le montant à l'assemblée générale évite d'avoir à modifier les statuts chaque année.</li>
        <li><strong>Article 7 — Ressources :</strong> l'énumération des recettes autorisées (cotisations, subventions, dons, recettes d'activités).</li>
        <li><strong>Article 8 — Conseil d'administration et bureau :</strong> qui dirige, comment sont élus les dirigeants, la durée des mandats et les règles de décision. C'est le cœur du fonctionnement.</li>
        <li><strong>Article 9 — Assemblée générale :</strong> la réunion souveraine des membres, ses règles de convocation, de quorum et de vote, en distinguant l'AG ordinaire de l'AG extraordinaire.</li>
        <li><strong>Article 10 — Règlement intérieur :</strong> la possibilité de préciser le fonctionnement quotidien dans un document plus facile à modifier que les statuts.</li>
        <li><strong>Article 11 — Dissolution :</strong> comment l'association prend fin et à qui revient l'actif restant. La loi interdit de partager cet actif entre les membres.</li>
      </ul>

      <h2>Comment utiliser ce modèle</h2>
      <p>Copiez le texte ci-dessous, collez-le dans votre traitement de texte, puis remplacez systématiquement chaque champ entre crochets par les informations de votre association. Relisez l'ensemble à plusieurs, car ces statuts vous engageront durablement : ajustez le nombre de dirigeants, la durée des mandats ou le mode de scrutin à votre réalité. Une fois le texte figé, faites-le voter lors de votre <a href="/modele-proces-verbal-assemblee-generale">assemblée générale constitutive</a> (dont nous fournissons aussi le procès-verbal), datez-le, faites-le signer par au moins deux membres fondateurs, puis procédez à la déclaration en préfecture. Pour comprendre le rôle de chacun une fois l'association créée, consultez notre article sur <a href="/blog/bureau-association-composition-role">la composition et le rôle du bureau</a> ainsi que nos conseils sur <a href="/blog/adhesion-association-regles-bonnes-pratiques">l'adhésion et ses bonnes pratiques</a>. Pour une vue d'ensemble des démarches, notre <a href="/guide-association-loi-1901">guide de l'association loi 1901</a> vous accompagne pas à pas.</p>
    </div>
  </div>
</section>

<section class="pub-section pub-section-creme" id="modele">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Le modèle</span><h2 class="pub-h2">Modèle de statuts <em>prêt à copier</em></h2><p class="pub-section-lead">Copiez ce texte, remplacez les champs entre crochets, et vos statuts sont prêts pour la déclaration.</p></div>
    <div class="ak-model-wrap reveal">
      <div class="ak-model-head">
        <strong>📜 Statuts — association loi 1901</strong>
        <button type="button" class="ak-copy-btn" id="akCopyStatuts">Copier le texte</button>
      </div>
      <pre class="ak-model" id="akModelStatuts"><?= pub_h($modele_statuts) ?></pre>
    </div>
    <p class="ak-note reveal">💡 Ce modèle est une base de travail générale conforme au cadre de la loi du 1<sup>er</sup> juillet 1901. Adaptez-le à votre projet ; pour les structures soumises à un régime particulier (agrément, reconnaissance d'utilité publique, activités réglementées, associations d'Alsace-Moselle), un accompagnement juridique reste conseillé.</p>
    <script>
      (function(){
        var btn=document.getElementById('akCopyStatuts'),src=document.getElementById('akModelStatuts');
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
      <p>Assokit pré-remplit vos statuts, votre procès-verbal d'assemblée constitutive et vos convocations à partir des informations de votre association, puis les archive au bon endroit. Plus de copier-coller, plus de champs oubliés.</p>
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
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Ressources liées</span><h2 class="pub-h2">Continuez avec les bons <em>documents</em></h2></div>
    <div class="lp-grid reveal">
      <a class="lp-card" style="text-decoration:none;" href="/modele-proces-verbal-assemblee-generale"><div class="lp-ic">🗳️</div><h3>Modèle de PV d'assemblée générale</h3><p>Le procès-verbal à établir lors de votre AG constitutive puis de chaque AG annuelle.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/modeles-gratuits-association"><div class="lp-ic">📂</div><h3>Tous les modèles gratuits</h3><p>Statuts, PV, convocation, reçu fiscal : retrouvez tous nos modèles au même endroit.</p></a>
      <a class="lp-card" style="text-decoration:none;" href="/guide-association-loi-1901"><div class="lp-ic">📖</div><h3>Guide association loi 1901</h3><p>Toutes les démarches pour créer et gérer votre association, expliquées simplement.</p></a>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Questions fréquentes</span><h2 class="pub-h2">Les <em>statuts</em> d'association</h2></div>
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
