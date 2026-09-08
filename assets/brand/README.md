# Identité visuelle Assokit

Kit complet : logo en SVG, PNG, JPG et PDF, plus les bannières Facebook et
LinkedIn aux dimensions officielles.

Rien n'a été inventé ici. Le symbole reprend au pixel près `assets/logo-assokit.png`
(déjà en production et déjà soumis à l'App Store), les couleurs sont celles
de `includes-layout.php` et de `mobile/App.js`, et la typographie est **Geist**,
la police que le site charge depuis Google Fonts.

---

## Couleurs

| Rôle | Hex | Usage |
|---|---|---|
| Vert Assokit | `#059669` | couleur principale, symbole, « kit » du mot-symbole |
| Vert clair | `#12CE93` | départ du dégradé uniquement |
| Vert profond | `#025C43` | fin du dégradé uniquement |
| Encre | `#0B1A13` | « Asso » du mot-symbole, titres, fonds sombres |
| Gris de texte | `#5F6D66` | textes secondaires (5,4:1 sur blanc → AA) |

Dégradé du symbole : `linear-gradient(140deg, #12CE93, #059669 58%, #025C43)`
Dégradé des bannières : `linear-gradient(115deg, #0B3B2A, #0E7A5A 42%, #12B886)`
— c'est l'en-tête de l'application mobile, repris tel quel.

## Typographie

**Geist**, 400 / 500 / 600. Interlettrage `-0.02em` sur les titres et le
mot-symbole.

Le mot-symbole des fichiers livrés est **vectorisé** : les lettres sont des
tracés, pas du texte. Les fichiers s'affichent donc à l'identique sur une
machine où Geist n'est pas installée — chez un imprimeur, par exemple.

## Géométrie du symbole

Sur un carré de 100 :

- rayon des angles : **22**
- point : rayon **14,94**, centré en **(68,31 · 68,31)**

Ces valeurs sont mesurées sur le PNG de production, pas approximées.

---

## Que prendre, et quand

| Besoin | Fichier |
|---|---|
| Site, e-mail, document bureautique | `svg/assokit-logo.svg` |
| Fond sombre ou photo | `svg/assokit-logo-blanc.svg` |
| Une seule couleur (tampon, fax, gravure) | `svg/assokit-logo-noir.svg` |
| Format carré ou très étroit | `svg/assokit-logo-vertical.svg` |
| Favicon, avatar, pastille | `svg/assokit-symbole.svg` |
| Imprimeur, sérigraphie, kakémono | `pdf/*.pdf` (vectoriel, 5 Ko) |
| Outil qui refuse le SVG | `png/*.png` (fond transparent) |
| Outil qui refuse la transparence | `jpg/*.jpg` (fond blanc, ou encre pour les logos blancs) |

Le dégradé (`-degrade`) est une variante d'apparat : icône d'application,
avatar, écran d'accueil. Pour tout le reste, la version à plat `#059669`
reste la référence — elle survit à la photocopie, au fax et au petit format.

## Règles d'usage

- **Zone de protection** : laisser autour du logo un vide égal à la moitié
  du côté du symbole. Rien d'autre n'y entre.
- **Taille minimale** : 96 px de large à l'écran, 25 mm à l'impression pour
  le verrouillage horizontal ; 16 px pour le symbole seul.
- **Ne pas** : redessiner le mot-symbole avec une autre police, changer les
  couleurs, ajouter une ombre portée, étirer, incliner, encadrer, ni poser
  la version couleur sur un fond sombre — c'est à ça que sert la version blanche.

---

## Réseaux sociaux

Dimensions officielles au 8 septembre 2026. Chaque visuel existe en `.png`
(qualité maximale) et en `.jpg` (plus léger). Les deux plateformes acceptent
les deux formats ; prenez le JPG si l'envoi traîne.

### Facebook

| Fichier | Taille | Usage |
|---|---|---|
| `facebook-couverture-1640x624` | 1640 × 624 | Couverture de Page |
| `facebook-profil-1080x1080` | 1080 × 1080 | Photo de profil |
| `facebook-profil-alt-clair-1080x1080` | 1080 × 1080 | Photo de profil, variante claire |
| `facebook-evenement-1920x1005` | 1920 × 1005 | Couverture d'événement |
| `facebook-publication-1200x630` | 1200 × 630 | Publication et partage de lien |

### LinkedIn

| Fichier | Taille | Usage |
|---|---|---|
| `linkedin-page-couverture-1128x191` | 1128 × 191 | Bannière de Page entreprise |
| `linkedin-page-logo-400x400` | 400 × 400 | Logo de Page entreprise |
| `linkedin-page-logo-alt-clair-400x400` | 400 × 400 | Logo de Page, variante claire |
| `linkedin-profil-fond-1584x396` | 1584 × 396 | Fond de profil personnel |
| `linkedin-publication-1200x627` | 1200 × 627 | Publication et partage de lien |

### Zones sûres respectées

Ces trois contraintes sont la raison pour laquelle les bannières ne sont pas
simplement centrées :

- **Couverture Facebook** — sur mobile, Facebook recadre au centre et perd
  environ 270 px de chaque côté. Tout le contenu tient dans la bande centrale.
- **Bannière de Page LinkedIn** — le logo de la page recouvre le coin
  bas-gauche. Le contenu commence à droite de 300 px.
- **Fond de profil LinkedIn** — la photo de profil chevauche le bas-gauche.
  Ce coin est laissé vide.

### Deux variantes d'avatar, un choix à faire

`facebook-profil-1080x1080` place le symbole **blanc sur vert** : c'est le
plus lisible une fois réduit à 40 px dans un fil d'actualité.

`facebook-profil-alt-clair-1080x1080` place le symbole **vert sur blanc** :
c'est exactement l'icône de l'application sur l'App Store, donc le plus
cohérent pour quelqu'un qui vous découvre par les deux canaux.

Prenez l'un ou l'autre, mais le même sur Facebook et sur LinkedIn.

---

## Régénérer le kit

Les fichiers sont produits par script, pas à la main. Le symbole est décrit
en géométrie et le mot-symbole vectorisé depuis Geist, ce qui rend le kit
reproductible à l'identique. Les scripts (`make-svg.js`, `make-assets.js`)
ne sont pas versionnés : ils dépendent d'`opentype.js`, de Playwright et du
téléchargement de Geist. Demandez-les si vous devez changer une dimension.
