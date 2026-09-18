# Assokit Mobile — Checklist de mise en ligne (App Store + Google Play)

Application Expo SDK 57 / React Native 0.86.3 · bundle `fr.assokit.app` · EAS project `dae894d1-…`.
Ce document liste tout ce qui reste à faire pour publier. ✅ = déjà en place · ⏳ = à faire.

---

## 🚀 0. Envoyer une version sur TestFlight (le plus court chemin)

```bash
cd mobile
npm install                 # installe expo-updates, ajouté au package.json
eas login                   # compte Expo
eas build --platform ios --profile production
eas submit --platform ios --latest
```

Puis dans **App Store Connect → TestFlight**, le build apparaît après le traitement Apple
(≈ 5 à 20 min). Ajoute-toi comme testeur interne pour le recevoir immédiatement — un groupe de
test **interne** ne passe pas par la revue Apple.

Prérequis : compte Apple Developer actif (99 $/an) et l'app créée dans App Store Connect avec le
bundle `fr.assokit.app`. EAS gère les certificats de signature tout seul si tu le laisses faire.

> ℹ️ `app.json` porte `ios.supportsTablet: false` : l'app ne vise que l'iPhone au
> lancement, donc **aucune capture iPad n'est à fournir**.

### 0-bis. Mises à jour suivantes : OTA sans repasser par un build

**`expo-updates` est maintenant dans `package.json`** (il manquait : sans ce module natif dans le
binaire, un `eas update` n'était reçu par aucun build et la publication partait dans le vide).
Une fois **ce** build installé sur TestFlight, toute modification JS ultérieure part en OTA :

```bash
eas update --channel production --message "…"
```

Rappel : l'OTA ne couvre que le JavaScript. Ajouter un module natif, changer une permission ou une
icône impose un nouveau `eas build`.

## 0-ter. Correctifs déjà intégrés (nécessitent un rebuild pour être actifs)

Ces changements sont dans le code mais **ne prennent effet qu'au prochain build EAS** :
- ✅ Sécurité : auto-login biométrique fail-closed, validation stricte du domaine (`isAssokitUrl`), `originWhitelist` HTTPS uniquement, permission Android `READ_EXTERNAL_STORAGE` retirée.
- ✅ Fonctionnel : message chat non perdu sur échec, scan facture MIME réel, raccourcis TPE Devis/Recettes natifs, menu « Plus » filtré par profil.
- ✅ Accessibilité : labels sur champs / œil mot de passe / switch, contraste MUTE relevé.
- ✅ Moteur JS : React Native passé de 0.86.0 à **0.86.3**, et toutes les dépendances
  alignées sur ce que le SDK 57 attend. La 0.86.0 embarquait une version de Hermes
  affectée par une régression connue — `npx expo-doctor` la signalait. Un binaire
  construit avant cette montée de version reste exposé : **elle impose un rebuild**,
  aucun `eas update` ne la livre.

---

## 1. Conformité `app.json` / `eas.json` (à vérifier avant build)

| Point | État | Action |
|-------|------|--------|
| `version` 1.0.0 / `buildNumber` / `versionCode` | ✅ | 1er build ; `eas.json` a `autoIncrement:true` + `appVersionSource:"remote"` → EAS gère l'incrément. |
| `bundleIdentifier` = `package` = `fr.assokit.app` | ✅ | cohérent. |
| iOS `infoPlist` : Face ID, Caméra, Photos + `ITSAppUsesNonExemptEncryption:false` | ✅ | descriptions présentes (évite le prompt export chiffrement). |
| Permissions Android (CAMERA, biometric) | ✅ | minimales. |
| Icônes (1024²) + splash (2732²) | ✅ | présents dans `assets/`. |
| `supportsTablet: false` | ✅ | iPhone seulement au lancement : pas de captures iPad à produire. |
| Version de React Native | ✅ | 0.86.3 — corrige la régression du moteur Hermes présente en 0.86.0 (voir §0-ter). |
| Icône avec canal alpha | ℹ️ | EAS régénère l'icône store (opaque). OK en pratique. |

---

## 2. Comptes & prérequis

- ⏳ **Compte Apple Developer** (99 $/an) — https://developer.apple.com
- ⏳ **Compte Google Play Developer** (25 $ une fois) — https://play.google.com/console
- ✅ **Politique de confidentialité** en ligne : https://assokit.fr/confidentialite (URL demandée par les 2 stores)
- ✅ **Compte de démo pour la revue** : `apple.review@assokit.fr` / `AppleReview2026!`.
  À réinstaller sur le serveur avant chaque soumission — `php seed-compte-apple-review.php`,
  qui vérifie lui-même que le compte se connecte. Détails dans `docs/APP-REVIEW-APPLE-GOOGLE.md`.

---

## 3. Build & soumission EAS

```bash
cd mobile
npm install -g eas-cli      # si pas déjà installé
eas login                   # compte Expo

# iOS (nécessite le compte Apple Developer)
eas build --platform ios --profile production
eas submit --platform ios --latest

# Android
eas build --platform android --profile production
eas submit --platform android --latest
```
- Le profil `production` d'`eas.json` est prêt. `eas submit` téléverse vers App Store Connect / Play Console.
- ⏳ Renseigner les identifiants de signature (EAS gère les certificats iOS et le keystore Android automatiquement si tu le laisses faire — recommandé).

---

## 4. Apple App Store Connect

- ⏳ Créer l'app (bundle `fr.assokit.app`).
- ⏳ **App Privacy** (« nutrition label ») : déclarer les données collectées. Assokit collecte au moins : e-mail/identifiant (compte), contenu utilisateur (via le compte web), usage. Caméra & Face ID = **locaux, non collectés**.
- ⏳ **Politique de confidentialité URL** : https://assokit.fr/confidentialite
- ✅ **Captures d'écran** : dix planches prêtes en 1290 × 2796 (iPhone 6,7"), dans
  `assets/app-store/capture-01.jpg` … `capture-10.jpg`. À relire avant de les téléverser.
  Regénération : `node assets/app-store/generer-captures.js`. Pas de captures iPad
  (`supportsTablet: false`).
- ⏳ **Notes de revue** : identifiants du compte de démo + « app compagnon du SaaS assokit.fr pour la gestion d'associations/TPE ».
- ⏳ Description, mots-clés, URL support (https://assokit.fr/contact), catégorie (Business/Productivité).
- ⚠️ **Guideline 4.2 (minimum functionality)** : Apple rejette les simples « wrappers de site web ». Assokit a de vrais écrans **natifs** (accueil KPI, projets, factures, chat, scan…) → mets-les en avant dans les captures et la description pour prouver la valeur native.
- ℹ️ **Sign in with Apple** non requis (pas de login social tiers, uniquement email/mot de passe).

---

## 5. Google Play Console

- ⏳ Créer l'app (`fr.assokit.app`).
- ⏳ **Data safety form** : mêmes déclarations que le nutrition label Apple.
- ⏳ **Content rating** : questionnaire (app pro → tout public probable).
- ⏳ **Politique de confidentialité URL** : https://assokit.fr/confidentialite
- ⏳ **Assets** : icône 512×512, feature graphic 1024×500. Captures téléphone :
  `assets/app-store/brut/ecran-*.png` (1290 × 2796), déjà prêtes.
- ⏳ **Target API level** : Play exige API récente (34+). Expo SDK 57 la cible → OK.
- ⏳ Publier d'abord en **test interne** (rapide) → valider → promouvoir en production.
- ⏳ Compte de démo dans les instructions de test.

---

## 6. Tests avant soumission (build `preview` ou `development`)

- ⏳ Connexion + auto-login biométrique (accepter / refuser / annuler Face ID).
- ⏳ Scan facture depuis **appareil photo ET galerie** (PNG/HEIC inclus) → rattachement à une dépense.
- ⏳ Création : adhérent/client, facture, devis, projet, dépense.
- ⏳ Chat : envoi OK + envoi sur canal lecture seule (doit alerter, garder le texte).
- ⏳ Notifications push (tap → bon écran).
- ⏳ Profil TPE : onglets + menu « Plus » sans les items d'association ; raccourcis Devis/Recettes → écrans natifs.
- ⏳ Déconnexion propre (pas de flash de page web).

---

## 7. Récap listing

✅ **Déjà rédigé** — nom, sous-titre, texte promotionnel, description, mots-clés,
catégorie, classification d'âge et notes d'examen (FR et EN) sont dans
**`docs/app-store-fiche.md`**, longueurs vérifiées contre les limites d'Apple.
Il n'y a qu'à copier-coller.

Le déroulé complet des deux stores est dans `docs/APP-REVIEW-APPLE-GOOGLE.md`.

---

*Point le plus souvent oublié → le **compte de démo** dans les notes de revue. Sans lui, Apple/Google ne peuvent pas dépasser l'écran de connexion et rejettent. À préparer en priorité.*
