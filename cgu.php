<?php
require_once __DIR__ . '/includes-public.php';
// ak_billing_hidden() : page vue dans l'application mobile (ou par un compte de démonstration). Le
// contrat reste le même, mais l'app ne décrit ni le mode de paiement ni le lien Tarifs (masqué par
// l'app, ce qui laissait « décrites sur la page  : ») : Apple 3.1.1 / 3.1.3 f, paiements Google Play.
require_once __DIR__ . '/app-context.php';
$cgu_in_app = ak_billing_hidden();

render_public_head([
    'title'       => 'Conditions générales d\'utilisation et de vente',
    'description' => 'Conditions générales d\'utilisation et de vente du service Assokit.',
    'path'        => '/cgu',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">CGU / CGV</strong></div>
    <h1 class="pub-h1" style="font-size:36px;">Conditions générales</h1>
    <?php /* Date de version du texte (dernière modification) : à changer seulement quand le texte change */ ?>
    <p class="pub-tagline">Version du 29/10/2026</p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content">
      <h2>1. Objet</h2>
      <p>Les présentes conditions générales (CGU/CGV) régissent l'utilisation du service Assokit, édité par RBPS, accessible à l'adresse <a href="https://assokit.fr">assokit.fr</a>. En s'inscrivant, l'utilisateur accepte sans réserve les présentes conditions.</p>

      <h2>2. Définitions</h2>
      <ul>
        <li><strong>Service</strong> : la plateforme Assokit et ses fonctionnalités</li>
        <li><strong>Utilisateur</strong> : toute personne physique ou morale utilisant le Service</li>
        <li><strong>Compte</strong> : espace personnel créé lors de l'inscription</li>
        <li><strong>Contenu</strong> : données, textes, fichiers téléversés ou générés par l'Utilisateur</li>
      </ul>

      <h2>3. Inscription et compte</h2>
      <p>L'inscription est ouverte aux associations loi 1901, TPE, indépendants et autres structures professionnelles. L'Utilisateur s'engage à fournir des informations exactes et à les maintenir à jour.</p>
      <p>La création d’un espace peut être soumise à la validation de notre équipe (c’est le cas d’une demande faite depuis l’application iPhone) : la demande est examinée sous 48 heures, l’espace n’est utilisable qu’après sa validation, et l’Utilisateur est prévenu par e-mail de l’activation comme d’un éventuel refus. Tant qu’elle n’est pas validée, ou après un refus, l’Utilisateur peut retirer sa demande à tout moment (page <a href="/retirer-demande">Retirer ma demande</a>, aussi accessible dans l’application)&nbsp;: son compte est supprimé et les informations transmises sont effacées immédiatement.</p>

<?php if ($cgu_in_app): ?>
      <h2>4. Conditions commerciales</h2>
      <p>Les conditions commerciales du Service (formule, durée, prix) sont convenues avec la structure (association, entreprise) lors de sa souscription, et décrites sur notre site, dans la version complète de ces conditions générales. Toute modification de prix est annoncée par e-mail au moins 30 jours avant son application ; la structure qui la refuse peut résilier avant son entrée en vigueur. En cas de défaut de paiement, l'accès peut être suspendu après un préavis de 15 jours.</p>
<?php else: ?>
      <h2 id="paiement">4. Formules, essai, tarifs et paiement</h2>
      <p>Le Service est proposé selon trois formules, décrites sur la page <a href="/tarifs">Tarifs</a> :</p>
      <ul>
        <li><strong>Essai PRO</strong> : essai gratuit de 14 jours, sans carte bancaire, donnant accès aux fonctionnalités de la formule Assokit (les envois d’e-mails et les imports sont plafonnés pendant l’essai). L’essai ne se transforme jamais en abonnement payant sans action de l’Utilisateur : à son terme, sans souscription, aucun montant n’est prélevé, les données sont conservées et l’espace passe en accès limité (fonctionnalités restreintes) jusqu’à la souscription d’une formule. L’Utilisateur qui souscrit pendant l’essai garde tous ses jours d’essai (voir ci-dessous).</li>
        <li><strong>Assokit</strong> : abonnement mensuel au prix indiqué sur la page Tarifs.</li>
        <li><strong>Sur-mesure</strong> : offre sur devis pour les réseaux, fédérations, grandes structures et collectivités, régie par les conditions particulières du devis accepté.</li>
      </ul>
      <p>Les prix sont exprimés <strong>hors taxes (HT)</strong> ; la TVA au taux en vigueur s’y ajoute, sauf mention « TVA non applicable, art. 293 B du CGI ». Le montant toutes taxes comprises (TTC) est indiqué avant tout paiement.</p>
      <p>L’abonnement Assokit est <strong>mensuel</strong> (il n’existe pas de formule annuelle). Il est payable d’avance chaque mois, via notre partenaire de paiement sécurisé (Stripe), par <strong>carte bancaire</strong>, <strong>Apple Pay</strong>, <strong>Google Pay</strong> ou <strong>prélèvement SEPA</strong>, ou, sur accord, sur facture par virement. Le prélèvement SEPA repose sur un mandat donné lors de la souscription ; chaque prélèvement est annoncé par e-mail au moins 2 jours avant sa date, et sa confirmation par la banque peut prendre quelques jours ouvrés.</p>
      <p>Souscrire pendant l’essai gratuit <strong>ne l’interrompt pas</strong> : l’Utilisateur garde tous ses jours d’essai. Le moyen de paiement (carte bancaire, Apple Pay, Google Pay ou mandat de prélèvement SEPA) est enregistré le jour de la souscription, <strong>sans aucun prélèvement</strong> ; le premier mois est prélevé <strong>automatiquement le jour de la fin de l’essai</strong>, date à laquelle l’abonnement commence. Un e-mail rappelle cette date et ce montant trois jours avant (pour une souscription faite moins de trois jours avant la fin de l’essai, l’e-mail de confirmation de la souscription en tient lieu). Jusqu’à cette date, l’Utilisateur peut <strong>annuler sa souscription sans frais</strong> (rubrique « Mon abonnement ») : aucun montant n’est alors prélevé et l’essai se termine normalement à sa date. Lorsque l’essai se termine dans moins de 48 heures, ou une fois l’essai terminé, le premier mois est prélevé le jour de la souscription.</p>
      <p>L’abonnement se renouvelle ensuite automatiquement <strong>chaque mois à la date anniversaire</strong> du premier prélèvement, jusqu’à sa résiliation. Les factures sont mises à disposition dans l’espace de l’Utilisateur (rubrique « Mon abonnement »).</p>
      <p>Toute modification des prix est annoncée par e-mail au moins 30 jours avant son application à un abonnement en cours ; l’Utilisateur qui la refuse peut résilier avant son entrée en vigueur.</p>
      <p><strong>Paiement refusé</strong> (y compris le premier prélèvement à la fin de l’essai) : l’Utilisateur est prévenu par e-mail et dispose de <strong>15 jours</strong> pour régulariser (mise à jour de la carte ou règlement de la facture), pendant lesquels l’accès est maintenu. Sans régularisation à l’issue de ce délai, certaines fonctionnalités (Assistant IA, diffusion d’e-mails) sont bloquées ; si les nouvelles tentatives de prélèvement échouent aussi, l’abonnement est arrêté et le compte passe en accès limité, ses données restant conservées. L’Utilisateur en est informé par e-mail et peut reprendre un abonnement à tout moment.</p>
      <p>Le Service est réservé aux structures (associations, entreprises, indépendants) qui l’utilisent pour leur organisation ; il n’est pas destiné aux consommateurs. Le droit de rétractation prévu pour les consommateurs (art. L221-18 du Code de la consommation) ne s’applique donc pas ; l’essai gratuit de 14 jours permet d’évaluer le Service avant tout paiement.</p>
<?php endif; ?>

      <h2>5. Propriété des données</h2>
      <p><strong>Vos données vous appartiennent.</strong> RBPS ne s'octroie aucun droit sur les données téléversées par l'Utilisateur. Un export complet est disponible à tout moment.</p>

      <h2>6. Engagements de RBPS</h2>
      <ul>
        <li>Disponibilité de service supérieure à 99% sur l'année</li>
        <li>Hébergement en France (O2Switch, Clermont-Ferrand)</li>
        <li>Sauvegardes quotidiennes des données</li>
        <li>Support utilisateur en moins de 24h ouvrées</li>
        <li>Conformité RGPD</li>
      </ul>

      <h2>7. Obligations de l'Utilisateur</h2>
      <p>L'Utilisateur s'engage à utiliser le Service conformément à la loi française et aux bonnes mœurs. Il est responsable des contenus qu'il téléverse et de la confidentialité de ses identifiants.</p>
      <p>Sont notamment interdits :</p>
      <ul>
        <li>L'utilisation du Service à des fins illégales</li>
        <li>Le téléversement de contenus protégés par droits d'auteur sans autorisation</li>
        <li>L'envoi de spam ou de communications non sollicitées</li>
        <li>Les tentatives d'intrusion ou de surcharge du Service</li>
      </ul>

      <h3 id="contenus-inacceptables">Contenus inacceptables et signalements : tolérance zéro</h3>
      <p>RBPS applique une <strong>tolérance zéro</strong> envers les contenus inacceptables et les utilisateurs abusifs. Sont notamment interdits, dans les messages, les documents et tout contenu partagé sur le Service : les contenus illicites, haineux ou discriminatoires, le harcèlement et les menaces, les contenus à caractère sexuel, les contenus violents, les propos diffamatoires ou injurieux, ainsi que le spam.</p>
      <p><strong>Signaler un contenu</strong> : dans l’application, appuyez longuement sur un message, puis touchez <strong>« Signaler ce message »</strong> ; vous pouvez aussi masquer les messages de son auteur. Sur le site comme dans l’application, vous pouvez également écrire au support (rubrique « Support ») ou à <a href="mailto:contact@assokit.fr?subject=Signalement">contact@assokit.fr</a>.</p>
      <p>Tout signalement est <strong>examiné sous 24 heures</strong>. Un contenu contraire aux présentes conditions est <strong>retiré</strong>, et son auteur peut être <strong>exclu</strong> du Service (compte désactivé), sans préjudice des signalements aux autorités compétentes lorsque la loi l’impose.</p>

      <h2 id="resiliation">8. Résiliation</h2>
      <p>L’abonnement est <strong>sans engagement de durée</strong>. L'Utilisateur peut le résilier à tout moment, sans frais, <?= $cgu_in_app ? 'depuis son espace sur le site' : 'depuis son espace (rubrique « Mon abonnement »)' ?> ou par e-mail. La résiliation prend effet à la <strong>fin de la période mensuelle en cours</strong>, déjà payée : l’accès à la formule est maintenu jusqu’à cette date et aucun nouveau prélèvement n’a lieu. Aucun remboursement au prorata n’est effectué pour la période entamée. La résiliation est confirmée par e-mail, avec la date de fin d’accès.<?= $cgu_in_app ? '' : ' Une souscription faite pendant l’essai gratuit peut être annulée sans frais jusqu’au premier prélèvement (§ 4) : rien n’est prélevé et l’essai continue jusqu’à sa fin.' ?></p>
      <p>Après la résiliation de l’abonnement, le compte passe en accès limité : les données restent conservées et exportables, et l’Utilisateur peut reprendre un abonnement à tout moment.</p>
      <p><strong>Suppression d’un compte personnel par son titulaire</strong> (depuis son espace, sur le site ou dans l’application) : les données d’identification du compte sont anonymisées immédiatement et la connexion n’est plus possible ; il appartient donc à l’Utilisateur d’exporter ses données avant (Paramètres › Compte). Les contenus qu’il a partagés dans son association restent dans les dossiers de celle-ci, signés « Compte supprimé », tant que l’association utilise le Service. Un compte retiré par un administrateur de l’association reste 30 jours dans la corbeille de l’association, puis il est anonymisé de la même façon.</p>
      <p><strong>Fermeture de l’espace d’une association</strong> (sur demande à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>, ou depuis l’application, Paramètres › Supprimer mon compte, quand le compte est le seul de l’association) : RBPS conserve les données 30 jours pour permettre de les exporter, puis les supprime définitivement, à l’exception des pièces comptables, conservées 10 ans (obligation comptable). Le détail figure sur la page <a href="/suppression-compte">Supprimer votre compte</a>.</p>

      <h2>9. Propriété intellectuelle</h2>
      <p>Le Service — son code source, sa conception, son architecture, ses interfaces, ses textes, ses bases de données, sa marque et son identité visuelle — est la propriété exclusive de <strong>Raphaël Badr Pujol-Siwane</strong>, auteur et titulaire de l'ensemble des droits de propriété intellectuelle qui s'y attachent, y compris ceux portant sur l'innovation qu'il met en œuvre. RBPS exploite le Service sur autorisation du titulaire des droits.</p>
      <p>« Assokit » est une <strong>marque déposée</strong> auprès de l'INPI sous le numéro national <strong>26 5259962</strong> (dépôt du 20 mai 2026). Le logo et l'identité visuelle sont protégés au même titre.</p>
      <p>Aucune licence n'est accordée à l'Utilisateur en dehors d'un droit d'usage personnel, non exclusif et non cessible du Service pendant la durée de son abonnement. Sont notamment interdites, sans autorisation écrite préalable : la reproduction, l'adaptation, la traduction, la diffusion, la mise à disposition de tiers, la décompilation en dehors des cas prévus par la loi, ainsi que l'extraction ou la réutilisation substantielle des bases de données.</p>
      <p>Les contenus téléversés par l'Utilisateur (données de son association, documents, fichiers) restent sa propriété pleine et entière. L'ensemble est régi par le droit français, et notamment par le Code de la propriété intellectuelle. Voir également les <a href="/mentions-legales">mentions légales</a>.</p>

      <h2>10. Limitation de responsabilité</h2>
      <p>RBPS ne pourra être tenue responsable des dommages indirects (perte d'exploitation, perte de bénéfices, etc.) résultant de l'utilisation du Service. Sa responsabilité est en tout état de cause limitée au montant des sommes versées par l'Utilisateur sur les 12 derniers mois.</p>

      <h2 id="integrations">11. Intégrations tierces (Google API)</h2>
      <p>Le Service propose des intégrations optionnelles avec des services tiers, notamment <strong>Google Calendar</strong>, qui sont soumises aux conditions d'utilisation du fournisseur tiers concerné.</p>
      <p><strong>Connexion Google Calendar :</strong> en activant cette intégration, l'Utilisateur autorise Assokit à accéder à son compte Google via le protocole sécurisé OAuth 2.0, dans la limite des autorisations (« scopes ») demandées :</p>
      <ul>
        <li><code>auth/calendar.events</code> — lecture et écriture des événements du calendrier sélectionné par l'Utilisateur, à des fins de synchronisation avec son espace Assokit.</li>
        <li><code>auth/userinfo.email</code> — identification du compte Google connecté.</li>
      </ul>
      <p>L'utilisation et le transfert des données reçues des API Google par Assokit respectent la <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, y compris les exigences de <strong>Limited Use</strong>. Les conditions détaillées d'usage des données Google figurent dans notre <a href="/confidentialite#google-api">politique de confidentialité — section 12</a>.</p>
      <p>L'Utilisateur peut révoquer cette autorisation à tout moment depuis son espace Assokit (Paramètres → Intégrations → Déconnecter) ou depuis son compte Google (<a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>).</p>
      <p>RBPS ne saurait être tenue responsable d'une indisponibilité, d'une limitation, d'une modification ou d'une suppression des API Google indépendante de sa volonté.</p>

      <h2 id="paiements-en-ligne">11 bis. Paiements en ligne reçus par l’Utilisateur (Stripe Connect)</h2>
      <p>Le Service permet à la structure (association, entreprise) de faire payer en ligne, par carte bancaire, Apple Pay, Google Pay ou prélèvement SEPA, ses propres <strong>factures</strong>, <strong>cotisations</strong> et <strong>dons</strong>, par l’intermédiaire du prestataire de paiement <strong>Stripe</strong> (Stripe Payments Europe, Ltd., Irlande).</p>
      <ul>
        <li><strong>Compte Stripe de la structure.</strong> Pour activer cette fonction, la structure ouvre son propre compte Stripe depuis son espace (« Paiements en ligne ») et accepte, auprès de Stripe, les conditions de service de Stripe (<a href="https://stripe.com/fr/legal/ssa" target="_blank" rel="noopener">accord de services Stripe</a>, y compris l’accord applicable aux comptes connectés). Stripe vérifie son identité, celle de son représentant légal et son compte bancaire.</li>
        <li><strong>La structure est le vendeur.</strong> Elle est seule bénéficiaire des paiements : les fonds sont versés directement par le payeur sur le compte Stripe de la structure, puis par Stripe sur son compte bancaire. Elle reste seule responsable de ce qu’elle vend ou collecte, de ses prix, de ses factures, de ses remboursements, des contestations de paiement et de ses obligations comptables, fiscales et, le cas échéant, de délivrance des reçus fiscaux (Assokit n’émet aucun reçu fiscal).</li>
        <li><strong>Assokit est un intermédiaire technique.</strong> RBPS fournit le logiciel qui affiche les liens de paiement et enregistre les paiements reçus ; elle n’encaisse, ne détient et ne transfère aucun fonds, n’est pas un prestataire de services de paiement et n’est pas partie aux transactions entre la structure et ses payeurs.</li>
        <li><strong>Frais.</strong> Les frais de Stripe sont à la charge de la structure et prélevés par Stripe sur chaque paiement, selon ses tarifs. RBPS ne prélève <strong>aucune commission</strong> sur ces paiements à ce jour ; toute commission future serait annoncée dans les conditions de l’article 12.</li>
        <li><strong>Données des payeurs.</strong> Assokit traite, pour le compte de la structure, le nom, l’adresse e-mail, le montant et les références Stripe de chaque paiement (enregistrement du paiement, reçu envoyé au payeur). Les données de carte et les coordonnées bancaires des payeurs sont traitées par Stripe ; RBPS n’y a jamais accès.</li>
        <li><strong>Déconnexion.</strong> La structure peut désactiver les paiements en ligne à tout moment depuis son espace ; son compte Stripe et les fonds qui s’y trouvent restent les siens. Stripe peut limiter ou fermer un compte selon ses propres conditions.</li>
      </ul>

      <h2>12. Modifications</h2>
      <p>RBPS se réserve le droit de modifier les présentes conditions. Les Utilisateurs seront informés par email au moins 30 jours avant l'entrée en vigueur des modifications substantielles.</p>

      <h2>13. Droit applicable et juridiction</h2>
      <p>Les présentes conditions sont soumises au droit français. En cas de litige, et après tentative de résolution amiable, les tribunaux français seront seuls compétents.</p>

      <h2>14. Contact</h2>
      <p>Pour toute question : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
