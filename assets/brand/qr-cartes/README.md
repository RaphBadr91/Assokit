# QR codes — cartes de visite numériques

Trois QR codes. Scannés, ils ouvrent une fiche contact aux couleurs Assokit
avec un bouton **Ajouter à mes contacts** : la personne enregistre le numéro,
le nom, l'e-mail et l'adresse d'un geste, sans que vous ayez à donner une carte.

| QR | Ouvre | Fiche |
|---|---|---|
| `qr-carte-1` | `https://assokit.fr/carte/1` | 1ʳᵉ personne |
| `qr-carte-2` | `https://assokit.fr/carte/2` | 2ᵉ personne |
| `qr-carte-3` | `https://assokit.fr/carte/3` | 3ᵉ personne |

## Le QR encode l'URL, pas les coordonnées

C'est la décision qui compte ici, et elle a trois conséquences.

**Vous pouvez corriger un numéro sans réimprimer.** Les coordonnées vivent
dans `cartes-contacts.php`, pas dans l'encre. Un changement de téléphone,
une nouvelle adresse, un intitulé de poste qui évolue : vous modifiez le
fichier, les QR déjà distribués pointent sur la version à jour.

**Le code reste simple donc facile à scanner.** Une vCard complète encodée
directement produit un QR beaucoup plus dense, qui exige un bon appareil,
une bonne lumière et une impression nette. Ici le code est court.

**Ce qui est imprimé est définitif.** Ne changez jamais les clés `1`, `2`,
`3` dans `cartes-contacts.php` : ce sont les adresses gravées dans les codes.

## Quel fichier prendre

| Fichier | Pour quoi |
|---|---|
| `qr-carte-N.svg` | **imprimeur** — vectoriel, net à n'importe quelle taille |
| `qr-carte-N.png` | 2048 px, noir sur blanc, usage courant |
| `qr-carte-N-marque.png` | 1280 px, vert Assokit avec le symbole au centre |

## Impression

- **Taille minimale : 2 cm de côté.** En dessous, la marge d'erreur d'un
  téléphone tenu à la main devient trop juste.
- **Garder la marge blanche** autour du code. Elle fait partie du code :
  sans elle, beaucoup de lecteurs ne trouvent pas les repères.
- **Ne pas inverser** (clair sur foncé) : une partie des lecteurs refuse.
- Sur fond coloré, poser le QR dans un rectangle blanc.

Les six images ont été décodées après génération, y compris les versions
avec le logo au centre, et jusqu'à 140 px de côté. La correction d'erreur
est en niveau H (30 %), le seul qui autorise un logo central.

## Renseigner les coordonnées

Tout se passe dans `cartes-contacts.php`, à la racine du site. Un champ
laissé vide disparaît de la fiche comme du fichier `.vcf` — rien ne casse.

Après modification : `git commit`, `git push`, puis `git pull` sur le
serveur. Les QR déjà imprimés continuent de fonctionner.
