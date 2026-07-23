# 📋 Dossier de validation — Apple App Store & Google Play

Tout ce qu'il faut pour qu'Apple et Google valident **Assokit** rapidement.
Ce fichier est un aide-mémoire à **copier-coller** — rien à deviner.

---

## 1. Créer le compte de démo (À FAIRE EN PREMIER)

Les relecteurs doivent pouvoir se connecter. On leur fournit un compte tout prêt,
rempli de fausses données réalistes.

**En SSH sur O2switch**, dans le dossier du site :
```bash
php api/seed-demo-account.php
```

Ça affiche à la fin :
```
Email        : demo-review@assokit.fr
Mot de passe : AssokitDemo2026!
```

> Ré-exécutable à volonté (il réinitialise la démo proprement, sans toucher aux vrais comptes).
> Accès navigateur de secours si pas de SSH :
> `https://assokit.fr/api/seed-demo-account.php?key=assokit-seed-8f3a91d6`

---

## 2. 🍏 Apple — App Store Connect

### 2.1 « App Review Information » (App Store Connect → ta version → Informations pour la review)

| Champ | Valeur à coller |
|---|---|
| **Sign-in required** | ✅ Oui (cocher) |
| **User name** | `demo-review@assokit.fr` |
| **Password** | `AssokitDemo2026!` |
| **Contact — First / Last** | Ton prénom / nom |
| **Contact — Phone** | Ton numéro |
| **Contact — Email** | psiwaneraph@gmail.com |

**Notes for Review** (colle tel quel) :

```
Bonjour,

Assokit est un logiciel de gestion tout-en-un pour les associations loi 1901
et les TPE/PME françaises : adhérents, cotisations, comptabilité, factures,
projets, agenda et messagerie interne.

COMPTE DE TEST (connexion requise) :
  Email    : demo-review@assokit.fr
  Password : AssokitDemo2026!

Ce compte donne accès à une association de démonstration déjà remplie
(clients, projets, événements) afin de parcourir toutes les fonctionnalités.

Suppression de compte : accessible dans l'app via Profil > Paramètres >
"Supprimer mon compte (RGPD)", conformément à la directive 5.1.1(v).

L'application ne contient pas de contenu généré par les utilisateurs public,
pas de connexion via réseau social, et n'utilise aucun chiffrement non exempté.

Merci pour votre review.
```

**Notes for Review (English version)** :

```
Hello,

Assokit is an all-in-one management app for French non-profits and small
businesses: members, dues, accounting, invoices, projects, calendar and
internal messaging.

TEST ACCOUNT (sign-in required):
  Email    : demo-review@assokit.fr
  Password : AssokitDemo2026!

This account opens a demo organization pre-filled with sample data
(clients, projects, events) so you can review every feature.

Account deletion: available in-app under Profile > Settings >
"Delete my account (GDPR)", per guideline 5.1.1(v).

The app has no public user-generated content, no social login, and uses
no non-exempt encryption.

Thank you for the review.
```

### 2.2 App Privacy (App Store Connect → Confidentialité de l'app)

Réponds **« Oui, on collecte des données »**, puis déclare exactement ceci :

| Type de donnée | Collecté ? | Lié à l'utilisateur | Suivi (tracking) | Usage |
|---|---|---|---|---|
| **Adresse e-mail** | Oui | Oui | Non | Fonctionnement de l'app, Authentification |
| **Nom** | Oui | Oui | Non | Fonctionnement de l'app |
| **Numéro de téléphone** | Oui | Oui | Non | Fonctionnement de l'app |
| **Identifiants de connexion (User ID)** | Oui | Oui | Non | Fonctionnement de l'app, Authentification |
| **Contenu utilisateur** (docs, factures, photos importées) | Oui | Oui | Non | Fonctionnement de l'app |
| **Données de diagnostic (crash/perf)** | Optionnel* | Non | Non | Analyse |

\* Ne coche les données de diagnostic **que** si tu as un outil de crash reporting.
Par défaut, tu peux répondre **Non**.

- **Tracking (App Tracking Transparency)** : **Non** — l'app ne suit pas les
  utilisateurs à travers d'autres apps/sites. Donc **pas** de pop-up ATT à ajouter.
- **Publicité tierce** : **Non**.
- **Vente de données** : **Non**.

### 2.3 Checklist Apple avant soumission

- [x] `ITSAppUsesNonExemptEncryption: false` (déjà dans le code → pas de "Missing Compliance")
- [x] Suppression de compte in-app (déjà présente)
- [x] Descriptions d'usage caméra / photos / Face ID (déjà présentes)
- [ ] Compte de démo renseigné (§2.1)
- [ ] App Privacy rempli (§2.2)
- [ ] Captures d'écran : **iPhone 6,7"** (1290×2796) obligatoire, + **iPad 12,9"** si l'app supporte iPad (elle le fait : `supportsTablet: true`)
- [ ] Description, mots-clés, catégorie (Business / Productivité)
- [ ] URL politique de confidentialité : `https://assokit.fr/confidentialite`
- [ ] URL support : `https://assokit.fr/contact`

---

## 3. 🤖 Google Play — Play Console

### 3.1 App access (Play Console → App content → App access)

Choisir **« All or some functionality is restricted »**, puis ajouter un accès :

| Champ | Valeur |
|---|---|
| **Name** | Compte de démonstration |
| **Username** | `demo-review@assokit.fr` |
| **Password** | `AssokitDemo2026!` |
| **Any other instructions** | Compte de démo pré-rempli. Suppression de compte : Profil > Paramètres > "Supprimer mon compte (RGPD)". |

### 3.2 Data safety (Play Console → App content → Data safety)

Réponds **« Oui, l'app collecte/partage des données »**, puis :

| Donnée | Collectée | Partagée | Chiffrée en transit | Suppression possible |
|---|---|---|---|---|
| **E-mail** | Oui | Non | Oui | Oui (in-app) |
| **Nom** | Oui | Non | Oui | Oui |
| **Téléphone** | Oui | Non | Oui | Oui |
| **Photos / fichiers** (import factures/logo) | Oui | Non | Oui | Oui |
| **Identifiants app** | Oui | Non | Oui | Oui |

- **Objectif** de chaque donnée : *App functionality* + *Account management*.
- **Chiffrement en transit** : Oui (HTTPS).
- **Suppression des données** : Oui — l'utilisateur peut demander la suppression
  in-app (Profil > Paramètres > Supprimer mon compte).
- **Compte requis** : Oui.

### 3.3 Autres formulaires Play obligatoires

- **Content rating** (questionnaire IARC) : app **Business/Utilitaire**, pas de
  contenu sensible → classée « Tout public ».
- **Target audience** : 18+ (outil professionnel) — pas destiné aux enfants.
- **Privacy policy URL** : `https://assokit.fr/confidentialite`
- **Ads** : l'app ne contient pas de publicité → répondre **Non**.

---

## 4. Récap des liens légaux (déjà en ligne)

| Rôle | URL |
|---|---|
| Politique de confidentialité | https://assokit.fr/confidentialite |
| CGU | https://assokit.fr/cgu |
| Mentions légales | https://assokit.fr/mentions-legales |
| Support / contact | https://assokit.fr/contact |

---

## 5. Les 3 causes de rejet les plus fréquentes (déjà couvertes)

1. **Reviewer ne peut pas se connecter** → réglé par le compte de démo (§1).
2. **Pas de suppression de compte** (Apple 5.1.1v) → déjà in-app.
3. **"Missing Compliance" chiffrement** → réglé par `ITSAppUsesNonExemptEncryption: false`.

> Une fois §2 et §3 remplis, l'app est prête à être soumise pour review.
