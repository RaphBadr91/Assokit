# Publier Assokit sur Google Play — guide pas à pas

Pour : le fondateur, sur le Mac. Application : `fr.assokit.app`, Expo SDK 57, version **1.0.1** (passée de 1.0.0 à 1.0.1 le 18/10/2026, voir l'étape 9), profil EAS `production` (fichier `.aab`).

Les autres fichiers de ce dossier contiennent les textes à coller :

| Fichier | Contenu |
|---|---|
| `fiche-google-play.md` | Nom, descriptions, notes de version, catégorie, coordonnées, URL de confidentialité |
| `securite-des-donnees.md` | Formulaire « Sécurité des données », case par case |
| `contenu-de-l-application.md` | Accès (comptes de démo), annonces, classification, public cible, fonctionnalités financières, etc. |
| `icon-512.png` | Icône 512 × 512 pour la fiche |
| `feature-graphic-1024x500.png` | Image de présentation 1024 × 500 (obligatoire) |
| `captures/` (ou `captures-sans-legende/`) | 8 captures téléphone 1080 × 1920, à importer dans l'ordre 01 → 08 (une seule des deux séries) |
| `README-visuels.md` | Détail des visuels, et ce qu'il faut revérifier sur un vrai téléphone Android |

Tous les éléments graphiques sont prêts.

Les libellés de la Play Console changent de temps en temps : si un bouton ne porte pas exactement le nom indiqué, cherchez le plus proche au même endroit.

---

## Étape 0 — Mettre le site à jour (avant tout envoi à Google)

Ces modifications du site sont actives **tout de suite**, sur Android comme sur iPhone, sans build ni OTA, dès qu'elles sont en ligne ; Google vérifiera ces pages :

- `suppression-compte.php` (nouvelle page publique `https://assokit.fr/suppression-compte`) + route dans `.htaccess` + `sitemap-pages.xml.php` ;
- `confidentialite.php` et `cgu.php` (durées de conservation alignées sur le code : compte supprimé par la personne, compte retiré par un administrateur, fermeture de l'espace d'une association ; paragraphe « Application mobile », sous-traitant Expo) ;
- `includes-public.php` : dans l'application, les pages de présentation du site, qui affichent le prix (accueil, fonctionnalités, pages « logiciel-… », guides, comparatifs, blog…), renvoient vers `/connexion` ; les pages légales, la suppression de compte et le contact gardent un en-tête et un pied de page sans lien vers elles ; lien « Suppression de compte » dans le pied de page ; Google Analytics jamais chargé dans l'application ;
- `signup.php` : dans l'application, plus de prix après l'essai (« Aucun achat dans l'application ») ;
- `app-context.php`, `api/app-delete-account.php`, `rgpd-supprimer-compte.php`, `cron-rgpd-purge.php` : la suppression de son compte efface aussi le mot de passe, la double authentification, les notes d'administrateur, le contrat, les dates d'adhésion et les liens d'agenda ; message explicite sur les comptes de démonstration.

Une fois ces modifications commitées et poussées sur GitHub :

```bash
ssh pura7044@ton-serveur.o2switch.net
cd ~/public_html && git pull origin claude/new-session-36m7pw
```

Puis vérifier dans un navigateur **non connecté** (fenêtre privée) :

1. `https://assokit.fr/suppression-compte` s'affiche ;
2. `https://assokit.fr/confidentialite` contient « Application mobile », « Expo » et, en section 4, « Compte que vous supprimez vous-même » ;
3. le pied de page contient le lien « Suppression de compte ».

Puis, dans le terminal du Mac, que l'application ne voit **aucun prix** (chaque ligne doit finir par `0`) :

```bash
for u in / /signup /connexion /fonctionnalites /logiciel-association /logiciel-adherents \
         /logiciel-comptabilite-association /logiciel-gestion-tpe /logiciel-association-gratuit \
         /comptabilite-analytique /alternative-helloasso /guide-association-loi-1901 \
         /guide-comptabilite-association /suppression-compte /cgu /confidentialite /contact; do
  printf '%-40s ' "$u"; curl -sL -A 'Mozilla/5.0 AssokitApp/1.0' "https://assokit.fr$u" | grep -c '49,99'
done
```

(Sur le banc local, le 13/10/2026 : 0 partout avec l'agent de l'application ; le navigateur, lui, voit toujours le prix sur l'accueil, `/signup` et les pages de présentation.)

Deux points à trancher par vous :

- les CGU (§ 12 de `cgu.php`) prévoient d'annoncer par e-mail toute modification substantielle 30 jours à l'avance. La nouvelle rédaction du § 8 décrit ce que fait déjà le code (anonymisation immédiate quand on supprime son compte, au lieu des « 30 jours pour exporter » écrits jusqu'ici) : à vous de juger s'il faut l'annoncer aux clients ;
- la **fermeture de l'espace d'une association** (30 jours pour exporter, puis suppression, sauf pièces comptables) reste une opération manuelle, faite par vous à la demande : aucun script ne l'automatise.

Vérifier aussi, une fois, que la purge RGPD tourne vraiment en production : `cron-rgpd-purge.php` ne supprime rien tant que `define('AK_RGPD_PURGE_LIVE', true);` n'est pas dans le `config.php` du serveur et que la tâche n'est pas planifiée dans le cPanel. Sans cela, les durées annoncées (corbeille 30 jours, journaux 12 mois) ne sont pas appliquées automatiquement.

---

## Étape 1 — Le compte développeur Play Console

### 1.1 Personnel ou organisation ?

Sur https://play.google.com/console/signup (frais d'inscription uniques de 25 $), Google propose deux types de compte :

| | Compte **personnel** | Compte **organisation** |
|---|---|---|
| Pour qui | Une personne | Une entreprise immatriculée |
| Pièces demandées | Pièce d'identité, téléphone, e-mail | **Numéro D-U-N-S** de l'entreprise (gratuit, à demander à Dun & Bradstreet, compter plusieurs jours), site web, téléphone et e-mail vérifiés |
| Nom affiché sur la fiche | Le vôtre | Celui de l'entreprise (ex. RBPS) |
| **Test fermé obligatoire** | **Oui** si le compte a été créé après le 13 novembre 2023 : au moins **12 testeurs inscrits pendant 14 jours consécutifs** avant de pouvoir demander l'accès à la production | **Non** |

**Recommandation** : si RBPS a un SIREN et que vous pouvez obtenir un D-U-N-S, le compte organisation évite les 14 jours de test fermé et affiche « RBPS » comme développeur. Sinon, compte personnel : c'est faisable, il faut juste lancer le test fermé tôt (étape 6).

Si le compte existe déjà : Play Console › ⚙️ **Paramètres** › **Compte de développeur** › **Informations sur le compte** indique son type.

### 1.2 Terminer les vérifications

Play Console › tableau de bord du compte : terminer **toutes** les vérifications demandées (identité, téléphone, adresse e-mail de contact). Tant qu'elles ne sont pas faites, la création d'application ou la publication peut être bloquée.

**Compte personnel** : Google demande aussi de prouver l'accès à un **appareil Android physique**. Installer l'application **Google Play Console** sur votre téléphone Android, s'y connecter avec le compte développeur et suivre la vérification proposée dans le tableau de bord. Gardez ce téléphone sous la main : c'est aussi lui qui servira au test interne (étape 5).

---

## Étape 2 — Créer l'application

Play Console › **Toutes les applications** › **Créer une application** :

| Champ | Valeur |
|---|---|
| Nom de l'application | `Assokit – Gestion asso & TPE` (modifiable ensuite) |
| Langue par défaut | **Français (France) – fr-FR** |
| Application ou jeu | **Application** |
| Gratuite ou payante | **Gratuite** (définitif : une application gratuite ne peut plus devenir payante — c'est ce qu'il faut, aucun achat ne se fait dans l'application) |
| Déclarations | Cocher les **Règles du programme pour les développeurs** et les **lois américaines sur l'exportation** |

**Créer l'application**.

---

## Étape 3 — Remplir le tableau de bord (dans cet ordre)

Tableau de bord de l'application › section **« Configurer votre application »** › chaque ligne ouvre un formulaire. Textes à coller : fichiers indiqués.

1. **Définir les règles de confidentialité** → `https://assokit.fr/confidentialite`
2. **Accès à l'application** → `contenu-de-l-application.md` § 2 (deux comptes de démo)
3. **Annonces** → Non
4. **Classification du contenu** → `contenu-de-l-application.md` § 5
5. **Public cible** → 18 ans et plus uniquement
6. **Applications d'actualités** → Non
7. **Applications COVID-19** → Non
8. **Sécurité des données** → `securite-des-donnees.md`
9. **Applications gouvernementales** → Non
10. **Fonctionnalités financières** → Aucune
11. **Applications de santé** → Aucune
12. **Identifiant publicitaire** (si la carte apparaît) → Non
13. **Sélectionner une catégorie d'application et fournir les coordonnées** → `fiche-google-play.md` § 6 et § 7
14. **Configurer la fiche Play Store** → `fiche-google-play.md` § 1 à 5 (nom, descriptions, icône, image de présentation, captures)

Les éléments graphiques sont prêts : `icon-512.png`, `feature-graphic-1024x500.png` et les 8 captures de `captures/` (ou de `captures-sans-legende/`). Avant d'envoyer la fiche en examen, comparez-les au rendu réel du build de test interne (étape 5 et `README-visuels.md`).

---

## Avant l'étape 4 — Corrections natives (déjà faites dans le code, à vérifier)

Ces éléments sont **natifs** : ils n'arrivent pas par OTA, il faut qu'ils soient dans le tout premier `.aab`.

- [ ] **Icône adaptative** : `mobile/assets/adaptive-icon.png` est un rond blanc sur fond transparent, réduit dans la zone sûre, et `app.json › android.adaptiveIcon` déclare aussi `monochromeImage` (icônes à thème d'Android 13 et plus). Le lanceur montrera le même dessin que `icon-512.png`.
- [ ] **Zones sûres** : `react-native-safe-area-context` est dans `mobile/package.json` (version `~5.7.0`, celle d'Expo SDK 57) ; `npm install` doit l'installer. L'application lit la hauteur réelle de la barre d'état et de la barre de navigation Android (3 boutons ou gestes).
- [ ] `git pull` fait sur le Mac **après** ces modifications (voir étape 4).

---

## Étape 4 — Construire le `.aab` sur le Mac

```bash
cd ~/Assokit/mobile
git pull
npm install
npx eas-cli@latest build --platform android --profile production
```

- Au **premier** build Android, EAS demande : *Generate a new Android Keystore?* → **Yes**. EAS garde cette clé (c'est la « clé d'importation ») ; ne la supprimez jamais de votre compte Expo.
- `eas.json` est déjà prêt : `buildType: app-bundle`, `autoIncrement: true` et `appVersionSource: remote` → EAS gère le `versionCode` tout seul (1, 2, 3…). Le nom de version affiché est `1.0.1` (`app.json › version`).
- Le build tourne dans le cloud Expo. À la fin, le terminal affiche un lien.

Télécharger le fichier : **https://expo.dev** › compte › projet **assokit** › **Builds** › le build **Android** le plus récent › **Download** (fichier `.aab`).

Ce build contient déjà :
- le fichier Firebase `google-services.json` (suivi par git, donc envoyé à EAS) pour les notifications Android ;
- le blocage de la permission `AD_ID` (`app.json › android.blockedPermissions`), cohérent avec la réponse « pas d'identifiant publicitaire » ;
- le bouton « Se connecter avec la biométrie » (le libellé « Face ID », marque d'Apple, n'apparaît plus que sur iPhone) ;
- l'icône adaptative refaite et le module des zones sûres (voir plus haut) ;
- la barre d'état teintée sur Android (vert foncé sur l'accueil, au lieu d'icônes blanches sur fond blanc) et le bas des écrans au-dessus de la barre de navigation ;
- les liens **Politique de confidentialité**, **Supprimer mon compte : aide** et **Conditions générales** dans Plus › Paramètres, et « Confidentialité · Conditions générales » sous le formulaire de connexion et sur l'écran d'accueil ;
- le blocage, dans l'application, des pages de présentation du site (en plus du renvoi fait par le serveur) ;
- la colonne horaire de l'agenda élargie (« Journée » sur une ligne).

---

## Étape 5 — Premier envoi, à la main, en test interne

Google exige que le **tout premier** `.aab` soit envoyé à la main dans la Console : l'envoi automatique (`eas submit`) ne fonctionne qu'ensuite.

1. Play Console › l'application › menu de gauche **Tester et publier** › **Tests** › **Test interne**.
2. Onglet **Testeurs** › **Créer une liste d'adresses e-mail** › nom « Équipe Assokit » › ajoutez votre adresse Gmail (celle de votre téléphone Android) › **Enregistrer**. Cochez la liste › **Enregistrer**.
3. Onglet **Releases** › **Créer une release**.
4. Encadré **Signature d'application par Google Play** : **Choisir une clé de signature** › **Utiliser une clé générée par Google** (choix par défaut, recommandé) › confirmer. Google signera l'application avec sa propre clé ; la clé EAS ne sert qu'à l'importation. En cas de perte de la clé EAS, Google peut la réinitialiser — sans Play App Signing, ce serait impossible.
5. **App bundles** › **Importer** › choisir le `.aab` téléchargé.
6. **Nom de la release** : laisser la valeur proposée (par exemple `1 (1.0.1)` ; le premier nombre est le `versionCode` donné par EAS).
7. **Notes de version** : coller le bloc de `fiche-google-play.md` § 4.
8. **Suivant**. L'écran de vérification peut afficher des **avertissements** (ex. « aucun fichier de désobscurcissement ») : ce ne sont pas des erreurs, on peut continuer. Une **erreur** (en rouge) bloque : la lire et la corriger.
9. **Enregistrer** › **Lancer le déploiement en test interne** (ou **Envoyer les modifications pour examen** selon l'écran).
10. Onglet **Testeurs** › **Copier le lien** (« Comment les testeurs rejoignent le test »). Ouvrir ce lien **sur le téléphone Android**, avec le même compte Google › **Devenir testeur** › **Télécharger sur Google Play**.

Le test interne est disponible en quelques minutes, sans examen complet.

### Ce qu'il faut vérifier sur le téléphone (avant d'aller plus loin)

- [ ] Connexion avec `admin@demo-f.assokit.fr` / `DemoFormation2026!`, puis avec `membre@demo-f.assokit.fr`.
- [ ] Barre d'état : sur l'accueil, bande vert foncé avec heure et icônes **blanches** ; ailleurs, fond blanc avec icônes sombres. Comparer à `captures/01-accueil.png`.
- [ ] Bas d'écran, en **navigation à 3 boutons** puis en **navigation par gestes** (Paramètres du téléphone › Système › Navigation) : barre d'onglets, bouton « + » et sa feuille, boutons « Enregistrer » des formulaires, zone de saisie des messages, tout reste au-dessus des boutons système.
- [ ] Icône sur l'écran d'accueil du téléphone : rond blanc entier en bas à droite, comme `icon-512.png`.
- [ ] Plus › Paramètres › **Politique de confidentialité** ouvre la politique dans l'application ; « Retour » ramène aux Paramètres. Sous le formulaire de connexion, « Confidentialité » ouvre la page dans le navigateur.
- [ ] Agenda : un événement « Journée » s'affiche sur une ligne.
- [ ] **+ › Scanner une facture** : Android demande l'appareil photo ; la photo est lue.
- [ ] Notifications : accepter la demande au lancement ; envoyer une notification de test (voir 8.3).
- [ ] Déconnexion, puis reconnexion par empreinte (bouton « Se connecter avec la biométrie »).
- [ ] Bouton retour Android : revient à l'écran précédent sans quitter l'application.
- [ ] Plus › Paramètres › « Supprimer mon compte (RGPD) » : grisé avec la raison pour l'admin, actif pour le membre ; la dernière étape affiche « Compte de démonstration partagé : sa suppression est désactivée… Sur un vrai compte, cette étape anonymise immédiatement les données personnelles et ferme la session. »
- [ ] Aucune page d'abonnement, aucun prix, aucun bouton « S'abonner » nulle part — y compris en partant de **Créer ma démo** (`/signup`) et en touchant le logo, les liens du pied de page ou les liens CGU / confidentialité.
- [ ] Faire les **captures d'écran** pour la fiche (bouton marche + volume bas).

---

## Étape 6 — Test fermé (obligatoire pour un compte personnel)

À lancer **dès que le test interne fonctionne** : c'est le délai le plus long.

1. **Tester et publier** › **Tests** › **Test fermé** › piste **Alpha** (créée par défaut) › **Gérer la piste**.
2. Onglet **Pays/régions** › **Ajouter des pays/régions** › **France** (+ ceux de vos testeurs s'ils sont ailleurs).
3. Onglet **Testeurs** — deux façons, au choix :
   - **Liste d'adresses e-mail** : créer une liste et y mettre les **adresses Gmail** (ou comptes Google) des testeurs ;
   - **Groupe Google** (plus simple pour ajouter du monde ensuite) : créer un groupe sur https://groups.google.com (ex. `assokit-testeurs@googlegroups.com`), y inviter les testeurs, puis saisir l'adresse du groupe dans la Console.
   
   Renseigner aussi l'**adresse e-mail ou l'URL de commentaires** (`contact@assokit.fr`). **Enregistrer**.
4. Onglet **Releases** › **Créer une release** › **Ajouter depuis la bibliothèque** › choisir le `.aab` déjà envoyé en test interne › notes de version › **Suivant** › **Enregistrer** › **Envoyer pour examen**. Le test fermé passe par l'examen de Google : d'où l'importance d'avoir rempli l'étape 3 **avant**.
5. Une fois la release approuvée : onglet **Testeurs** › **Copier le lien** et l'envoyer aux testeurs. Chacun doit, **avec le compte Google inscrit** : ouvrir le lien › **Devenir testeur** › installer l'application depuis le Play Store.

Règle Google : au moins **12 testeurs inscrits (opt-in) sans interruption pendant les 14 derniers jours**. Conseils :

- inscrivez-en **15 à 20** pour garder de la marge (certains se désinscrivent ou se trompent de compte Google) ;
- vérifiez le compteur dans la Console (onglet Testeurs / tableau de bord) au bout de 2 ou 3 jours ;
- demandez-leur de vraiment ouvrir l'application de temps en temps (avec les comptes de démo s'ils n'ont pas de compte) : à la demande d'accès, Google pose des questions sur l'usage et les retours obtenus ;
- notez les retours reçus et ce que vous avez corrigé : ça sert pour le questionnaire.

6. Après 14 jours : **Tableau de bord** › **Demander l'accès à la production** › répondre au questionnaire (comment les testeurs ont été recrutés, ce qu'ils ont testé, ce que vous avez changé, en quoi l'application est prête). Google répond en général en quelques jours.

Compte **organisation** : cette étape n'est pas obligatoire, passer directement à l'étape 7.

---

## Étape 7 — Production

1. **Tester et publier** › **Production** › onglet **Pays/régions** › **Ajouter des pays/régions** › **France** (et, si vous le souhaitez, Belgique, Suisse, Luxembourg, Monaco : l'application est en français, mais pensée pour le droit français).
2. Onglet **Releases** › **Créer une release** › **Ajouter depuis la bibliothèque** (même `.aab`) ou **Promouvoir la release** depuis le test fermé.
3. Notes de version › **Suivant** › **Enregistrer** › **Envoyer pour examen**.
4. Option utile : **Vue d'ensemble de la publication** › **Publication gérée** activée → une fois l'examen passé, c'est vous qui cliquez sur **Publier**, au moment choisi.

---

## Étape 8 — Ensuite : envois automatiques avec `eas submit`

### 8.1 Créer le compte de service Google (une seule fois)

1. https://console.cloud.google.com › sélecteur de projet en haut › **Nouveau projet** « assokit-play » (ou réutiliser le projet Firebase `assokit-ddca2`).
2. **API et services** › **Bibliothèque** › chercher **Google Play Android Developer API** › **Activer**.
3. **IAM et administration** › **Comptes de service** › **Créer un compte de service** › nom « eas-submit » › **Créer et continuer** › aucun rôle › **OK**.
4. Cliquer sur le compte créé › onglet **Clés** › **Ajouter une clé** › **Créer une clé** › **JSON** › le fichier se télécharge. **Ne jamais le mettre dans git.**
5. Copier l'adresse du compte de service (`eas-submit@….iam.gserviceaccount.com`).
6. Play Console › **Utilisateurs et autorisations** › **Inviter de nouveaux utilisateurs** › coller l'adresse › onglet **Autorisations des applications** › **Ajouter une application** › Assokit › cocher au minimum **Publier des applications dans des canaux de test** et **Publier en production, exclure des appareils et utiliser la signature d'application Play** (ou **Administrateur**) › **Appliquer** › **Inviter l'utilisateur**.

Les droits peuvent mettre un moment à s'appliquer ; si le premier `eas submit` répond « permission denied », réessayer plus tard.

### 8.2 Donner la clé à EAS

```bash
cd ~/Assokit/mobile
npx eas-cli@latest credentials
```

Choisir : **Android** › profil **production** › **Google Service Account** › **Manage your Google Service Account Key for Play Store Submissions** › **Upload a new service account key** › indiquer le chemin du fichier JSON (ex. `~/Downloads/assokit-play-xxxx.json`).

### 8.3 Notifications Android : la clé Firebase (si ce n'est pas déjà fait)

Le service de notifications d'Expo a besoin d'une clé Firebase pour joindre les téléphones Android. Sans elle, l'application obtient bien un jeton mais **aucune notification n'arrive** :

1. https://console.firebase.google.com › projet **assokit-ddca2** › ⚙️ **Paramètres du projet** › **Comptes de service** › **Générer une nouvelle clé privée** (fichier JSON, à garder hors de git).
2. `npx eas-cli@latest credentials` › **Android** › **production** › **Google Service Account** › **Manage your Google Service Account Key for Push Notifications (FCM V1)** › **Upload a new service account key** › ce fichier.
3. Test : https://expo.dev/notifications avec le jeton de votre téléphone (table `asso_push_tokens`, plateforme `android`).

### 8.4 Envoyer un nouveau build

```bash
cd ~/Assokit/mobile
npx eas-cli@latest build --platform android --profile production
npx eas-cli@latest submit --platform android --latest
```

`eas.json › submit.production.android` envoie en **test interne**, en **brouillon** (`track: internal`, `releaseStatus: draft`) : rien n'est publié tout seul. Dans la Console : **Test interne** › ouvrir la release en brouillon › notes de version › **Enregistrer** › **Lancer le déploiement**, puis **Promouvoir** vers le test fermé ou la production quand c'est validé.

Raccourci : `npx eas-cli@latest build --platform android --profile production --auto-submit` enchaîne les deux.

---

## Étape 9 — Les mises à jour OTA continuent de marcher

- `app.json` : `runtimeVersion.policy = appVersion` → la version d'exécution est **`1.0.1`** depuis le 18/10/2026, la même pour l'iPhone et pour Android. Le build Android de production est sur le canal **`production`** (`eas.json`).
- **Pourquoi 1.0.1** : React Native est passé de 0.86.0 à 0.86.3 (changement natif, `../app-store/conformite-ios.md` § A4), et des binaires 1.0.0 ont déjà été distribués (iPhone sur TestFlight, peut-être un test interne Android). Restés en 1.0.0, ils **accepteraient** une OTA compilée avec le JavaScript de 0.86.3 (même version d'exécution) et recevraient une mise à jour incompatible. En 1.0.1, les nouveaux builds ont leur propre version d'exécution ; les binaires 1.0.0 gardent leur dernière OTA. Remplacer ces anciens builds de test par les builds 1.0.1.
- Une mise à jour JavaScript part donc aux deux plateformes :

```bash
cd ~/Assokit/mobile
npx eas-cli@latest update --channel production --message "…"
```

  (ajouter `--platform android` ou `--platform ios` pour n'en viser qu'une).
- Une mise à jour publiée **avant** le build Android ne remplace pas le code embarqué, plus récent : l'application garde le plus récent des deux.
- **Règle d'or** : un changement **natif** (nouveau module, nouvelle permission, icône, `app.json › android` ou `ios`) n'arrive **pas** par OTA. Dans ce cas : passer `version` à la version suivante (`1.0.2` après la 1.0.1) dans `app.json`, refaire les builds iOS **et** Android, et les envoyer aux deux stores. Sinon une OTA pourrait appeler du code natif absent des anciens builds.
- Le libellé « Se connecter avec la biométrie » ajouté aujourd'hui est du JavaScript : il est dans le build Android et partira vers l'iPhone avec la prochaine OTA (sans effet visible sur iPhone, qui garde « Face ID »).
- **Le module `react-native-safe-area-context` était une exception voulue à la règle d'or** (13/10/2026), sans changement de version : le JavaScript ne l'importe pas ; il lit seulement sa constante par `TurboModuleRegistry.get('RNCSafeAreaContext')`, qui renvoie `null` quand le module manque, et uniquement sur Android. Le binaire iPhone 1.0.0, qui ne l'a pas, peut donc recevoir la même OTA sans planter (sur iPhone, rien ne change : `SafeAreaView` y fait déjà le travail). Le prochain build iPhone l'embarquera, sans effet.
- Les autres corrections du 13/10/2026 dans `App.js` (liens légaux dans les Paramètres, sur la connexion et l'accueil ; blocage des pages de présentation ; agenda) sont du JavaScript : elles partiront aussi vers l'iPhone avec la prochaine OTA (`--platform ios` si l'on veut ne viser que lui). Le lien vers la politique de confidentialité dans l'application est aussi demandé par Apple (règle 5.1.1).

---

## Ce que Google vérifie, et les refus fréquents pour ce type d'application

### Vérifications automatiques

- **Rapport de pré-lancement** (tests fermés et ouverts) : des robots installent l'application sur plusieurs téléphones, la parcourent et signalent plantages, problèmes d'accessibilité et de sécurité. Pour qu'ils dépassent l'écran de connexion : **Tester et publier** › **Rapport de pré-lancement** › **Paramètres** › identifiants de test (`admin@demo-f.assokit.fr` / `DemoFormation2026!`).
- Analyse du `.aab` : permissions, niveau d'API cible, SDK embarqués, comparés à vos déclarations (sécurité des données, identifiant publicitaire).

### Examen humain — causes de refus classiques

1. **Connexion impossible** : compte de démo absent, mot de passe changé, double authentification activée, démo en cours de remise à zéro. → Tester les deux comptes depuis l'application juste avant chaque envoi. La démo est remise à zéro chaque nuit ; les mots de passe des comptes de démo ne peuvent pas être changés (`demo-guard.php`).
2. **Suppression de compte** : lien absent, cassé ou qui exige de se connecter ; parcours introuvable dans l'application. → `https://assokit.fr/suppression-compte` est publique ; le parcours dans l'application est décrit dans les instructions d'accès, avec le compte membre.
3. **Paiements** : un prix, un bouton « S'abonner » ou un lien vers une page d'achat **dans l'application, dans la fiche ou sur les captures** — y compris dans un parcours de création de compte. → Les pages d'abonnement sont bloquées dans l'application ; `/signup` n'affiche plus de prix dans l'application ; les pages de présentation du site, qui l'affichent, renvoient vers `/connexion` (serveur) et sont bloquées par l'application ; la fiche ne contient ni prix, ni lien d'achat, ni renvoi vers une inscription à une formule payante ; les captures ne montrent aucun prix (aucun bandeau d'essai avec « S'abonner », aucun « 49,99 € »). Contrôle : la boucle `curl` de l'étape 0.
4. **Permissions** : une permission sans fonction visible, ou une permission sensible non déclarée. → `CAMERA` sert au scan (visible dans la fiche et dans l'application), la biométrie à la connexion ; aucune permission soumise à déclaration (voir `contenu-de-l-application.md` § 13). Si un module en ajoute une un jour, la déclarer ou la bloquer.
5. **Sécurité des données incohérente** : un SDK qui collecte une donnée non déclarée, ou une politique de confidentialité qui ne dit pas la même chose. → Formulaire, politique de confidentialité, CGU et page de suppression alignés le 13/10/2026 (IBAN de la structure déclaré ; mêmes durées de conservation partout) ; politique accessible dans l'application (Paramètres, connexion, accueil) ; à revoir à chaque nouveau SDK.
6. **Fonctionnalité minimale / simple site web** : Google refuse les applications qui ne sont qu'un site emballé **sans l'accord du propriétaire du site**. Assokit est l'éditeur du site, et l'application a de vrais écrans natifs (accueil, listes, formulaires, scan, biométrie, notifications) : les montrer dans les captures.
7. **Métadonnées trompeuses** : captures qui montrent un iPhone, une autre application ou des fonctions absentes ; mots-clés en rafale ; « gratuit », « n° 1 » dans le nom. → Captures Android réelles uniquement.
8. **Formulaires incomplets** : classification, public cible, sécurité des données ou fonctionnalités financières non remplis → la release reste bloquée.

### Délais à prévoir (compte personnel)

| Étape | Durée |
|---|---|
| Vérification du compte développeur | variable (de quelques heures à quelques jours) |
| Test interne | quelques minutes |
| Examen du test fermé | quelques jours possibles |
| Test fermé obligatoire | **14 jours** minimum avec 12 testeurs |
| Demande d'accès à la production | quelques jours |
| Examen de la version de production | quelques jours possibles |

---

## Récapitulatif des commandes

```bash
# Build Android (.aab)
cd ~/Assokit/mobile && npx eas-cli@latest build --platform android --profile production

# Envoi automatique (après le 1er envoi manuel et la clé de compte de service)
npx eas-cli@latest submit --platform android --latest

# Clés (Play Store + notifications FCM V1)
npx eas-cli@latest credentials

# Mise à jour OTA (JavaScript seulement, iPhone + Android)
npx eas-cli@latest update --channel production --message "…"
```
