# Visuels Google Play — Assokit

Tous les fichiers de ce dossier respectent les formats exigés par Google Play :
taille exacte, PNG, pas de transparence sauf pour l'icône. Aucun prix, aucune
mention d'essai ou d'abonnement, aucun bouton d'achat (règles de paiement de
Google Play).

## Fichiers et emplacement dans la Play Console

Dans la Play Console : **Développer le nombre d'utilisateurs → Présence sur le Play
Store → Fiche Play Store principale**, section **Éléments graphiques**. En anglais :
*Grow users → Store presence → Main store listing → Graphics*. Google renomme
parfois ces menus.

| Fichier | Format | Champ de la Play Console |
|---|---|---|
| `icon-512.png` | 512 × 512, PNG 32 bits (canal alpha présent mais entièrement opaque), 8 Ko | **Icône de l'application** (*App icon*) |
| `feature-graphic-1024x500.png` | 1024 × 500, PNG 24 bits sans transparence | **Image de présentation** (*Feature graphic*), obligatoire |
| `captures/01-accueil.png` … `captures/08-coach-ia.png` | 8 × 1080 × 1920 (9:16), PNG 24 bits | **Captures d'écran de téléphone** (*Phone screenshots*), à importer dans l'ordre 01 → 08 |
| `captures-sans-legende/01-accueil.png` … `08-coach-ia.png` | 8 × 1080 × 1920 (9:16), PNG 24 bits | Variante sans légende, **à la place** de la série précédente si vous la préférez. N'importez pas les deux séries. |

Google accepte entre 2 et 8 captures de téléphone. Pour que l'application puisse
être mise en avant dans les recommandations, il en faut au moins 4 de 1080 px
minimum. Les 8 captures remplissent cette condition.

Les captures pour tablette (7 et 10 pouces) sont facultatives. Aucune n'est fournie.

`icon-512.png` est la réduction exacte de `mobile/assets/icon.png` (écart nul,
vérifié le 13/10/2026). C'est la seule icône du dossier : l'ancienne copie
`icon-512.png`, presque identique, a été supprimée pour éviter de se tromper de
fichier.

Sur le téléphone, Android affiche l'**icône adaptative** (`mobile/assets/adaptive-icon.png`,
premier plan transparent avec le seul rond blanc, sur le fond `#059669` déclaré dans
`app.json`). Elle a été refaite le 13/10/2026 pour que le lanceur montre le même dessin
que `icon-512.png` : rond réduit aux 2/3 et placé dans la zone sûre (centre à
637 px sur 1024, rayon 102 px ; bord du rond à 0,273 du centre, zone sûre 0,306).
Aperçus vérifiés avec des masques cercle, « squircle » et carré arrondi.
`adaptive-icon-monochrome.png` (même rond) sert aux icônes à thème d'Android 13 et plus.

## Les 8 captures

Chaque capture montre un **écran natif réel** d'`App.js`, avec les données de
l'association de démonstration **DEMO F** (compte de test
`admin@demo-f.assokit.fr`). La légende de la version `captures/` décrit
seulement ce qu'on voit à l'écran.

| # | Fichier | Écran natif | Légende |
|---|---|---|---|
| 1 | `01-accueil.png` | Accueil : indicateurs (budget engagé, factures en retard, adhérents, projets actifs), boîte mail, actions rapides, « Aujourd'hui » | Pilotez votre association en un coup d'œil |
| 2 | `02-projet.png` | Fiche projet « Parcours FLE A1→A2 » : avancement 63 % (5 étapes sur 8 terminées), budget, référent, étapes validées avec compte rendu | Suivez chaque projet, étape par étape |
| 3 | `03-agenda.png` | Fiche événement « Café des langues » (date, lieu, projet lié) ouverte depuis l'agenda | Vos événements, reliés à vos projets |
| 4 | `04-factures.png` | Factures : total, encaissé, à encaisser, analyse IA, liste avec statuts | Facturez et suivez vos encaissements |
| 5 | `05-cotisations.png` | Cotisations : montant encaissé, campagnes avec nombre de paiements reçus et en attente | Cotisations : qui a payé, qui est en attente |
| 6 | `06-messagerie.png` | Canal de discussion « Général » | Échangez avec votre équipe et vos bénévoles |
| 7 | `07-boite-mail.png` | Boîte mail, vue « Prioritaires » : catégories et urgences attribuées par l'IA | Vos e-mails triés par l'IA, l'urgent d'abord |
| 8 | `08-coach-ia.png` | Coach IA : bilan de la semaine, points forts, points de vigilance | Chaque semaine, un bilan et des conseils par l'IA |

Les légendes et l'image de présentation reprennent la police (Geist) et le dégradé
vert (`#0B3B2A` → `#0E7A5A` → `#12B886`) des planches App Store de
`assets/app-store/`.

L'image de présentation montre l'icône, « Assokit », la phrase « La gestion de
votre association ou TPE, dans votre poche », six modules réels (Adhérents,
Projets, Agenda, Factures, Messagerie, IA) et l'écran d'accueil réel. Aucun
élément important n'est placé contre les bords.

## Comment les captures ont été produites

1. L'application réelle a été compilée pour le navigateur à partir d'une copie de
   travail de `mobile/` (`npx expo export --platform web`). Les écrans, styles,
   animations, icônes Ionicons et la barre d'onglets sont donc ceux d'`App.js`.
2. La WebView a été remplacée par une doublure. Celle-ci exécute les requêtes
   `fetch('/api/app-*.php')` que l'application injecte. Ces requêtes partent vers
   un banc PHP local, peuplé par `seed-demo-formation.php` puis par les migrations
   du 09 au 12/10. La session est celle de `admin@demo-f.assokit.fr`. Les réponses
   JSON affichées sont donc les vraies réponses du serveur. Le banc était en lecture
   seule : aucun envoi de données vers le serveur.
3. Rendu au format d'un téléphone Android : 412 × 824 dp pour la version légendée,
   412 × 732 dp pour la version sans légende, densité × 3, police Roboto, emojis
   Noto. La barre d'état Android (10:30) et la barre de gestes sont ajoutées
   par-dessus la capture.
4. Seule retouche de la copie de travail : `flexShrink: 0` sur la rangée de filtres
   de la boîte mail. Sans elle, le navigateur l'écrasait, alors que le moteur de
   mise en page natif (Yoga) l'affiche.

Vérifications faites sur chaque image :
- taille exacte ;
- PNG 24 bits sans alpha (32 bits pour l'icône) ;
- les 99 icônes Ionicons affichées correspondent toutes à un vrai glyphe de la police ;
- aucune erreur JavaScript ni erreur HTTP ;
- textes entièrement en français, sans « lorem » ;
- aucun prix Assokit, essai ou abonnement.

Données personnelles : seules les données fictives de DEMO F apparaissent. Les
adresses e-mail sont en `@demo-f.assokit.fr` ou `.example`. Le numéro de téléphone
visible (01 99 00 13 34) appartient à la tranche que l'ARCEP réserve aux œuvres de
fiction.

## À vérifier sur un vrai téléphone Android avant publication

Les captures montrent le rendu prévu par le code. Les écarts relevés entre ce rendu
et un appareil réel ont été corrigés dans `mobile/App.js` le 13/10/2026 ; ils restent
à contrôler sur le build de test interne, avant d'envoyer la fiche :

1. **Barre d'état de l'accueil** (corrigé). Avec l'affichage bord à bord d'Expo SDK 57,
   `backgroundColor` de `StatusBar` n'a plus d'effet sur Android : la zone de la barre
   d'état était blanche, avec des icônes blanches sur l'accueil. L'app dessine
   maintenant elle-même une bande de la hauteur de la barre d'état, vert foncé
   (`#0B3B2A`) sur l'accueil et blanche ailleurs, comme sur `01-accueil.png`.
   À contrôler : l'accueil réel doit ressembler à la capture 01 (barre vert foncé,
   heure et icônes blanches). Si ce n'est pas le cas, refaire la capture 01 des deux
   séries depuis le téléphone.
2. **Bas de l'écran** (corrigé). La barre d'onglets, les boutons « Enregistrer » des
   formulaires, la zone de saisie des messages et les feuilles du bas (bouton « + »,
   choix d'un client, etc.) sont placés au-dessus de la barre de navigation Android,
   dont la hauteur réelle est lue au lancement (module `react-native-safe-area-context`,
   ajouté à `package.json`). Les captures ont été faites avec une barre de gestes
   ajoutée par-dessus. À contrôler : Paramètres › Système › Navigation, essayer
   **navigation à 3 boutons** puis **navigation par gestes** ; rien ne doit passer sous
   les boutons système.
3. **Agenda, événements « Journée »** (corrigé). La colonne horaire passe de 40 à 56 px
   et le texte tient sur une ligne (réduit si besoin) : « Journée » ne se coupe plus en
   « Journé / e ». La capture 3 montre la fiche d'un événement ; elle reste exacte.
4. **Boîte mail, rangée de filtres** (non modifié). Le `ScrollView` horizontal a
   `flexGrow: 0` mais garde le `flexShrink: 1` par défaut. À contrôler sur l'appareil :
   si la rangée paraît rognée en bas, ajouter `flexShrink: 0`.
5. **Icône sur l'écran d'accueil du téléphone** (corrigé, voir plus haut) : elle doit
   montrer le rond blanc en bas à droite, entier, comme `icon-512.png`.

Si l'un de ces écrans diffère, refaites la capture concernée avec la même méthode
(ou directement sur le téléphone, au format 1080 × 1920) pour que la fiche reste
fidèle à l'application.
