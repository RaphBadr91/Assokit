# Maquettes de captures App Store

Dix planches prêtes à recevoir vos captures d'écran, au format exact
qu'Apple exige pour le 6,7 pouces : **1290 × 2796 px**.

| Fichier | Écran à y déposer |
|---|---|
| `maquette-01.jpg` | Tableau de bord |
| `maquette-02.jpg` | Liste des adhérents |
| `maquette-03.jpg` | Cotisations |
| `maquette-04.jpg` | Une facture ou un devis |
| `maquette-05.jpg` | Un projet et ses étapes |
| `maquette-06.jpg` | Agenda |
| `maquette-07.jpg` | Subventions |
| `maquette-08.jpg` | Un canal de discussion |
| `maquette-09.jpg` | Codes QR |
| `maquette-10.jpg` | Écran de votre choix |

Apple en demande **trois au minimum**, dix au maximum. Les trois premières
sont celles qui comptent : ce sont les seules visibles sans faire défiler.

## Y mettre vos captures

Le fichier Figma est le plus simple :
**Assokit — Captures App Store**, dans les brouillons de l'équipe.

1. Prenez vos captures depuis le build TestFlight, sur un iPhone 15 ou 16
   Pro Max, avec un compte contenant de vraies données.
2. Dans Figma, glissez l'image sur le calque nommé
   `CAPTURE — remplacer par votre image`.
3. L'emplacement fait 884 × 1916 px, soit exactement le ratio d'une capture
   1290 × 2796 : l'image se pose sans déformation ni recadrage.
4. Exportez la planche entière en JPEG, échelle 1×.

Sans Figma, n'importe quel éditeur d'images fait l'affaire : l'emplacement
clair en pointillés est à 189 + 14 = **203 px du bord gauche** et
**744 px du haut**, pour **884 × 1916 px**, coins arrondis de 82 px.

## Regénérer les planches vides

```bash
node assets/app-store/generer-maquettes.js
```

Le script rend les dix JPEG avec Chromium. Les textes sont dans le tableau
`ECRANS` en haut du fichier — c'est là qu'on change un titre.

Les cotes du script reproduisent exactement le fichier Figma. **Si vous
modifiez l'un, modifiez l'autre**, sinon les deux divergent.

Chaque titre a été mesuré dans le navigateur : tous tiennent en deux lignes
sans déborder de la colonne de 1090 px, le plus large étant celui de la
planche 01 à 1043 px.

## Police

`geist-variable.woff2` est le sous-ensemble latin de Geist, la police du
site, récupérée chez Google Fonts. C'est une fonte **variable** : les
graisses sont réelles, il n'y a pas de faux gras — le défaut qu'on avait
corrigé sur le site en ajoutant `;700` à l'URL Google Fonts.

Elle est incorporée en base64 dans le HTML au moment du rendu, donc le
script fonctionne sans accès réseau et sans installer quoi que ce soit.

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
