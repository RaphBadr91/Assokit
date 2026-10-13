# « Contenu de l'application » — réponses pour la Play Console

Play Console › votre application › menu de gauche, tout en bas : **Règles et programmes › Contenu de l'application** (*Policy and programs › App content*). Chaque carte se remplit une fois ; elles doivent toutes être au vert avant la première mise en production. L'ordre ci-dessous est celui du tableau de bord.

---

## 1. Règles de confidentialité

Carte **Règles de confidentialité** › **Commencer** :

```
https://assokit.fr/confidentialite
```

Enregistrer. (Ne pas mettre `/politique-confidentialite` : c'est une redirection vers cette même page.)

---

## 2. Accès à l'application (App access)

Toutes les fonctions sont derrière la connexion : sans identifiants, l'examinateur ne dépasse pas l'écran d'accueil et l'application est refusée.

Carte **Accès à l'application** › **Commencer** :

1. Cocher **« Tout ou partie des fonctionnalités sont soumises à des restrictions »** (*All or some functionality is restricted*).
2. Cliquer **« Ajouter des instructions »** et remplir le **premier jeu** :

| Champ | Valeur |
|---|---|
| Nom des instructions | `Compte de démonstration (administrateur)` |
| Nom d'utilisateur | `admin@demo-f.assokit.fr` |
| Mot de passe | `DemoFormation2026!` |
| Case « Aucune autre information n'est nécessaire pour accéder à mon application » | **Décochée** : coller le texte ci-dessous dans « Autres informations nécessaires pour accéder à votre application » |

Autres informations (anglais, pour l'examinateur — 475 caractères) :

```
Shared demo organisation "DEMO F" with sample data (reset every night; e-mails and SMS are simulated, nothing is really sent). Open the app, tap "Se connecter", enter the e-mail and password, tap "Se connecter". Then: home dashboard, bottom tabs (Projets, Membres, Plus), the "+" button, Plus > Factures, Agenda, Messages, Boîte mail. Privacy policy: login screen, or Plus > Paramètres > Politique de confidentialité. The app is in French. No purchase is possible in the app.
```

3. **« Ajouter des instructions »** encore, pour un **deuxième jeu** (sert à montrer la suppression de compte) :

| Champ | Valeur |
|---|---|
| Nom des instructions | `Compte membre (suppression de compte)` |
| Nom d'utilisateur | `membre@demo-f.assokit.fr` |
| Mot de passe | `DemoFormation2026!` |
| Case « Aucune autre information… » | **Décochée** |

Autres informations (479 caractères) :

```
Use this member account to test account deletion: Plus > Paramètres > "Supprimer mon compte (RGPD)" (bottom) > password > "Supprimer définitivement". This shared demo account is protected so the demo stays usable: the last step says deletion is disabled here, and nothing is deleted. On a real account this step anonymises the personal data at once and signs the user out. The admin cannot delete itself (only admin; reason shown). Web page: https://assokit.fr/suppression-compte
```

4. Laisser décoché « Autoriser Android à utiliser les identifiants… » s'il est proposé. **Enregistrer**.

### Pourquoi deux comptes

Vérifié dans le code :

- `admin@demo-f.assokit.fr` est la **seule administratrice** de DEMO F (`demo-formation/00-organisation.php`). La règle `ak_account_delete_blocker()` (`app-context.php`) interdit au dernier administrateur de supprimer son compte : dans l'application, « Supprimer mon compte (RGPD) » est **grisé**, avec la raison écrite dessous. C'est le comportement voulu, mais un examinateur qui ne verrait que ça pourrait conclure que la suppression ne marche pas.
- `membre@demo-f.assokit.fr` (rôle membre, même mot de passe) voit le bouton **actif**, ouvre la confirmation et saisit le mot de passe. Comme tous les comptes partagés de la démo (`ak_demo_protected_account()`, `demo-guard.php`), la dernière étape ne supprime rien — sinon le premier examinateur supprimerait la démo pour tous les suivants. Le message affiché (`api/app-delete-account.php`, mis en ligne avec le site) n'est plus une erreur sèche ; il dit ce que fait l'étape sur un vrai compte : « Compte de démonstration partagé : sa suppression est désactivée pour que la démo reste utilisable (elle est remise à zéro chaque nuit). Sur un vrai compte, cette étape anonymise immédiatement les données personnelles et ferme la session. »

### Pourquoi pas `demo-review@assokit.fr`

`api/app-delete-account.php` simule bien une suppression réussie pour `demo-review@assokit.fr` (compte créé par `api/seed-demo-account.php`). Mais ce compte est la **seule administratrice** de son association « Focus Point Asso », qui compte 120 adhérents : `ak_account_delete_blocker()` lui refuse la suppression, l'application grise le bouton et affiche « Vous êtes le seul administrateur… », exactement comme pour `admin@demo-f`. La simulation n'est donc jamais atteinte depuis l'application. Par ailleurs, une suppression simulée laisse le compte en place : un examinateur qui se reconnecte ensuite constaterait que rien n'a été supprimé. Le compte membre de DEMO F reste le meilleur choix.

Si Google refusait malgré tout l'application pour ce motif : répondre dans la Console en citant le message ci-dessus et la page `https://assokit.fr/suppression-compte`. Autre solution, à demander si besoin : un sixième compte de démonstration, non protégé, **réellement** anonymisé quand l'examinateur le supprime et recréé par la remise à zéro de la nuit (inconvénient : il n'est plus utilisable par un autre examinateur avant la nuit suivante).

### Ce que l'examinateur doit voir (parcours conseillé)

1. Écran d'accueil vert › **Se connecter** › e-mail + mot de passe › **Se connecter**.
2. **Accueil** natif : chiffres clés, notifications, raccourcis.
3. Onglets du bas : **Projets**, **Membres**, bouton **+** (création rapide), **Plus**.
4. **Plus** : Adhérents, Agenda, Cotisations, Subventions, Assemblées, Émargement, Factures, Devis, Clients, Statistiques, Boîte mail, Messages, Notifications, Communication, Coach IA, Paramètres, Support.
5. **+ › Scanner une facture** : demande l'accès à l'appareil photo (c'est là que la permission `CAMERA` sert).
6. **Plus › Paramètres** : profil, sécurité, **informations légales** (politique de confidentialité, aide à la suppression du compte, conditions générales), déconnexion, suppression du compte. Les liens « Confidentialité » et « Conditions générales » sont aussi sous le formulaire de connexion et sur l'écran d'accueil.

Avant chaque envoi en examen, se connecter soi-même avec ces deux comptes **depuis l'application** (pas depuis le navigateur) pour vérifier que la démo répond.

---

## 3. Annonces (Ads)

**« Non, mon application ne contient pas d'annonces »**. Enregistrer.

Aucun SDK publicitaire, aucune bannière d'autopromotion dans l'application (les encarts d'abonnement du site sont masqués dans l'application).

## 4. Identifiant publicitaire (Advertising ID)

S'il est demandé (carte « Identifiant publicitaire ») : **« Non, mon application n'utilise pas l'identifiant publicitaire »**.

La permission `com.google.android.gms.permission.AD_ID` est bloquée dans `mobile/app.json` (`android.blockedPermissions`) : même si une bibliothèque l'ajoutait, elle est retirée du manifeste final. Sans ce blocage, la Console refuse la version quand la réponse est « Non » et que le manifeste contient la permission.

---

## 5. Classification du contenu (questionnaire IARC)

Carte **Classification du contenu** › **Commencer** :

1. **Adresse e-mail** : `contact@assokit.fr`
2. **Catégorie** : **« Utilitaire, productivité, communication ou autre »** (en anglais *Utility, Productivity, Communication, or Other*). Ne pas choisir « Réseaux sociaux » : la messagerie est interne à chaque association, ce n'est pas l'objet de l'application.
3. **J'accepte les conditions d'utilisation de l'IARC** › **Suivant**.
4. Questionnaire — répondre **Non** à tout, **sauf** à la question sur l'interaction entre utilisateurs :

| Thème | Réponse |
|---|---|
| Violence, peur, horreur | Non |
| Sexualité, nudité | Non |
| Langage grossier, humour vulgaire | Non |
| Drogues, alcool, tabac | Non |
| Jeux d'argent (réels ou simulés) | Non |
| Symboles haineux (croix gammée, etc.) | Non |
| **Les utilisateurs peuvent-ils interagir ou échanger du contenu (texte, images, sons) entre eux ?** | **Oui** |
| L'application partage-t-elle la position physique de l'utilisateur avec d'autres utilisateurs ? | Non |
| L'application permet-elle d'acheter des biens numériques ? | Non |
| L'application est-elle un navigateur web ou un moteur de recherche ? | Non (la WebView n'affiche que assokit.fr ; les liens externes s'ouvrent dans le navigateur du téléphone) |
| L'application est-elle principalement un produit d'actualités ou éducatif ? | Non |

Le libellé exact des questions peut varier d'une version du questionnaire à l'autre ; la logique reste : **seule l'interaction entre utilisateurs est « Oui »**.

Si une question porte sur la **modération** des échanges, répondre Oui. Moyens réels, vérifiés dans le code :

- les échanges (canaux de discussion, messages de projet) sont **privés**, limités aux membres d'une même association, inscrits par ses administrateurs : pas de profils publics, pas de messagerie avec des inconnus ;
- un **administrateur peut supprimer n'importe quel message** d'un canal (`action-message-canal.php`, action `delete`) et **désactiver ou retirer le compte** d'un membre ;
- tout utilisateur peut **signaler un problème** au support (Plus › Support, ou contact@assokit.fr) ;
- les conditions générales interdisent les usages illicites et le spam (`cgu.php`, § 7).

5. **Enregistrer** › **Calculer la classification** › **Appliquer la classification**.

Résultat attendu : la classification la plus basse (PEGI 3 / équivalents), avec la mention « Les utilisateurs interagissent ».

---

## 6. Public cible et contenu (Target audience)

Carte **Public cible et contenu** › **Commencer** :

1. **Tranches d'âge cibles** : cocher **uniquement « 18 ans et plus »**. Suivant.
2. **L'application pourrait-elle attirer involontairement les enfants ?** : **Non** (outil de gestion professionnel : comptabilité, facturation, assemblées générales ; inscription réservée aux associations et structures professionnelles, `cgu.php` § 3).
3. Enregistrer.

Comme aucun groupe de moins de 13 ans n'est coché, le programme « Familles » ne s'applique pas.

---

## 7. Sécurité des données

Voir le fichier [`securite-des-donnees.md`](securite-des-donnees.md) (réponses case par case et clics).

URL de suppression de compte à déclarer dans ce formulaire :

```
https://assokit.fr/suppression-compte
```

---

## 8. Applications d'actualités (News apps)

**« Non, mon application n'est pas une application d'actualités »**. Enregistrer.

## 9. Applications de recherche des contacts et de suivi de l'état de santé liées au COVID-19

**« Mon application n'est pas une application de recherche des contacts ni de suivi de l'état de santé liées au COVID-19 »**. Enregistrer.

## 10. Applications gouvernementales

**« Non »**. Assokit est un logiciel édité par une entreprise privée (RBPS). L'« espace collectivité » proposé aux mairies n'en fait pas une application publiée par ou pour le compte d'un gouvernement.

## 11. Fonctionnalités financières (Financial features)

Cocher **« Mon application ne propose aucune fonctionnalité financière »** (*My app doesn't provide any financial features*). Enregistrer.

Pourquoi, vérifié dans le code :

- Assokit **enregistre** des informations de gestion : factures, devis, paiements de cotisation saisis à la main (virement, chèque, espèces…), dépenses, comptabilité, export FEC. Ce sont des outils de **tenue de comptes et de facturation**, pas un service financier.
- **Aucun argent ne circule par Assokit** dans l'application : pas de paiement, pas de virement, pas de porte-monnaie, pas de connexion à un compte bancaire, pas de prêt, de crédit, d'investissement, de cryptoactif ni d'assurance. Le moyen de paiement « Stripe » d'un paiement de cotisation n'est qu'une étiquette sur un paiement déjà reçu ailleurs.
- Le seul paiement en ligne du service (l'abonnement à Assokit via Stripe) se fait sur le **site**, jamais dans l'application.

Si la Console présente une liste détaillée (services bancaires, prêts, paiements et virements, achats à crédit, trading, services d'assistance…), ne rien cocher d'autre que l'option « aucune fonctionnalité financière ».

## 12. Applications de santé

**« Mon application n'a pas de fonctionnalités liées à la santé »** (ne cocher aucune catégorie). Enregistrer.

---

## 13. Autorisations sensibles

Avec les permissions actuelles, **aucun formulaire de déclaration n'est demandé** :

| Permission | Pourquoi | Déclaration Play |
|---|---|---|
| `CAMERA` | Scanner une facture (+ › Scanner une facture) | Aucune ; Android la demande au premier scan |
| `USE_BIOMETRIC`, `USE_FINGERPRINT` | Connexion par empreinte ou visage | Aucune (permissions « normales ») |
| `POST_NOTIFICATIONS`, `RECEIVE_BOOT_COMPLETED` | Notifications | Aucune |
| `READ/WRITE_EXTERNAL_STORAGE` (Android 12 et moins seulement) | Ajoutées par les modules image et fichiers | Aucune |

Pas de `READ_MEDIA_IMAGES` / `READ_MEDIA_VIDEO` (le sélecteur de photos du système est utilisé), pas de localisation, pas de SMS ni de journal d'appels, pas de service de premier plan : aucune des déclarations « Autorisations relatives aux photos et vidéos », « Service de premier plan », « Alarmes exactes » ou « Intent plein écran » ne doit apparaître. Si la Console en réclame une après l'envoi d'un `.aab`, c'est qu'un module a ajouté une permission : la signaler avant de répondre.
