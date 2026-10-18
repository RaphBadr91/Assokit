# Formulaire « Sécurité des données » — Google Play

Play Console › **Contenu de l'application** (menu de gauche, tout en bas : *Règles et programmes › Contenu de l'application*) › carte **Sécurité des données** › **Commencer** (ou **Gérer**).

Les réponses ci-dessous ont été établies **en lisant le code**, pas de mémoire. Ce qui a été vérifié :

| Source | Ce qu'on y a trouvé |
|---|---|
| `mobile/package.json` + `package-lock.json` | Aucun SDK d'analyse, de publicité ou de rapport de plantage (pas de Firebase Analytics, Crashlytics, Sentry, Amplitude, Segment…). Modules natifs : WebView, notifications, appareil photo / galerie, biométrie, stockage sécurisé, fichiers, partage, mises à jour OTA. |
| Manifestes Android des modules (versions du lockfile) | Permissions : `CAMERA`, `USE_BIOMETRIC`, `USE_FINGERPRINT`, `POST_NOTIFICATIONS`, `RECEIVE_BOOT_COMPLETED`, `INTERNET`, `ACCESS_NETWORK_STATE`, et `READ/WRITE_EXTERNAL_STORAGE` limitées à Android 12 et moins (`maxSdkVersion 32`). **Aucune** permission de localisation, de contacts, de SMS, de micro, ni `READ_MEDIA_IMAGES`. L'identifiant publicitaire `AD_ID` est explicitement bloqué (`app.json › android.blockedPermissions`). |
| `mobile/App.js` | Données envoyées au serveur `https://assokit.fr` uniquement (API `api/app-*.php` et WebView). Jeton de notifications envoyé à `api/app-register-push.php`. Identifiants de connexion gardés dans `expo-secure-store` sur le téléphone pour la connexion biométrique, effacés à la déconnexion. Liens externes ouverts dans le navigateur du téléphone, pas dans l'application. |
| `api/app-register-push.php` | Stocke : jeton Expo, plateforme (android / ios), utilisateur, association. Rien d'autre sur l'appareil. |
| `api/app-*.php`, `activity-tracker.php` | Le serveur journalise les pages et appels d'API des comptes connectés (chemin, IP, navigateur, date) : sécurité et statistiques d'usage. |
| `action-scan-facture.php` | La photo d'une facture est envoyée à Anthropic (sous-traitant) pour en lire les montants. Depuis le 18/10/2026, dans l'application, aucune fonction IA n'envoie quoi que ce soit sans l'accord de l'utilisateur, demandé une fois dans une fenêtre native qui nomme Anthropic (réglage dans Paramètres › Fonctions IA ; contrôle côté serveur dans `ai-consent-helper.php`). |
| `mon-asso-factures-parametres.php`, `admin-paiements.php` | L'**IBAN et le BIC de la structure** se saisissent dans ces pages, ouvertes dans l'application (WebView, page en 200 avec l'agent « AssokitApp »), et s'impriment sur ses factures, devis et appels à cotisation (tables `org_invoice_settings` et `org_payment_settings`). |
| `includes-public.php` | Google Analytics n'est chargé que sur le site public, avec consentement, **et plus jamais dans l'application** (agent « AssokitApp » exclu — modification faite le 13/10/2026, à mettre en ligne avant la soumission). |
| `confidentialite.php` | Politique alignée sur ces réponses (paragraphe « Application mobile » et sous-traitant Expo ajoutés le 13/10/2026). |

---

## Étape 1 — Présentation

Lire, puis **Suivant**.

## Étape 2 — Collecte et sécurité des données

| Question | Réponse |
|---|---|
| Votre application collecte-t-elle ou partage-t-elle certains des types de données utilisateur requis ? | **Oui** |
| Toutes les données utilisateur collectées par votre application sont-elles chiffrées en transit ? | **Oui** — tout passe en HTTPS : le serveur force HTTPS (`.htaccess`), la WebView n'accepte que `https://*`, Expo et Firebase sont en HTTPS. |
| Quelles méthodes de création de compte votre application accepte-t-elle ? | **Nom d'utilisateur et mot de passe** (adresse e-mail + mot de passe ; le bouton « Créer ma démo » ouvre l'inscription du site dans l'application). La double authentification existe mais reste facultative. |
| Lien de suppression du compte | `https://assokit.fr/suppression-compte` |
| Permettez-vous aux utilisateurs de demander la suppression de certaines de leurs données sans supprimer leur compte ? | **Non** (les contenus supprimés dans l'association sont conservés en suppression douce : on ne s'engage pas sur une suppression partielle). |
| Validation de sécurité indépendante (badge MASA) | **Non** (facultatif) |

**Suivant**.

## Étape 3 — Types de données

Cocher **exactement** ces cases (en anglais entre parenthèses, si la Console est en anglais) :

- **Informations personnelles** (*Personal info*) : ☑ Nom · ☑ Adresse e-mail · ☑ ID utilisateur · ☑ Adresse · ☑ Numéro de téléphone
- **Informations financières** (*Financial info*) : ☑ Informations de paiement de l'utilisateur (*User payment info*) · ☑ Historique des achats · ☑ Autres informations financières
- **Messages** : ☑ E-mails · ☑ Autres messages dans l'application
- **Photos et vidéos** : ☑ Photos
- **Fichiers et documents** : ☑ Fichiers et documents
- **Agenda** : ☑ Événements d'agenda
- **Contacts** : ☑ Contacts
- **Activité dans les applications** (*App activity*) : ☑ Interactions avec l'application · ☑ Historique des recherches dans l'application · ☑ Autres contenus générés par l'utilisateur
- **Appareil ou autres identifiants** (*Device or other IDs*) : ☑ Appareil ou autres identifiants

Laisser **décoché** tout le reste : Position (approximative, exacte) · Origine ethnique · Convictions politiques ou religieuses · Orientation sexuelle · Autres informations · Cote de crédit · Santé et remise en forme · SMS ou MMS · Vidéos · Fichiers audio · Applications installées · Autres actions · Historique de navigation sur le Web · Journaux de plantage · Diagnostics · Autres données sur les performances.

**Suivant**.

## Étape 4 — Utilisation et traitement des données

Pour chaque type coché, la Console pose quatre questions. **Pour tous les types** :

- **Collectées** : ☑ oui. **Partagées** : ☐ non (voir « Pourquoi “non partagé” » plus bas).
- **Ces données sont-elles traitées de manière éphémère ?** **Non** (elles sont enregistrées sur le serveur).

Puis, type par type :

| Type de données | Collecte obligatoire ou facultative ? | Finalités à cocher | Ce que c'est concrètement |
|---|---|---|---|
| **Nom** | Obligatoire | Fonctionnement de l'application · Gestion du compte | Prénom et nom du compte ; noms des adhérents, clients, contacts saisis |
| **Adresse e-mail** | Obligatoire | Fonctionnement de l'application · Gestion du compte · Communications du développeur · **Publicité ou marketing** | Identifiant de connexion ; e-mails de service (vérification d'adresse, mot de passe, factures) ; e-mails de fin de période d'essai qui présentent les formules (`cron-trial-check.php`, `cron-email-templates.php` : communication commerciale, d'où « Publicité ou marketing », comme chez Apple) ; adresses des adhérents et clients |
| **ID utilisateur** | Obligatoire | Fonctionnement de l'application · Gestion du compte · Prévention des fraudes, sécurité et conformité · Analyses | Identifiant interne du compte, utilisé aussi dans le journal d'activité, qui sert à la sécurité et aux statistiques d'usage (12 mois) |
| **Adresse** | Facultative | Fonctionnement de l'application | Ville du profil, adresses de facturation de la structure et des clients |
| **Numéro de téléphone** | Facultative | Fonctionnement de l'application | Téléphone du profil, des adhérents et des clients |
| **Informations de paiement de l'utilisateur** | Facultative | Fonctionnement de l'application | IBAN et BIC de la structure (réglages des factures et des cotisations), imprimés sur ses factures, devis et appels à cotisation. Aucune carte bancaire. |
| **Historique des achats** | Facultative | Fonctionnement de l'application | Dépenses et notes de frais saisies, paiements de cotisation enregistrés |
| **Autres informations financières** | Facultative | Fonctionnement de l'application | Factures, devis, budgets, subventions, écritures comptables |
| **E-mails** | Facultative | Fonctionnement de l'application | Boîte mail Gmail de l'association, si un administrateur la relie ; e-mailings rédigés |
| **Autres messages dans l'application** | Facultative | Fonctionnement de l'application | Canaux de discussion et messages entre membres de l'association, tickets de support |
| **Photos** | Facultative | Fonctionnement de l'application | Photo d'une facture à scanner, logo de l'organisation |
| **Fichiers et documents** | Facultative | Fonctionnement de l'application | Pièces jointes, justificatifs, documents de projet |
| **Événements d'agenda** | Facultative | Fonctionnement de l'application | Agenda, événements, assemblées ; synchronisation Google Agenda si un administrateur la relie |
| **Contacts** | Facultative | Fonctionnement de l'application | Carnet d'adresses de l'association (adhérents, clients) **saisi par l'utilisateur**. L'application ne lit **jamais** les contacts du téléphone (pas de permission `READ_CONTACTS`). Coché par prudence, parce que la politique de confidentialité mentionne les « contacts » téléversés. |
| **Interactions avec l'application** | Obligatoire | Analyses · Prévention des fraudes, sécurité et conformité | Journal serveur des pages et écrans consultés (chemin, date, IP, navigateur), conservé 12 mois maximum |
| **Historique des recherches dans l'application** | Facultative | Fonctionnement de l'application · Prévention des fraudes, sécurité et conformité | Les recherches faites dans les pages du site affichées par l'application passent dans l'adresse de la page, donc dans le même journal |
| **Autres contenus générés par l'utilisateur** | Facultative | Fonctionnement de l'application | Projets, étapes, comptes rendus, notes, contenus rédigés avec l'IA |
| **Appareil ou autres identifiants** | Obligatoire | Fonctionnement de l'application · Analyses | Jeton de notifications Expo / Firebase Cloud Messaging ; identifiant d'installation aléatoire envoyé par le module de mises à jour OTA (`expo-updates`), qu'Expo compte pour ses statistiques de mises à jour |

Rappel des définitions Google : « facultative » = l'utilisateur peut utiliser l'application sans fournir la donnée ; « obligatoire » = il ne peut pas s'y soustraire.

**Ne cocher nulle part ailleurs** : Publicité ou marketing (seulement pour l'adresse e-mail, ligne plus haut) · **nulle part** : Personnalisation.

Ces finalités sont alignées le 18/10/2026 sur la rubrique « Confidentialité de l'app » d'Apple
(`../app-store/confidentialite-app.md`), qui en déclare le plus : e-mail aussi pour le marketing
du développeur ; identifiant utilisateur, identifiants de l'appareil et interactions aussi pour
les analyses ; historique des achats déclaré des deux côtés. La politique de confidentialité
(`confidentialite.php` § 2 et § 3) dit la même chose depuis le 18/10/2026 : « statistiques
d'utilisation tirées du journal d'activité (12 mois) » et e-mails de fin d'essai fondés sur
l'intérêt légitime.

**Suivant**, puis **Enregistrer**.

## Étape 5 — Aperçu

Vérifier que la fiche affiche :

- « Aucune donnée partagée avec des tiers »
- « Données chiffrées pendant le transfert »
- « Vous pouvez demander la suppression des données »

Puis **Enregistrer**. Le formulaire est ensuite publié avec la prochaine version envoyée en examen.

---

## Pourquoi « non partagé » partout

Pour Google, **ne sont pas** du partage :

1. les transferts vers des **prestataires de services** qui traitent les données pour le compte du développeur ;
2. les transferts **déclenchés par l'utilisateur** quand il s'attend à ce que la donnée parte (envoyer une facture par e-mail à un client, répondre depuis la boîte mail, lancer un e-mailing, relier son Gmail).

Les tiers qui reçoivent des données dans Assokit entrent tous dans l'un de ces deux cas :

| Tiers | Rôle | Cas |
|---|---|---|
| O2Switch (France) | Hébergement | Prestataire |
| Resend (UE) | Envoi des e-mails | Prestataire ; e-mails déclenchés par l'utilisateur |
| Anthropic (USA) | IA : scan des factures, rédaction, tri et brouillons de la boîte mail | Prestataire |
| Expo (USA) + Google Firebase Cloud Messaging | Acheminement des notifications, mises à jour OTA | Prestataires |
| Google (Gmail, Google Agenda) | Seulement si un administrateur relie ces comptes | Déclenché par l'utilisateur |
| Stripe | Paiements : **sur le site uniquement**, jamais dans l'application ; l'IBAN de la structure ne lui est pas transmis | Hors application |

Aucune donnée n'est vendue, ni utilisée pour de la publicité.

## Ce qui n'est pas collecté, et pourquoi

- **Position** : aucune permission de localisation. L'adresse IP est journalisée pour la sécurité, sans en déduire de position.
- **Biométrie** : l'empreinte ou le visage sont vérifiés par Android ; l'application ne reçoit qu'un « oui / non ». Les identifiants mémorisés pour la connexion biométrique restent dans le stockage sécurisé du téléphone (non collectés au sens de Google : ils ne sont envoyés que pour se connecter).
- **Carte bancaire** : aucun achat dans l'application, aucune carte bancaire saisie dans l'application. En revanche, l'**IBAN et le BIC de la structure** sont collectés (voir « Informations de paiement de l'utilisateur » plus haut) : ils servent à ses factures, pas à payer quoi que ce soit dans l'application.
- **Journaux de plantage, diagnostics** : aucun SDK de rapport de plantage. Les statistiques « Android vitals » de la Play Console sont collectées par Google Play lui-même, pas par l'application.
- **Vidéos, audio** : le sélecteur d'images est limité aux photos ; les pièces jointes acceptées sont des documents et des images (`action-fichier.php`) ; micro désactivé (`microphonePermission: false`).
- **SMS** : l'application ne lit ni n'envoie de SMS depuis le téléphone.
- **Mesure d'audience** : aucun outil dans l'application ; Google Analytics est exclu de la WebView (voir plus haut).

## Contrôle de cohérence avant d'envoyer

Google compare ce formulaire avec la politique de confidentialité et avec ce que l'application fait réellement. Points à garder alignés :

- [ ] `https://assokit.fr/confidentialite` est en ligne **avec** le paragraphe « Application mobile » et la ligne « Expo » (sections 2, 5 et 11).
- [ ] `https://assokit.fr/suppression-compte` répond 200 sans connexion.
- [ ] Les durées de conservation sont les mêmes dans `confidentialite.php` § 4, `cgu.php` § 8 et `suppression-compte.php` (trois cas : compte supprimé par la personne → anonymisation immédiate ; compte retiré par un administrateur → corbeille 30 jours puis anonymisation ; fermeture de l'espace de l'association → 30 jours pour exporter, puis suppression, sauf pièces comptables 10 ans ; journaux 12 mois). Alignées le 13/10/2026.
- [ ] `includes-public.php` modifié est en ligne (Google Analytics exclu de l'application).
- [ ] Si un SDK est ajouté un jour (Sentry, Firebase Analytics…), **mettre ce formulaire à jour avant** de publier la version qui l'embarque.
