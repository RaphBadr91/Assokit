<?php
require_once __DIR__ . '/config.php';          // paramètres société (identité du responsable)
require_once __DIR__ . '/includes-public.php';

$L = pub_legal_identity();

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
      <?php /* Adresses d'API Google (section 12.1) : coupées au besoin, sinon la page déborde sur mobile */ ?>
      <style>.pub-article-content code{overflow-wrap:anywhere;word-break:break-word}</style>
      <p style="background:var(--c-emeraude-light);border-left:4px solid var(--c-emeraude);padding:16px 20px;border-radius:8px;font-style:italic;color:var(--c-encre);">
        🌿 <strong>En une phrase</strong> : on ne collecte que le strict nécessaire, on l'héberge en France, on ne le revend jamais, et vous pouvez tout récupérer ou tout supprimer en un clic.
      </p>

      <h2>1. Qui est responsable du traitement ?</h2>
      <p><strong><?= pub_h($L['name']) ?></strong>, éditeur d'Assokit, est responsable du traitement de vos données personnelles.</p>
      <ul>
        <li>Adresse : <?= pub_h($L['address'] ?: 'Évry, France') ?></li>
        <li>Contact : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></li>
      </ul>
      <p>Les autres informations sur l'éditeur figurent dans les <a href="/mentions-legales">mentions légales</a>.</p>

      <h2>2. Quelles données collectons-nous ?</h2>
      <p>Nous collectons uniquement les données nécessaires au fonctionnement du Service :</p>
      <ul>
        <li><strong>Identification</strong> : nom, prénom, email, mot de passe (haché)</li>
        <li><strong>Structure</strong> : nom, type, adresse, SIREN/RNA si applicable</li>
        <li><strong>Usage</strong> : journaux de connexion, statistiques d'utilisation anonymisées</li>
        <li><strong>Contenus</strong> : ce que vous téléversez (factures, contacts, messages…)</li>
      </ul>

      <h2>3. Pourquoi ces données, et sur quelle base légale ?</h2>
      <p>Vos données sont utilisées exclusivement pour les finalités suivantes, chacune fondée sur une base légale de l'article 6 du RGPD :</p>
      <ul>
        <li><strong>Vous fournir le Service</strong> (gestion de votre compte, fonctionnalités, abonnement et paiement) — <em>exécution du contrat</em> (nos <a href="/cgu">conditions générales</a>)</li>
        <li><strong>Vous envoyer les informations contractuelles</strong> (factures, support, sécurité du compte) — <em>exécution du contrat</em></li>
        <li><strong>Relier Gmail ou Google Agenda</strong>, si un administrateur de votre association le décide (section 12) — <em>exécution du contrat</em>, révocable à tout moment</li>
        <li><strong>Respecter nos obligations légales</strong> (comptabilité, fiscalité, conservation des factures) — <em>obligation légale</em></li>
        <li><strong>Sécuriser le Service</strong> (journaux de connexion, prévention des abus) et <strong>l'améliorer</strong> à partir de statistiques anonymisées — <em>intérêt légitime</em> à fournir un service fiable</li>
        <li><strong>Répondre à vos demandes</strong> envoyées par le formulaire de contact — <em>mesures précontractuelles</em> prises à votre demande, ou <em>intérêt légitime</em> à vous répondre</li>
        <li><strong>Mesurer l'audience du site public</strong> (Google Analytics) — <em>votre consentement</em>, que vous pouvez retirer à tout moment (section 9)</li>
      </ul>
      <p>Nous ne faisons <strong>aucune publicité ciblée</strong>. Nous ne <strong>revendons aucune donnée</strong>. Jamais.</p>

      <h2>4. Combien de temps les conservons-nous ?</h2>
      <ul>
        <li><strong>Compte actif</strong> : tant que le compte existe (essai, abonnement, ou accès limité après résiliation de l'abonnement)</li>
        <li><strong>Après suppression du compte</strong> : 30 jours pour permettre l'export, puis suppression définitive</li>
        <li><strong>Factures et obligations légales</strong> : 10 ans (obligation comptable française)</li>
        <li><strong>Logs techniques</strong> : 12 mois maximum</li>
        <li><strong>Cookies de mesure d'audience</strong> : 13 mois maximum ; votre choix sur ces cookies est conservé 6 mois</li>
      </ul>

      <h2>5. Avec qui partageons-nous vos données ?</h2>
      <p>Nous faisons appel aux sous-traitants suivants, conformes au RGPD et limités à leur mission. Certains sont situés aux États-Unis (voir section 11) :</p>
      <ul>
        <li><strong>O2Switch</strong> (France) — hébergement</li>
        <li><strong>Resend</strong> (UE) — envoi d'emails transactionnels</li>
        <li><strong>Stripe</strong> (UE) — traitement des paiements (abonnements Assokit, paiements en ligne)</li>
        <li><strong>Anthropic</strong> (USA) — pour les fonctions IA : contenus que vous générez volontairement et, si la Boîte mail est reliée, tri et brouillons de réponse (voir section 12.3). Pas d'entraînement de modèles sur vos données.</li>
        <li><strong>Google LLC</strong> (USA) — uniquement si vous reliez Gmail ou Google Agenda (voir section 12)</li>
        <li><strong>Google Analytics</strong> (Google LLC, USA) — mesure d'audience anonymisée sur le site public uniquement (pas dans l'application), <strong>seulement si vous l'acceptez</strong> (section 9). IP anonymisée. Aucune publicité.</li>
      </ul>
      <p>Aucun partage à des fins publicitaires.</p>

      <h2>6. Où sont stockées vos données ?</h2>
      <p>Vos données sont stockées sur des serveurs <strong>situés en France</strong> (O2Switch, Clermont-Ferrand). Aucun transfert hors UE en dehors des cas mentionnés ci-dessus (voir section 11).</p>

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
      <p>Le site utilise des cookies <strong>strictement nécessaires</strong> (session, sécurité, mémorisation de votre choix) et, <strong>uniquement si vous l'acceptez</strong>, des cookies de mesure d'audience Google Analytics sur le site public : adresse IP anonymisée, 13 mois au maximum, aucun usage publicitaire. Vous pouvez donner ou retirer ce consentement à tout moment avec le lien <a href="/cookies#gerer" onclick="if(window.akShowCookieBanner){akShowCookieBanner();return false;}">Gérer mes cookies</a>, présent en bas de chaque page. Pas de cookies publicitaires. Voir notre <a href="/cookies">politique cookies</a> pour le détail.</p>

      <h2>10. Réclamation</h2>
      <p>Si vous estimez que vos droits ne sont pas respectés, vous pouvez introduire une réclamation auprès de la <a href="https://www.cnil.fr" target="_blank" rel="noopener">CNIL</a>.</p>

      <h2>11. Transferts hors de l'Union européenne</h2>
      <p>Vos données sont hébergées en France. Certains sous-traitants sont établis aux <strong>États-Unis</strong> : <strong>Anthropic</strong> (fonctions d'IA) et <strong>Google</strong> (Gmail et Google Agenda si vous les reliez, Google Analytics si vous l'acceptez). Ces transferts se limitent aux données nécessaires à la fonction concernée et sont encadrés par les garanties prévues par le RGPD : décision d'adéquation de la Commission européenne pour les entreprises adhérant au cadre de protection des données UE–États-Unis (<em>Data Privacy Framework</em>), ou à défaut clauses contractuelles types de la Commission européenne (article 46 du RGPD).</p>

      <h2 id="google-api">12. Connexion à Google (Gmail et Google Agenda)</h2>
      <p>Deux connexions à Google sont proposées dans Assokit, toutes deux <strong>facultatives</strong>, activées uniquement par un administrateur de l'association et <strong>révocables à tout moment</strong> : la <strong>Boîte mail</strong> (Gmail) et la <strong>synchronisation de l'agenda</strong> (Google Agenda). L'accès passe par le protocole sécurisé <strong>OAuth 2.0</strong> : Assokit ne connaît jamais votre mot de passe Google.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.1 Quelles données Google sont accédées ?</h3>
      <p>Nous demandons uniquement les autorisations (« scopes ») nécessaires aux fonctionnalités que vous activez :</p>
      <ul>
        <li><code>https://www.googleapis.com/auth/gmail.modify</code> — <strong>Boîte mail</strong> : lire les e-mails reçus et envoyés de la boîte reliée, les marquer lus ou non lus, et envoyer les réponses que vous rédigez dans Assokit. Assokit <strong>ne supprime jamais</strong> d'e-mail dans Gmail.</li>
        <li><code>https://www.googleapis.com/auth/calendar.events</code> — <strong>Agenda</strong> : lire et écrire les événements du calendrier sélectionné. Nous ne pouvons pas modifier les paramètres du calendrier, ses partages ou ses listes.</li>
        <li><code>openid</code>, <code>https://www.googleapis.com/auth/userinfo.email</code> — identifier le compte Google relié.</li>
      </ul>
      <p>Nous n'accédons à <strong>aucun autre service Google</strong> : ni Drive, ni Photos, ni Contacts.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.2 Comment ces données sont-elles utilisées ?</h3>
      <p>Les données reçues de Google servent <strong>exclusivement</strong> aux fonctionnalités visibles dans Assokit :</p>
      <ul>
        <li><strong>Boîte mail</strong> : afficher les conversations aux administrateurs et coordinateurs de l'association ; les <strong>ranger par catégorie</strong> (facturation, adhérents, subventions…) selon l'objet et l'expéditeur ; les <strong>relier</strong> à la fiche de l'adhérent, du client ou de la facture concernée ; <strong>répondre</strong> depuis Assokit dans le même fil Gmail, depuis l'adresse de l'association ; synchroniser l'état lu / non lu.</li>
        <li><strong>Agenda</strong> : synchroniser les événements entre Google Agenda et Assokit.</li>
      </ul>
      <p>Nous n'utilisons jamais ces données à des fins de <strong>publicité</strong>, de profilage, de revente ou d'étude de marché.</p>

      <h3 style="font-size:18px;margin-top:18px;">12.3 Fonctions d'intelligence artificielle</h3>
      <p>Deux fonctions de la Boîte mail utilisent l'IA (Anthropic, États-Unis, en tant que sous-traitant) :</p>
      <ul>
        <li><strong>Tri automatique</strong> : pour les seuls e-mails qu'aucune règle de l'association ne reconnaît, l'expéditeur, l'objet et le début du message sont transmis à l'IA afin de proposer une catégorie. Cette fonction peut être <strong>désactivée</strong> par l'administrateur (Boîte mail › Réglages).</li>
        <li><strong>Brouillon de réponse</strong> : uniquement quand un utilisateur clique sur « Brouillon IA », la conversation concernée est transmise afin de proposer une réponse, que l'utilisateur relit et modifie avant tout envoi.</li>
      </ul>
      <p>Ces transmissions servent uniquement à fournir la fonction demandée. Les données reçues des API Google <strong>ne sont jamais utilisées pour entraîner ou améliorer des modèles d'intelligence artificielle</strong>, ni par Assokit ni par son sous-traitant (les contenus transmis via l'API d'Anthropic ne servent pas à l'entraînement de ses modèles).</p>

      <h3 style="font-size:18px;margin-top:18px;">12.4 Limited Use — conformité Google</h3>
      <p>L'utilisation et le transfert vers toute autre application d'informations reçues des API Google par Assokit respectent la <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener"><strong>Google API Services User Data Policy</strong></a>, y compris les exigences de <strong>Limited Use</strong>.</p>
      <p lang="en"><em>Assokit's use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, including the Limited Use requirements.</em></p>
      <p>En particulier :</p>
      <ul>
        <li>Nous n'utilisons les données Google que pour fournir et améliorer les fonctionnalités visibles décrites ci-dessus.</li>
        <li>Nous ne transférons ces données à aucun tiers, sauf au sous-traitant nécessaire à une fonctionnalité (section 12.3), pour la sécurité, ou si la loi l'exige.</li>
        <li>Nous n'utilisons pas ces données pour de la publicité, ni pour entraîner des modèles d'IA généralistes.</li>
        <li>Aucun membre de l'équipe Assokit ne lit ces données, sauf : (a) avec votre consentement explicite (par exemple pour une demande de support), (b) pour la sécurité (enquête sur un abus), (c) pour respecter une obligation légale, ou (d) si les données sont agrégées et anonymisées. Seuls les administrateurs et coordinateurs <strong>de votre propre association</strong> voient vos e-mails dans Assokit.</li>
      </ul>

      <h3 style="font-size:18px;margin-top:18px;">12.5 Stockage, sécurité et durée de conservation</h3>
      <ul>
        <li>Les jetons d'accès Google (access_token, refresh_token) sont stockés <strong>chiffrés (AES-256)</strong> en base de données chez O2Switch (France).</li>
        <li>Une copie des e-mails de la boîte reliée (en-têtes, texte, liste des pièces jointes) est conservée dans votre espace Assokit, en France, pour l'affichage, la recherche et le tri. Les pièces jointes ne sont pas copiées : elles sont téléchargées depuis Gmail à la demande.</li>
        <li>Par défaut, seuls les e-mails des 30 derniers jours sont importés lors de la connexion. La copie locale est <strong>effacée automatiquement</strong> au-delà de la durée de conservation choisie par l'administrateur (24 mois par défaut, réglable de 6 mois à 5 ans). Les e-mails restent dans Gmail.</li>
        <li>Les données sont soumises aux mêmes mesures de sécurité que vos autres données (TLS, sauvegardes chiffrées, journaux d'accès, cloisonnement strict entre associations).</li>
      </ul>

      <h3 style="font-size:18px;margin-top:18px;">12.6 Comment révoquer l'accès et supprimer les données ?</h3>
      <ol>
        <li>Depuis Assokit : <strong>Boîte mail › Réglages › Déconnecter et effacer la copie locale</strong> (ou <strong>Paramètres › Intégrations › Google Calendar › Déconnecter</strong> pour l'agenda). L'accès est immédiatement révoqué auprès de Google et, pour la boîte mail, toute la copie locale des e-mails est supprimée.</li>
        <li>Depuis votre compte Google : <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a> → « Assokit » → Supprimer l'accès.</li>
      </ol>
      <p>Pour toute demande de suppression ou question : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>.</p>

      <h2>13. Modifications</h2>
      <p>Cette politique peut évoluer. Toute modification substantielle vous sera notifiée par email avec un préavis de 30 jours.</p>

      <?php /* Date de version : à changer seulement quand le texte change */ ?>
      <p style="margin-top:30px;color:var(--c-text-3);font-size:14px;">Version du 12/10/2026</p>
    </article>
  </div>
</section>
<?php render_public_footer(); render_public_foot(); ?>
