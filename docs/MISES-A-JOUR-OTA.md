# 🔄 Mises à jour par-dessus l'air (OTA)

Corriger l'app sans repasser par App Store Connect ni la Play Console, et sans
attendre une revue. C'est `expo-updates` + EAS Update, déjà en place dans le
projet.

---

## 1. Ce que l'OTA couvre — et ce qu'elle ne couvre pas

| Modification | OTA suffit ? |
|---|---|
| Texte, libellé, traduction | ✅ |
| Couleur, espacement, mise en page, animation | ✅ |
| Logique d'écran, appel d'API, correction de bug JavaScript | ✅ |
| Nouvel écran construit avec ce qui existe déjà | ✅ |
| Image embarquée dans `assets/` | ✅ |
| **Ajouter un module natif** (`npx expo install expo-…`) | ❌ build |
| **Changer une permission** (caméra, notifications…) | ❌ build |
| **Changer l'icône ou l'écran de démarrage** | ❌ build |
| **Changer `version`, `bundleIdentifier`, `package`** | ❌ build |
| **Monter de version le SDK Expo ou React Native** | ❌ build |

La règle tient en une phrase : **l'OTA remplace le JavaScript et les assets, pas
le binaire**. Si la modification touche quoi que ce soit que le compilateur
natif doit voir, il faut un `eas build`.

> ⚠️ Le correctif Hermes (React Native 0.86.3) est dans le binaire. Aucun
> `eas update` ne le livre — il impose un build neuf.

---

## 2. Le piège à connaître : `runtimeVersion`

`app.json` porte :

```json
"runtimeVersion": { "policy": "appVersion" }
```

La version d'exécution est donc **le champ `version`**, aujourd'hui `1.0.0`.
Une mise à jour OTA ne descend que sur les binaires qui portent **la même**
version d'exécution.

Concrètement :

- Tant que `version` reste `1.0.0`, tous les builds publiés reçoivent vos OTA.
  `eas.json` a `autoIncrement: true`, mais cela incrémente le *numéro de build*
  (buildNumber / versionCode), **pas** `version` : la version d'exécution ne
  bouge pas, et c'est voulu.
- Le jour où vous passez `version` à `1.0.1` et construisez, ce binaire a une
  version d'exécution `1.0.1`. Il **ne recevra pas** les mises à jour publiées
  pour `1.0.0`, et réciproquement. Il faut republier pour la nouvelle version.
- Pendant la transition, les deux cohabitent : tant que des téléphones portent
  encore `1.0.0`, une correction urgente doit être publiée **deux fois**, une
  fois depuis chaque version d'exécution.

Montez donc `version` seulement quand vous construisez de toute façon — pas pour
le confort.

---

## 3. Publier une mise à jour

```bash
cd mobile
eas update --channel production --message "Correction du calcul des impayés"
```

Le message est ce que vous relirez six mois plus tard dans l'historique :
écrivez ce qui change, pas « fix ».

Les canaux correspondent aux profils d'`eas.json` :

| Canal | Ce qu'il sert |
|---|---|
| `production` | les binaires de l'App Store et du Play Store |
| `preview` | les builds internes de test |
| `development` | les builds de développement |

Essayez toujours sur `preview` avant `production` quand la modification n'est
pas triviale :

```bash
eas update --channel preview --message "…"
```

---

## 4. Vérifier qu'elle est bien arrivée

C'est le point que l'on oublie, et qui fait croire à un correctif qui ne marche
pas alors qu'il n'est jamais descendu.

**Dans l'app** : *Plus → Paramètres*, tout en bas.

- La ligne **« Mise à jour »** donne l'état et permet de chercher à la main.
- L'**empreinte** sous elle donne, dans l'ordre : la version, le numéro de
  build, la révision d'interface (`UI_REV`), le canal, et l'origine du bundle —
  `embarqué` si c'est celui du binaire, `OTA xxxxxxxx` si c'est une mise à jour
  téléchargée.

Si l'empreinte dit `embarqué` alors que vous venez de publier, la mise à jour
n'est pas descendue : vérifiez le canal et la version d'exécution.

`UI_REV` est une constante en haut de `mobile/App.js`. **Incrémentez-la** à
chaque publication visible : c'est elle qui distingue « le correctif n'est pas
dans le bundle » de « le bundle n'est pas arrivé ».

---

## 5. Ce que l'app fait toute seule

`app.json` porte `updates.checkAutomatically: "ON_ERROR_RECOVERY"`. La couche
native ne cherche donc plus de son côté, sauf après un démarrage qui s'est mal
passé — ce filet-là reste. Tout le reste est piloté par l'app, ce qui évite
d'avoir deux chercheurs qui s'ignorent : l'état affiché à l'écran correspond
toujours à ce qui se passe vraiment.

Depuis la mise en place du pilotage :

1. **Au lancement et à chaque retour au premier plan**, l'app interroge son
   canal. Silencieusement : une panne de réseau n'affiche rien.
2. Si une mise à jour existe, elle est **téléchargée en arrière-plan**.
3. Une fois téléchargée, une **bannière discrète** apparaît au-dessus de la
   barre d'onglets : « Mise à jour prête — Appliquer ».
4. L'utilisateur décide. S'il applique, l'app redémarre sur la nouvelle version.
   S'il referme la bannière, la mise à jour s'appliquera d'elle-même à la
   prochaine ouverture.

On ne redémarre **jamais** d'autorité : `reloadAsync()` relance l'app, et le
faire pendant la saisie d'un adhérent perdrait le travail en cours.

Dans Expo Go, dans un build de développement et dans un binaire compilé sans
`expo-updates`, tout ce mécanisme se désactive proprement — la ligne des
réglages affiche alors « Indisponible sur cette version ».

---

## 6. Revenir en arrière

Une mise à jour OTA se défait aussi vite qu'elle se publie. C'est le principal
avantage sur un build.

```bash
cd mobile
eas update:rollback
```

La commande est interactive : elle propose de revenir à la mise à jour
précédente, ou au bundle embarqué dans le binaire.

Pour remettre en service une mise à jour précise de l'historique :

```bash
eas update:republish --channel production
```

> Réflexe : devant un comportement anormal signalé après une publication,
> on revient en arrière **d'abord**, on diagnostique ensuite. Le retour arrière
> coûte deux minutes ; laisser une régression en production coûte des clients.

---

## 7. Ce que les stores autorisent

Les deux boutiques acceptent qu'une app mette à jour son code interprété
(JavaScript) sans repasser par une revue — c'est le principe même d'Expo, de
React Native et de toutes les applications hybrides.

La limite, elle, est la même des deux côtés : **une mise à jour ne doit pas
changer la nature de l'application**. Corriger, améliorer, ajouter une
fonctionnalité dans la continuité : oui. Transformer un outil de gestion
d'association en autre chose, ou activer après la revue des fonctions qu'on
avait cachées pendant : non — et c'est un motif de retrait, pas de simple rejet.

Dans le cas d'Assokit, les mises à jour OTA resteront des corrections et des
améliorations du même produit. Rien à déclarer, rien à craindre.

---

## 8. Le mémo

```bash
# publier
cd mobile && eas update --channel production --message "…"

# revenir en arrière
eas update:rollback

# vérifier dans l'app
# Plus → Paramètres → ligne « Mise à jour » + empreinte en bas
```

Et la question à se poser avant chaque publication : *est-ce que ma modification
touche au natif ?* Si oui, ce n'est pas une OTA, c'est un build.
