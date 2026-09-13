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
Email: apple.review@assokit.fr
Password: AppleReview2026!
The account is an administrator of a sample association with 50 members,
38 membership fees, 40 invoices, 10 clients, 12 events, projects, grant
applications and message channels. All data is fictional.

NO IN-APP PURCHASE
The app does not sell anything and shows no price, no subscription button and
no link to any purchase page. The server detects requests coming from the app
(user agent "AssokitApp") and removes every billing surface before rendering:
the subscription pages redirect, the payment endpoint refuses, and the upgrade
banners are not emitted. Assokit subscriptions are purchased only on our
website, in a web browser, outside the app.

ACCOUNT DELETION
Settings > Delete my account (RGPD). The account is anonymised and deactivated
immediately.

LANGUAGE
The app is in French, as it targets French associations and their legal
obligations.
```

---

## Compte de démonstration

```
apple.review@assokit.fr
AppleReview2026!
```

Créé par `demo-sql/08-compte-apple-review.sql`. Pour l'installer ou le
réinstaller sur le serveur :

```bash
php seed-compte-apple-review.php
```

Le script applique le SQL **puis vérifie** que le compte se connecte vraiment :
mot de passe conforme à l'empreinte, compte actif, pas de 2FA, pas de
changement de mot de passe imposé, et des données dans chaque écran. Il sort en
erreur si l'une de ces conditions manque — un compte de démo cassé découvert
par l'examinateur coûte une semaine d'aller-retour.

Le compte est administrateur de « Solidarité Évry » : 50 adhérents, 38
cotisations (30 payées, 8 en attente), 6 subventions dans six états différents,
40 factures, 10 clients, 12 événements, des projets et des canaux de
discussion. Les dates des subventions sont relatives au jour courant, donc la
démo ne périme jamais.

Le fichier porte le numéro 08 pour une raison précise : `cron-demo-reset.php`
rejoue chaque nuit tous les `.sql` du dossier par ordre alphabétique, et le
snapshot (00) commence par `DELETE FROM users WHERE org_id IN (23,24,25,26)`.
Un compte ajouté à une asso de démo disparaîtrait donc toutes les nuits. En
passant après, le 08 le recrée — et l'examinateur retrouve chaque matin des
données propres.

---

## Aucun paiement dans l'app (directive 3.1.1)

C'est le motif de rejet le plus fréquent pour une app de ce type, et il ne
suffit pas de bloquer la navigation : **afficher** un bouton « S'abonner » ou un
prix suffit à faire rejeter.

`app-context.php` reconnaît les requêtes venant de l'app à son agent utilisateur
(`AssokitApp`, posé par `applicationNameForUserAgent` dans `mobile/App.js`) et
retire la surface d'achat avant le rendu :

| Surface | Traitement dans l'app |
|---|---|
| Bandeau d'essai avec « ⚡ S'abonner » | non émis |
| Entrée « Abonnement » du menu | non émise |
| `/abonnement`, `/mon-asso-plan`, `/mon-asso-paiement`, `/mon-asso-paiement-success`, `/mon-asso-annuler-abonnement`, `/tarifs` | redirigées vers le tableau de bord |
| `stripe-create-payment-intent.php` | refuse en 403 |
| Encart « Passer au plan Assokit (49,99 €/mois) » de la diffusion e-mail | remplacé par une phrase neutre |
| Boutons « Passer au plan Pro » des exports | non émis |
| `upgrade_url` renvoyée par l'IA | `null` |

Le même masquage s'applique au compte d'examen même hors de l'app, au cas où
l'examinateur ouvrirait le site à côté.

Sur assokit.fr depuis un navigateur, **rien ne change** : les clients gèrent
leur abonnement normalement.

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

Dix planches vides sont prêtes dans `assets/app-store/` (JPEG 1290 × 2796),
avec un emplacement au ratio exact d'une capture 6,7 pouces. Le fichier
Figma correspondant s'appelle **Assokit — Captures App Store**. Le mode
d'emploi est dans `assets/app-store/README.md`.

Les trois premières sont les seules visibles sans faire défiler sur la fiche
App Store : ce sont elles qui décident du téléchargement.

| # | Planche | Écran à y déposer |
|---|---|---|
| 1 | Tableau de bord | l'accueil avec ses indicateurs |
| 2 | Adhérents | la liste, bien remplie |
| 3 | Cotisations | qui a payé, qui reste à relancer |
| 4 | Facturation | une facture ou un devis |
| 5 | Projets | un projet et ses étapes |
| 6 | Agenda | le mois en cours |
| 7 | Subventions | les six demandes, leurs statuts |
| 8 | Communication | un canal de discussion |
| 9 | Codes QR | un code généré |
| 10 | Fait en France | l'écran de votre choix |

---

## Avant de cliquer sur « Envoyer pour examen »

- [ ] Le build sélectionné est bien le dernier, construit depuis la branche à jour
- [ ] `php seed-compte-apple-review.php` lancé sur le serveur, sortie sans erreur
- [ ] Connexion à `apple.review@assokit.fr` testée **depuis l'app**, pas depuis le navigateur
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
