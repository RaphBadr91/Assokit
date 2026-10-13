<?php
require_once __DIR__ . '/includes-public.php';

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
    <p class="pub-tagline">Version du 13/10/2026</p>
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

      <h2>4. Formules, essai, tarifs et paiement</h2>
      <p>Le Service est proposé selon trois formules, décrites sur la page <a href="/tarifs">Tarifs</a> :</p>
      <ul>
        <li><strong>Essai PRO</strong> : essai gratuit de 14 jours, sans carte bancaire, donnant accès aux fonctionnalités de la formule Assokit (les envois d’e-mails et les imports sont plafonnés pendant l’essai). L’essai ne se transforme jamais en abonnement payant sans action de l’Utilisateur : à son terme, aucun montant n’est prélevé, les données sont conservées et l’espace passe en accès limité (fonctionnalités restreintes) jusqu’à la souscription d’une formule.</li>
        <li><strong>Assokit</strong> : abonnement mensuel au prix indiqué sur la page Tarifs.</li>
        <li><strong>Sur-mesure</strong> : offre sur devis pour les réseaux, fédérations, grandes structures et collectivités, régie par les conditions particulières du devis accepté.</li>
      </ul>
      <p>Les prix sont exprimés <strong>hors taxes (HT)</strong> ; la TVA au taux en vigueur s’y ajoute, sauf mention « TVA non applicable, art. 293 B du CGI ». Le montant toutes taxes comprises (TTC) est indiqué avant tout paiement.</p>
      <p>L’abonnement Assokit est <strong>mensuel</strong> (il n’existe pas de formule annuelle). Il est payable d’avance chaque mois, par carte bancaire via notre partenaire de paiement sécurisé (Stripe) ou, sur accord, sur facture par virement, et se renouvelle automatiquement chaque mois jusqu’à sa résiliation. Les factures sont mises à disposition dans l’espace de l’Utilisateur.</p>
      <p>Toute modification des prix est annoncée par e-mail au moins 30 jours avant son application à un abonnement en cours ; l’Utilisateur qui la refuse peut résilier avant son entrée en vigueur.</p>
      <p>En cas de défaut de paiement, l'accès peut être suspendu après un préavis de 15 jours.</p>

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

      <h2>8. Résiliation</h2>
      <p>L’abonnement est <strong>sans engagement de durée</strong>. L'Utilisateur peut le résilier à tout moment, sans frais, depuis son espace (rubrique « Mon abonnement ») ou par e-mail. La résiliation prend effet à la <strong>fin de la période mensuelle en cours</strong>, déjà payée : l’accès à la formule est maintenu jusqu’à cette date et aucun nouveau prélèvement n’a lieu. Aucun remboursement au prorata n’est effectué pour la période entamée.</p>
      <p>Après la résiliation de l’abonnement, le compte passe en accès limité : les données restent conservées et exportables, et l’Utilisateur peut reprendre un abonnement à tout moment.</p>
      <p><strong>Suppression d’un compte personnel par son titulaire</strong> (depuis son espace, sur le site ou dans l’application) : les données d’identification du compte sont anonymisées immédiatement et la connexion n’est plus possible ; il appartient donc à l’Utilisateur d’exporter ses données avant (Paramètres › Compte). Les contenus qu’il a partagés dans son association restent dans les dossiers de celle-ci, signés « Compte supprimé », tant que l’association utilise le Service. Un compte retiré par un administrateur de l’association reste 30 jours dans la corbeille de l’association, puis il est anonymisé de la même façon.</p>
      <p><strong>Fermeture de l’espace d’une association</strong> (sur demande à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>) : RBPS conserve les données 30 jours pour permettre de les exporter, puis les supprime définitivement, à l’exception des pièces comptables, conservées 10 ans (obligation comptable). Le détail figure sur la page <a href="/suppression-compte">Supprimer votre compte</a>.</p>

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
