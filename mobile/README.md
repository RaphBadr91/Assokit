# Assokit — Application mobile native (iOS + Android)

Projet **Capacitor** qui emballe l'application web Assokit dans de **vraies applications natives**
publiables sur l'**App Store** (Apple) et le **Google Play Store** (Android).

- **App ID** : `fr.assokit.app`
- **Nom** : `Assokit`
- **Principe** : l'app native charge `https://assokit.fr` dans une WebView native, avec un
  splash screen natif, la barre de statut aux couleurs Assokit, la gestion du bouton retour
  Android, et la base pour les **notifications push**.
- **Avantage clé** : comme l'app charge le site en direct, **99 % de tes mises à jour se font en
  modifiant le site** (git pull) — **sans re-soumettre** l'app aux stores. On ne re-soumet que si
  on change l'icône, le nom, ou qu'on ajoute une fonction native.

---

## 0. Ce qu'il te faut

| Pour… | Il te faut |
|---|---|
| **Android** | Un PC ou Mac + [Android Studio](https://developer.android.com/studio) (gratuit) + un compte **Google Play Console** (25 $ une fois) |
| **iOS** | Obligatoirement un **Mac** + [Xcode](https://apps.apple.com/app/xcode/id497799835) (gratuit) + un compte **Apple Developer** (99 $/an) |
| **Les deux** | [Node.js LTS](https://nodejs.org) installé |

> ⚠️ Le build iOS **ne peut se faire que sur un Mac** (contrainte Apple, pas Assokit).

---

## 1. Installation du projet (sur ta machine)

```bash
# Récupère le dépôt (si pas déjà fait)
git clone git@github.com:RaphBadr91/Assokit.git
cd Assokit/mobile

# Installe les dépendances
npm install
```

## 2. Génère les icônes et le splash (une fois, et à chaque changement de logo)

Les sources sont dans `resources/` (`icon.png` 1024×1024, `splash.png` 2732×2732).

```bash
npm run icons
```
Cela génère automatiquement toutes les tailles d'icônes/splash pour iOS et Android.

## 3. Ajoute les plateformes natives

```bash
npm run add:android      # crée le dossier android/
npm run add:ios          # crée le dossier ios/  (Mac uniquement)
npx cap sync             # synchronise config + plugins + web
```

---

## 4. Android — tester puis publier

### Tester
```bash
npm run open:android     # ouvre Android Studio
```
Dans Android Studio : branche ton téléphone (mode développeur activé) ou lance un émulateur,
puis clique **▶ Run**. L'app s'ouvre sur Assokit.

### Publier sur le Google Play Store
1. Dans Android Studio : **Build → Generate Signed Bundle / APK → Android App Bundle (.aab)**.
2. Crée une **clé de signature** (keystore) quand il le demande — **garde-la précieusement**
   (sans elle, impossible de mettre à jour l'app plus tard).
3. Va sur [Google Play Console](https://play.google.com/console) → **Créer une application**.
4. Remplis la fiche (nom, description, captures d'écran, icône 512×512 = `resources/`).
5. Envoie le fichier `.aab` dans **Production** (ou d'abord en **Test fermé**).
6. Validation Google : généralement quelques heures à 3 jours.

---

## 5. iOS — tester puis publier (Mac requis)

### Tester
```bash
npm run open:ios         # ouvre Xcode
```
Dans Xcode : sélectionne ton iPhone (ou un simulateur), signe avec ton **Apple Developer Team**
(onglet *Signing & Capabilities*), puis **▶ Run**.

### Publier sur l'App Store
1. Dans Xcode : **Product → Archive**.
2. **Distribute App → App Store Connect → Upload**.
3. Va sur [App Store Connect](https://appstoreconnect.apple.com) → **Mes apps → +**.
4. Remplis la fiche (nom, description, captures, mots-clés, politique de confidentialité →
   `https://assokit.fr/confidentialite`).
5. Teste via **TestFlight**, puis **Soumettre pour révision**.
6. Révision Apple : généralement 24–48 h.

> **Astuce anti-rejet (règle Apple 4.2)** : une app qui n'est « qu'un site web » peut être
> refusée. Le projet inclut déjà des fonctions natives (splash, barre de statut, retour Android,
> base push). Pour être tranquille, **active les notifications push** (section 7) : c'est le
> meilleur argument de valeur native, et ça booste l'engagement.

---

## 6. Mettre à jour l'app

- **Changement sur le site** (texte, pages, fonctionnalités) → **rien à faire côté app**, elle
  charge le site en direct. Juste `git pull` sur le serveur, comme d'habitude.
- **Changement d'icône / nom / version / fonction native** → refaire `npx cap sync`, rebuild,
  et re-soumettre aux stores (nouvelle version).

---

## 7. Notifications push (étape suivante recommandée)

Le plugin `@capacitor/push-notifications` est déjà installé. Pour l'activer, il faudra :
- **Android** : créer un projet [Firebase](https://console.firebase.google.com) (FCM), déposer
  le `google-services.json` dans `android/app/`.
- **iOS** : activer *Push Notifications* dans Xcode + créer une clé APNs sur le compte Apple Developer.
- **Côté serveur** : stocker le « device token » (envoyé par l'app à sa connexion) et envoyer les
  notifications via FCM/APNs.

Dis-moi quand tu veux : je te prépare le code (client + serveur) pour envoyer par ex. une notif
« Nouvelle facture », « Nouveau message », ou pour tes relances.

---

## Résumé des commandes

```bash
cd Assokit/mobile
npm install
npm run icons
npm run add:android      # + npm run add:ios sur Mac
npx cap sync
npm run open:android     # puis Run / Build signé
npm run open:ios         # puis Archive / Upload (Mac)
```
