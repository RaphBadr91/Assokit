# Fiche App Store — Assokit

Tout ce qu'il faut coller dans App Store Connect pour soumettre à l'examen.
Les longueurs sont vérifiées contre les limites d'Apple (voir en fin de fichier).

---

## Nom de l'app

```
Assokit
```

## Sous-titre

```
Votre association, tout-en-un
```

## Texte promotionnel

Modifiable sans nouvelle soumission — utile pour annoncer une nouveauté.

```
Adhérents, cotisations, factures, projets et agenda : toute la vie de votre association dans une seule application.
```

## Description

```
Assokit réunit dans une seule application tout ce qui fait tourner une association loi 1901 ou une TPE : les adhérents, les cotisations, la facturation, les projets, l'agenda et la communication.

Fini les tableurs qui circulent par e-mail et les relances oubliées.

ADHÉRENTS ET COTISATIONS
Votre fichier d'adhérents à jour, consultable partout. Créez une campagne de cotisation, enregistrez les paiements, suivez qui est à jour et qui ne l'est pas. Les relances partent sans que vous ayez à y penser.

FACTURATION ET DEVIS
Établissez un devis, transformez-le en facture, envoyez-la par e-mail. Suivez les impayés et relancez d'un geste. Vos documents portent votre logo et vos mentions légales.

PROJETS ET BÉNÉVOLES
Organisez vos actions en projets avec leurs étapes, leur budget et leurs participants. Chacun voit ce qu'il a à faire.

AGENDA ET ASSEMBLÉES
Vos événements, vos réunions, vos assemblées générales au même endroit. Émargement numérique, convocations, comptes rendus.

SUBVENTIONS
Ne laissez plus passer une date limite. Assokit suit vos demandes, leurs échéances de dépôt et de compte rendu.

COMMUNICATION
Des canaux de discussion par projet, pour remplacer les groupes de messagerie où tout se perd.

CODES QR
Créez un code QR pour votre stand : les visiteurs enregistrent vos coordonnées et vous laissent les leurs. Plus besoin de cartes de visite.

PROSPECTION
Suivez vos appels et vos e-mails, programmez vos rappels, gardez l'historique de chaque échange.

FAIT EN FRANCE
Vos données sont hébergées en France. Assokit est pensé pour le droit français : loi 1901, mentions légales obligatoires, facturation conforme.

L'application nécessite un compte Assokit. Les abonnements se souscrivent sur assokit.fr.

Conditions d'utilisation : https://assokit.fr/cgu
Confidentialité : https://assokit.fr/confidentialite
```

## Mots-clés

Cent caractères, virgules sans espaces. Ne répétez pas le nom de l'app ni le
sous-titre : Apple les indexe déjà.

```
asso,loi 1901,adhérent,cotisation,bénévole,facture,devis,assemblée,subvention,club,TPE,gestion,dons
```

## URL

| Champ | Valeur |
|---|---|
| Assistance | `https://assokit.fr/contact` |
| Marketing | `https://assokit.fr` |
| Confidentialité | `https://assokit.fr/confidentialite` |

## Catégorie

- Principale : **Entreprise**
- Secondaire : **Productivité**

## Classification par âge

4+ — aucun contenu sensible.

---

## Notes pour l'examen (Review Notes)

À coller tel quel, en remplaçant les identifiants.

```
Assokit is a management platform for French non-profit associations (loi 1901)
and small businesses. It requires an account.

DEMO ACCOUNT
Email: [à compléter]
Password: [à compléter]
This account contains sample members, projects, invoices and events.

NO IN-APP PURCHASE
The app does not sell anything. Assokit subscriptions are purchased only on our
website, outside the app. No pricing or subscription page is reachable from the
app: navigation to those URLs is blocked in code.

ACCOUNT DELETION
Settings > Delete my account (RGPD). The account is anonymised and deactivated
immediately.

LANGUAGE
The app is in French, as it targets French associations and their legal
obligations.
```

---

## Questionnaire « Confidentialité des données »

Vérifié contre le code : l'app n'embarque **aucun SDK de suivi ni d'analytique**,
et ne demande que caméra, photos et Face ID.

**Collectez-vous des données ?** Oui.

| Catégorie | Données | Usage | Liée à l'identité | Suivi publicitaire |
|---|---|---|---|---|
| Coordonnées | Nom, e-mail, téléphone, adresse postale | Fonctionnalité de l'app | Oui | **Non** |
| Identifiants | Identifiant de compte | Fonctionnalité, authentification | Oui | **Non** |
| Contenu utilisateur | Documents, photos importées, messages | Fonctionnalité de l'app | Oui | **Non** |
| Informations financières | Montants de cotisations et de factures saisis | Fonctionnalité de l'app | Oui | **Non** |

**Répondez « Non » à « Suivi »** pour toutes les lignes : aucune donnée n'est
utilisée pour de la publicité ni transmise à un courtier en données. Il n'y a pas
d'ATT à demander.

Les coordonnées saisies concernent les adhérents et clients de l'association,
pas l'utilisateur de l'app lui-même — Apple accepte cette lecture dès lors que
la catégorie est déclarée.

---

## Captures d'écran

Obligatoires : **6,7 pouces** (iPhone 15/16 Pro Max), 3 minimum, 10 maximum.
Prenez-les depuis le build TestFlight, sur un compte contenant de vraies
données. Apple rejette les captures qui ne correspondent pas à l'app.

Ordre conseillé, du plus parlant au plus détaillé :

1. Accueil avec ses indicateurs
2. Liste des adhérents
3. Tableau de bord complet
4. Une facture ou un devis
5. Agenda

---

## Avant de cliquer sur « Envoyer pour examen »

- [ ] Le build sélectionné est bien le dernier, construit depuis la branche à jour
- [ ] Compte de démonstration créé, testé, et ses identifiants dans les notes
- [ ] Captures 6,7" importées
- [ ] Questionnaire de confidentialité rempli
- [ ] Trois URL renseignées
- [ ] Droits d'exportation : « Non » (ITSAppUsesNonExemptEncryption est déjà à false)
- [ ] Publication : « Automatique après approbation » ou « Manuelle », au choix

---

## Limites d'Apple, pour mémoire

Longueurs mesurées sur les textes ci-dessus, en caractères Unicode — les
accents comptent pour un, comme chez Apple.

| Champ | Limite | Ce fichier |
|---|---|---|
| Nom | 30 | 7 |
| Sous-titre | 30 | 29 |
| Texte promotionnel | 170 | 115 |
| Description | 4 000 | 1 833 |
| Mots-clés | 100 | 99 |

Le champ mots-clés est plein à une unité près : pour en ajouter un, retirez-en
un autre. Ne recopiez jamais « Assokit » ni les mots du sous-titre, Apple les
indexe déjà et la place serait perdue.
