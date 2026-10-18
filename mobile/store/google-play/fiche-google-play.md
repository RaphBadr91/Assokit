# Fiche Google Play — Assokit

Tout ce qu'il faut coller dans la Play Console, rubrique **Développer la présence sur le Play Store › Fiche Play Store principale** (en anglais *Grow users › Store presence › Main store listing*).

Langue par défaut de la fiche : **Français (France) – fr-FR**. Les longueurs ont été mesurées en caractères Unicode, comme le fait Google (un accent compte pour un). Tableau récapitulatif en fin de fichier.

Chaque phrase de la description correspond à une fonction présente dans le code (application mobile ou site). Aucun chiffre, avis client ou récompense n'y figure.

---

## 1. Nom de l'application (30 caractères max)

```
Assokit – Gestion asso & TPE
```

28 caractères. Variante plus courte si vous préférez : `Assokit – Asso & TPE` (20).

Règle Google : pas de prix, pas de « gratuit », pas de « n° 1 », pas d'emoji ni de majuscules à outrance dans le nom. Les deux versions respectent la règle.

## 2. Description courte (80 caractères max)

```
Adhérents, cotisations, projets, factures et agenda de votre asso ou TPE
```

72 caractères.

## 3. Description complète (4 000 caractères max)

```
Assokit réunit dans une seule application la gestion d'une association loi 1901 ou d'une petite entreprise : adhérents et cotisations, projets, agenda et assemblées générales, devis et factures, comptabilité, subventions et communication.

L'application donne accès à votre espace Assokit depuis votre téléphone, avec des écrans conçus pour le mobile.

DANS VOTRE POCHE
• Un accueil avec vos chiffres clés : budget engagé, encaissements, factures en retard, événements à venir
• Le bouton « + » pour créer en un geste, selon votre profil, une facture, un devis, un client, un adhérent, un paiement de cotisation, un événement, un projet ou une subvention
• Le scan des factures fournisseurs : photographiez la facture, l'IA lit le fournisseur, la date et les montants HT, TVA et TTC
• Les notifications sur votre téléphone
• La connexion par empreinte digitale ou reconnaissance faciale, selon votre téléphone

ADHÉRENTS ET COTISATIONS
• Un fichier d'adhérents à jour, avec des rôles adaptés : administrateur, coordinateur, référent, membre
• Campagnes de cotisation, paiements enregistrés, suivi de qui est à jour
• Relances automatiques des cotisations dues, si vous les activez

PROJETS
• Un espace par projet : étapes, équipe, échéances, budget et dépenses
• Des bilans rédigés avec l'IA, exportables en PDF

AGENDA ET ASSEMBLÉES GÉNÉRALES
• Agenda partagé et événements avec les réponses des participants
• Assemblées générales : convocations, quorum, pouvoirs, vote des résolutions et procès-verbal
• Émargement par QR code

FACTURATION
• Devis et factures à votre logo, en HT, TVA et TTC
• Signature électronique des devis
• Factures récurrentes et relances automatiques des impayés
• Factures électroniques au format Factur-X

COMPTABILITÉ
• Recettes et dépenses, comptabilité analytique par projet
• Notes de frais
• Export du fichier des écritures comptables (FEC)

SUBVENTIONS
• Suivi de vos demandes et de leurs échéances de dépôt et de compte rendu
• Un radar des dispositifs de financement adaptés à votre profil

COMMUNICATION
• Canaux de discussion par équipe ou par projet
• E-mailings ciblés par rôle, par projet ou par liste, avec suivi des envois
• La boîte mail Gmail de votre structure reliée à Assokit : tri, rattachement aux fiches, réponses

UNE IA QUI VOUS FAIT GAGNER DU TEMPS
• Rédaction de convocations, d'appels aux dons, de publications pour les réseaux sociaux ou de demandes de subvention
• Un copilote et un coach pour piloter votre structure
• Des brouillons de réponse dans la boîte mail, que vous relisez avant tout envoi

VOS DONNÉES PROTÉGÉES
• Hébergement en France
• Conformité RGPD : export et suppression de vos données à tout moment
• Double authentification
• Aucune publicité, aucune revente de données

UN COMPTE ASSOKIT EST NÉCESSAIRE
L'application est réservée aux structures qui utilisent Assokit : connectez-vous avec votre compte, ou créez-le depuis l'application. Aucun achat n'est proposé dans l'application.

Une question ? Écrivez-nous à contact@assokit.fr
```

### Pourquoi ni prix, ni lien d'abonnement dans la fiche

Le règlement Google Play sur les paiements interdit d'orienter les utilisateurs vers un moyen de paiement autre que la facturation Google Play, **y compris depuis la fiche Play Store** (la fiche est citée en toutes lettres, au même titre que les boutons, liens et pages web de l'application). D'où les choix suivants :

- **aucun prix** (ni 49,99 € HT/mois, ni « sur devis ») ;
- **aucun lien ni appel à l'action** du type « abonnez-vous sur assokit.fr », et aucun renvoi vers une inscription sur le site à une formule payante (ni « essai PRO », ni « formule ») ;
- on dit seulement ce qui est vrai et neutre : un compte est nécessaire, il se crée depuis l'application (bouton « Créer ma démo »), et **rien ne s'achète dans l'application**.

C'est cohérent avec l'application elle-même (vérifié sur le banc avec l'agent « AssokitApp », 13/10/2026) :

- les pages d'abonnement et de paiement sont bloquées dans la WebView (`ak_block_billing_page`, `ak_is_mobile_app` dans `app-context.php`, filtre `onShouldStartLoadWithRequest` dans `mobile/App.js`) ;
- la page d'inscription `/signup`, ouverte par « Créer ma démo », n'affiche **aucun prix** dans l'application : la phrase sur le prix après l'essai est remplacée par « Aucun achat dans l'application » (`ak_billing_hidden()`, `signup.php`) ;
- les pages de présentation du site, qui annoncent le prix de l'offre (accueil, fonctionnalités, pages « logiciel-… », guides, comparatifs, blog…), **renvoient vers /connexion** dans l'application (`includes-public.php`, liste `AK_PUBLIC_APP_PAGES`) ; seules restent ouvertes les pages légales, la suppression de compte et le contact, avec un en-tête et un pied de page sans lien vers ces pages ;
- les écrans natifs n'affichent aucun prix.

À garder ainsi : toute nouvelle page publique qui affiche un prix doit passer par `render_public_head()` (redirigée d'office dans l'application) ou tester `ak_billing_hidden()`.

## 4. Notes de version « Nouveautés » — version 1.0.1 (500 caractères max)

Première version publiée sur Google Play : le numéro est 1.0.1 depuis le 18/10/2026
(`publication-android.md`, étape 9) ; le texte ne change pas.

À coller dans la release (Tests › Test interne, puis Production), champ **Notes de version**. La Console ajoute elle-même les balises de langue ; si elle les affiche, gardez-les.

```
<fr-FR>
Première version d'Assokit sur Android :
• Accueil avec les chiffres clés de votre structure
• Adhérents, cotisations, projets, agenda et assemblées
• Factures, devis, clients et scan des factures fournisseurs
• Messages d'équipe, boîte mail et notifications
• Connexion par empreinte digitale ou reconnaissance faciale
</fr-FR>
```

319 caractères entre les balises.

## 5. Éléments graphiques

Tous les fichiers sont prêts dans ce dossier ; le détail (formats, ordre des captures, méthode de production, points à revérifier sur un téléphone) est dans [`README-visuels.md`](README-visuels.md).

| Élément | Exigence Google | Fichier |
|---|---|---|
| **Icône de l'application** | PNG 512 × 512, 1 Mo max | ✅ `icon-512.png` (réduction de `mobile/assets/icon.png`) |
| **Image de présentation** (*feature graphic*) | 1024 × 500, JPEG ou PNG sans transparence — **obligatoire** | ✅ `feature-graphic-1024x500.png` |
| **Captures d'écran téléphone** | 2 minimum, 8 maximum ; JPEG ou PNG sans transparence ; côtés entre 320 et 3 840 px ; le grand côté ne dépasse pas 2 fois le petit | ✅ `captures/01-accueil.png` … `08-coach-ia.png` (1080 × 1920, avec légende), **ou** `captures-sans-legende/` (même série sans légende). N'importez qu'une des deux séries. |
| Captures tablette 7" et 10" | Facultatives | — |
| Vidéo YouTube | Facultative | — |

⚠️ **Les planches App Store de `assets/app-store/` (1290 × 2796) seraient refusées par Google** : leur rapport est de 2,17 pour 1, au-delà de la limite de 2 pour 1, et elles montrent un iPhone. Utilisez les captures de ce dossier.

Avant d'envoyer la fiche, comparez sur le build de test interne l'accueil réel à `01-accueil.png` (barre d'état vert foncé) : voir `README-visuels.md`, « À vérifier sur un vrai téléphone Android ».

## 6. Catégorie et tags

Rubrique **Développer la présence sur le Play Store › Paramètres de la fiche Play Store** (*Store settings*).

- **Type d'application** : Application
- **Catégorie** : **Entreprise** (recommandé)

  Pourquoi Entreprise plutôt que Productivité : Assokit est un outil de gestion (facturation, comptabilité, adhérents, subventions), ce qui correspond à la catégorie des logiciels de gestion et de facturation. Productivité regroupe surtout des listes de tâches et des prises de notes. C'est aussi la catégorie principale choisie sur l'App Store : la cohérence entre les deux stores aide.

- **Tags** (5 maximum, à choisir dans la liste que propose Google ; prenez les plus proches de) : Gestion d'entreprise · Facturation · Comptabilité · Gestion de projets · Agenda / planning

## 7. Coordonnées (même rubrique *Paramètres de la fiche Play Store*)

| Champ | Valeur | Visible sur la fiche |
|---|---|---|
| Adresse e-mail (obligatoire) | `contact@assokit.fr` | Oui |
| Site web | `https://assokit.fr` | Oui |
| Téléphone | Facultatif : à laisser vide sauf si vous voulez qu'il soit public | Oui |

## 8. Règles de confidentialité (URL)

```
https://assokit.fr/confidentialite
```

Vérifié dans le code :

- `/confidentialite` est servie par `confidentialite.php` (`.htaccess` : `RewriteRule ^confidentialite/?$ confidentialite.php`) — c'est la politique à jour (version du 13/10/2026), qui cite O2Switch, Resend, Stripe, Anthropic, Google, les durées de conservation et les droits RGPD.
- `/politique-confidentialite` **redirige en 301** vers `/confidentialite`. Le fichier `politique-confidentialite.php` est une **ancienne version** qui n'est plus servie (toute URL en `.php` est elle aussi redirigée). **Ne pas utiliser** cette adresse.

À déclarer à deux endroits de la Console : **Contenu de l'application › Règles de confidentialité**, et la fiche (champ « Règles de confidentialité » des paramètres de la fiche, s'il est proposé).

## 9. Récapitulatif des longueurs

| Champ | Limite | Ce fichier |
|---|---|---|
| Nom | 30 | 28 |
| Description courte | 80 | 72 |
| Description complète | 4 000 | 3 012 |
| Notes de version 1.0.1 | 500 | 319 |
