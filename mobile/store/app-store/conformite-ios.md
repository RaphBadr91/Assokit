# Conformité iOS — Assokit (vérifiée dans le code le 18/10/2026, mise à jour le 23/10/2026)

Contrôle fait **avant le premier build iOS payant**, contre le code réel : `mobile/app.json`,
`mobile/App.js`, les modules natifs aux versions exactes du `package-lock.json`, et les pages
du site affichées dans l'application (agent « AssokitApp »). Les problèmes trouvés ont été
corrigés au plus court ; ce document dit lesquels, pourquoi, et comment ils arrivent en
ligne.

## Résumé

| Point | État | Ce qui a été fait |
|---|---|---|
| Manifeste de confidentialité (raisons d'API) | ✅ corrigé | Bloc `ios.privacyManifests` ajouté dans `app.json` (A1) |
| Textes des autorisations (Info.plist) | ✅ complété | Ajout de l'enregistrement dans Photos ; app déclarée en français, `CFBundleLocalizations = fr` (A2) |
| Notifications push iOS | ✅ prêt | Répondre **Yes** à EAS au premier build ; test à faire sur TestFlight (A3) |
| Versions des dépendances | ✅ corrigé | React Native 0.86.0 → **0.86.3** (régression Hermes signalée par `expo-doctor`) (A4) |
| Version de l'app et mises à jour OTA | ✅ corrigé | `expo.version` 1.0.0 → **1.0.1** : les binaires 1.0.0 déjà distribués ne reçoivent plus d'OTA incompatible (A4) |
| 3.1.1 / 3.1.3 : rien à acheter dans l'app | ✅ corrigé | **iPhone : création de compte sur demande**, examinée par l'équipe sous 48 h, rien d'actif avant la validation, aucun essai ni prix ni formule à l'écran (19/10/2026) + 13 surfaces corrigées (B1) |
| 4.2 : fonctionnalités minimales | ✅ | Plus de 25 écrans natifs (B2) |
| 5.1.1(v) : suppression du compte dans l'app | ✅ corrigé | Compte seul de son association supprimable dans l'app ; plus de suppression simulée pour les anciens comptes d'examen ; demande d'espace en attente ou refusée retirée dans l'app, sans session (« Retirer ma demande », 19/10/2026) (B3) |
| 4.8 : Se connecter avec Apple | ✅ non requis | Aucune connexion par un service tiers (B4) |
| 2.1 : compte de démonstration | ✅ | DEMO F, deux comptes (B5) |
| 5.1.2 : suivi, ATT | ✅ non requis | Aucun suivi, aucun IDFA (B6) |
| 5.1.2(i) : envoi de données à une IA tierce | ✅ corrigé | Accord explicite, une fois, avant tout envoi à Anthropic ; contrôlé aussi par le serveur (B10) |
| 1.2 : contenus des utilisateurs | ✅ corrigé | Appui long sur un message : signaler, masquer son auteur, supprimer (administrateur) ; CGU « tolérance zéro », signalements examinés sous 24 h (B7) |
| 2.1 : fonctions annoncées qui marchent | ✅ corrigé | Export FEC, Excel, ZIP enregistrables sur iPhone (`onFileDownload`) (B11) |
| 2.3.3 : captures | ✅ | Écrans réels (dossier `captures-6.9/`) (B8) |

---

## A. Configuration du build iOS

### A1. Manifeste de confidentialité (`PrivacyInfo.xcprivacy`)

**Le risque.** Depuis mai 2024, Apple refuse à l'envoi (e-mail « ITMS-91053: Missing API
declaration ») un binaire dont le code appelle une API « à raison obligatoire » sans la
déclarer dans le manifeste de l'app.

**Ce qui a été vérifié.** Les paquets natifs ont été téléchargés depuis npm aux versions du
lockfile (expo 57.0.20, react-native, expo-notifications 57.0.17, expo-file-system 57.0.6…),
puis leurs fichiers `PrivacyInfo.xcprivacy` lus et leur code iOS fouillé :

| Module | Manifeste fourni | API à raison obligatoire |
|---|---|---|
| react-native (React-Core, cxxreact, timing, Folly, boost, glog) | oui | UserDefaults CA92.1, FileTimestamp C617.1, SystemBootTime 35F9.1 |
| expo-file-system | oui | FileTimestamp 0A2A.1 / 3B52.1, DiskSpace E174.1 / 85F4.1 |
| expo-constants, expo-notifications | oui | UserDefaults CA92.1 |
| expo-application | oui | FileTimestamp C617.1 |
| expo-updates, expo-eas-client | **non** | UserDefaults (lecture d'un réglage, identifiant d'installation) |
| expo-sharing, expo-modules-core | non | UserDefaults (groupe d'apps), date de fichier |

Tous déclarent `NSPrivacyTracking = false` et aucune donnée collectée.

À la compilation, `pod install` (modèle Expo SDK 57, `privacy_file_aggregation_enabled`
actif par défaut) **fusionne** les manifestes des modules dans celui de l'app. Deux modules
n'en fournissent pas, et Expo SDK 57 compile par défaut les modules en binaires
précompilés : on ne s'en remet donc pas à cette seule fusion.

**Correction.** `app.json › expo.ios.privacyManifests` déclare les quatre catégories, avec la
raison standard de chacune :

| Catégorie | Raison | Sens |
|---|---|---|
| `NSPrivacyAccessedAPICategoryUserDefaults` | CA92.1 | Réglages lus et écrits par l'app elle-même |
| `NSPrivacyAccessedAPICategoryFileTimestamp` | C617.1 | Dates des fichiers du conteneur de l'app |
| `NSPrivacyAccessedAPICategorySystemBootTime` | 35F9.1 | Mesure du temps écoulé (React Native) |
| `NSPrivacyAccessedAPICategoryDiskSpace` | E174.1 | Vérifier la place avant d'écrire un fichier |

plus `NSPrivacyTracking: false`, aucun domaine de suivi, et `NSPrivacyCollectedDataTypes`
**identique** aux réponses « Confidentialité de l'app »
([`confidentialite-app.md`](confidentialite-app.md)) : 16 types (historique des achats ajouté
le 18/10/2026, comme sur Google Play), tous liés à
l'utilisateur, aucun pour le suivi. Expo écrit ce bloc dans
`ios/Assokit/PrivacyInfo.xcprivacy` ; la fusion de `pod install` y ajoute ensuite les
raisons des modules (elle complète, n'efface rien).

**Vérifié** : `npx expo prebuild --platform ios` sur une copie de `mobile/` produit bien
`ios/Assokit/PrivacyInfo.xcprivacy` avec ces 4 catégories et ces 16 types, référencé dans
le projet Xcode (revérifié le 18/10/2026 après l'ajout de l'historique des achats).

### A2. Textes des autorisations et `Info.plist`

`Info.plist` généré par `npx expo prebuild` (copie de travail), clé par clé :

| Clé | Valeur | État |
|---|---|---|
| `NSCameraUsageDescription` | « Assokit utilise l'appareil photo pour scanner vos factures et documents. » | ✅ |
| `NSPhotoLibraryUsageDescription` | « Assokit accède à vos photos pour importer un logo ou une facture. » | ✅ |
| `NSFaceIDUsageDescription` | « Assokit utilise Face ID pour déverrouiller l'application en toute sécurité. » | ✅ (posé par `ios.infoPlist`, `expo-local-authentication` ; `expo-secure-store` le garde) |
| `NSPhotoLibraryAddUsageDescription` | « Assokit enregistre dans vos photos les images que vous choisissez d'enregistrer. » | **ajouté** |
| `NSMicrophoneUsageDescription` | absente | ✅ voulu : `microphonePermission: false` (le sélecteur est limité aux images) |
| `ITSAppUsesNonExemptEncryption` | `false` | ✅ HTTPS et trousseau d'iOS seulement : exempté |
| `CFBundleDevelopmentRegion` | `fr` | **ajouté** |
| `CFBundleLocalizations` | `["fr"]` | **ajouté** le 18/10/2026 |
| `CFBundleShortVersionString` | `1.0.1` | vient de `app.json › expo.version` (voir A4) |
| `UIUserInterfaceStyle` | `Light` | ✅ |
| `TARGETED_DEVICE_FAMILY` | `1` (iPhone seulement) | ✅ `supportsTablet: false` → pas de captures iPad |
| Version minimale | iOS 16.4 (modèle Expo SDK 57) | — |

Aucune clé de localisation, de contacts, de calendrier, de Bluetooth, de suivi
(`NSUserTrackingUsageDescription`) : l'app ne les utilise pas.

**Pourquoi `NSPhotoLibraryAddUsageDescription`.** `App.js` n'enregistre rien lui-même dans
Photos (pas de `MediaLibrary` ; le bilan PDF passe par la feuille de partage, en
`application/pdf`). Mais la WebView affiche des images (logos, pièces jointes, QR codes) :
un appui long propose « Enregistrer dans Photos », et iOS **arrête l'app** si cette clé
manque au moment où l'utilisateur touche ce bouton. Une app qui plante est refusée (règle
2.1). La phrase décrit exactement ce cas.

**Pourquoi `CFBundleDevelopmentRegion = fr`.** Sans cette clé, l'app est déclarée en
anglais : l'App Store affiche « Langue : anglais » sur la fiche, et iOS montre en anglais
les textes système affichés dans l'app (menu « Choose File / Take Photo » d'un champ fichier,
boutons « Cancel »). L'app est entièrement en français.

**Pourquoi `CFBundleLocalizations = ["fr"]`.** Le prebuild ne crée aucun dossier `.lproj`
(le projet Xcode généré ne connaît que les régions `en` et `Base`). L'App Store tire la liste
des langues de la fiche des localisations déclarées par le bundle : sans elles, rien ne
garantit qu'il affiche « Français ». Cette clé déclare le français sans autre effet. Vérifié
dans l'`Info.plist` d'un nouveau prebuild (18/10/2026). À contrôler sur le build TestFlight,
puis sur la fiche publiée (`publication-ios.md`, étape 4).

### A3. Notifications push sur iOS

- Le module `expo-notifications` ajoute le droit `aps-environment` (valeur `development` dans
  le fichier d'autorisations généré, vérifié). Au moment de l'export pour l'App Store, Xcode
  signe avec le profil de distribution, qui porte la valeur `production` : rien à changer.
- Les notifications iOS passent par le service d'Apple (APNs). **Au premier build iOS, EAS
  demande « Would you like to set up Push Notifications for your project? » : répondre
  Yes**, puis « Generate a new Apple Push Notifications service key? » : **Yes**. EAS crée
  la clé APNs dans le compte Apple et la garde pour le service Expo. Si l'étape a été sautée :
  `npx eas-cli@latest credentials --platform ios` › *Push Notifications* › configurer.
- Côté serveur, rien à faire : `api/app-register-push.php` enregistre le jeton Expo avec la
  plateforme `ios`, et `cron-push-notifications.php` envoie par l'API Expo, la même pour les
  deux plateformes.
- L'app demande l'autorisation au premier lancement (avant la connexion). Apple l'accepte
  (règle 4.5.4 : notifications facultatives, jamais publicitaires) ; les demander juste après
  la connexion serait plus agréable, mais ce n'est pas nécessaire.
- **À tester sur TestFlight** (les builds TestFlight utilisent l'APNs de production) :
  voir `publication-ios.md`, étape 4.

### A4. Versions des dépendances

`npx expo-doctor` (copie de travail, modules installés depuis le lockfile) signalait :

> Detected Hermes V1 250829098.0.14 from React Native. Hermes V1 250829098.0.15 and earlier
> are affected by this regression … Upgrade to React Native 0.86.2 or later.

`package.json` figeait `react-native` à **0.86.0** alors qu'Expo SDK 57 attend **0.86.3**.
Corrigé : `react-native: 0.86.3` dans `mobile/package.json`, `package-lock.json` mis à jour
par npm (20 lignes changées : react-native, les paquets `@react-native/*` en 0.86.3,
`hermes-compiler` 250829098.0.17 ; doublons supprimés). Vérifié ensuite :

- `npm ci` depuis le nouveau lockfile, sur une copie vierge : OK (507 paquets) ;
- `expo-doctor` : la vérification Hermes passe ; les deux seuls échecs restants demandent
  l'accès à `exp.host` et à React Native Directory, bloqué dans cet environnement ;
- `npx expo export --platform ios` : le JavaScript se compile en bytecode Hermes sans erreur.

**Conséquence** : c'est un changement natif. Les builds iOS **et** Android de production
doivent être faits depuis ce même état du dépôt.

**Version portée à 1.0.1 (18/10/2026).** `runtimeVersion` suit la politique `appVersion` :
tous les binaires 1.0.0 partagent la version d'exécution `1.0.0`. Or des binaires 1.0.0 ont
déjà été distribués : l'iPhone sur TestFlight (le commentaire d'`App.js` sur
`react-native-safe-area-context` en parle, et les workflows `ci:` publient des OTA vers
lui), peut-être aussi un test interne Android. Un ancien binaire 1.0.0 (React Native
antérieur) **accepterait** une mise à jour compilée ensuite avec le JavaScript de 0.86.3,
puisque la version d'exécution est la même ; il recevrait une mise à jour incompatible
(JavaScript et code natif ne correspondent plus), ce qui peut faire planter l'app ou la
ramener au code embarqué. `app.json › expo.version` passe donc à **1.0.1** : les builds de
production 1.0.1 ont leur propre version d'exécution, et les anciens binaires 1.0.0 gardent
la dernière OTA qu'ils ont reçue sans en recevoir de nouvelle. Les remplacer par les builds
1.0.1. (L'autre solution, `runtimeVersion: { policy: "fingerprint" }`, n'a pas été retenue :
le guide de publication demande déjà de changer de version à chaque changement natif.)

Autres constats, sans action nécessaire :

- `expo prebuild` avertit que `expo.backgroundColor` demande le module `expo-system-ui`
  sur iOS : sans lui, la couleur de fond de la vue racine n'est pas appliquée. Effet
  purement visuel (l'écran de lancement a sa propre couleur) ; ne rien ajouter avant
  d'avoir vu le build TestFlight.
- `eas.json` : `appVersionSource: remote` + `autoIncrement: true` → EAS gère le numéro de
  build iOS ; `ios.image: latest` ; canal `production` pour les mises à jour OTA. Rien à
  changer.

---

## B. Règles de l'App Store, vérifiées dans le code

### B1. 3.1.1 et 3.1.3 : rien à acheter, aucun appel à acheter

**Position d'Assokit.** Assokit est vendu aux structures (associations, TPE) sur le site.
L'app est gratuite, ne vend rien et ne montre ni prix, ni formule, ni lien vers un paiement :
c'est l'application compagnon gratuite d'un service web payant (règle **3.1.3(f)**),
utilisée par les membres des structures clientes (**3.1.3(c)**). Cette position ne tient
que si **aucune** surface de l'app n'invite à acheter, même indirectement.

**Sur iPhone, la création d'un compte est une demande examinée par l'équipe (décision du
19/10/2026, qui remplace « connexion seule » du 18/10/2026).** Un essai de 14 jours ouvert
dans l'app, avec un compte à rebours, puis prolongeable seulement en payant hors de l'app,
est le motif de refus 3.1.1 le plus fréquent pour une app B2B. Le test sur le banc le
montrait : un compte créé dans l'app affichait « Essai PRO jusqu'au … · J-14 » sur l'accueil,
dans « Plus » et dans les Paramètres. Désormais, quand `Platform.OS === 'ios'`
(`IOS_SIGNUP_REQUEST`, `App.js`) et pour l'agent iPhone de l'app côté serveur
(`ak_is_ios_app()`, `app-context.php`) :

- l'écran d'accueil propose « Créer l'espace de ma structure » ; l'écran de connexion dit
  « Pas encore d'espace ? Demander la création de mon espace » ; `/connexion`, dans l'app
  iPhone, a le même lien ;
- `/signup` s'ouvre en **mode demande** (`signup.php`, `signup-review-helpers.php`) : même
  formulaire et mêmes contrôles, mais la page dit « Validation par notre équipe sous 48 h »
  et « Un e-mail vous confirme l'activation de votre espace » ; ni prix, ni « essai », ni
  « gratuit », ni abonnement ; bouton « Envoyer ma demande ». L'association est créée « en
  attente » (`validation_status = pending_founder`, statut `suspended` avec le motif
  `AK_SIGNUP_REVIEW_REASON`), **sans** essai, plan ni abonnement, et le demandeur n'est pas
  connecté : la page « Demande envoyée » s'affiche ;
- tant que la demande attend, la connexion (site ou app) répond « Votre espace est en cours
  de validation par notre équipe (48 h maximum). Vous recevrez un e-mail dès son
  activation. » et aucune page ni API n'est accessible (`ak_org_access` → `pending`) ;
- le Fondateur reçoit un e-mail (boutons « Valider » / « Refuser », liens signés valables
  7 jours, et lien vers le cockpit) et une notification ; il peut aussi décider dans le
  cockpit ou dans l'appli (mêmes effets). Un rappel part au bout de 24 h ;
- l'essai PRO de 14 jours démarre **à la validation**, et l'administrateur reçoit « Votre
  espace Assokit est activé » (e-mail, hors de l'app). Un refus envoie un e-mail poli ;
- `orgTrialInfo()` renvoie `null` : ni pastille d'essai sur l'accueil, ni ligne d'essai dans
  « Plus » ; la carte « FORMULE » des Paramètres n'est plus affichée ;
- `api/app-dashboard.php` renvoie aussi, pour l'agent iPhone, un essai vide et aucune
  formule (même avec une ancienne version du JavaScript).

Android garde l'inscription dans l'app avec essai immédiat, comme le site et comme décrit
dans le dossier Google Play.

**Contrôles.**

1. `App.js` : recherche de `€`, `tarif`, `abonnement`, `paiement`, `stripe`, `essai`,
   `formule`, `PRO`, `upgrade`, `souscri`. Les montants affichés sont les chiffres de
   gestion de l'association (factures à ses clients, cotisations de ses adhérents). Les
   pages d'achat sont bloquées par `onShouldStartLoadWithRequest` et masquées par
   `APP_ONLY_CSS`.
2. Banc PHP local (base copiée de la démo DEMO F) : **400 pages** parcourues avec l'agent
   « AssokitApp », connecté en administrateur de DEMO F, en relevant chaque ligne qui
   contient un de ces mots. `/abonnement`, `/tarifs`, `/mon-asso-plan`,
   `/mon-asso-paiement` renvoient vers le tableau de bord.
3. Pages publiques ouvertes dans l'app (`/signup`, `/connexion`, pages légales),
   comparées à leur version navigateur.
4. Textes renvoyés par le serveur à l'app (messages d'erreur, suggestions).

**Problèmes trouvés et corrigés** (rien ne change sur le site vu dans un navigateur, sauf
la page de suppression de compte, B3) :

| # | Où | Ce que voyait l'app | Correction |
|---|---|---|---|
| 1 | `signup.php` (bouton « Créer ma démo ») | « Essai PRO gratuit 14 jours · sans carte bancaire », « Démarrez votre essai PRO gratuit », « Sans carte bancaire, sans engagement », « Activer mon essai PRO de 14 jours » | Dans l'app : « Aucun achat dans l'application », « Créez l'espace de votre structure », « Toutes les fonctionnalités sont ouvertes dès l'inscription, pendant 14 jours. », bouton « Créer mon espace ». La durée reste dite (honnêteté ; détail dans les CGU § 4, à accepter pour s'inscrire). Ces textes ne concernent plus que l'app Android : **depuis le 19/10/2026, l'app iPhone ouvre cette page en mode demande** (voir plus haut), sans essai ni durée. |
| 2 | `connexion.php` | « Créer mon compte gratuit » ; « Votre essai gratuit est terminé » | « Créer mon compte » ; « Votre période d'essai est terminée » |
| 3 | `trial-limits-helpers.php`, `action-assemblee.php` | 9 messages de plafond d'essai finissant par « Passez à une formule Assokit pour lever la limite » (envois d'e-mails, imports d'adhérents, convocations), affichés tels quels par l'app | Nouvelle fonction `ak_trial_msg()` : dans l'app, la phrase d'achat et le mot « gratuit » disparaissent (« … Réessayez demain. »). Les 9 messages réels testés. |
| 4 | `mon-asso-factures.php` (factures de l'abonnement Assokit) | Bouton « Régulariser » vers la page de paiement Stripe d'une facture impayée. Aucun lien n'y mène dans l'app, mais l'adresse restait ouverte. | `ak_block_billing_page()` en tête, comme `/abonnement` : renvoi vers le tableau de bord dans l'app. |
| 5 | `today-ai-helper.php` (suggestions « Aujourd'hui » du tableau de bord web) | Suggestions « Ton essai PRO se termine… Voir les formules », et l'IA invitée à « rappeler de choisir une formule » | Dans l'app, toute suggestion qui mène à une page d'achat ou parle de formule, d'abonnement ou d'essai est retirée à l'affichage. |
| 6 | `App.js`, écran d'accueil et écran de connexion | Liens « Confidentialité » et « Conditions générales » ouverts dans **Safari**, sur des pages dont l'en-tête montre « Tarifs » et « Essai gratuit » | Ouverts **dans l'app** (version « app » des pages, sans ces liens), comme depuis les Paramètres. |
| 7 | `App.js`, `api/app-dashboard.php` (accueil, « Plus », Paramètres) | « Essai PRO jusqu'au 01/11/2026 · J-14 », « FORMULE · Sur-mesure » | Rien sur iPhone (voir plus haut). |
| 8 | `adherents-import.php`, `communication-diffuser-nouveau.php` | « ⏳ Pendant l'essai gratuit : 200 … » (avis écrits en dur, hors `ak_trial_msg()`) | Passés par `ak_trial_msg()` : « Pendant l'essai : … » dans l'app, texte d'origine sur le site. |
| 9 | `cgu.php` (ouvert dans l'app avant connexion) | Lien « Tarifs » masqué par l'app, d'où « décrites sur la page  : » ; § 4 décrivant l'abonnement mensuel, la carte bancaire via Stripe ; § 8 « Mon abonnement » | Dans l'app (`ak_billing_hidden()`) : § 4 « Conditions commerciales » (convenues avec la structure lors de sa souscription, décrites sur notre site), sans mode de paiement ; § 8 « depuis son espace sur le site ». Site inchangé. |
| 10 | `404.php` (toute adresse inconnue ouverte dans l'app) | Liens « Tarifs » et **« Essai gratuit 14 jours »** | Dans l'app : « Retour à mon espace » et « Nous contacter » seulement. |
| 11 | `contact.php` (ouvert dans l'app depuis `/signup`, `/retirer-demande` et le pied des pages légales) — 23/10/2026 | Objets « 💰 Devis sur-mesure » et « 💼 Facturation » (« Question facturation / abonnement »), « devis sur-mesure » dans le chapeau, encadré « Offre Sur-mesure » avec `?plan=sur-mesure` | Dans l'app (`pub_in_app()`) : ces deux objets retirés (un envoi qui les cite est refusé), chapeau « Démo, question, partenariat… », `?plan=` ignoré. Site inchangé. |
| 12 | `parametres.php`, onglet Organisation — 23/10/2026 | Carte « FORMULE — Essai PRO — jusqu'au … (J-14) » (seul le bouton « Choisir une formule » était masqué). Aucun lien de l'app n'y mène, mais l'adresse restait ouverte | Carte absente sous l'agent iPhone (`ak_is_ios_app()`), comme la carte native (`IOS_SIGNUP_REQUEST`). Android et site inchangés. |
| 13 | `api/app-delete-account.php` — 23/10/2026 | Échec de l'arrêt Stripe à la suppression du seul compte : alerte native « L'abonnement de l'association n'a pas pu être arrêté… » | « La suppression n'a pas pu aboutir : rien n'a été supprimé. Réessayez dans quelques minutes, ou écrivez à contact@assokit.fr. » (même règle que `/retirer-demande`). |

**Restes acceptés, et pourquoi.**

- Une structure inscrite **sur le site** et encore en essai peut se connecter sur iPhone :
  l'app n'affiche alors ni essai ni formule. À la fin de l'essai, la page de connexion dit
  « Votre période d'essai est terminée » et, pour un membre, « Vous pouvez en parler à
  l'administrateur de votre association » : aucun lien ni prix (ce que permet la règle
  3.1.3(f) pour un service vendu hors de l'app).
- La politique de confidentialité (§ 3) mentionne les e-mails de fin d'essai et le § 5 cite
  Stripe : ce sont des informations légales, sans lien ni appel à acheter.
- **Textes légaux ouverts dans l'app** (exception assumée à la règle « aucun mot d'achat »,
  23/10/2026) : les CGU (§ 4 « Conditions commerciales », § 8 « Résiliation », § 9, libellé
  « CGU / CGV » du pied de page), la politique de confidentialité (§ 3, § 4, § 5) et
  `/suppression-compte` (§ 6) gardent les mots « abonnement », « prix », « formule »,
  « paiement », « essai ». Ce sont les conditions du contrat et les informations exigées par
  le RGPD : elles doivent être les mêmes que sur le site, sans montant, sans lien vers un
  achat, sans bouton ni invitation à acheter (la règle 3.1.3 vise les appels à acheter, pas
  le texte du contrat). Les surfaces d'achat de ces pages (lien « Tarifs », mode de
  paiement, rubrique « Mon abonnement ») sont déjà retirées dans l'app (ligne 9).
- `NativeSubInvoices` (`App.js`) affichait les factures de l'abonnement avec un lien vers la
  page Stripe ; **aucun écran ne l'ouvre** (code mort, vérifié). Ne pas le rebrancher.
- E-mails de fin d'essai avec « Choisir une formule » : envoyés hors de l'app, ce que la
  règle 3.1.3 permet. Déclarés en « marketing du développeur » dans la confidentialité.

### B1 bis. Paiements reçus par les associations (Stripe Connect) — 30/10/2026

**Ce qui a changé.** Depuis la version `UI_REV = '2026-10-30-paiements-en-ligne'` (`App.js`),
une association peut faire payer **ses propres** factures, cotisations et dons en ligne, sur
**son** compte Stripe (Stripe Connect, compte Standard). L'app ne paie rien et n'ouvre
aucune page de paiement ; elle **partage** un lien et **affiche** un état. La phrase de B1
« ni lien vers un paiement » se lit désormais ainsi : aucun lien vers un **achat dans
l'app** ni vers un achat du service Assokit ; un lien de paiement de l'association peut être
**partagé** à son payeur, jamais ouvert dans l'app.

**Pourquoi les règles d'achat intégré ne s'appliquent pas.**

- **3.1.1** vise l'achat de contenus ou de fonctions **numériques utilisés dans l'app**. Ici,
  le payeur est le client, l'adhérent ou le donateur de l'association, pas l'utilisateur de
  l'app ; il ne débloque rien dans l'app.
- **3.1.3(e)** (biens et services consommés hors de l'app) : une facture d'association
  (prestation, formation, location de salle…) et une cotisation d'adhésion sont des services
  rendus hors de l'app. Le paiement se fait sur la page Stripe de l'association, dans le
  navigateur.
- **3.2.2(iv)** (dons) : un don à une association n'est **jamais** collecté dans l'app ; la
  page de dons s'ouvre dans Safari (`Linking.openURL`). L'app ne montre qu'un bouton de
  **partage** du lien (feuille de partage du système).
- Le serveur refuse toute page de paiement pour l'agent « AssokitApp » :
  `ak_connect_start_checkout()` (`stripe-connect-helpers.php`), `payer-facture.php` (renvoi
  vers la facture avec un message) et `cotiser.php` (page sans formulaire : « Le paiement en
  ligne s'ouvre dans votre navigateur »). La page « Paiements en ligne »
  (`encaissement-en-ligne.php`) est en lecture seule dans l'app : relier le compte Stripe se
  fait sur le site.

**Surfaces de l'app concernées** (`App.js`, numéros de ligne du 30/10/2026) :

| Où | Ce que fait l'app |
|---|---|
| l. 158 `PAY_PAGE_RE` | `/cotiser`, `/don`, `/payer-facture`, `/paiement-confirme` reconnues comme pages de paiement |
| l. 162 `sharePayLink()` | feuille de partage du système (`Share.share`) avec le lien ; aucun chargement |
| l. 2259–2278, fiche facture | badges « Prélèvement SEPA en cours de confirmation par la banque » et « Payée en ligne par le client » (états, sans montant à payer) ; bouton « Partager le lien de paiement » |
| l. 6925–6932, fiche campagne | bouton « Partager le lien de paiement » ou « Partager la page de dons » |
| l. 6613, routeur des notifications | ces pages ne s'ouvrent jamais dans la vue web (`null`) |
| l. 9546, `onShouldStartLoadWithRequest` | lien vers une de ces pages → Safari, jamais la vue web |

Les API de l'app renvoient `pay_url` et `online_status` (`api/app-invoice.php`), `kind` et
`pay_url` (`api/app-cotisation.php`) : un lien à partager et un état, rien d'autre.

**Contrôle sur le banc** (`audit/sc4/e2e.sh`, 168 contrôles, à rejouer avant chaque envoi) :
avec l'agent « AssokitApp », la facture publique n'a aucun formulaire `action="/payer-facture"`
(« Votre client paie depuis cette page, dans son navigateur ») ; un POST
`/payer-facture` de l'app est renvoyé à la facture et **aucune** session Stripe n'est créée ;
`/cotiser/…` n'a pas de champ `payer_email` ; `/encaissement-en-ligne` est en lecture seule et
un POST « connect » de l'app ne crée aucun compte ; le tableau de bord d'un membre n'affiche
pas « Renouveler ma cotisation » dans l'app.

**Note pour l'examinateur** : ajoutée au § 6 de [`age-et-revue.md`](age-et-revue.md)
(paragraphe « PAYMENTS TO ORGANISATIONS »).

### B2. 4.2 : fonctionnalités minimales (pas un simple site emballé)

Écrans **natifs** (React Native, pas des pages web), à citer si Apple invoque 4.2 :
connexion (Face ID / Touch ID), accueil à indicateurs, projets (liste, fiche avec étapes,
budget, équipe, bilan PDF partagé), membres et fiche membre, factures (liste, fiche,
création, analyse IA), devis, clients et fiche client, statistiques, agenda et fiche
événement, cotisations et fiche campagne, subventions et fiche, tableau de bord, messages
(canaux et discussion), boîte mail (liste triée par l'IA, fil, réponse), notifications,
coach IA, paramètres (profil, sécurité, logo, informations légales, suppression du compte),
support ; bouton « + » de création rapide (facture, devis, client, adhérent, cotisation
reçue, événement, projet, subvention, message) ; **scan d'une facture à l'appareil photo**
lue par l'IA ; notifications push. Les pages web restent pour les fonctions avancées.

### B3. 5.1.1(v) : suppression du compte dans l'app

**Chemin** : **Plus › Paramètres › « Supprimer mon compte (RGPD) »** (bas de l'écran) ›
mot de passe › **« Supprimer définitivement »** → anonymisation immédiate et déconnexion
(`api/app-delete-account.php`). Sur le web : `https://assokit.fr/suppression-compte`.

**Bloquant trouvé et corrigé.** Un compte créé dans l'app (« Créer ma démo ») est le
**seul compte** de sa nouvelle association. La règle `ak_account_delete_blocker()` lui
répondait « Vous êtes le seul compte de l'association : pour la fermer et supprimer vos
données, écrivez à contact@assokit.fr ». Apple refuse qu'un compte créé dans l'app ne se
supprime que par e-mail au support, et c'est exactement le compte qu'un examinateur
créerait pour tester la suppression.

Correction, limitée à l'app :

- `app-context.php` : `ak_account_sole_org()` reconnaît ce cas (administrateur, aucun autre
  compte actif ; jamais pour le Fondateur, un administrateur de la plateforme ou un agent
  de collectivité).
- `api/app-account.php` : le bouton devient actif, avec une note affichée dans la fiche de
  confirmation (`delete_note`) : « Vous êtes le seul compte de « … » : supprimer votre
  compte ferme aussi son espace Assokit. Ses données sont conservées 30 jours (export sur
  demande à contact@assokit.fr), puis supprimées, sauf les pièces comptables (10 ans). »
- `api/app-delete-account.php` : ferme l'espace comme une résiliation du Fondateur
  (abonnement Stripe arrêté **d'abord** — en cas d'échec, rien n'est supprimé —, fin
  d'essai, association « résiliée »), puis anonymise le compte.
- `App.js` : la fiche de confirmation affiche cette note.
- `suppression-compte.php` § 6 : la page publique le dit.

**Testé sur le banc** : inscription avec l'agent de l'app → `can_delete: true` et la note →
suppression → compte anonymisé, association `cancelled`, plan Démarrage. Comptes DEMO F
inchangés : l'administrateur garde le bouton grisé (« seul administrateur… nommez un autre
administrateur »), le membre garde le message de démonstration protégée.

Sur iPhone, un compte demandé dans l'app (B1) ne peut pas se connecter avant la validation ;
une fois l'espace activé, c'est le seul compte de sa structure et ce correctif s'applique :
il se supprime dans l'app. Le correctif sert aussi à Android et aux structures inscrites sur
le site dont la personne est le seul compte.

**Demande en attente ou refusée : retrait dans l'app (19/10/2026).** Jusque-là, une demande
pas encore validée (ou refusée) ne se retirait que par e-mail : le demandeur ne peut pas ouvrir
de session, donc pas atteindre les Paramètres. Apple demande que la suppression d'un compte créé
dans l'app se fasse dans l'app. Désormais :

- **écran de connexion natif** : sous l'avis « Votre espace est en cours de validation… »,
  un bouton discret **« Retirer ma demande »** (`NativeLogin`, `onWithdraw` ; `LOGIN_PAGE_JS`
  lit `.error-box.is-pending` sur `/connexion`) ouvre `/retirer-demande` dans l'app ; pour une
  demande **refusée** (la connexion ouvre alors l'avis « Demande non validée »), le même bouton
  est sur l'avis natif (`SuspendedScreen`), et « Mon compte › Supprimer mon compte » fait le
  même retrait (`api/app-delete-account.php`) ; la page « Demande envoyée », juste après la
  demande, porte aussi le lien « Vous ne souhaitez plus de cet espace ? Retirer ma demande »
  (23/10/2026 : c'est le premier endroit où l'examinateur qui vient de créer un compte le
  cherche) ;
- **page `/retirer-demande`** (`retirer-demande.php`, sans session, dans l'app avec l'en-tête
  et le pied de page de l'app, hors du plan du site) : adresse e-mail, mot de passe, case « Je
  confirme le retrait de ma demande et l'effacement des informations transmises ». **Même
  blocage que la connexion** (`ak_login_lockout`, `app-context.php`, vérifié avant le mot de
  passe : 10 échecs par compte, 50 par adresse IP, en 15 minutes ; une erreur est notée
  `login_failed` et compte pour les deux pages) ; adresse inconnue ou mot de passe faux : le
  message de la connexion, sans dire lequel, ni par le texte ni par le temps de réponse
  (`ak_login_password_ok`, 23/10/2026, aussi pour `/connexion` : une adresse inconnue fait le
  même calcul bcrypt qu'un mauvais mot de passe) ;
- **effet** (`ak_signup_review_withdraw`, `signup-review-helpers.php`, une transaction, ligne de
  l'association verrouillée et état relu sous le verrou) : celui de la suppression du seul
  compte dans l'app (fermeture de l'espace par `ak_account_close_sole_org`, désormais commune
  avec `api/app-delete-account.php` ; anonymisation par `ak_account_self_anonymise`), plus
  l'effacement des informations de la demande (fiche de la structure sauf son nom, notes
  internes, ligne `public_signups`, nom et adresse dans les alertes de la cloche) ; e-mail
  « Votre demande a été retirée » envoyé avant l'anonymisation ; cloche du Fondateur « Demande
  retirée par le demandeur » (nom de la structure seulement). La demande n'est plus en attente
  nulle part (cockpit et ses compteurs, appli du Fondateur, rappel de 24 h) ; les liens
  « Valider » / « Refuser » de l'e-mail affichent « Demande retirée » ; « Valider » ou
  « Refuser » envoyé depuis une page du cockpit ou une liste de l'appli du Fondateur ouverte
  avant le retrait ne fait rien (« Demande retirée par le demandeur… rien n'a été modifié »,
  état relu sous le verrou, 23/10/2026) : avant, il rouvrait l'espace fermé (refus = suspendu)
  ou le marquait « validé » ; réactiver, suspendre, prolonger ou changer de formule sont
  refusés de même ;
- un espace **validé** (même pendant le retrait : le Fondateur qui valide au même instant passe
  en premier, rien n'est supprimé), une association à plusieurs comptes ou un compte inscrit sur
  le site : rien n'est fait, la page dit comment supprimer un compte actif (Plus › Paramètres ›
  Supprimer mon compte, ou Paramètres › Compte sur le site).

**Comptes de démonstration.** `admin@demo-f` ne peut pas se supprimer (seule
administratrice, raison affichée) ; `membre@demo-f` montre tout le parcours, mais la
dernière étape ne supprime rien (compte partagé protégé, message explicite). Les notes pour
l'examinateur expliquent les deux.

**Plus de suppression simulée (18/10/2026).** `api/app-delete-account.php` répondait
`ok: true` « Votre compte a été supprimé. » pour `apple.review@assokit.fr` et
`demo-review@assokit.fr` sans rien supprimer. Ces comptes ne sont plus donnés à Apple, mais
l'ancienne fiche `docs/app-store-fiche.md` les cite encore : une suppression simulée face à
l'examinateur serait une tromperie (règles 2.3.1 et 5.6), visible dès qu'il se reconnecte. La
branche est retirée : ces comptes reçoivent le même refus explicite (403 `demo`) que les
comptes DEMO F. Testé sur le banc.

### B4. 4.8 : Se connecter avec Apple

Non requis. La connexion se fait **uniquement par e-mail et mot de passe** (`connexion.php`,
écran natif). Aucun bouton Google, Facebook ou autre. La connexion d'une boîte Gmail ou d'un
agenda Google (Boîte mail, Agenda) relie un **service** au compte Assokit déjà ouvert : ce
n'est pas une méthode de connexion à l'app, la règle 4.8 ne s'applique pas.

### B5. 2.1 : informations pour la vérification

Compte `admin@demo-f.assokit.fr` / `DemoFormation2026!` (toutes les fonctions) et
`membre@demo-f.assokit.fr` (suppression de compte). Pas de double authentification, mot de
passe non modifiable, e-mails et SMS simulés (`demo-guard.php`), données remises à zéro
chaque nuit vers minuit (heure de Paris) sans déconnecter, erreur de saisie bloquante pour
l'adresse IP seulement. Notes complètes dans [`age-et-revue.md`](age-et-revue.md).

### B6. 5.1.2 : suivi et App Tracking Transparency

Aucun SDK de publicité, de mesure d'audience ou de suivi ; aucun accès à l'IDFA ; Google
Analytics exclu des pages vues dans l'app (`includes-public.php`). Pas de fenêtre ATT à
afficher, `NSPrivacyTracking = false`.

### B7. 1.2 : contenus générés par les utilisateurs

Messagerie et documents **internes à une association** (membres invités par ses
administrateurs, aucun profil public). Les réponses à la classification par âge déclarent
Messagerie = Oui et Contenus des utilisateurs = Oui : l'examinateur vérifie donc la règle 1.2,
qui demande de pouvoir signaler et bloquer. **Ajouté le 18/10/2026** dans l'écran natif d'un
canal (`NativeChat`) : un **appui long** sur une bulle ouvre une feuille d'actions.

| Action | Pour qui | Ce qui se passe |
|---|---|---|
| Signaler ce message | tout membre, sur le message d'un autre | `api/app-message-action.php` (`report`) ouvre un ticket de support pré-rempli : canal et son identifiant, identifiant et date du message, auteur, extrait (500 caractères). Mêmes tables et même alerte au support que **Plus › Support** (`support-nouveau.php`), priorité haute. 10 signalements par heure au plus. |
| Masquer les messages de cette personne | tout membre | Identifiant de l'auteur gardé dans le trousseau (`expo-secure-store`, clé propre au compte connecté) ; ses messages et les citations de ses messages disparaissent de tous les canaux ; bandeau « N messages masqués · Réafficher ». Rien n'est envoyé au serveur. |
| Supprimer le message | l'auteur, ou un administrateur | `api/app-message-action.php` (`delete`), mêmes règles que l'action `delete` d'`action-message-canal.php` (sauf messages des agents de la mairie sur « Suivi · Mairie ») ; suppression douce. L'option n'apparaît que si le serveur l'accepte (`can_delete` dans `api/app-channel-messages.php`). |

S'y ajoutent : désactivation ou retrait d'un membre par un administrateur
(`supprimer-adherent.php`), contact `contact@assokit.fr`, CGU § 7. Les signalements faits
avec les comptes DEMO F sont effacés par la remise à zéro de minuit (tickets de l'org).

**Ajouté le 19/10/2026 (décision du fondateur)** : `cgu.php` § 7, « Contenus inacceptables et
signalements : tolérance zéro », dans la version du site comme dans celle de l'app : tolérance
zéro envers les contenus illicites, haineux, de harcèlement, sexuels, violents, diffamatoires,
le spam, et envers les utilisateurs abusifs ; chaque signalement est examiné **sous 24 heures**,
le contenu contraire aux règles est retiré et son auteur peut être exclu ; pour signaler :
appui long sur un message › « Signaler ce message », ou le support. C'est la formule qu'Apple
cite dans ses refus 1.2. Engagement à tenir : traiter les signalements dans la journée.

### B8. 2.3.3 : captures d'écran

Les 8 captures de `captures-6.9/` montrent des écrans natifs réels, remplis par les vraies
réponses du serveur pour DEMO F, sans prix Assokit (voir `README-visuels.md`).

### B9. Autres points vérifiés

- **5.1.1(i)** : politique de confidentialité accessible dans l'app (écran d'accueil, écran
  de connexion, Paramètres › Informations légales) et déclarée dans App Store Connect.
- **5.1.1(ii)/(iv)** : chaque autorisation est demandée au moment où elle sert (appareil
  photo au scan, photos à l'import d'un logo) ; l'app fonctionne si on refuse.
- **2.5.2 / mises à jour OTA** : `expo-updates` ne télécharge que du JavaScript de l'app
  elle-même, sans en changer l'objet : admis. Les changements natifs passent par un build.
- **2.3.10** : aucune mention d'une autre plateforme mobile dans la fiche ni dans l'app iOS.
- **2.3.1** (fonctions annoncées réelles) : l'écran d'accueil de l'app annonçait « Factures &
  reçus fiscaux » ; Assokit n'émet pas de reçus fiscaux (l'IA rédige seulement la lettre qui
  accompagne un reçu). Remplacé le 18/10/2026 par « Devis & factures » (`FEATURES`, `App.js`).
- **Repère de version** : `UI_REV` (`App.js`) vaut `2026-10-19-retrait-demande` ; la ligne grise en
  bas des Paramètres permet de vérifier sur TestFlight que le build exécute bien ce code.
- **5.6.1** : aucune demande de note maison (pas de fenêtre « Notez-nous »).
- **iPad** : l'app est réservée à l'iPhone, mais Apple peut l'ouvrir sur iPad en mode
  compatibilité (écran d'iPhone agrandi). À essayer sur un iPad avant l'envoi si vous en
  avez un.

### B10. 5.1.2(i) : données envoyées à une IA tierce

La règle, dans sa version en vigueur, demande de dire clairement quand des données
personnelles partent chez une IA tierce et d'obtenir une permission explicite avant. Avant le
18/10/2026, la photo d'une facture, l'analyse des factures, le cockpit des statistiques, le
coach, les brouillons de la boîte mail et le Copilote partaient chez Anthropic sans aucune
fenêtre d'accord dans l'app, qui ne citait Anthropic nulle part.

- **Dans l'app** : avant la première fonction IA, une fenêtre native « Fonctions IA » :
  « Pour [lire la facture photographiée / analyser vos factures / analyser vos chiffres /
  rédiger le bilan du coach / rédiger ce brouillon de réponse / répondre à vos questions], le
  contenu concerné est envoyé à Anthropic (États-Unis), notre sous-traitant d'intelligence
  artificielle, uniquement pour produire ce résultat ; il ne sert pas à entraîner ses
  modèles. » avec **Refuser** et **Accepter** (`withAi`, `askAiConsent`, `App.js`). En cas de
  refus, les boutons IA passent en gris et un appui explique comment les réactiver.
  **Plus › Paramètres › Fonctions IA** : interrupteur et texte d'information.
- **Choix gardé par compte sur le serveur** : `api/app-ai-consent.php` (écriture),
  `api/app-dashboard.php` › `ai_consent` (lecture), table `user_ai_consent`
  (`ai-consent-helper.php`, créée à la première utilisation ;
  `migrations/2026-10-18-accord-ia.sql` en double).
- **Contrôle côté serveur** (`ak_ai_app_gate()`), pour toute requête de l'app, écrans
  natifs **et** pages du site affichées dans l'app : `ask_claude`, `ask_claude_stream`,
  `extract_invoice_from_image` (`ai-helper.php`), `ak_ai_call_api` (`asso-ai-helpers.php`),
  `action-scan-facture.php`, `communication-generer.php`, suggestions « Aujourd'hui »
  (`today-ai-helper.php`). Sans accord, rien ne part et le motif s'affiche. Hors de l'app
  (navigateur, tâches planifiées), rien ne change.
- Le **tri de la Boîte mail** est réglé pour toute l'association par un administrateur, sur
  le site : il n'est pas soumis à l'accord de la personne qui appuie sur « Synchroniser »
  (`ak_ai_org_process`). La case de `boite-mail-parametres.php` nomme désormais Anthropic.
- Politique de confidentialité § 5 et notes pour l'examinateur (PRIVACY) complétées. La
  remise à zéro de DEMO F efface l'accord des comptes de démonstration.

### B11. 2.1 : exports enregistrables sur iPhone

Sur iOS, `react-native-webview` n'intercepte un fichier en pièce jointe que si
`onFileDownload` est fourni (`RNCWebViewImpl.m`, `decidePolicyForNavigationResponse`). Sans
lui, l'export FEC (`export-fec.php`, `text/plain` en pièce jointe) s'affichait en texte brut
sans moyen de l'enregistrer, et les exports Excel ou ZIP ne faisaient rien, alors que la
description annonce l'export FEC. Ajouté : `onFileDownload` relit le fichier avec la session
de la WebView (`fetchFileJS`, le mécanisme base64 de `fetchPdfJS`, généralisé au type MIME),
l'écrit dans le cache (`expo-file-system`) et ouvre la feuille de partage avec le bon type
MIME et l'UTI d'Apple (`FILE_TYPES` : txt, csv, xlsx, docx, zip, pdf, ics, xml…). Nom du
fichier tiré de `Content-Disposition`. Android garde son gestionnaire de téléchargements.
Tous les exports du site sont des liens GET (vérifié : `export-fec.php`,
`adherents-export.php`, `mon-asso-export.php`, `rgpd-export.php`, `emargement-export.php`…).

---

## C. Fichiers modifiés, et comment ils arrivent en ligne

| Fichier | Mise en ligne |
|---|---|
| `mobile/app.json` (version 1.0.1, manifeste à 16 types, `Info.plist` avec `CFBundleLocalizations`), `mobile/package.json`, `mobile/package-lock.json` | **Seulement par un build** (iOS et Android) |
| `mobile/App.js` (liens légaux dans l'app, note de suppression ; puis connexion seule sur iPhone — remplacée le 19/10/2026 par la demande d'espace, voir plus bas —, appui long sur un message, accord pour l'IA, `onFileDownload`) | Inclus dans le build iOS 1.0.1. Pour Android : build 1.0.1 (la version change, l'OTA ne suffit pas) |
| Premier lot : `signup.php`, `connexion.php`, `trial-limits-helpers.php`, `action-assemblee.php`, `mon-asso-factures.php`, `today-ai-helper.php`, `app-context.php`, `api/app-account.php`, `api/app-delete-account.php`, `suppression-compte.php` | **Serveur** : `git pull` sur O2Switch **avant** d'envoyer l'app en vérification (l'examinateur teste contre assokit.fr) |
| Second lot (18/10/2026, soir) — **nouveaux** : `ai-consent-helper.php`, `api/app-ai-consent.php`, `api/app-message-action.php`, `migrations/2026-10-18-accord-ia.sql` ; **modifiés** : `404.php`, `action-scan-facture.php`, `adherents-import.php`, `ai-helper.php`, `api/_app-write-boot.php`, `api/app-channel-messages.php`, `api/app-coach-generate.php`, `api/app-dashboard.php`, `api/app-delete-account.php`, `api/app-invoices-ai.php`, `api/app-stats-ai.php`, `app-context.php`, `asso-ai-helpers.php`, `boite-mail-parametres.php`, `cgu.php`, `communication-diffuser-nouveau.php`, `communication-generer.php`, `confidentialite.php`, `connexion.php`, `demo-formation/purge.php`, `mail-helpers.php`, `signup.php`, `today-ai-helper.php` | **Serveur**, même chose, **avant** la soumission. La migration est facultative (table créée à la première utilisation). |
| Troisième lot (19/10/2026, demande d'espace sur iPhone et CGU) — **nouveaux** : `signup-review-helpers.php`, `valider-inscription.php` ; **modifiés** : `.htaccess` (route `/valider-inscription`), `app-context.php`, `signup.php`, `signup-email-helpers.php`, `trial-helpers.php`, `connexion.php`, `connexion-2fa.php`, `super-admin-associations.php`, `api/app-founder-action.php`, `api/app-dashboard.php` (commentaire), `cron-trial-check.php`, `cgu.php`, `suppression-compte.php` | **Serveur**, **avant** la soumission. Aucune migration : les colonnes utilisées existent déjà (`organizations.validation_status`, `status`, `suspended_reason`, `validated_at` ; carnet `cron_envois` pour le rappel). Le secret des liens de l'e-mail est dérivé d'une constante de `config.php` (`AK_CONTACT_SECRET`, sinon `RESEND_API_KEY` ; plus `CRON_TOKEN` depuis le 23/10/2026, il passe dans l'adresse des crons) : rien à ajouter, puisque `RESEND_API_KEY` sert déjà aux e-mails. |
| `mobile/App.js` (19/10/2026 : « Créer l'espace de ma structure », lien « Demander la création de mon espace », retour à la connexion native depuis « Demande envoyée », alerte « Espace activé » de l'appli fondateur ; `UI_REV` `2026-10-19-demande-ios`) | JavaScript seul : build iOS 1.0.1 s'il n'est pas encore fait, sinon mise à jour OTA (`publication-ios.md`, étape 10). Avec l'ancien JavaScript, l'app iPhone bloque encore `/signup` : la demande n'y apparaît qu'avec ce JavaScript |
| Quatrième lot (19/10/2026, retrait d'une demande d'espace, Apple 5.1.1 v) — **nouveau** : `retirer-demande.php` ; **modifiés** : `.htaccess` (route `/retirer-demande`), `app-context.php` (`ak_login_lockout`, `ak_account_close_sole_org`, `AK_GUARD_PUBLIC`), `connexion.php`, `includes-public.php` (`AK_PUBLIC_APP_PAGES`), `signup-review-helpers.php`, `valider-inscription.php`, `super-admin-associations.php`, `api/app-delete-account.php`, `api/app-account.php`, `suppression-compte.php` | **Serveur**, **avant** la soumission. Aucune migration. |
| `mobile/App.js` (19/10/2026 : « Retirer ma demande » sous l'avis « en cours de validation » et sur l'avis « Demande non validée », `/retirer-demande` ouverte dans l'app ; `UI_REV` `2026-10-19-retrait-demande`) | JavaScript seul : dans le build iOS 1.0.1 s'il n'est pas encore fait, sinon mise à jour OTA. Le serveur suffit pour le lien de la page `/connexion` ; le bouton natif demande ce JavaScript |
| Cinquième lot (23/10/2026, corrections après la vérification adversariale du retrait) — **modifiés** : `signup-email-helpers.php` (e-mail d'alerte d'une demande : plus de « répondre » au demandeur), `signup-review-helpers.php` (secret des liens sans `CRON_TOKEN` ; « Valider » / « Refuser » d'une demande retirée : état `withdrawn` ; e-mails au demandeur), `valider-inscription.php`, `super-admin-associations.php`, `api/app-founder-action.php`, `app-context.php` (`ak_login_password_ok`), `connexion.php`, `retirer-demande.php`, `api/app-delete-account.php`, `contact.php`, `parametres.php`, `signup.php`, `suppression-compte.php`, `cgu.php`, `confidentialite.php` (« Version du 23/10/2026 » pour les trois) | **Serveur**, **avant** la soumission. Aucune migration, aucun changement de `App.js` (le lien de « Demande envoyée » est une page du site) |

## D. Tests faits

Premier lot (18/10/2026, après-midi) :

- `php -l` sur les 10 fichiers PHP modifiés : aucune erreur.
- Banc PHP `iosf` (base `assokit_t_iosf`, copie de la démo DEMO F). Port **9751** : le 9750
  prévu était occupé par l'étape de captures, lancée en parallèle.
  - `/signup` et `/connexion` avec l'agent de l'app et avec Safari : textes de l'app
    neutres, site inchangé (prix et essai gratuit toujours affichés dans le navigateur).
  - `/mon-asso-factures` dans l'app : renvoi vers `/dashboard` ; `/mon-asso-factures-client`
    (factures de l'association à ses clients) toujours ouverte.
  - Parcours inscription → suppression du seul compte (B3) ; comptes DEMO F inchangés.
  - 9 messages de plafond réels passés dans `ak_trial_msg()` : aucune « formule » ni
    « gratuit » côté app, texte d'origine côté site.
  - Suggestions du tableau de bord : 1 sur 3 dans l'app (les 2 d'abonnement retirées), 3
    sur 3 sur le site.
- `App.js` : analyse Babel, puis `npx expo export --platform ios` (compilation Hermes) : OK.
- `npx expo prebuild --platform ios` : `Info.plist`, autorisations et manifeste générés
  comme décrit en A1 et A2.
- `npm ci` sur le nouveau lockfile et `expo-doctor` : voir A4.

Second lot (18/10/2026, soir) :

- `php -l` sur les 31 fichiers PHP modifiés ou ajoutés depuis le dernier commit : aucune
  erreur. `app.json` : JSON valide. `App.js` : analyse Babel, puis
  `npx expo export --platform ios` (bytecode Hermes) : OK. Nouveau
  `npx expo prebuild --platform ios` : `CFBundleShortVersionString` 1.0.1,
  `CFBundleLocalizations` `fr`, `PrivacyInfo.xcprivacy` à 16 types.
- Banc PHP **`iosx`** (base `assokit_t_iosx`, copie de la démo DEMO F), port **9770**, avec
  un faux point d'accès Anthropic (port 9771) qui compte les appels reçus. **47 contrôles,
  47 OK**, deux fois (la seconde sur une base neuve) :
  - agent iPhone de l'app : `/signup` → `/connexion` ; `/connexion` sans lien d'inscription ;
    `api/app-dashboard.php` sans essai ni formule pour une structure en essai ; agent
    Android et navigateur inchangés (essai « J-14 » toujours visible sur Android) ;
  - `/404.php`, `/cgu`, `/adherents-import`, `/communication-diffuser-nouveau`,
    `/confidentialite` : textes attendus dans l'app, site inchangé ;
  - messages : `viewer_id`, `author_id`, `can_delete` ; signalement → ticket pré-rempli ;
    refus pour son propre message, pour un membre qui veut supprimer le message d'un autre,
    pour un jeton CSRF faux, pour un suiveur ; suppression par l'administrateur ;
  - IA : sans accord, l'analyse des factures, le cockpit, le coach et le scan d'une facture
    n'envoient **rien** (compteur du faux point d'accès inchangé) et renvoient le motif ;
    après accord, l'envoi part ; même compte dans un navigateur : inchangé ;
    suggestions « Aujourd'hui » : désactivées dans l'app sans accord (contrôle en ligne de
    commande) ;
  - `demo-review@assokit.fr` : 403 `demo`, compte intact ;
  - remise à zéro de DEMO F (`seed-demo-formation.php`) : accords pour l'IA des comptes de
    démonstration effacés, identifiants des comptes conservés.
- **Contrôle d'interface** : export web d'`App.js` en branches iOS (copie de travail, `Alert`
  instrumenté puisque le navigateur ne l'affiche pas), branché sur le banc `iosx` avec la
  session de l'administrateur DEMO F. **19 contrôles, 19 OK**, aucune erreur JavaScript :
  écran d'accueil sans « Créer ma démo » ; accueil sans essai ni formule ; appui long sur un
  message → les trois actions ; masquer (bandeau « 4 messages masqués · Réafficher ») puis
  réafficher ; signaler (confirmation, puis « Message signalé ») ; Paramètres sans carte
  « FORMULE », avec la rubrique « Fonctions IA » ; première fonction IA → fenêtre « Fonctions
  IA » (Refuser / Accepter, Anthropic nommé) ; refus → « IA désactivée » ; réactivation par
  l'interrupteur des Paramètres ; analyse IA ensuite sans nouvelle question.
- Capture `05-cotisations.png` recomposée et revérifiée (1290 × 2796, RVB sans alpha) ; les 7
  autres, recomposées pour contrôle, identiques au pixel près.

Troisième lot (19/10/2026, demande d'espace sur iPhone et CGU) — banc **`iv`** (base
`assokit_t_iv`, copie de la démo DEMO F + migrations d'octobre), port **9800**, avec un faux
Resend (port 9801) qui garde chaque e-mail envoyé :

- `php -l` sur les 14 fichiers PHP modifiés ou ajoutés : aucune erreur ; `App.js` : analyse
  Babel OK ; aucune alerte PHP dans le journal du banc.
- Agent iPhone : `/signup` s'ouvre (plus de renvoi vers `/connexion`), sans aucun des mots
  « essai », « gratuit », « abonnement », « prix », « € », « formule », « tarif », « paiement » ;
  envoi → 303 vers « Demande envoyée » ; association `suspended` / `pending_founder`, sans
  essai, plan, abonnement ni dossier ; e-mails « Demande reçue — validation sous 48 h » et
  « 🔔 Demande d'espace à valider » (fiche complète, boutons Valider / Refuser, lien cockpit),
  notification dans la cloche du Fondateur.
- Connexion pendant l'attente (site et app) : pas de session, message « Votre espace est en
  cours de validation… » ; une session déjà ouverte est fermée (API : 401 `auth` avec ce
  message ; page : `/connexion?acces=attente`). Incarnation par le Fondateur : possible.
  Association « à valider » créée par un super-admin : toujours utilisable.
- Validation par le lien, par le cockpit et par l'appli fondateur : mêmes lignes en base
  qu'une inscription sur le site (essai `pro-essai` de 14 jours **à partir de la
  validation**, ancienne ligne `subscriptions`, mode « Offert », dossier « Projets
  généraux ») et e-mail « Votre espace Assokit est activé » ; ensuite bandeau d'essai sur le
  site, rien dans l'app iPhone (`api/app-dashboard.php` : `trial: false`), essai visible sur
  Android. Une seconde validation ne rouvre rien et n'envoie rien.
- Refus par le lien (avec motif contenant du HTML, échappé), par le cockpit et par l'appli :
  refusée et suspendue, e-mail poli. Réactiver, suspendre, prolonger ou changer de formule une
  demande en attente : refusé (cockpit, y compris « Modifier » avec un autre plan ; appli,
  409 `pending_request`, y compris `edit` avec un plan ; le nom reste modifiable).
- Incarnation du demandeur par le Fondateur avant la décision : la lecture du plan crée d'office
  une ligne « Démarrage » ; à la validation, cette ligne devient la ligne d'essai
  (`ak_org_set_plan`) au lieu d'en ajouter une seconde qui serait passée devant l'essai.
- Lien : signature modifiée, association changée, action inversée, expiration modifiée,
  jeton illisible → « Lien non valable » (403) ; jeton bien signé mais expiré → « Lien
  expiré » ; POST sans jeton CSRF ou avec l'autre action → rien n'est fait ; rejeu →
  « Demande déjà validée / refusée », rien n'est refait.
- Rappel : demande vieille de 25 h → un rappel (2 e-mails, 1 notification) au premier passage
  de `cron-trial-check.php`, aucun au second ; demande de moins de 24 h ignorée.
- Site et app Android : `/signup` identique octet pour octet à la version précédente (hors
  jeton CSRF) ; inscription → essai immédiat, comme avant.
- Schéma incapable de marquer la demande (colonne `suspended_reason` retirée sur le banc) :
  rien n'est créé, demande gardée, Fondateur alerté, message neutre au demandeur.
- Compte demandé puis validé : « Supprimer mon compte » actif dans l'app, suppression OK
  (association fermée). `/cgu` (navigateur et app) : clause « tolérance zéro » et « Version du
  19/10/2026 ». Ces contrôles remplacent, pour l'agent iPhone, ceux du second lot
  (`/signup` → `/connexion`).

Quatrième lot (19/10/2026, retrait d'une demande d'espace) — banc **`rd`** (base
`assokit_t_rd`, migrations d'octobre + DEMO F), port **9830**, faux Resend sur le port 9831 :

- `php -l` sur les fichiers PHP modifiés ou ajoutés : aucune erreur ; `App.js` : analyse Babel
  OK (et le script injecté `LOGIN_PAGE_JS` aussi) ; aucune alerte PHP ni erreur SQL venant de
  ces fichiers dans les journaux du banc ; tour des pages de DEMO F (administrateur, membre)
  sans erreur.
- Agent iPhone : demande → connexion → avis « en cours de validation » avec « Retirer ma
  demande » (`/connexion`, aussi avec l'agent du site). `/retirer-demande` : en-tête de l'app,
  `noindex`, aucun des mots « essai », « gratuit », « abonnement », « offre », « formule »,
  « tarif », « paiement », « prix », « € ».
- Case non cochée : refus, mot de passe non vérifié. Adresse inconnue et mauvais mot de passe :
  « Email ou mot de passe incorrect. », notés `login_failed` (`unknown_email`,
  `wrong_password`). 4 échecs sur `/connexion` + 5 sur `/retirer-demande` + 1 = 10 : le bon mot de
  passe est ensuite refusé sur les **deux** pages (« Trop de tentatives… 15 minutes »), rien
  n'est retiré ; avec 9 échecs récents, le bon mot de passe passe. 50 échecs depuis l'adresse
  IP : blocage par IP sur les deux pages.
- Bon mot de passe : redirection 303 vers « Demande retirée » ; compte anonymisé
  (`deleted_…@anonyme.assokit.local`, mot de passe vidé, désactivé) ; association `cancelled`,
  validation `rejected`, motif « Demande retirée par le demandeur », fiche vidée sauf le nom ;
  `public_signups` : coordonnées, IP et fiche effacées, statut `completed` (ENUM du banc) ;
  journaux `self_delete_close_org` et `signup_request_withdrawn` ; un e-mail « Votre demande a
  été retirée » ; cloche du Fondateur « Demande retirée par le demandeur : … », anciennes
  alertes sans nom ni adresse et lues. Cockpit : plus dans « à valider », badge doré du menu
  à 0 (puis 2 avec deux nouvelles demandes), ligne « ↩ Retirée » ; appli du Fondateur :
  `signals.pending` 0, liste « à valider » vide. Liens « Valider » / « Refuser » de l'e-mail :
  « Demande retirée » en GET et en POST, rien n'est fait. Rappel de 24 h : envoyé pour les
  deux demandes en attente, pas pour la demande retirée.
- Rejeu du même envoi : « Email ou mot de passe incorrect. », rien de plus (ni e-mail ni
  cloche) ; second appel direct de `ak_signup_review_withdraw` : `already`.
- Course : le Fondateur valide en gardant le verrou 4 s pendant l'envoi du retrait ; la page
  attend, puis répond « Votre espace est actif… rien n'a été modifié » : essai ouvert, compte
  intact, seul l'e-mail « Votre espace Assokit est activé » est parti.
- Demande refusée (cockpit, avec motif) : connexion → « Demande non validée » avec « Retirer
  ma demande et effacer mes informations », sans bouton de paiement ; retrait depuis ce
  navigateur (session suspendue ouverte, page non renvoyée vers `/connexion`) → retrait
  complet, session fermée (`/connexion` ensuite sans message d'erreur). Autre demande refusée,
  supprimée par l'API de l'app (`api/app-delete-account.php`, « Mon compte › Supprimer mon
  compte ») : même retrait complet, message « Votre demande a été retirée et votre compte
  supprimé. ».
- Comptes actifs (DEMO F administrateur, Fondateur, espace validé, inscription sur le site) :
  rien n'est fait, message « Votre espace est actif… » avec le chemin de suppression. Double
  authentification sans session : rien n'est fait, explication ; avec une session ouverte du
  même compte : retrait possible.
- Suppression dans l'app d'un compte seul de son association (inscription Android, essai) :
  inchangée après la mise en commun (`cancelled`, plan Démarrage, journaux). Connexion sur le
  site et sur Android, inscription sur le site et sur Android (essai immédiat) : inchangées ;
  avis de suspension ordinaire (impayé) inchangé.

Non testable ici, à faire sur le build TestFlight : notifications push réelles, Face ID,
appareil photo, appui long sur une image → « Enregistrer dans Photos », appui long sur un
message, fenêtre « Fonctions IA », feuille de partage d'un export (voir
`publication-ios.md`, étape 4).
