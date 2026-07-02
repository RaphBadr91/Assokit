<?php
require_once __DIR__ . '/includes-public.php';

render_public_head([
    'title'       => 'Politique de confidentialité',
    'description' => 'Politique de confidentialité d\'Assokit : protection de vos données personnelles, conformité RGPD, hébergement en France.',
    'path'        => '/confidentialite',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Confidentialité</strong></div>
    <h1 class="pub-h1" style="font-size:36px;">Politique de confidentialité</h1>
    <p class="pub-tagline">Vos données restent les vôtres. Vraiment.</p>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content">
      <p style="background:var(--c-emeraude-light);border-left:4px solid var(--c-emeraude);padding:16px 20px;border-radius:8px;font-style:italic;color:var(--c-encre);">
        🌿 <strong>En une phrase</strong> : on ne collecte que le strict nécessaire, on l'héberge en France, on ne le revend jamais, et vous pouvez tout récupérer ou tout supprimer en un clic.
      </p>

      <h2>1. Qui est responsable du traitement ?</h2>
      <p><strong>RBPS</strong>, éditeur d'Assokit, est responsable du traitement de vos données personnelles.</p>
      <p>Contact : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>

      <h2>2. Quelles données collectons-nous ?</h2>
      <p>Nous collectons uniquement les données nécessaires au fonctionnement du Service :</p>
      <ul>
        <li><strong>Identification</strong> : nom, prénom, email, mot de passe (haché)</li>
        <li><strong>Structure</strong> : nom, type, adresse, SIREN/RNA si applicable</li>
        <li><strong>Usage</strong> : journaux de connexion, statistiques d'utilisation anonymisées</li>
        <li><strong>Contenus</strong> : ce que vous téléversez (factures, contacts, messages…)</li>
      </ul>

      <h2>3. Pourquoi ces données ?</h2>
      <p>Vos données sont utilisées exclusivement pour :</p>
      <ul>
        <li>Vous fournir le Service (gestion de votre compte, fonctionnalités)</li>
        <li>Vous envoyer les informations contractuelles (factures, support)</li>
        <li>Améliorer le Service de manière anonymisée</li>
        <li>Respecter nos obligations légales (comptabilité, fiscalité)</li>
      </ul>
      <p>Nous ne faisons <strong>aucune publicité ciblée</strong>. Nous ne <strong>revendons aucune donnée</strong>. Jamais.</p>

      <h2>4. Combien de temps les conservons-nous ?</h2>
      <ul>
        <li><strong>Compte actif</strong> : pendant toute la durée de l'abonnement</li>
        <li><strong>Après résiliation</strong> : 30 jours pour permettre l'export, puis suppression définitive</li>
        <li><strong>Factures et obligations légales</strong> : 10 ans (obligation comptable française)</li>
        <li><strong>Logs techniques</strong> : 12 mois maximum</li>
      </ul>

      <h2>5. Avec qui partageons-nous vos données ?</h2>
      <p>Nous utilisons des sous-traitants français ou européens, conformes RGPD :</p>
      <ul>
        <li><strong>O2Switch</strong> (France) — hébergement</li>
        <li><strong>Resend</strong> (UE) — envoi d'emails transactionnels</li>
        <li><strong>Stripe</strong> (UE) — traitement des paiements (à venir)</li>
        <li><strong>Anthropic</strong> (USA) — pour les fonctions IA, uniquement les contenus que vous générez volontairement</li>
        <li><strong>Google LLC</strong> (USA) — uniquement si vous activez la connexion Google Calendar (voir section 12)</li>
        <li><strong>Google Analytics</strong> (Google LLC, USA) — mesure d'audience anonymisée sur le site public uniquement (pas dans l'application). IP anonymisée. Aucune publicité.</li>
      </ul>
      <p>Aucun partage à des fins publicitaires.</p>

      <h2>6. Où sont stockées vos données ?</h2>
      <p>Vos données sont stockées sur des serveurs <strong>situés en France</strong> (O2Switch, Clermont-Ferrand). Aucun transfert hors UE en dehors des cas mentionnés ci-dessus.</p>

      <h2>7. Comment sont-elles protégées ?</h2>
      <ul>
        <li>Connexion HTTPS (TLS 1.3) sur tout le site</li>
        <li>Mots de passe hachés avec bcrypt</li>
        <li>Sauvegardes quotidiennes chiffrées</li>
        <li>Journal des accès administrateurs</li>
        <li>Mises à jour de sécurité régulières</li>
      </ul>

      <h2>8. Vos droits (RGPD)</h2>
      <p>Vous disposez à tout moment des droits suivants :</p>
      <ul>
        <li><strong>Accès</strong> : consulter vos données</li>
        <li><strong>Rectification</strong> : modifier vos données inexactes</li>
        <li><strong>Effacement</strong> : demander la suppression complète</li>
        <li><strong>Portabilité</strong> : récupérer vos données en format ouvert</li>
        <li><strong>Opposition</strong> : refuser certains traitements</li>
        <li><strong>Limitation</strong> : restreindre certains traitements</li>
      </ul>
      <p>Pour exercer ces droits : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a> · réponse sous 30 jours maximum.</p>

      <h2>9. Cookies</h2>
      <p>Nous utilisons uniquement des cookies <strong>strictement nécessaires</strong> au fonctionnement du Service (session, sécurité). Pas de cookies publicitaires, pas de tracking tiers. Voir notre <a href="/cookies">politique cookies</a> pour le détail.</p>

      <h2>10. Réclamation</h2>
      <p>Si vous estimez que vos droits ne sont pas respectés, vous pouvez introduire une réclamation auprès de la <a href="https://www.cnil.fr" target="_blank" rel="noopener">CNIL</a>.</p>

      <h2 id="google-api">12. Connexion à Google Calendar (OAuth)</h2>
      <p>Si vous activez la synchronisation Google Calendar dans votre espace Assokit, nous accédons à votre compte Google via le protocole sécurisé <strong>OAuth 2.0</strong>. Cette connexion est <strong>strictement optionnelle</strong> et peut être révoquée à tout moment.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.1 Quelles données Google sont accédées ?</h3>
      <p>Nous demandons uniquement le périmètre minimum nécessaire (« scope ») :</p>
      <ul>
        <li><code>https://www.googleapis.com/auth/calendar.events</code> — pour <strong>lire et écrire les événements</strong> de votre calendrier sélectionné. Nous ne pouvons pas modifier les paramètres du calendrier, ses partages ou ses listes.</li>
        <li><code>https://www.googleapis.com/auth/userinfo.email</code> — pour identifier le compte Google connecté.</li>
      </ul>
      <p>Nous <strong>n'accédons à aucune autre donnée Google</strong> : ni Gmail, ni Drive, ni Photos, ni Contacts, ni aucun autre service.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.2 Comment ces données sont utilisées ?</h3>
      <p>Les données reçues de Google sont utilisées <strong>exclusivement</strong> pour :</p>
      <ul>
        <li><strong>Synchroniser</strong> les événements entre votre Google Calendar et votre espace Assokit (création, modification, suppression)</li>
        <li><strong>Afficher</strong> dans Assokit les événements importés</li>
        <li><strong>Identifier</strong> le compte Google associé à votre connexion</li>
      </ul>
      <p>Nous <strong>ne lisons jamais</strong> le contenu de vos événements à des fins d'analyse, de profilage, de publicité ou de revente.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.3 Limited Use — conformité Google</h3>
      <p>L'utilisation et le transfert d'informations reçues des API Google par Assokit respectent strictement la <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener"><strong>Google API Services User Data Policy</strong></a>, y compris les exigences de <strong>Limited Use</strong>. En particulier :</p>
      <ul>
        <li>Nous n'utilisons les données Google que pour fournir et améliorer les fonctionnalités visibles par l'utilisateur (la synchronisation de calendrier).</li>
        <li>Nous ne transférons ces données à aucun tiers, sauf pour fournir ou améliorer le service, ou si la loi l'exige.</li>
        <li>Nous n'utilisons pas ces données pour de la publicité.</li>
        <li>Nous ne permettons à aucun humain de lire ces données, sauf : (a) avec votre consentement explicite, (b) pour la sécurité (enquête sur un abus), (c) pour respecter une obligation légale, ou (d) si les données sont agrégées et anonymisées.</li>
      </ul>

      <h3 style="font-size:18px;margin-top:18px;">12.4 Stockage &amp; sécurité</h3>
      <ul>
        <li>Les jetons d'accès Google (access_token, refresh_token) sont stockés <strong>chiffrés</strong> en base de données chez O2Switch (France).</li>
        <li>Les événements importés sont stockés dans votre espace Assokit, en France, soumis aux mêmes mesures de sécurité que vos autres données (TLS, sauvegardes, journaux d'accès).</li>
        <li>Aucune donnée Google n'est transférée hors de l'Union Européenne en dehors des appels API techniques nécessaires à la synchronisation.</li>
      </ul>

      <h3 style="font-size:18px;margin-top:18px;">12.5 Comment révoquer l'accès ?</h3>
      <p>Vous pouvez révoquer notre accès à votre compte Google <strong>à tout moment</strong>, de deux façons :</p>
      <ol>
        <li>Depuis Assokit : <strong>Paramètres → Intégrations → Google Calendar → Déconnecter</strong>. Les jetons sont immédiatement révoqués et supprimés.</li>
        <li>Depuis votre compte Google : <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a> → trouver « Assokit » → Supprimer l'accès.</li>
      </ol>
      <p>Les événements déjà synchronisés dans Assokit restent dans votre espace après révocation. Vous pouvez demander leur suppression via <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>.</p>

      <h2>13. Modifications</h2>
      <p>Cette politique peut évoluer. Toute modification substantielle vous sera notifiée par email avec un préavis de 30 jours.</p>

      <p style="margin-top:30px;color:var(--c-text-3);font-size:14px;">Dernière mise à jour : <?= date('d/m/Y') ?></p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
