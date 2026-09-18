# 📱 Captures App Store

Tout est au format exact qu'Apple exige pour le 6,7 pouces : **1290 × 2796 px**.

| Fichier | Quoi |
|---|---|
| `capture-01.jpg` … `capture-10.jpg` | **Planches prêtes à téléverser** : habillage marketing + capture de l'app |
| `brut/ecran-01.png` … `ecran-10.png` | Les écrans seuls, sans habillage — pour Google Play |
| `maquette-01.jpg` … `maquette-10.jpg` | Les mêmes planches, **vides**, si vous préférez y déposer vos propres captures |

Apple en demande **trois au minimum**, dix au maximum. Les trois premières sont
celles qui comptent : ce sont les seules visibles sans faire défiler.

---

## Les regénérer

```bash
cd mobile
npm install
npx expo export --platform web --output-dir dist-web

cd ..
node assets/app-store/generer-captures.js
```

Le script compile `mobile/App.js` — le **même fichier que le binaire iOS** —,
le remplit avec les données de démonstration de `mobile/preview/mock-api.js`,
parcourt les dix écrans en les touchant comme le ferait un doigt, et pose chaque
capture dans sa planche. Comptez deux à trois minutes.

Ce n'est donc pas une maquette redessinée : mise en page, couleurs, typographie,
espacements et navigation sont ceux qui seront livrés.

**Ce qui diffère d'un iPhone** : le rendu passe par Chromium, donc le flou, les
ombres et le lissage des polices varient légèrement ; et la zone de statut
(9:41, réseau, batterie) est dessinée par le script, pas par iOS.

> Apple exige que les captures montrent l'app telle qu'elle est.
> **Regardez les dix planches avant de les téléverser.** Si un écran a changé
> depuis, relancez la commande.

### Changer un texte ou un écran

Tout est dans le tableau `ECRANS` de `gabarit.js` : le titre, la phrase de
dessous, et `nav` — la suite de libellés à toucher pour atteindre l'écran.
`gabarit.js` est partagé par les deux scripts, c'est ce qui empêche les planches
pleines et les planches vides de diverger.

Chaque titre a été mesuré dans le navigateur : tous tiennent en deux lignes sans
déborder de la colonne de 1090 px.

### Les codes QR

La planche 9 montrait un code QR dans une version précédente. Les codes QR sont
une page du site affichée en WebView : il n'y a pas d'écran natif à capturer.
La planche montre désormais le menu complet, qui prouve mieux l'étendue de l'app.

---

## Y mettre vos propres captures

Si vous préférez des captures prises sur un vrai téléphone — depuis un build
TestFlight, sur un iPhone 15 ou 16 Pro Max — partez des planches **vides** :

```bash
node assets/app-store/generer-maquettes.js
```

Le fichier Figma correspondant s'appelle **Assokit — Captures App Store**, dans
les brouillons de l'équipe.

1. Dans Figma, glissez l'image sur le calque `CAPTURE — remplacer par votre image`.
2. L'emplacement fait 884 × 1916 px, soit exactement le ratio d'une capture
   1290 × 2796 : l'image se pose sans déformation ni recadrage.
3. Exportez la planche entière en JPEG, échelle 1×.

Sans Figma, n'importe quel éditeur d'images fait l'affaire : l'emplacement clair
en pointillés est à 189 + 14 = **203 px du bord gauche** et **744 px du haut**,
pour **884 × 1916 px**, coins arrondis de 82 px.

Les cotes du script reproduisent exactement le fichier Figma. **Si vous modifiez
l'un, modifiez l'autre**, sinon les deux divergent.

---

## Police

`geist-variable.woff2` est le sous-ensemble latin de Geist, la police du site,
récupérée chez Google Fonts. C'est une fonte **variable** : les graisses sont
réelles, il n'y a pas de faux gras — le défaut qu'on avait corrigé sur le site
en ajoutant `;700` à l'URL Google Fonts.

Elle est incorporée en base64 au moment du rendu, donc les scripts fonctionnent
sans accès réseau et sans installer quoi que ce soit.

## Couleurs

Reprises du kit de marque (`assets/brand/README.md`) :

| Rôle | Valeur |
|---|---|
| Haut du dégradé | `#0B3B2A` |
| Milieu (48 %) | `#0E7A5A` |
| Bas | `#12B886` |
| Coque du téléphone | `#0B1A13` |
| Emplacement de capture | `#E9F0EC` |
| Texte de consigne | `#5F6D66` |
