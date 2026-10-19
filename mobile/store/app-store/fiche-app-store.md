# Fiche App Store — Assokit (iPhone)

Tout ce qu'il faut coller dans App Store Connect pour la version **1.0.1** iOS (la première
version publiée sur l'App Store ; voir `publication-ios.md`, étape 3, pour le numéro).
Langue principale de la fiche : **Français (France)**. Les longueurs ont été mesurées par
programme, en caractères Unicode (un accent compte pour un) **et** en octets UTF-8 pour
les mots-clés, que certains outils d'Apple comptent en octets. Tableau récapitulatif en
fin de fichier.

Règles appliquées, vérifiées ligne par ligne :

- chaque phrase de la description correspond à une fonction présente dans le code
  (application ou site) ; ce sont les mêmes fonctions que la fiche Google Play
  (`../google-play/fiche-google-play.md`), vérifiées le 13/10/2026 ;
- **aucun prix, aucun mot sur l'abonnement, l'essai, le paiement ou un achat**, aucun lien
  ni appel à acheter (règle 3.1.1 d'Apple : elle vise aussi les métadonnées) ;
- aucun chiffre, avis client, classement ou récompense ;
- aucun nom d'une autre plateforme mobile (règle 2.3.10) ni d'un concurrent.

Où : **App Store Connect › Apps › Assokit**, puis l'onglet **Distribution** (version iOS
1.0.1) pour la description, les mots-clés et les URL ; **Informations sur l'app** (*App
Information*) pour le nom, le sous-titre et les catégories.

---

## 1. Nom (30 caractères max) — *Informations sur l'app*

```
Assokit – Gestion asso & TPE
```

28 caractères. Même nom que sur Google Play. Sur l'iPhone, l'icône garde le nom court
**« Assokit »** (`app.json › expo.name`) : Apple l'accepte, le nom de la fiche commence
par le même mot.

Si App Store Connect refuse le nom (déjà pris par une autre app), variante :
`Assokit – Asso & TPE` (20).

## 2. Sous-titre (30 caractères max) — *Informations sur l'app*

```
Adhérents, factures et projets
```

30 caractères (la limite exacte). Il ne répète aucun mot du nom : Apple indexe déjà le nom
et le sous-titre pour la recherche, chaque mot nouveau compte.

## 3. Texte promotionnel (170 caractères max) — *version iOS*

Modifiable à tout moment, **sans nouvelle soumission**.

```
Adhérents, cotisations, projets, agenda, factures et messages d'équipe : pilotez votre association ou votre TPE depuis votre iPhone.
```

132 caractères.

## 4. Description (4 000 caractères max) — *version iOS*

```
Assokit réunit dans une seule application la gestion d'une association loi 1901 ou d'une petite entreprise : adhérents et cotisations, projets, agenda et assemblées générales, devis et factures, comptabilité, subventions et communication.

L'application donne accès à votre espace Assokit depuis votre iPhone, avec des écrans conçus pour le mobile.

DANS VOTRE POCHE
• Un accueil avec vos chiffres clés : budget engagé, encaissements, factures en retard, événements à venir
• Le bouton « + » pour créer en un geste, selon votre profil, une facture, un devis, un client, un adhérent, une cotisation reçue, un événement, un projet ou une subvention
• Le scan des factures fournisseurs : photographiez la facture, l'IA lit le fournisseur, la date et les montants HT, TVA et TTC
• Les notifications sur votre iPhone
• Le déverrouillage par Face ID ou Touch ID, selon votre iPhone

ADHÉRENTS ET COTISATIONS
• Un fichier d'adhérents à jour, avec des rôles adaptés : administrateur, coordinateur, référent, membre
• Campagnes de cotisation, cotisations reçues, suivi de qui est à jour
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
Votre compte vous est créé par votre association ou votre entreprise. Nouvelle structure ? Demandez la création de votre espace depuis l'application : notre équipe examine la demande sous 48 h et vous confirme l'activation par e-mail.

Une question ? Écrivez-nous à contact@assokit.fr
```

3030 caractères.

### Ce qui a été écarté, et pourquoi

- **« Aucun achat n'est proposé dans l'application »**, présent dans la fiche Google Play :
  retiré ici. Même formulée à la négative, la phrase parle d'achat ; Apple lit les
  métadonnées avec la règle 3.1.1. La consigne pour cette fiche est de ne parler ni
  d'abonnement ni de paiement.
- **« un paiement de cotisation »** et **« paiements enregistrés »** (fiche Google Play) :
  devenus « une cotisation reçue » et « cotisations reçues ». C'est la même fonction
  (noter qu'un adhérent a payé sa cotisation à l'association), mais un examinateur qui
  survole le texte ne doit pas y voir un paiement dans l'application.
- **« ou vous le créez depuis l'application »** (fin de la description) : retiré le 18/10/2026,
  puis remplacé le 19/10/2026 par « Nouvelle structure ? Demandez la création de votre espace
  depuis l'application : notre équipe examine la demande sous 48 h et vous confirme
  l'activation par e-mail. » Sur iPhone, la création d'un compte est une **demande** examinée
  par l'équipe : rien n'est actif avant la validation, aucun essai ne commence dans l'app,
  aucun prix ni formule n'est affiché (`conformite-ios.md` § B1). Un accès d'essai ouvert
  dans l'app puis impossible à prolonger dans l'app est le motif de refus 3.1.1 le plus
  courant pour une app B2B : la phrase ne parle donc ni d'essai, ni de gratuité, ni
  d'abonnement.
- « empreinte digitale » : remplacé par **Face ID ou Touch ID** (vocabulaire iPhone ;
  `expo-local-authentication` gère les deux).
- Ce que disait l'ancienne fiche `docs/app-store-fiche.md` et qui n'est plus permis ou
  pas exact : « Les abonnements se souscrivent sur assokit.fr » (appel à acheter
  ailleurs : motif de rejet 3.1.1), « reçus fiscaux » (Assokit n'émet pas de reçus
  fiscaux ; l'IA rédige une lettre de remerciement qui peut citer le numéro d'un reçu).
  Les codes QR de contact (`mon-asso-qr`) et la prospection téléphonique (`prospection`)
  existent bien, mais seulement dans les pages du site ouvertes dans l'app : ils restent
  hors de la description, comme sur Google Play.

## 5. Mots-clés (100 max, virgules sans espace) — *version iOS*

```
association,loi 1901,cotisation,bénévole,devis,facturation,subvention,comptabilité,club,agenda
```

94 caractères, 97 octets : sous la limite dans les deux façons de compter.

- Aucun mot du nom ni du sous-titre (« Assokit », « gestion », « asso », « TPE »,
  « adhérents », « factures », « projets ») : Apple les indexe déjà.
- Aucun nom de concurrent, ni « gratuit », ni prix.
- « club » : les clubs sportifs sont des associations (domaine « Sport » proposé à
  l'inscription).

## 6. URL — *version iOS* et *Informations sur l'app*

| Champ | Valeur | Remarque |
|---|---|---|
| **URL d'assistance** (obligatoire) | `https://assokit.fr/contact` | Formulaire de contact + `contact@assokit.fr` |
| **URL marketing** (facultative) | **laisser vide** pour la première soumission | Voir ci-dessous |
| **URL de la politique de confidentialité** (rubrique *Confidentialité de l'app*) | `https://assokit.fr/confidentialite` | Pas `/politique-confidentialite` (redirection vers l'ancienne adresse) |

**Pourquoi laisser l'URL marketing vide.** Elle fait partie des métadonnées. La page
d'accueil `https://assokit.fr` et la page `https://assokit.fr/application` affichent le
prix de l'offre et un bouton « Essai gratuit » : un examinateur peut y voir un lien vers
un achat hors de l'App Store (règle 3.1.1). Le champ est facultatif ; on pourra le
remplir après la première validation. L'URL d'assistance, obligatoire, pointe vers la
page Contact.

## 7. Catégories — *Informations sur l'app*

- **Catégorie principale : Économie et entreprise** (*Business*)
- **Catégorie secondaire : Productivité** (*Productivity*)

Même logique que Google Play (catégorie Entreprise) : Assokit est un logiciel de gestion
(facturation, comptabilité, adhérents, subventions).

## 8. Copyright — *version iOS*, rubrique *Informations générales*

```
2026 Raphaël Badr Pujol-Siwane
```

Vérifié dans `mentions-legales.php` : **RBPS exploite** le site et le service, mais
**l'auteur et titulaire de l'ensemble des droits** sur Assokit (code, interfaces, marque
INPI n° 26 5259962) est **Raphaël Badr Pujol-Siwane**. Le champ Copyright d'Apple attend le
titulaire des droits exclusifs : c'est lui.

Le compte Apple Developer est au nom de **FOCUS POINT AGENCY** : c'est elle qui apparaîtra
comme **vendeur** (« Seller ») sur la fiche. Le champ Copyright reste celui du **titulaire
des droits**, Raphaël Badr Pujol-Siwane : vendeur et titulaire des droits peuvent être
différents. Les notes de vérification le précisent (rubrique « PUBLISHER » de
`age-et-revue.md`) et `attestation-autorisation.md` contient l'autorisation de publication
à signer, à joindre si Apple la demande (règle 5.2.1).

## 9. Notes de version (« Nouveautés »)

**App Store Connect n'affiche pas ce champ pour la toute première version** d'une app.
Le texte ci-dessous servira pour la version suivante (1.0.2), ou si Apple le demande :

```
Première version d'Assokit sur iPhone :
• Accueil avec les chiffres clés de votre structure
• Adhérents, cotisations, projets, agenda et assemblées
• Factures, devis, clients et scan des factures fournisseurs
• Messages d'équipe, boîte mail et notifications
• Déverrouillage par Face ID
```

286 caractères (limite : 4 000).

## 10. Captures d'écran

Prêtes dans ce dossier : `captures-6.9/01-accueil.png` … `08-coach-ia.png`
(1290 × 2796, iPhone 6,9 pouces), détail et méthode dans
[`README-visuels.md`](README-visuels.md). Aucune capture iPad à fournir
(`ios.supportsTablet: false`). Apple réduit lui-même les captures pour les autres tailles
d'iPhone. Elles montrent l'application en cours d'utilisation, avec des données (règle
2.3.3), et aucun prix Assokit.

**Les planches `assets/app-store/maquette-*.jpg` ne sont plus à utiliser** : cadres vides,
textes qui annoncent des écrans absents (« Codes QR », « reçus fiscaux », « Fait en
France »).

## 11. Récapitulatif des longueurs

| Champ | Limite Apple | Ce fichier |
|---|---|---|
| Nom | 30 | 28 |
| Sous-titre | 30 | 30 |
| Texte promotionnel | 170 | 132 |
| Description | 4 000 | 3030 |
| Mots-clés | 100 | 94 caractères (97 octets) |
| Notes de version | 4 000 | 286 |
