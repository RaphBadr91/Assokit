# QR codes — cartes de visite numériques

Douze QR codes pré-générés. Scannés, ils ouvrent une fiche contact aux
couleurs Assokit avec un bouton **Ajouter à mes contacts** : la personne
enregistre le numéro, le nom, l'e-mail et l'adresse d'un geste, sans que
vous ayez à donner une carte papier.

Les fiches s'éditent dans **le tableau de bord fondateur → Pilotage →
Cartes de visite QR** (`/fondateur-cartes`).

| QR | Ouvre |
|---|---|
| `qr-carte-1` … `qr-carte-12` | `https://assokit.fr/carte/1` … `/carte/12` |

Douze emplacements existent d'avance, la plupart vides. Un emplacement vide
répond « carte introuvable » : rien ne fuit tant que vous ne l'avez pas
rempli. Remplissez-en un et son QR — déjà généré ici — devient utilisable
immédiatement, sans rien produire de nouveau.

## Le QR encode l'URL, pas les coordonnées

C'est la décision qui structure tout le reste, et elle a trois conséquences.

**Vous corrigez un numéro sans réimprimer.** Les coordonnées vivent en base,
pas dans l'encre. Changement de téléphone, nouvelle adresse, intitulé de
poste qui évolue : vous modifiez la fiche, les QR déjà distribués pointent
sur la version à jour.

**Le code reste court, donc facile à scanner.** Une vCard complète encodée
directement produit un QR bien plus dense, qui exige un bon appareil, une
bonne lumière et une impression nette.

**Ce qui est imprimé est définitif.** Les numéros `1` à `12` sont les
adresses gravées dans les codes. Ils ne changent jamais.

## Quel fichier prendre

| Fichier | Pour quoi |
|---|---|
| `qr-carte-N.svg` | **imprimeur** — vectoriel, net à n'importe quelle taille |
| `qr-carte-N.png` | 2048 px, noir sur blanc, usage courant |
| `qr-carte-N-marque.png` | 1280 px, vert Assokit avec le symbole au centre |

Les trois sont téléchargeables directement depuis `/fondateur-cartes`.

## Impression

- **Taille minimale : 2 cm de côté.** En dessous, la marge d'erreur d'un
  téléphone tenu à la main devient trop juste.
- **Garder la marge blanche** autour du code. Elle fait partie du code :
  sans elle, beaucoup de lecteurs ne trouvent pas les repères.
- **Ne pas inverser** (clair sur foncé) : une partie des lecteurs refuse.
- Sur fond coloré, poser le QR dans un rectangle blanc.

Les 24 images ont été décodées après génération, versions avec logo central
comprises, et jusqu'à 140 px de côté. La correction d'erreur est en niveau H
(30 %), le seul qui autorise un logo au centre.

## Mise en service

Une fois le code déployé sur le serveur, lancer la migration une seule fois :

```bash
cd ~/public_html
php migrations/run.php 2026-09-08-cartes-qr.sql
```

Tant qu'elle n'est pas passée, `/fondateur-cartes` le signale et les fiches
retombent sur `cartes-contacts.php`.
