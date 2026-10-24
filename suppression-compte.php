<?php
/**
 * suppression-compte.php — Page publique « Supprimer votre compte Assokit ».
 * --------------------------------------------------------------
 * Exigée par Google Play (règle sur la suppression de compte) : une page web,
 * accessible sans se connecter, qui explique comment demander la suppression
 * du compte et des données, dans l'application comme hors de l'application.
 * Son adresse est déclarée dans la Play Console (Contenu de l'application ›
 * Sécurité des données › Suppression des données).
 *
 * Le texte décrit ce que fait VRAIMENT le code ; à garder aligné avec :
 *   - ak_account_self_anonymise() dans app-context.php, appelée par
 *     rgpd-supprimer-compte.php et api/app-delete-account.php (suppression par
 *     la personne : anonymisation immédiate, jetons effacés, déconnexion) ;
 *   - ak_account_delete_blocker() dans app-context.php (seul administrateur,
 *     seul compte de l'association) ;
 *   - cron-rgpd-purge.php (corbeille 30 jours puis anonymisation, journaux
 *     12 mois) ;
 *   - ak_signup_review_withdraw() dans signup-review-helpers.php, appelée par
 *     retirer-demande.php (§ 3 : retrait d'une demande d'espace en attente ou
 *     refusée, sans session) ;
 *   - cgu.php § 8 et confidentialite.php § 4 (trois cas : suppression par la
 *     personne, retrait par un administrateur, fermeture de l'association ;
 *     10 ans, 12 mois ; plus, dans confidentialite.php § 4 et cgu.php § 3, la
 *     demande d'espace retirée ou refusée).
 * Si l'un change, changer aussi cette page.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';          // paramètres société (nom de l'éditeur)
require_once __DIR__ . '/includes-public.php';

$L = pub_legal_identity();

render_public_head([
    'title'       => 'Supprimer votre compte Assokit',
    'description' => 'Comment supprimer votre compte Assokit, dans l\'application ou sur le site, ce qui est effacé, ce qui est conservé et combien de temps.',
    'path'        => '/suppression-compte',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Suppression de compte</strong></div>
    <h1 class="pub-h1" style="font-size:36px;">Supprimer votre compte Assokit</h1>
    <p class="pub-tagline">Application Assokit (Android et iPhone) et site assokit.fr, édités par <?= pub_h($L['name']) ?>.</p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content">
      <p style="background:var(--c-emeraude-light);border-left:4px solid var(--c-emeraude);padding:16px 20px;border-radius:8px;color:var(--c-encre);">
        <strong>En bref</strong> : vous pouvez supprimer votre compte vous-même, dans l'application ou sur le site. Vos données personnelles sont anonymisées immédiatement et vous êtes déconnecté. Si vous n'avez plus accès à votre compte, écrivez-nous à <a href="mailto:contact@assokit.fr?subject=Suppression%20de%20compte">contact@assokit.fr</a>.
      </p>

      <h2 id="application">1. Depuis l'application Assokit</h2>
      <ol>
        <li>Ouvrez l'application Assokit et connectez-vous.</li>
        <li>Touchez l'onglet <strong>« Plus »</strong>, en bas à droite de l'écran.</li>
        <li>Dans la rubrique <strong>« Compte »</strong>, touchez <strong>« Paramètres »</strong>.</li>
        <li>Tout en bas de l'écran, touchez <strong>« Supprimer mon compte (RGPD) »</strong>.</li>
        <li>Saisissez votre mot de passe, puis touchez <strong>« Supprimer définitivement »</strong>.</li>
      </ol>
      <p>La suppression est immédiate : l'application vous déconnecte et revient à l'écran d'accueil.</p>

      <h2 id="site">2. Depuis le site assokit.fr</h2>
      <ol>
        <li>Connectez-vous sur <a href="/connexion">assokit.fr/connexion</a>.</li>
        <li>Ouvrez <strong>« Paramètres »</strong>, onglet <strong>« Compte »</strong>.</li>
        <li>Dans l'encadré <strong>« Mes données personnelles (RGPD) »</strong>, saisissez votre mot de passe à côté de <strong>« Supprimer mon compte »</strong>.</li>
        <li>Cliquez sur <strong>« Supprimer mon compte »</strong>, puis confirmez.</li>
      </ol>
      <p><strong>Conseil</strong> : avant de supprimer votre compte, téléchargez une copie de vos données dans <strong>Paramètres › Compte › « Exporter mes données »</strong> (fichier JSON). Une fois le compte supprimé, vous ne pourrez plus vous connecter pour le faire.</p>

      <h2 id="sans-application">3. Sans l'application, ou si vous n'avez plus accès à votre compte</h2>
      <p>Envoyez votre demande à <a href="mailto:contact@assokit.fr?subject=Suppression%20de%20compte">contact@assokit.fr</a> avec l'objet <strong>« Suppression de compte »</strong>, ou passez par le <a href="/contact?subject=other">formulaire de contact</a> (choisissez l'objet « Autre », puis écrivez « Suppression de compte » dans le champ de l'objet).</p>
      <ul>
        <li>Écrivez si possible depuis l'adresse e-mail de votre compte, et indiquez le nom de votre association ou de votre entreprise : cela nous permet de vérifier que la demande vient bien de vous.</li>
        <li>Nous vous répondons sous 30 jours maximum, comme pour toute demande liée à vos données personnelles (voir notre <a href="/confidentialite">politique de confidentialité</a>).</li>
      </ul>
      <p><strong>Demande de création d’espace en cours de validation, ou refusée</strong> (faite depuis l’application iPhone)&nbsp;: tant qu’elle n’est pas validée, l’espace demandé n’est pas accessible. Retirez-la vous-même sur la page <a href="/retirer-demande">Retirer ma demande</a>, avec l’adresse e-mail et le mot de passe de la demande. Dans l’application, saisissez-les sur l’écran de connexion, puis touchez <strong>«&nbsp;Retirer ma demande&nbsp;»</strong> sous le message «&nbsp;en cours de validation&nbsp;» ou, après un refus, sur l’écran «&nbsp;Demande non validée&nbsp;» (le lien figure aussi sur la page «&nbsp;Demande envoyée&nbsp;», juste après la demande). Votre compte est supprimé, l’espace demandé est fermé et les informations transmises sont effacées immédiatement&nbsp;; un e-mail vous le confirme. Plus accès à l’adresse ou au mot de passe&nbsp;? Écrivez-nous de la même façon depuis l’adresse e-mail de la demande&nbsp;: nous la retirons pour vous.</p>

      <h2 id="supprime">4. Ce qui est supprimé, tout de suite</h2>
      <ul>
        <li>Vos <strong>nom et prénom</strong> sont remplacés par « Compte supprimé ».</li>
        <li>Votre <strong>adresse e-mail</strong> est remplacée par une adresse anonyme : elle ne permet plus de vous identifier ni de vous connecter.</li>
        <li>Votre <strong>téléphone</strong> et votre <strong>ville</strong> sont effacés.</li>
        <li>Votre <strong>mot de passe</strong>, votre <strong>double authentification</strong> et ses codes de secours sont effacés : plus personne ne peut se connecter à ce compte.</li>
        <li>Les <strong>notes</strong> qu'un administrateur avait écrites sur votre fiche, les informations de <strong>contrat</strong> (type, dates) et vos <strong>dates d'adhésion</strong> sont effacées.</li>
        <li>Vos <strong>liens d'abonnement à l'agenda</strong> et vos liens de changement de mot de passe non utilisés sont supprimés.</li>
        <li>Vos <strong>téléphones ne reçoivent plus aucune notification</strong> : leurs jetons de notification sont effacés.</li>
        <li>Le compte est <strong>désactivé</strong> et votre session est fermée.</li>
      </ul>

      <h2 id="conserve">5. Ce qui est conservé, et combien de temps</h2>
      <ul>
        <li><strong>Ce que vous avez partagé dans votre association</strong> (messages, comptes rendus, fichiers déposés dans un projet…) fait partie des dossiers de l'association : ces contenus y restent, signés « Compte supprimé », <strong>tant que l'association utilise Assokit</strong> (à la fermeture de son espace, ils suivent la règle de la section 6). Pour qu'un contenu précis soit retiré, demandez-le à un administrateur de votre association ou écrivez-nous.</li>
        <li><strong>Les données de votre association</strong> (adhérents, projets, agenda, factures…) appartiennent à l'association : la suppression de votre compte personnel ne les efface pas.</li>
        <li><strong>Les factures et pièces comptables</strong> sont conservées <strong>10 ans</strong> (obligation comptable française).</li>
        <li><strong>Les journaux techniques</strong> (connexions, adresses IP) sont conservés <strong>12 mois au maximum</strong>, pour la sécurité du service.</li>
      </ul>
      <p>Nous ne revendons aucune donnée, ni avant ni après la suppression de votre compte.</p>

      <h2 id="cas-particuliers">6. Cas particuliers</h2>
      <h3>Vous êtes le seul administrateur de votre association</h3>
      <p>La suppression est bloquée tant que votre association n'a pas d'autre administrateur, pour qu'elle ne se retrouve pas sans personne pour la gérer. L'application et le site affichent alors la raison sous le bouton « Supprimer mon compte ». Sur le site, ouvrez le menu <strong>« Administration »</strong>, donnez le rôle <strong>Administrateur</strong> à un autre membre, puis supprimez votre compte. Dans un espace collectivité, l'autre administrateur se nomme depuis le menu <strong>« Paramètres »</strong>.</p>

      <h3>Vous êtes le seul compte de votre association</h3>
      <p>Il n'y a personne d'autre à nommer : c'est l'association elle-même qu'il faut fermer. Dans l'application, faites-le vous-même : <strong>Plus › Paramètres › Supprimer mon compte</strong> anonymise votre compte et ferme l'espace de l'association (un éventuel abonnement est arrêté en même temps). Depuis le site, écrivez-nous à <a href="mailto:contact@assokit.fr?subject=Fermeture%20de%20l%27association">contact@assokit.fr</a>. Comme le prévoient nos <a href="/cgu">conditions générales</a> (section 8), à la fermeture de l'espace de l'association, les données restent conservées 30 jours pour vous permettre de les exporter, puis elles sont supprimées définitivement, à l'exception des pièces comptables (10 ans).</p>

      <h3>Votre compte a été retiré par un administrateur de votre association</h3>
      <p>Il reste 30 jours dans la corbeille de l'association, d'où il peut encore être restauré. Passé ce délai, vos données personnelles sont anonymisées de la même façon (section 4).</p>

      <h3>Boîte mail Gmail ou Google Agenda reliés</h3>
      <p>Ces connexions appartiennent à l'association, pas à votre compte personnel. Un administrateur peut les couper à tout moment : <strong>Boîte mail › Réglages › Déconnecter et effacer la copie locale</strong>, ou <strong>Paramètres › Intégrations › Google Calendar › Déconnecter</strong> (détails dans la <a href="/confidentialite#google-api">section 12 de la politique de confidentialité</a>).</p>

      <h2 id="contact">7. Une question ?</h2>
      <p>Écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>. Si vous estimez que vos droits ne sont pas respectés, vous pouvez saisir la <a href="https://www.cnil.fr" target="_blank" rel="noopener">CNIL</a>.</p>
      <p>Voir aussi : <a href="/confidentialite">politique de confidentialité</a> · <a href="/cgu">conditions générales</a> · <a href="/mentions-legales">mentions légales</a>.</p>

      <?php /* Date de version : à changer seulement quand le texte change */ ?>
      <p style="margin-top:30px;color:var(--c-text-3);font-size:14px;">Version du 23/10/2026</p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
