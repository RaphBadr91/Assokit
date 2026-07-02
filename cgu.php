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
    <p class="pub-tagline">Dernière mise à jour : <?= date('d/m/Y') ?></p>
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

      <h2>4. Tarifs et paiement</h2>
      <p>Les tarifs sont indiqués sur la page <a href="/tarifs">tarifs</a> et peuvent être TTC ou HT selon le plan. Le paiement est mensuel ou annuel, par carte bancaire via notre partenaire de paiement sécurisé.</p>
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
      <p>L'Utilisateur peut résilier son abonnement à tout moment depuis son espace ou par email. La résiliation prend effet à la fin de la période en cours. Aucun remboursement prorata pour le mois en cours.</p>
      <p>RBPS conserve les données 30 jours après résiliation pour permettre à l'Utilisateur de les exporter, puis les supprime définitivement.</p>

      <h2>9. Propriété intellectuelle</h2>
      <p>Le Service, son code, sa marque et ses contenus sont la propriété exclusive de RBPS. Aucune licence n'est accordée à l'Utilisateur en dehors du droit d'usage du Service.</p>

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
