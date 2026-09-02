# Assokit Mobile — Checklist de mise en ligne (App Store + Google Play)

Application Expo SDK 57 / React Native 0.86 · bundle `fr.assokit.app` · EAS project `dae894d1-…`.
Ce document liste tout ce qui reste à faire pour publier. ✅ = déjà en place · ⏳ = à faire.

---

## ⚠️ 0-bis. AVANT TOUT `eas update` : installer les modules natifs manquants (rebuild obligatoire)

L'audit a révélé que **`expo-updates` n'est pas dans `package.json`** : sans ce module natif dans le binaire,
un `eas update` (OTA) n'est reçu par **aucun** build — la publication part dans le vide. Il faut donc,
une seule fois, sur le Mac :

```bash
cd mobile
npx expo install expo-updates react-native-safe-area-context   # versions alignées sur le SDK 57
eas build --profile production --platform all                     # nouveau binaire (modules natifs)
# puis, pour toutes les mises à jour JS suivantes :
eas update --channel production --message "…"
```

`react-native-safe-area-context` corrige aussi l'affichage Android 15+ (edge-to-edge) : en-têtes sous la
barre de statut et onglets sous la barre gestuelle. En attendant ce build, un repli pur JS
(`StatusBar.currentHeight` + marge basse) est en place dans `App.js`.

## 0. Correctifs déjà intégrés (nécessitent un rebuild pour être actifs)

Ces changements sont dans le code mais **ne prennent effet qu'au prochain build EAS** :
- ✅ Sécurité : auto-login biométrique fail-closed, validation stricte du domaine (`isAssokitUrl`), `originWhitelist` HTTPS uniquement, permission Android `READ_EXTERNAL_STORAGE` retirée.
- ✅ Fonctionnel : message chat non perdu sur échec, scan facture MIME réel, raccourcis TPE Devis/Recettes natifs, menu « Plus » filtré par profil.
- ✅ Accessibilité : labels sur champs / œil mot de passe / switch, contraste MUTE relevé.

---

## 1. Conformité `app.json` / `eas.json` (à vérifier avant build)

| Point | État | Action |
|-------|------|--------|
| `version` 1.0.0 / `buildNumber` / `versionCode` | ✅ | 1er build ; `eas.json` a `autoIncrement:true` + `appVersionSource:"remote"` → EAS gère l'incrément. |
| `bundleIdentifier` = `package` = `fr.assokit.app` | ✅ | cohérent. |
| iOS `infoPlist` : Face ID, Caméra, Photos + `ITSAppUsesNonExemptEncryption:false` | ✅ | descriptions présentes (évite le prompt export chiffrement). |
| Permissions Android (CAMERA, biometric) | ✅ | minimales. |
| Icônes (1024²) + splash (2732²) | ✅ | présents dans `assets/`. |
| **`supportsTablet: true`** | ⚠️ | **Oblige à fournir des captures iPad à Apple.** Si tu ne vises pas l'iPad au lancement → passe à `false` (plus simple, moins de tests). C'est un choix produit. |
| Icône avec canal alpha | ℹ️ | EAS régénère l'icône store (opaque). OK en pratique. |

> 💡 Recommandation lancement rapide : `supportsTablet: false` pour ne gérer que l'iPhone au départ.

---

## 2. Comptes & prérequis

- ⏳ **Compte Apple Developer** (99 $/an) — https://developer.apple.com
- ⏳ **Compte Google Play Developer** (25 $ une fois) — https://play.google.com/console
- ✅ **Politique de confidentialité** en ligne : https://assokit.fr/confidentialite (URL demandée par les 2 stores)
- ⏳ **Compte de démo pour la revue** (email + mot de passe d'une asso de test) — **OBLIGATOIRE** : Apple et Google se connectent pour tester. À mettre dans les notes de revue. Sans ça = rejet quasi certain (login requis).

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
- ⏳ **Captures d'écran** : iPhone 6.7" **et** 6.5" (obligatoires). iPad **si** `supportsTablet:true`.
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
- ⏳ **Assets** : icône 512×512, feature graphic 1024×500, captures téléphone (min. 2).
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

## 7. Récap listing (à rédiger)

- ⏳ **Nom** : Assokit — Gestion d'association & TPE
- ⏳ **Sous-titre / court** : Adhérents, cotisations, compta, factures — en un seul outil français.
- ⏳ **Description longue** : reprendre les arguments du site (tout-en-un, IA intégrée, hébergé en France, RGPD), en insistant sur les écrans natifs.
- ⏳ **Mots-clés** : association, gestion association, adhérents, cotisations, facturation, compta, TPE.
- ⏳ **Captures** : accueil KPI, projets, facture, scan, chat (montrer le natif, pas la WebView).

---

*Point le plus souvent oublié → le **compte de démo** dans les notes de revue. Sans lui, Apple/Google ne peuvent pas dépasser l'écran de connexion et rejettent. À préparer en priorité.*
