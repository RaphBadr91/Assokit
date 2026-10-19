# Publier Assokit sur l'App Store — guide pas à pas

Ce guide va de « rien n'est fait chez Apple » à « l'app est en ligne », puis explique les
mises à jour. Chaque étape dit où cliquer. Apple renomme parfois ses menus : le libellé
anglais est donné entre parenthèses.

Fichiers de ce dossier utilisés en route :

| Fichier | Sert à |
|---|---|
| [`conformite-ios.md`](conformite-ios.md) | Ce qui a été vérifié et corrigé avant le build (à lire une fois) |
| [`fiche-app-store.md`](fiche-app-store.md) | Nom, sous-titre, description, mots-clés, URL, catégories, copyright |
| [`confidentialite-app.md`](confidentialite-app.md) | Rubrique « Confidentialité de l'app », case par case |
| [`age-et-revue.md`](age-et-revue.md) | Classification par âge, chiffrement, droits, DSA, informations pour la vérification et notes en anglais |
| [`README-visuels.md`](README-visuels.md) + `captures-6.9/` | Les 8 captures iPhone 6,9 pouces |

> **Coût des builds.** Chaque `eas build` est compté par Expo (quota mensuel de l'offre
> gratuite, crédits des offres payantes, facturation à l'usage au-delà : voir
> expo.dev › votre compte › *Billing* avant de lancer). Ce guide n'en demande **qu'un**
> pour iOS. Ne relancez un build que si du code natif ou `app.json` a changé ; le reste
> part en mise à jour OTA (étape 10).

---

## Étape 0 — Mettre le serveur à jour (avant tout)

L'examinateur d'Apple utilise l'app contre **assokit.fr**. Les corrections du 18/10/2026
côté serveur (textes sans « essai gratuit » dans l'app, suppression du compte seul de son
association, **inscription bloquée pour l'iPhone, signalement et suppression d'un message,
accord pour l'IA** — liste dans `conformite-ios.md` § C) doivent être en ligne. Sans elles,
l'app iPhone 1.0.1 propose des actions que le serveur ne connaît pas.

1. En SSH sur O2Switch, dans le dossier du site : `git pull`.
   Facultatif : `/usr/local/bin/php migrations/run.php 2026-10-18-accord-ia.sql` (la table
   `user_ai_consent` est de toute façon créée à la première utilisation).
2. Vérifier, depuis un navigateur :
   - `https://assokit.fr/suppression-compte` s'affiche sans connexion, et son § 6 parle de
     « Plus › Paramètres › Supprimer mon compte » pour le seul compte d'une association ;
   - `https://assokit.fr/confidentialite` s'affiche sans connexion.
3. Se connecter une fois sur le site avec `admin@demo-f.assokit.fr` / `DemoFormation2026!`
   pour vérifier que la démo DEMO F est là et remplie.

## Étape 1 — Prérequis (une seule fois)

1. **Compte Apple Developer actif** : https://developer.apple.com/account › *Membership
   details* (Abonnement) : l'adhésion doit être active (cotisation annuelle, 99 USD).
2. **Accords** : https://appstoreconnect.apple.com › **Business** (ou *Accords, taxes et
   opérations bancaires*) : si un bandeau demande d'accepter un nouvel accord, l'accepter.
   Pour une app **gratuite**, l'accord « Apps payantes » et les coordonnées bancaires ne
   sont **pas** nécessaires.
3. **Compte Expo** : sur l'ordinateur, `npx eas-cli@latest login` (le même compte que pour
   Android ; projet `dae894d1-…` déjà lié dans `app.json`).
4. **Le code à jour sur l'ordinateur** :

   ```bash
   cd ~/Assokit && git pull
   cd mobile && npm ci
   ```

   `npm ci` installe exactement les versions du `package-lock.json` (React Native 0.86.3).
   EAS en a besoin en local pour lire les autorisations de l'app avant le build.

## Étape 2 — Le build iOS (cloud EAS, pas besoin de Mac)

```bash
cd ~/Assokit/mobile
npx eas-cli@latest build --platform ios --profile production
```

Questions posées au **premier** build, et réponses :

| Question d'EAS | Réponse |
|---|---|
| *Do you want to log in to your Apple account?* | **Yes**, puis identifiant Apple, mot de passe et code à 6 chiffres reçu sur l'iPhone |
| *Select a team* (si plusieurs) | L'équipe du compte Apple Developer d'Assokit |
| *Bundle identifier fr.assokit.app is not registered… register it?* (si posée) | **Yes** |
| *Generate a new Apple Distribution Certificate?* | **Yes** (EAS le garde) |
| *Generate a new Apple Provisioning Profile?* | **Yes** |
| *Would you like to set up Push Notifications for your project?* | **Yes** |
| *Generate a new Apple Push Notifications service key?* | **Yes** (clé APNs : sans elle, aucune notification n'arrive sur iPhone) |
| Version distante absente (*no remote version… set it from the local project?*) | **Yes** (numéro de build 1 ; EAS l'augmente ensuite seul) |

Le build dure en général 15 à 30 minutes (plus en file gratuite). Le terminal donne un lien
vers la page du build sur expo.dev, avec ses journaux. À la fin : « Build finished » et un
fichier `.ipa`.

Si une étape des notifications a été sautée :
`npx eas-cli@latest credentials --platform ios` › *Push Notifications* › configurer, puis
refaire le build.

## Étape 3 — Créer la fiche de l'app dans App Store Connect (si elle n'existe pas)

À faire **après** le build de l'étape 2, qui a enregistré l'identifiant `fr.assokit.app`
chez Apple (sinon il n'apparaît pas dans la liste).

1. https://appstoreconnect.apple.com › **Apps** (*Mes apps*) › bouton **+** › **Nouvelle app**
   (*New App*).
2. Remplir :
   - **Plateformes** : iOS
   - **Nom** : `Assokit – Gestion asso & TPE` (voir `fiche-app-store.md` § 1 ; si le nom
     est refusé car déjà pris, `Assokit – Asso & TPE`)
   - **Langue principale** : Français (France)
   - **Identifiant de lot** (*Bundle ID*) : `fr.assokit.app`
   - **SKU** : `assokit-ios`
   - **Accès utilisateur** : Accès complet
3. **Créer**.
4. **Numéro de version.** App Store Connect crée la première version sous le numéro
   **1.0**, alors que le build porte **1.0.1** (`app.json › expo.version`, devenu
   `CFBundleShortVersionString`). Onglet **Distribution** › version en préparation (colonne
   de gauche) › champ **Version** : saisir `1.0.1`, puis **Enregistrer**. Sinon le build
   risque de ne pas être proposé dans « Ajouter un build » (étape 5.4). Si la fiche existait
   déjà (anciens builds TestFlight en 1.0.0), faire la même vérification sur la version en
   préparation.

## Étape 4 — Envoyer le build à Apple, puis le tester sur votre iPhone (TestFlight)

```bash
cd ~/Assokit/mobile
npx eas-cli@latest submit --platform ios --latest
```

- Se connecter au compte Apple si demandé ; choisir l'app **Assokit** (`fr.assokit.app`) ;
  à la question *Generate a new App Store Connect API Key?* répondre **Yes** (EAS la garde
  pour les envois suivants).
- Apple « traite » ensuite le build : **5 à 30 minutes**. Un e-mail « has completed
  processing » arrive. Le build apparaît dans **App Store Connect › Apps › Assokit ›
  TestFlight**. Aucune question de chiffrement : la réponse est déjà dans l'app
  (`ITSAppUsesNonExemptEncryption = false`).

**Test interne** (sans validation d'Apple) :

1. **TestFlight › Tests internes** (*Internal Testing*) › **+** › nom du groupe : `Équipe`.
2. **Testeurs** › **+** › cocher votre propre compte (il doit figurer dans *Utilisateurs et
   accès*).
3. **Builds** › **+** › choisir le build.
4. Sur l'iPhone : installer l'app **TestFlight** depuis l'App Store, ouvrir l'invitation
   reçue par e-mail, **Installer**.

**À vérifier sur l'iPhone avant d'aller plus loin** (15 minutes) :

- [ ] **Écran d'accueil vert** : trois atouts (« Projets, adhérents & cotisations »,
      « Devis & factures », « Comptabilité analytique incluse »), un seul bouton,
      « Se connecter », et la phrase « Accès réservé aux structures qui utilisent
      Assokit. » (**pas** de « Créer ma démo » sur iPhone). « Confidentialité » et « Conditions générales » s'ouvrent **dans l'app**, sans
      lien « Tarifs » ni « Essai gratuit » ; dans les Conditions générales, le § 4 s'appelle
      « Conditions commerciales » et ne parle ni de carte bancaire ni de Stripe ; « Retour »
      ramène à l'écran de connexion.
- [ ] **Écran de connexion** : en bas, « Pas encore de compte ? Demandez-le à
      l'administrateur de votre association ou de votre entreprise. », sans lien.
- [ ] **Connexion** avec `admin@demo-f.assokit.fr` / `DemoFormation2026!` : l'accueil
      ressemble à `captures-6.9/01-accueil.png` (bande vert foncé sous l'heure), la barre
      d'onglets reste au-dessus de la barre d'accueil de l'iPhone.
- [ ] **Notifications** : accepter la demande ; puis, depuis un ordinateur, se connecter
      sur le site avec `benevole@demo-f.assokit.fr` (même mot de passe) et écrire un
      message dans le canal « Général ». iPhone verrouillé, une notification doit arriver
      dans les 5 à 10 minutes. Si rien n'arrive : vérifier dans le cPanel d'O2Switch que la
      tâche planifiée `cron-push-notifications.php` tourne (toutes les 5 minutes), puis que
      la clé APNs existe (`npx eas-cli@latest credentials --platform ios`).
- [ ] **Face ID** : fermer complètement l'app, la rouvrir : Face ID est demandé (première
      fois : texte « Assokit utilise Face ID pour déverrouiller… »).
- [ ] **+ › Scanner une facture** : demande d'accès à l'appareil photo en français, photo,
      montants lus.
- [ ] **Plus › Paramètres › Changer le logo** (administrateur) : la première fois, iOS
      demande l'accès aux photos, en français (« Assokit accède à vos photos pour importer un
      logo ou une facture. »), puis le sélecteur s'ouvre. La demande vient de l'app elle-même
      (`requestMediaLibraryPermissionsAsync`, appelé avant `launchImageLibraryAsync` dans
      `uploadLogo` et dans le scan depuis la galerie) : sans cet appel, le sélecteur d'iOS 14+
      ne demanderait rien.
- [ ] **Plus › Paramètres** : ni carte « Formule » ni « Essai » en haut ; rubrique
      **Fonctions IA** avec un interrupteur et la phrase qui nomme Anthropic. Tout en bas, la
      ligne grise commence par `v1.0.1 (` et contient `2026-10-18-app-store` : c'est bien
      le code de ce build.
- [ ] **Première fonction IA** (Factures › « Analyser via IA », ou + › Scanner une facture) :
      la fenêtre **« Fonctions IA »** nomme Anthropic (États-Unis), avec « Refuser » et
      « Accepter ». Refuser : les boutons IA passent en gris, un appui explique comment les
      réactiver ; l'interrupteur des Paramètres les réactive. Le choix reste enregistré pour
      ce compte jusqu'à la remise à zéro de minuit de la démo.
- [ ] **Plus › Messages › Général** : **appui long** sur le message d'une autre personne →
      « Signaler ce message », « Masquer les messages de … », et, pour l'administrateur,
      « Supprimer le message ». Signaler : message de confirmation, puis le ticket
      « Signalement d'un message — #Général » apparaît dans Plus › Support (et chez vous,
      côté support). Masquer : ses messages disparaissent, un bandeau « N messages masqués ·
      Réafficher » permet de revenir en arrière.
- [ ] **Export FEC** (Plus › ouvrir une page Comptabilité du site › « Export FEC ») : la
      feuille de partage s'ouvre avec un fichier `FEC_….txt` ; « Enregistrer dans Fichiers »
      fonctionne. Même chose pour un export Excel (Clients › Export Excel).
- [ ] **Appui long sur une image** d'une page web (logo dans une facture, QR code) ›
      *Enregistrer dans Photos* : demande d'autorisation en français, **pas de plantage**.
- [ ] **Plus › Paramètres** tout en bas : « Supprimer mon compte (RGPD) » grisé avec la
      raison (seule administratrice). Avec `membre@demo-f.assokit.fr` : le bouton s'ouvre,
      la dernière étape affiche le message de compte de démonstration protégé.
- [ ] Aucun écran ne montre un prix d'Assokit, une formule, un essai, un compte à rebours
      ou un bouton d'abonnement.
- [ ] **Langue** : dans l'app TestFlight, sur la page d'Assokit, la langue indiquée (si la
      rubrique est affichée) doit être le français ; après publication, la fiche App Store
      doit indiquer « Langues : Français » (`CFBundleLocalizations` = `fr`, voir
      `conformite-ios.md` § A2). Sinon, le signaler avant la soumission suivante.

Si un écran diffère des captures, refaire la capture concernée sur l'iPhone (voir
`README-visuels.md`).

## Étape 5 — Remplir App Store Connect, rubrique par rubrique

Dans **App Store Connect › Apps › Assokit**.

### 5.1 Informations sur l'app (*App Information*, menu de gauche, rubrique Général)

- **Nom**, **Sous-titre** : `fiche-app-store.md` § 1 et 2.
- **Catégorie** : principale **Économie et entreprise**, secondaire **Productivité**.
- **Droits sur le contenu** : **Non** (`age-et-revue.md` § 3).
- **Classification par âge** : **Modifier**, réponses de `age-et-revue.md` § 1.
- **Digital Services Act** : déclarer le statut de **professionnel**, avec des coordonnées
  professionnelles vérifiées (`age-et-revue.md` § 5). Sans cela, l'app ne peut pas être
  distribuée dans l'Union européenne.
- **Enregistrer**.

### 5.2 Tarifs et disponibilité (*Pricing and Availability*)

- **Prix** : **0 (Gratuit)**.
- **Disponibilité** : **France** recommandé (l'app est en français, pensée pour la loi 1901,
  la facturation et la comptabilité françaises ; les départements et régions d'outre-mer
  font partie de la boutique France). Tous les pays sont possibles, mais sans intérêt
  aujourd'hui.
- **Mac avec puce Apple** et **Apple Vision Pro** : **décocher** (l'app n'y a pas été testée ;
  Face ID et l'appareil photo n'y fonctionnent pas de la même façon).
- **Enregistrer**.

### 5.3 Confidentialité de l'app (*App Privacy*)

- **URL de la politique de confidentialité** : `https://assokit.fr/confidentialite`.
- **Commencer** et suivre `confidentialite-app.md` (16 types de données, aucun suivi), puis
  **Publier**.

### 5.4 Page de la version (*onglet Distribution › iOS 1.0.1*)

Dans l'ordre de la page :

1. **Aperçus et captures d'écran › iPhone › 6,9"** : glisser
   `captures-6.9/01-accueil.png` … `08-coach-ia.png` dans l'ordre (ou la série
   `captures-6.9-sans-legende/`, pas les deux). Rien pour l'iPad.
2. **Texte promotionnel**, **Description**, **Mots-clés** : `fiche-app-store.md` § 3 à 5.
3. **URL d'assistance** : `https://assokit.fr/contact`. **URL marketing** : laisser vide
   (`fiche-app-store.md` § 6).
4. **Build** : vérifier d'abord que le champ **Version** vaut `1.0.1` (étape 3, point 4),
   puis **+** (ou *Ajouter un build*) › choisir le build testé à l'étape 4 › **Terminé**.
5. **Informations générales** : **Copyright** `2026 Raphaël Badr Pujol-Siwane` (titulaire des droits ; le vendeur affiché sera FOCUS POINT AGENCY, voir `attestation-autorisation.md`)
   (`fiche-app-store.md` § 8). L'icône vient du build, rien à importer.
6. **Informations pour la vérification de l'app** : connexion requise, identifiant, mot de
   passe, coordonnées et **Notes** en anglais : copier-coller depuis `age-et-revue.md` § 6.
7. **Publication de la version** (*Version Release*) : **Publier manuellement cette
   version** pour la première fois (vous choisissez le jour, après un dernier contrôle) ;
   « automatiquement » conviendra pour les suivantes.
8. **Enregistrer** (en haut à droite).

## Étape 6 — Soumettre

1. En haut de la page de version : **Ajouter pour vérification** (*Add for Review*).
2. App Store Connect liste ce qui manque éventuellement ; corriger, puis **Soumettre pour
   vérification** (*Submit to App Review*).
3. Statuts successifs : *En attente de vérification* → *En cours de vérification* →
   *En attente de publication par le développeur* (publication manuelle) ou *Prêt à la
   distribution*.
4. Délai habituel : **24 à 48 heures** (souvent moins). Apple écrit à l'adresse du compte à
   chaque changement.

## Étape 7 — Si Apple refuse

1. **App Store Connect › Apps › Assokit** : le message d'Apple est dans la rubrique **App
   Review** (Centre de résolution, *Resolution Center*). Il cite une règle (« Guideline
   3.1.1 », « 5.1.1(v) »…) et souvent une capture.
2. Répondre **en anglais**, poliment, factuellement, dans ce même fil. Modèles plus bas.
3. Selon la cause :
   - **problème côté serveur** (texte d'une page, compte de démo) : corriger, `git pull` sur
     O2Switch, répondre « fixed on our server », sans nouveau build ;
   - **problème dans `App.js`** : corriger, puis **nouveau build** (étapes 2 et 4) et
     choisir ce build dans la page de version. Une mise à jour OTA ne suffit pas pour la
     revue : l'app télécharge la mise à jour au lancement mais ne l'applique qu'au
     lancement suivant (`fallbackToCacheTimeout: 0`), l'examinateur ne la verrait pas ;
   - **réglage natif** (`app.json`) : nouveau build obligatoire.
4. Puis **Soumettre à nouveau** (*Resubmit*). En cas de désaccord persistant : formulaire
   d'appel à l'*App Review Board* (lien dans le message de refus).

**Modèles de réponse (anglais)**

*3.1.1 / 3.1.3 (achats)*

```
Thank you for your review. Assokit is a free companion app for the organisations
(associations and small businesses) that use our web service. Nothing can be bought
in the app: it has no sign-up, and shows no price, no plan or trial status, no
purchase button and no link to a purchase page.
Organisations arrange their Assokit plan with us outside the app, which we understand
is allowed for free stand-alone companion apps (3.1.3(f)) and services sold to
organisations (3.1.3(c)). Could you tell us which screen looked like a purchase flow?
We will remove it right away.
```

*5.1.1(v) (suppression de compte)*

```
Account deletion is in the app: Plus > Paramètres > "Supprimer mon compte (RGPD)" at the
bottom of the screen > password > "Supprimer définitivement". Personal data is
anonymised immediately and the user is signed out.
The admin demo account is the organisation's only administrator, so the button is
disabled with the reason shown. The member demo account membre@demo-f.assokit.fr
(password DemoFormation2026!) shows the whole flow, but its last step is disabled
because the account is shared by reviewers. On a real account, the deletion is
immediate.
```

*1.2 (contenus des utilisateurs)*

```
Team messages are private to one organisation. In Plus > Messages, long-press any
message: "Signaler ce message" reports it to our team (it opens a support ticket we
review), "Masquer les messages de ..." hides everything that person writes, and an
administrator can delete any message. Administrators can also deactivate or remove a
member, and our terms forbid unlawful or abusive content.
```

*5.1.2(i) (IA)*

```
Before any content is sent to our AI provider, the app shows a consent prompt that
names Anthropic (United States) and what is sent; nothing is sent if the user refuses,
and the AI buttons are then disabled. The choice can be changed at any time in
Plus > Paramètres > Fonctions IA. Our server also refuses AI requests from the app
without this consent.
```

*4.2 (fonctionnalités minimales)*

```
Most of Assokit is built with native screens: home dashboard, projects with steps and
budget, members, invoices with creation and AI analysis, quotes, clients, statistics,
calendar, membership fees, grants, team messaging, a shared mailbox sorted by AI,
notifications, settings, support, a quick "+" creation menu, invoice scanning with the
camera, Face ID unlock and push notifications. The web view is only used for advanced
features.
```

*2.1 (connexion impossible)* : vérifier d'abord que la démo répond (étape 0, point 3) ;
après 10 erreurs de mot de passe, l'adresse IP de l'examinateur est bloquée 15 minutes.

```
We have checked the demo account again and it works: admin@demo-f.assokit.fr /
DemoFormation2026! (please mind the capital D and F and the final "!"). After ten failed
attempts our server pauses sign-in from the same network for 15 minutes; please try
again after that delay.
```

## Étape 8 — Publication

Statut *En attente de publication par le développeur* : page de version › **Publier cette
version**. L'app apparaît sur l'App Store en quelques heures (jusqu'à 24 h selon les pays).
Lien public : App Store Connect › *Informations sur l'app* › *Afficher sur l'App Store*.

---

## Étape 9 — Après la publication : Android et iPhone sur la même base

Le passage à React Native 0.86.3 (`conformite-ios.md` § A4) est un changement natif. Des
binaires **1.0.0** ont déjà été distribués (TestFlight, et peut-être un test interne Android),
compilés avec une version antérieure de React Native. Avec `runtimeVersion: appVersion`, un
binaire 1.0.0 **accepterait** une mise à jour OTA compilée pour 1.0.0 avec le JavaScript de
0.86.3 : JavaScript et code natif ne correspondraient plus, ce qui peut faire planter l'app ou
la ramener au code embarqué. C'est pourquoi `app.json › expo.version` est passé à **1.0.1** le
18/10/2026 : les builds 1.0.1 (iPhone **et** Android) ont leur propre version d'exécution, et
les anciens binaires 1.0.0 ne reçoivent plus aucune mise à jour OTA nouvelle (ils gardent la
dernière reçue). Remplacer ces anciens builds de test par les builds 1.0.1. Le build Android
de production doit lui aussi être fait **depuis ce même état du dépôt** (voir
`../google-play/publication-android.md`).

## Étape 10 — Les mises à jour

**Changement de JavaScript seulement** (`App.js`, textes, écrans, corrections) : pas de
build, pas de nouvelle vérification d'Apple.

```bash
cd ~/Assokit/mobile
npx eas-cli@latest update --channel production --message "Ce qui change"
```

(Équivalent : `--branch production`, la branche lue par le canal `production`.) La mise à
jour part vers l'iPhone **et** Android, sauf `--platform ios` ou `--platform android`.
Elle s'installe au lancement suivant de l'app. Elle doit rester dans l'objet de l'app
(règle 2.5.2) : pas de nouvelle fonction qui changerait sa nature.

**Changement natif** (`app.json › ios` ou `android`, autorisations, nouveau module, icône,
écran de lancement, mise à jour d'Expo ou de React Native) : l'OTA n'y suffit pas.

1. `app.json › expo.version` : passer à la version suivante, `1.0.2` (la version
   d'exécution suit la version : les anciens binaires ne recevront pas un JavaScript qui
   appellerait du code natif absent).
2. `npx eas-cli@latest build --platform ios --profile production` puis
   `npx eas-cli@latest submit --platform ios --latest` (idem Android).
3. App Store Connect › Apps › Assokit › **+** à côté de « iOS » › **Nouvelle version** `1.0.2` ›
   remplir « Nouveautés » (`fiche-app-store.md` § 9), choisir le build › **Ajouter pour
   vérification**.

Le **texte promotionnel** et la rubrique **Confidentialité de l'app** se modifient à tout
moment sans nouvelle version.
