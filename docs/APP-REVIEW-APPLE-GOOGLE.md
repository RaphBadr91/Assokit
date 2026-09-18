# 🚀 Mise en ligne — App Store & Google Play

Le déroulé complet, de la branche à jour jusqu'au bouton « Envoyer pour examen ».

| Vous cherchez | C'est ici |
|---|---|
| Les textes de la fiche Apple, les notes d'examen, le questionnaire de confidentialité | **`docs/app-store-fiche.md`** |
| Le déroulé de bout en bout, et tout Google Play | ce fichier |
| Le détail des commandes EAS | `mobile/RELEASE-CHECKLIST.md` |

`app-store-fiche.md` fait foi pour tout ce qui se colle dans App Store Connect.
Ce fichier ne recopie pas ses textes : deux copies finissent toujours par se
contredire, et c'est l'identifiant du compte d'examen qui en paie le prix.

---

## 1. Le compte d'examen (à faire en premier)

Un seul compte, pour Apple **et** pour Google :

```
apple.review@assokit.fr
AppleReview2026!
```

Sur le serveur, avant chaque soumission :

```bash
php seed-compte-apple-review.php
```

Le script applique `demo-sql/08-compte-apple-review.sql` **puis vérifie** que le
compte se connecte vraiment (mot de passe conforme, compte actif, pas de 2FA,
pas de changement de mot de passe imposé, des données dans chaque écran). Il
sort en erreur si l'une de ces conditions manque. Rejouable à volonté.

Le compte est administrateur de « Solidarité Évry » : 50 adhérents, 38
cotisations, 6 subventions, 40 factures, 10 clients, 12 événements, des projets
et des canaux. Les dates sont relatives au jour courant : la démo ne périme pas.
`cron-demo-reset.php` le recrée chaque nuit après le reset — l'examinateur
retrouve chaque matin des données propres.

> ⚠️ N'utilisez **pas** `api/seed-demo-account.php` pour l'examen. Il crée un
> autre compte, dans une autre association, et surtout : le masquage des pages
> de paiement hors de l'app (directive 3.1.1) ne reconnaît que
> `apple.review@assokit.fr` — voir `AK_APPLE_REVIEW_EMAIL` dans `app-context.php`.

Vérifiez la connexion **depuis l'app**, pas depuis le navigateur.

---

## 2. Construire et envoyer

```bash
cd mobile
npm install
npx expo-doctor          # doit passer ; les échecs réseau en environnement fermé sont sans objet
eas login

# iOS
eas build --platform ios --profile production
eas submit --platform ios --latest

# Android
eas build --platform android --profile production
eas submit --platform android --latest
```

`eas.json` est prêt (`autoIncrement`, `appVersionSource: "remote"` : EAS gère les
numéros de build). Laissez EAS gérer les certificats iOS et le keystore Android.

Ensuite, les modifications **JavaScript** partent sans repasser par un build :

```bash
eas update --channel production --message "…"
```

Un module natif, une permission ou une icône imposent en revanche un nouveau
`eas build`.

---

## 3. 🍏 Apple — App Store Connect

Tous les textes sont dans **`docs/app-store-fiche.md`** : nom, sous-titre,
description, mots-clés, catégorie, notes d'examen (FR et EN), questionnaire de
confidentialité, et la liste de contrôle avant envoi.

Ce qu'il faut savoir en plus :

- **Captures** : les dix planches sont **déjà remplies**, dans
  `assets/app-store/capture-01.jpg` … `capture-10.jpg`, en 1290 × 2796
  (iPhone 6,7"). Elles se téléversent telles quelles. Regardez-les d'abord.
  Pour les refaire après une évolution de l'app :
  ```bash
  cd mobile && npx expo export --platform web --output-dir dist-web
  cd .. && node assets/app-store/generer-captures.js
  ```
- **Pas de captures iPad à fournir** : `app.json` porte
  `ios.supportsTablet: false`, l'app ne vise que l'iPhone au lancement.
- **Droits d'exportation** : rien à déclarer, `ITSAppUsesNonExemptEncryption`
  est déjà à `false` dans `app.json`.
- **Sign in with Apple** : non requis, l'app n'a pas de connexion via un réseau
  social — uniquement e-mail et mot de passe.
- **Directive 4.2 (fonctionnalité minimale)** : Apple rejette les simples
  habillages de site web. Assokit a de vrais écrans natifs — c'est ce que
  montrent les captures, et c'est le premier argument des notes d'examen.

---

## 4. 🤖 Google Play — Play Console

### 4.1 App access

**« All or some functionality is restricted »**, puis un accès :

| Champ | Valeur |
|---|---|
| Name | Compte de démonstration |
| Username | `apple.review@assokit.fr` |
| Password | `AppleReview2026!` |
| Any other instructions | Compte pré-rempli. Suppression du compte : Plus > Paramètres > « Supprimer mon compte (RGPD) ». |

### 4.2 Data safety

**Oui**, l'app collecte des données ; elle n'en partage aucune.

| Donnée | Collectée | Partagée | Chiffrée en transit | Suppression possible |
|---|---|---|---|---|
| E-mail | Oui | Non | Oui | Oui (in-app) |
| Nom | Oui | Non | Oui | Oui |
| Téléphone | Oui | Non | Oui | Oui |
| Photos / fichiers (import factures, logo) | Oui | Non | Oui | Oui |
| Identifiants de connexion | Oui | Non | Oui | Oui |

- Objectif de chaque donnée : *App functionality* + *Account management*.
- Chiffrement en transit : oui (HTTPS).
- Suppression : oui, in-app — Plus > Paramètres > « Supprimer mon compte (RGPD) ».
- Compte requis : oui.
- Aucun SDK de suivi ni d'analytique n'est embarqué : répondez **non** au suivi
  publicitaire et à la publicité.

### 4.3 Les autres formulaires

- **Content rating** (questionnaire IARC) : outil professionnel, aucun contenu
  sensible → tout public.
- **Target audience** : 18+, l'app s'adresse aux dirigeants d'associations et
  d'entreprises.
- **Privacy policy URL** : `https://assokit.fr/confidentialite`
- **Ads** : non.
- **Target API level** : Play exige une cible récente ; Expo SDK 57 la satisfait.

### 4.4 Assets de la fiche

| Élément | Format | Où le trouver |
|---|---|---|
| Icône | 512 × 512 | `mobile/assets/icon.png` (1024², à réduire) |
| Captures téléphone (2 min.) | 1290 × 2796 | `assets/app-store/brut/ecran-*.png` |
| Feature graphic | 1024 × 500 | à produire |

Google n'attend pas d'habillage marketing : les écrans seuls de `brut/`
conviennent. Les planches habillées d'`assets/app-store/` fonctionnent aussi.

### 4.5 Déroulé conseillé

Publier d'abord en **test interne** (disponible en quelques minutes), vérifier
sur un vrai téléphone, puis promouvoir en production.

---

## 5. Liens légaux (déjà en ligne)

| Rôle | URL |
|---|---|
| Politique de confidentialité | https://assokit.fr/confidentialite |
| CGU | https://assokit.fr/cgu |
| Mentions légales | https://assokit.fr/mentions-legales |
| Support / contact | https://assokit.fr/contact |

---

## 6. Les quatre motifs de rejet les plus fréquents

| Motif | État |
|---|---|
| L'examinateur ne peut pas se connecter | ✅ compte d'examen vérifié par son script (§1) |
| Pas de suppression de compte (Apple 5.1.1 v) | ✅ in-app, Plus > Paramètres |
| Achat hors App Store visible dans l'app (3.1.1) | ✅ toute surface de paiement retirée côté serveur — voir `app-store-fiche.md` |
| « Missing Compliance » chiffrement | ✅ `ITSAppUsesNonExemptEncryption: false` |

Le point le plus souvent oublié reste le compte d'examen. Lancez
`php seed-compte-apple-review.php` **avant** chaque soumission, et lisez sa
sortie jusqu'au bout.
