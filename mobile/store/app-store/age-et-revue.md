# Classification par âge, conformité et informations pour la vérification (App Review)

Tout ce qu'App Store Connect demande en plus de la fiche et de la confidentialité, avec les
réponses vérifiées dans le code. Les libellés exacts peuvent changer d'une version de la
console à l'autre : la logique des réponses, elle, reste valable.

---

## 1. Classification par âge

App Store Connect › **Apps › Assokit › Informations sur l'app** (*App Information*) ›
**Classification par âge** › **Modifier** (ou **Configurer**).

Depuis 2025, Apple utilise des classifications **4+, 9+, 13+, 16+ et 18+**, avec un
questionnaire en plusieurs parties : contrôles dans l'app, fonctions (*capabilities*),
thèmes. Réponses :

### Contrôles dans l'app (*In-App Controls*)

| Question | Réponse | Pourquoi |
|---|---|---|
| Contrôle parental (*Parental Controls*) | **Non** | Aucun |
| Vérification de l'âge (*Age Assurance*) | **Non** | Aucune ; l'inscription est réservée aux structures (CGU § 3) |

### Fonctions de l'app (*Capabilities*)

| Question | Réponse | Pourquoi |
|---|---|---|
| Accès Web sans restriction (*Unrestricted Web Access*) | **Non** | La WebView n'ouvre que `assokit.fr` et ses sous-domaines (`isAssokitUrl` dans `App.js`) ; tout autre lien part dans Safari. Ce n'est pas un navigateur. |
| Contenu généré par les utilisateurs (*User-Generated Content*) | **Oui** | Projets, documents, comptes rendus partagés **à l'intérieur d'une association** |
| Messagerie et discussion (*Messaging and Chat*) | **Oui** | Canaux de discussion entre membres d'une même association |
| Publicité (*Advertising*) | **Non** | Aucune publicité, ni de tiers ni d'Assokit |

### Thèmes (fréquence : Aucune / Rare / Fréquente)

Répondre **Aucune** (ou **Non**) à tout : grossièretés ou humour vulgaire ; horreur ou
peur ; alcool, tabac ou drogues ; thèmes adultes ou suggestifs ; nudité, contenu sexuel ;
violence de dessin animé, réaliste, prolongée ; armes ; informations médicales ou de
traitement ; santé et bien-être ; jeux d'argent simulés ; concours ; jeux d'argent réels ;
coffres à butin (*loot boxes*).

Assokit est un outil de gestion : comptabilité, factures, adhérents, agenda. Les
contenus libres (messages, documents) sont ceux d'une association, entre ses membres.

### Résultat attendu

**4+** en principe. Si App Store Connect calcule une classification plus élevée à cause
de la messagerie ou des contenus des utilisateurs, l'accepter telle quelle : c'est sans
conséquence pour un outil professionnel. Ne pas « forcer » une classification plus basse.

Ne pas cocher **« Conçue pour les enfants »** (*Made for Kids*) : le public visé est
adulte (Google Play : « 18 ans et plus »).

### Modération des messages (règle 1.2)

Apple demande aux apps avec du contenu d'utilisateurs un moyen de filtrer, de signaler, de
bloquer et un contact. Ce qui existe, vérifié dans le code et sur le banc le 18/10/2026 :

- les échanges sont **privés**, limités aux membres d'une même association, inscrits par ses
  administrateurs : pas de profil public, pas de messages d'inconnus ;
- **dans l'app** (Plus › Messages › un canal), un **appui long sur un message** ouvre une
  feuille d'actions (`NativeChat`, `App.js`) :
  - **« Signaler ce message »** : ouvre un ticket de support pré-rempli (canal, identifiant du
    message, auteur, extrait), priorité haute, que l'équipe Assokit reçoit comme tout ticket
    (alerte par e-mail et dans la cloche) ; la personne le suit dans **Plus › Support**
    (`api/app-message-action.php`, action `report`) ;
  - **« Masquer les messages de cette personne »** : ses messages disparaissent de tous les
    canaux, sur cet appareil et pour ce compte (liste gardée dans le trousseau,
    `expo-secure-store`) ; un bandeau « N messages masqués · Réafficher » permet de revenir
    en arrière ;
  - **« Supprimer le message »** : pour son auteur et pour un administrateur, avec les règles
    du site (`action-message-canal.php`, reprises dans `api/app-message-action.php`, action
    `delete`) ;
- un administrateur peut aussi **désactiver ou retirer un membre** (`supprimer-adherent.php`) ;
- tout utilisateur peut écrire au support (**Plus › Support**) ou à `contact@assokit.fr` ;
- les CGU interdisent les contenus illicites et le spam (`cgu.php` § 7).

---

## 2. Conformité de l'exportation (chiffrement)

Rien à faire. `app.json › ios.infoPlist.ITSAppUsesNonExemptEncryption = false` (vérifié
dans l'`Info.plist` généré par `npx expo prebuild`) : l'app n'utilise que le chiffrement
fourni par iOS (HTTPS, trousseau), ce qui est exempté. App Store Connect et TestFlight ne
posent donc plus la question à chaque build, et aucune déclaration de chiffrement
française n'est demandée.

Si la console la pose quand même : l'app **n'utilise pas de chiffrement non exempté** ;
elle utilise uniquement le chiffrement intégré à iOS (HTTPS).

## 3. Droits sur le contenu (*Content Rights*)

App Store Connect › **Informations sur l'app** › **Droits sur le contenu** :
« Votre app contient-elle, affiche-t-elle ou donne-t-elle accès à du contenu de tiers ? »
→ **Non**.

L'app affiche les données de l'association de l'utilisateur et les textes qu'il fait
rédiger par l'IA. Aucun contenu sous licence (musique, vidéo, presse, images de banques).

## 4. Identifiant publicitaire (IDFA)

Si la question apparaît (anciennes versions de la console) : **Non**, l'app n'utilise pas
l'identifiant publicitaire. Aucun SDK de publicité, pas de demande App Tracking
Transparency, et `NSPrivacyTracking = false` dans le manifeste de confidentialité.

## 5. Statut de professionnel (Digital Services Act, Union européenne)

Obligatoire pour être distribué dans l'UE (donc en France). App Store Connect ›
**Informations sur l'app** › **Digital Services Act** (ou **Business › Conformité DSA** pour
le compte) : déclarer **« Je suis un professionnel »** (*trader*) — Assokit est un service
commercial. Apple demande alors une **adresse, un téléphone et un e-mail**, vérifiés par
code, et **les affiche sur la fiche App Store dans l'UE**. Utiliser des coordonnées
professionnelles (celles de RBPS, `contact@assokit.fr`) plutôt que personnelles.

---

## 6. Informations pour la vérification de l'app (*App Review Information*)

App Store Connect › onglet **Distribution** › version **iOS 1.0.1** › tout en bas,
**Informations pour la vérification de l'app**.

### Connexion requise (*Sign-In Information*)

| Champ | Valeur |
|---|---|
| Connexion requise | **cochée** |
| Nom d'utilisateur | `admin@demo-f.assokit.fr` |
| Mot de passe | `DemoFormation2026!` |

Le second compte (membre, pour la suppression de compte) est donné dans les notes : la
console n'a qu'un seul couple identifiant / mot de passe.

Vérifié dans le code : ces comptes n'ont pas de double authentification, leur mot de passe
ne peut pas être changé (`demo-guard.php`), les e-mails et SMS qu'ils envoient sont
simulés, et une erreur de saisie ne bloque que l'adresse IP de l'examinateur, pas le
compte. Les comptes gardent leur identifiant d'une nuit à l'autre : la remise à zéro de
minuit ne déconnecte personne.

### Coordonnées de contact (non publiques)

| Champ | Valeur |
|---|---|
| Prénom | `[Prénom du fondateur]` |
| Nom | `[Nom du fondateur]` |
| Téléphone | `[+33 6 XX XX XX XX]` (format international, joignable en anglais aux heures de bureau américaines si possible) |
| E-mail | `contact@assokit.fr` |

### Notes (en anglais, 3713 caractères sur 4 000)

```
Assokit is the iPhone companion app of assokit.fr, a management service used by French non-profit associations and small businesses: members and membership fees, projects, calendar and general meetings, quotes and invoices, internal team messaging. The app is in French.

SIGN-IN
Use the account entered in Sign-In Information: admin@demo-f.assokit.fr / DemoFormation2026!
Green welcome screen > "Se connecter" > e-mail and password > "Se connecter".
It is the administrator of "DEMO F", a sample organisation with fictional data. E-mails and text messages sent from this demo are simulated: nothing leaves our server. The sample data is reset every night around midnight, Paris time.

WHAT TO LOOK AT (native screens)
- Home: key figures, today's events, quick actions.
- Bottom tabs: Projets (project detail: steps, budget, team), Membres, the "+" button (new invoice, quote, client, member, membership fee received, event, project, grant), Plus.
- Plus: Factures, Devis, Clients, Statistiques, Agenda, Cotisations, Subventions, Assemblées, Emargement, Messages (team channels), Boîte mail (shared mailbox sorted by AI), Notifications, Communication, Coach IA, Paramètres, Support.
- "+" > "Scanner une facture": take a photo of a supplier invoice; the amounts are read by AI. Camera or photo access is asked only at that moment.
- On Cotisations, "Enregistrer un paiement" only records a fee a member paid to the association offline (cash, cheque, bank transfer); nothing is paid in the app.
- Face ID: after signing in, the next launch is unlocked with Face ID.
- Push notifications: new messages, mentions, project steps assigned, being added to a team. They are optional.
- Monetary amounts on screen are the organisation's own bookkeeping (its invoices to its clients, fees received from its members), not prices of our service.

NOTHING IS SOLD IN THE APP
Assokit is sold to organisations (associations and small businesses), which subscribe outside the app. The iPhone app only signs in to existing accounts: it has no sign-up, offers no in-app purchase and shows no price, plan, trial or subscription status, and no link to a purchase page. It is a free companion app to our web service, in line with guidelines 3.1.3(f) and 3.1.3(c).

ACCOUNT DELETION (5.1.1(v))
Plus > Paramètres > "Supprimer mon compte (RGPD)" at the bottom > password > "Supprimer définitivement". Personal data is anonymised at once and the user is signed out. Also explained at https://assokit.fr/suppression-compte
- The admin demo account cannot delete itself: it is the organisation's only administrator, and the reason is shown under the greyed button.
- Member account membre@demo-f.assokit.fr / DemoFormation2026! shows the full flow; this shared demo account is protected at the last step (nothing is deleted, the message says so) so that the demo keeps working for the next reviewer.

USER-GENERATED CONTENT
Messages and documents are only shared inside one organisation, between members its administrators invited. There are no public profiles and no contact with strangers. In Plus > Messages, long-press any message to report it or hide its author; admins can delete it. A report opens a support ticket that our team reviews. Administrators can also deactivate or remove a member. Our terms forbid unlawful content and spam.

PRIVACY
No advertising, no tracking, no third-party analytics SDK in the app. AI features send only the content needed to Anthropic, after an in-app consent prompt (it can be changed in Paramètres > Fonctions IA). The web view only shows assokit.fr; other links open in Safari. Privacy policy: Paramètres > "Politique de confidentialité", and on the sign-in screen.

Thank you for your review.
```

### Pièce jointe

Facultative. Pas nécessaire : le compte de démonstration suffit.

### Avant chaque envoi en vérification

- [ ] Se connecter **depuis l'app** (build TestFlight) avec `admin@demo-f.assokit.fr`, puis
      avec `membre@demo-f.assokit.fr` : la démo répond, les écrans sont remplis.
- [ ] Les fichiers PHP modifiés ou ajoutés le 18/10/2026 sont **en ligne** sur assokit.fr
      (voir `publication-ios.md`, étape 0, et `conformite-ios.md` § C) : l'examinateur teste
      l'app contre le serveur réel. Sans eux, l'appui long sur un message, l'accord pour l'IA
      et le blocage de l'inscription sur iPhone ne fonctionnent pas.
- [ ] `https://assokit.fr/suppression-compte` et `https://assokit.fr/confidentialite`
      répondent sans connexion.
- [ ] La démo a été remise à zéro depuis votre propre essai (la remise à zéro de minuit efface
      aussi les accords pour l'IA des comptes DEMO F) : l'examinateur doit voir la question
      « Fonctions IA » à sa première fonction IA.
