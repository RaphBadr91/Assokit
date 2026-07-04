# Assokit — Application mobile (Expo / React Native)

Application native **iOS + Android** construite avec **Expo**. Elle affiche l'application web
Assokit (`https://assokit.fr`) dans une WebView native, avec splash screen, barre de statut aux
couleurs Assokit, gestion du bouton retour Android, et **notifications push** (`expo-notifications`).

- **Bundle ID iOS** : `fr.assokit.app`
- **Package Android** : `fr.assokit.app`
- **Avantage** : avec **EAS Build**, l'iOS se compile **dans le cloud** — pas besoin de Mac/Xcode
  pour builder. (Un compte Apple Developer reste requis pour *publier* sur l'App Store.)
- **Mises à jour de contenu** : elles se font en modifiant le site (`git pull` serveur), **sans**
  re-soumettre l'app.

---

## Prérequis (une fois)

```bash
npm install -g eas-cli
eas login              # ton compte Expo (le meme que ton autre projet)
```

## Installer et tester

```bash
cd Assokit/mobile
npm install
npx expo-doctor        # verifie que les versions sont coherentes
npx expo start         # teste dans Expo Go (scanne le QR code avec ton telephone)
```
> Dans Expo Go, la WebView fonctionne. Les **notifications push** réelles ne fonctionnent qu'avec
> un *build de développement* ou un build EAS (voir ci-dessous), pas dans Expo Go.

## Lier le projet à EAS (une fois)

```bash
eas init               # cree le projectId (a coller dans app.json -> extra.eas.projectId si demande)
```

## Builder les apps (cloud)

```bash
eas build --platform android      # genere un .aab (Play Store)
eas build --platform ios          # genere un .ipa (App Store) — cloud, pas besoin de Mac
# ou les deux :
eas build --platform all
```
Au 1er build iOS, EAS te guide pour la signature (identifiants Apple Developer).

## Tester le build

- **Android** : télécharge l'`.aab`/`.apk` depuis le lien EAS, installe-le sur ton téléphone.
- **iOS** : `eas build` produit un build TestFlight → installe via **TestFlight**.

## Publier sur les stores

```bash
eas submit --platform android     # envoie sur Google Play Console
eas submit --platform ios         # envoie sur App Store Connect
```
Puis, dans les consoles, remplis les fiches (nom, description, captures, politique de
confidentialité → `https://assokit.fr/confidentialite`) et soumets pour révision.

| | Compte requis |
|---|---|
| 🤖 Google Play | Google Play Console — 25 $ une fois |
| 🍎 App Store | Apple Developer — 99 $/an |

---

## Notifications push (étape suivante)

Le client est déjà prêt (`expo-notifications` demande la permission et récupère le **Expo push
token** au lancement — visible dans les logs). Pour envoyer des notifications :
1. L'app envoie son token au serveur (à sa connexion) → on stocke le token par utilisateur.
2. Le serveur Assokit envoie une notif via l'**API Expo Push**
   (`https://exp.host/--/api/v2/push/send`) — ex. « Nouvelle facture », « Nouveau message ».

Dis-moi quand tu veux : je code le côté serveur (stockage du token + envoi) et le petit bout
JS qui transmet le token au site.

---

## Récap des commandes

```bash
cd Assokit/mobile
npm install
eas login
eas init
eas build --platform all
eas submit --platform android   # puis --platform ios
```
