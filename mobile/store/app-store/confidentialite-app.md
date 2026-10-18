# « Confidentialité de l'app » — réponses pour App Store Connect

App Store Connect › **Apps › Assokit › Confidentialité de l'app** (menu de gauche, rubrique
*Confiance et sécurité* ou *Général* selon la version de la console ; en anglais **App
Privacy**) › **Commencer** (ou **Modifier**).

Ces réponses forment l'« étiquette de confidentialité » affichée sur la fiche App Store.
Elles reprennent, catégorie par catégorie, l'analyse du code faite pour Google Play
([`../google-play/securite-des-donnees.md`](../google-play/securite-des-donnees.md),
section « Ce qui a été vérifié »), traduite dans la nomenclature d'Apple, qui n'est pas la
même. Ce qui a été revérifié pour iOS le 18/10/2026 :

| Source | Constat |
|---|---|
| `mobile/package.json` + `package-lock.json` | Aucun SDK de publicité, de mesure d'audience, de rapport de plantage ou de suivi. Aucun appel à l'identifiant publicitaire (IDFA), pas d'App Tracking Transparency. |
| Fichiers `PrivacyInfo.xcprivacy` des modules (téléchargés depuis npm aux versions du lockfile) | `expo-application`, `expo-constants`, `expo-file-system`, `expo-notifications`, React Native : tous déclarent `NSPrivacyTracking = false` et **aucune donnée collectée**. |
| `mobile/App.js` | Données envoyées seulement à `https://assokit.fr` (API `api/app-*.php` et WebView). Jeton de notifications envoyé à `api/app-register-push.php`. Identifiants de connexion gardés dans le trousseau de l'iPhone (`expo-secure-store`) pour Face ID, jamais envoyés ailleurs qu'à la connexion. Liens externes ouverts dans Safari. |
| `expo-updates` | Envoie à Expo (prestataire) un identifiant d'installation aléatoire (`EAS-Client-ID`) avec chaque recherche de mise à jour ; Expo s'en sert pour livrer les mises à jour et compter les installations qui les reçoivent. |
| `activity-tracker.php` | Journal serveur des pages et appels d'API des comptes connectés (chemin avec ses paramètres, IP, navigateur, date), 12 mois : sécurité et statistiques d'usage (`fondateur-activity.php`). |
| `cron-email-templates.php`, `cron-trial-check.php` | E-mails de fin de période d'essai envoyés à l'adresse du compte, avec un bouton vers les formules (hors de l'app : permis par la règle 3.1.3). C'est de la communication commerciale : finalité « marketing du développeur » sur l'adresse e-mail. La politique de confidentialité le dit depuis le 18/10/2026 (§ 3, intérêt légitime, opposition possible). |
| `ai-consent-helper.php`, `api/app-ai-consent.php` | Depuis le 18/10/2026, aucune fonction IA n'envoie quoi que ce soit à Anthropic depuis l'app sans l'accord de l'utilisateur, demandé dans une fenêtre native qui nomme Anthropic (règle 5.1.2(i)). Ce n'est pas une donnée de plus : l'accord porte sur des contenus déjà déclarés (photos, contenu utilisateur, e-mails). |

**Le manifeste de confidentialité de l'app** (`mobile/app.json › ios.privacyManifests ›
NSPrivacyCollectedDataTypes`) déclare **exactement** la même liste que ci-dessous.
Si l'on change l'une, changer l'autre.

---

## Étape 1 — Collecte

**« Est-ce que vous ou vos partenaires tiers collectez des données à partir de cette
app ? »** → **Oui, nous collectons des données à partir de cette app.**

(Au sens d'Apple, « collecter » = envoyer hors de l'iPhone et conserver plus longtemps
que le temps de répondre à la requête. Les données saisies dans la WebView comptent.)

## Étape 2 — Types de données à cocher

Cocher **exactement** ces 16 cases (libellé anglais entre parenthèses) :

- **Coordonnées** (*Contact Info*) : ☑ Nom (*Name*) · ☑ Adresse e-mail (*Email Address*) ·
  ☑ Numéro de téléphone (*Phone Number*) · ☑ Adresse physique (*Physical Address*)
- **Informations financières** (*Financial Info*) : ☑ Informations de paiement (*Payment
  Info*) · ☑ Autres informations financières (*Other Financial Info*)
- **Achats** (*Purchases*) : ☑ Historique des achats (*Purchase History*)
- **Contacts** (*Contacts*) : ☑ Contacts
- **Contenu utilisateur** (*User Content*) : ☑ E-mails ou SMS (*Emails or Text Messages*) ·
  ☑ Photos ou vidéos (*Photos or Videos*) · ☑ Service client (*Customer Support*) ·
  ☑ Autre contenu utilisateur (*Other User Content*)
- **Historique des recherches** (*Search History*) : ☑
- **Identifiants** (*Identifiers*) : ☑ Identifiant utilisateur (*User ID*) · ☑ Identifiant
  de l'appareil (*Device ID*)
- **Données d'utilisation** (*Usage Data*) : ☑ Interaction avec le produit (*Product
  Interaction*)

Laisser **décoché** : Santé, Forme, Solvabilité (*Credit Info*), Localisation précise et
approximative, Informations sensibles, Historique de navigation, Données audio, Contenu de jeu, Autres coordonnées, Données publicitaires, Autres données
d'utilisation, Données de plantage, Données de performances, Autres données de
diagnostic, Analyse de l'environnement, Mains, Tête, Autres types de données.

**Enregistrer.**

## Étape 3 — Pour chaque type coché

App Store Connect pose trois questions par type. **Pour les 16 types** :

- **Utilisées pour vous suivre (*tracking*) ?** → **Non.** Aucune donnée n'est reliée à
  des données d'autres entreprises pour de la publicité, ni transmise à un courtier.
- **Liées à l'identité de l'utilisateur ?** → **Oui.** Tout est rattaché au compte
  Assokit (le serveur relie chaque donnée à un utilisateur et à son association).
- **Finalités** : celles du tableau. Ne cocher **nulle part** « Publicité tierce » ni
  « Personnalisation du produit ».

| Type (Apple) | Finalités à cocher | Ce que c'est dans Assokit |
|---|---|---|
| **Nom** | Fonctionnalité de l'app | Prénom et nom du compte (inscription, profil) |
| **Adresse e-mail** | Fonctionnalité de l'app · **Publicité ou marketing du développeur** | Identifiant de connexion, e-mails de service (vérification, mot de passe, factures) **et** e-mails de fin de période d'essai qui présentent les formules (voir plus haut) |
| **Numéro de téléphone** | Fonctionnalité de l'app | Téléphone demandé à l'inscription, profil |
| **Adresse physique** | Fonctionnalité de l'app | Adresse de la structure demandée à l'inscription, adresses de facturation |
| **Informations de paiement** | Fonctionnalité de l'app | **IBAN et BIC de la structure**, saisis dans les réglages des factures et des cotisations (pages ouvertes dans l'app), imprimés sur ses factures et appels à cotisation. Aucune carte bancaire. Apple cite « numéro de compte bancaire » dans sa définition : on déclare, comme sur Google Play. |
| **Autres informations financières** | Fonctionnalité de l'app | Factures, devis, budgets, dépenses, notes de frais, cotisations reçues, subventions, écritures comptables |
| **Historique des achats** | Fonctionnalité de l'app | Dépenses et notes de frais saisies (achats de la structure), cotisations réglées par chaque adhérent (qui a payé, quand, combien). Déclaré le 18/10/2026 pour s'aligner sur Google Play, qui le coche : déclarer plus n'est jamais reproché, déclarer moins peut l'être. Rien n'est acheté dans l'app. |
| **Contacts** | Fonctionnalité de l'app | Carnet d'adresses de l'association (adhérents, clients, prospects) **saisi ou importé par l'utilisateur**. L'app **ne lit jamais** les contacts de l'iPhone (aucune clé `NSContactsUsageDescription`). Coché parce qu'Apple définit les Contacts comme une « liste de contacts… ou carnet d'adresses », et que la politique de confidentialité parle de « contacts » téléversés. |
| **E-mails ou SMS** | Fonctionnalité de l'app | Messages des canaux de l'équipe, boîte mail Gmail de la structure si un administrateur la relie, e-mailings rédigés |
| **Photos ou vidéos** | Fonctionnalité de l'app | Photo d'une facture à scanner (lue par l'IA, sous-traitant Anthropic, seulement après l'accord donné dans l'app), logo de l'organisation. Photos seulement : sélecteur limité aux images, micro désactivé. |
| **Service client** | Fonctionnalité de l'app | Tickets ouverts dans Plus › Support |
| **Autre contenu utilisateur** | Fonctionnalité de l'app | Projets, étapes, comptes rendus, agenda, documents, textes rédigés avec l'IA |
| **Historique des recherches** | Fonctionnalité de l'app | Les recherches faites dans les pages du site passent dans l'adresse de la page, donc dans le journal de sécurité |
| **Identifiant utilisateur** | Fonctionnalité de l'app · **Analyses** | Identifiant interne du compte ; il figure dans le journal d'activité qui sert aussi aux statistiques d'usage |
| **Identifiant de l'appareil** | Fonctionnalité de l'app · **Analyses** | Jeton de notifications (Expo / service de notifications d'Apple), identifiant d'installation aléatoire d'`expo-updates`, qu'Expo compte pour ses statistiques de mises à jour |
| **Interaction avec le produit** | Fonctionnalité de l'app · **Analyses** | Journal serveur des pages et écrans consultés (sécurité, et statistiques d'usage). La politique de confidentialité dit depuis le 18/10/2026 « statistiques d'utilisation tirées du journal d'activité (12 mois) », et non plus « anonymisées » : ces données sont liées au compte. |

Rappel des définitions d'Apple : « Fonctionnalité de l'app » couvre aussi l'authentification,
la sécurité, la prévention des fraudes et le support. « Analyses » = mesurer l'usage de
l'app. « Personnalisation du produit » = adapter ce que voit l'utilisateur à partir de ses
données : le radar des subventions filtre selon le profil déclaré de l'association, c'est la
fonction elle-même (Fonctionnalité de l'app), pas une personnalisation.

**Publier** (bouton en haut à droite de la page Confidentialité de l'app).

## Résultat attendu sur la fiche

- **Données utilisées pour vous suivre** : aucune.
- **Données associées à vous** : Coordonnées, Informations financières, Achats, Contacts,
  Contenu utilisateur, Historique des recherches, Identifiants, Données d'utilisation.
- **Données non associées à vous** : aucune.

## URL demandées dans cette rubrique

| Champ | Valeur |
|---|---|
| URL de la politique de confidentialité (obligatoire) | `https://assokit.fr/confidentialite` |
| URL des choix de confidentialité de l'utilisateur (facultative) | `https://assokit.fr/suppression-compte` (export et suppression des données) — ou laisser vide |

---

## Ce qui n'est pas déclaré, et pourquoi

- **Localisation** : aucune permission de localisation. L'adresse IP est journalisée pour la
  sécurité, sans en déduire de position.
- **Informations sensibles** : Assokit ne demande ni religion, ni opinion, ni santé, ni
  appartenance syndicale. La biométrie (Face ID) est vérifiée par iOS, l'app reçoit
  seulement « oui / non ».
- **Diagnostics** (plantages, performances) : aucun SDK de rapport de plantage. Les rapports
  que l'utilisateur partage avec Apple sont collectés par Apple, pas par l'app.
- **Historique de navigation** : la WebView n'affiche que `assokit.fr` (le parcours dans
  l'app est déclaré en « Interaction avec le produit ») ; les autres sites s'ouvrent dans
  Safari, hors de l'app.
- **Données audio, vidéos** : micro désactivé (`microphonePermission: false`), sélecteur
  limité aux images.
- **Données publicitaires** : aucune publicité.
- **Google Analytics** : chargé seulement sur le site public, avec consentement, et
  **jamais dans l'app** (`includes-public.php`, agent « AssokitApp » exclu). Les liens
  « Confidentialité » et « Conditions générales » de l'écran d'accueil s'ouvrent
  désormais dans l'app (modification du 18/10/2026), donc sans Google Analytics non plus.

## Tiers qui reçoivent des données (pour mémoire)

Apple ne demande pas la liste des sous-traitants, mais compte leur collecte comme celle de
l'app. Tous sont des prestataires agissant pour Assokit, aucun ne fait de suivi :
O2Switch (hébergement, France), Resend (e-mails, UE), Anthropic (IA : scan des factures,
rédaction, tri des e-mails), Expo (notifications et mises à jour), Apple (acheminement des
notifications), Google (Gmail et Google Agenda, seulement si un administrateur les relie),
Stripe (paiements **sur le site uniquement**, jamais dans l'app).

## À garder aligné

Alignés le 18/10/2026 sur la déclaration Apple, qui en dit le plus : adresse e-mail aussi pour
le marketing du développeur (Google Play : « Publicité ou marketing » ajouté) ; identifiant
utilisateur, identifiant de l'appareil et interactions aussi pour les analyses (Google Play :
« Analyses » ajouté aux deux premiers) ; historique des achats déclaré des deux côtés (ajouté
ici et dans le manifeste) ; politique de confidentialité § 2, 3, 4 et 5 corrigée en conséquence.

- [ ] Cette page, `mobile/app.json › ios.privacyManifests`, `../google-play/securite-des-donnees.md`
      et `https://assokit.fr/confidentialite` disent la même chose.
- [ ] Ajout d'un SDK (Sentry, Firebase Analytics…) : **mettre à jour cette rubrique et le
      manifeste avant** d'envoyer le build qui l'embarque. Apple peut refuser une app dont
      l'étiquette ne correspond pas au comportement réel.
- [ ] Toute modification de cette rubrique est publiée immédiatement, sans nouvelle
      version de l'app.
