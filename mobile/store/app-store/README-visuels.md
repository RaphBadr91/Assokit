# Visuels App Store — Assokit (iPhone)

Huit captures d'écran pour l'écran **iPhone 6,9 pouces**, au format exigé par Apple :
**1290 × 2796 px, PNG 24 bits, couleurs RVB, sans transparence**. Aucun prix, aucune
mention d'essai ou d'abonnement, aucun bouton d'achat : rien ne s'achète dans
l'application (règle 3.1.1 d'Apple).

## Fichiers et emplacement dans App Store Connect

Dans App Store Connect : **Apps → Assokit → version iOS 1.0.1 → Aperçus et captures
d'écran → iPhone → 6,9 pouces**. En anglais : *Previews and Screenshots → iPhone →
6.9" Display*. Glissez les fichiers dans l'ordre 01 → 08. Apple renomme parfois ces
menus.

| Dossier | Contenu | À importer ? |
|---|---|---|
| `captures-6.9/01-accueil.png` … `08-coach-ia.png` | 8 captures avec légende, sur fond vert Assokit | **Oui**, c'est la série conseillée |
| `captures-6.9-sans-legende/01-accueil.png` … `08-coach-ia.png` | Les 8 mêmes écrans en plein cadre, sans légende ni cadre de téléphone | Seulement **à la place** de la série précédente. N'importez pas les deux. |

- 1290 × 2796 fait partie des tailles qu'Apple accepte pour l'écran 6,9 pouces (avec
  1260 × 2736 et 1320 × 2868). Apple en accepte jusqu'à 10 par taille d'écran.
- Apple réduit lui-même ces captures pour les iPhone plus petits : aucune autre taille
  n'est à fournir.
- **Pas de captures iPad** : `app.json` déclare `ios.supportsTablet: false`,
  l'application n'est publiée que pour l'iPhone.
- Les trois premières captures sont les seules visibles dans les résultats de recherche
  de l'App Store, sans faire défiler. D'où l'ordre : accueil, projet, agenda.

### Icône

Rien à produire. `mobile/assets/icon.png` fait déjà **1024 × 1024 px, en RVB sans
canal alpha** (vérifié le 18/10/2026), ce qu'Apple exige. Apple prend l'icône dans le
build envoyé par EAS ; App Store Connect ne demande plus de l'importer.

## Les 8 captures

Chaque capture montre un **écran natif réel** d'`App.js`, avec les données de
l'association de démonstration **DEMO F** (compte de revue `admin@demo-f.assokit.fr`).
La légende et la phrase dessous décrivent seulement ce qu'on voit à l'écran.

| # | Fichier | Écran | Légende | Phrase dessous |
|---|---|---|---|---|
| 1 | `01-accueil.png` | Accueil : budget engagé, factures en retard, adhérents, projets actifs, boîte mail, actions rapides, « Aujourd'hui » | Pilotez votre association en un coup d'œil | Budget, factures en retard, adhérents et projets : l'essentiel dès l'ouverture. |
| 2 | `02-projet.png` | Fiche projet « Parcours FLE A1→A2 » : avancement 63 % (5 étapes sur 8), budget engagé, description, référent, première étape validée avec son compte rendu | Suivez chaque projet, étape par étape | Avancement, budget, référent et compte rendu de chaque étape validée. |
| 3 | `03-agenda.png` | Agenda, jour par jour : heure, lieu et projet de chaque événement (dont un événement « Journée ») | Votre agenda, relié à vos projets | Réunions, ateliers et formations, jour par jour, avec le lieu et le projet lié. |
| 4 | `04-factures.png` | Factures : total, encaissé, à encaisser, analyse IA, liste avec statuts (Brouillon, En attente) | Facturez et suivez vos encaissements | Total, encaissé, reste à encaisser et statut de chaque facture. |
| 5 | `05-cotisations.png` | Cotisations : montant encaissé, campagnes avec le nombre de cotisations reçues et en attente | Cotisations : qui a payé, qui est en attente | Chaque campagne avec les cotisations reçues et celles en attente. |
| 6 | `06-messagerie.png` | Canal de discussion « Général » | Échangez avec votre équipe et vos bénévoles | Des canaux de discussion pour toute l'association. |
| 7 | `07-boite-mail.png` | Boîte mail, vue « Prioritaires » : catégorie, mention « Urgent » et échéance repérées par l'IA | Vos e-mails triés par l'IA, l'urgent d'abord | Catégorie, urgence et échéance repérées dans chaque message. |
| 8 | `08-coach-ia.png` | Coach IA : bilan de la semaine, points forts, points de vigilance, début des actions recommandées | Chaque semaine, un bilan et des conseils par l'IA | Points forts, points de vigilance et actions recommandées. |

Ce sont les mêmes écrans et les mêmes légendes que sur Google Play
(`../google-play/captures/`), avec deux différences : la capture 3 montre la liste de
l'agenda (plus remplie sur l'écran plus haut de l'iPhone) au lieu de la fiche d'un
événement, et chaque capture porte une phrase sous la légende.

### Sur les montants visibles

Les seuls montants en euros sont les **chiffres de gestion de DEMO F** : budgets,
factures émises par l'association à ses clients, cotisations reçues de ses adhérents.
Ce ne sont pas des prix d'Assokit. Le bouton « Enregistrer un paiement » (capture 5)
sert à noter qu'un adhérent a payé sa cotisation à l'association (espèces, chèque,
virement) : ce n'est pas un achat dans l'application. Les notes pour l'équipe de revue le
disent (`age-et-revue.md` § 6, partie WHAT TO LOOK AT).

La phrase sous la légende de la capture 5 disait « paiements reçus » : remplacée le
18/10/2026 par « les cotisations reçues et celles en attente » (la fiche évite le mot
« paiement », règle 3.1.1). Seule la capture légendée `captures-6.9/05-cotisations.png` a été
recomposée (même gabarit, même écran) ; les 7 autres, recomposées pour contrôle, sont
identiques au pixel près à celles livrées le jour même, et la série sans légende ne change
pas.

## Mise en page

Le gabarit est celui des planches `assets/app-store/` (script `generer-maquettes.js`),
toujours valable : dégradé vertical `#0B3B2A` → `#0E7A5A` (48 %) → `#12B886`, symbole
Assokit blanc en haut à gauche, titre en Geist gras, coque de téléphone `#0B1A13` de
912 × 1944 px placée à 189 px du bord gauche et 730 px du haut, écran de
884 × 1916 px (exactement le rapport d'une capture 1290 × 2796).

- Titre : Geist 700, **82 px** pour les huit (taille la plus grande où chaque ligne tient
  dans la colonne de 1090 px ; la plus large, « Échangez avec votre équipe », mesure
  1074 px). Deuxième ligne en vert clair `#A7F3D0`, comme sur Google Play.
- Phrase dessous : Geist 500, 42 px, blanc à 80 %, une ou deux lignes.
- Sur la coque seulement, l'îlot dynamique (*Dynamic Island*) de l'iPhone est dessiné
  en noir. Il ne cache rien : il tombe entre l'heure et les icônes de la barre d'état.
  La série sans légende ne l'a pas, comme une vraie capture d'iPhone.

**Les planches `assets/app-store/maquette-01.jpg` à `maquette-10.jpg` n'ont pas été
réutilisées telles quelles.** Elles ne montrent aucun prix, mais ce sont des cadres
vides (« Déposez ici votre capture d'écran »), le dossier parle encore d'écran
« 6,7 pouces » (Apple dit aujourd'hui 6,9 pouces pour 1290 × 2796), et leurs textes
annoncent des écrans absents de cette série (adhérents, subventions, codes QR,
« Fait en France ») avec des promesses qu'aucune de ces captures ne montre
(« relances envoyées sans y penser », « reçus fiscaux, facturation conforme »,
« Hébergé en France »). Seuls le gabarit, les couleurs, la police et le symbole ont été
repris. Si vous gardez le fichier Figma « Assokit — Captures App Store », remplacez ses
textes par ceux du tableau ci-dessus.

## Comment les captures ont été produites

Même méthode que pour Google Play (`../google-play/README-visuels.md`), adaptée à
l'iPhone :

1. **L'application réelle** a été compilée pour le navigateur à partir d'une copie de
   travail de `mobile/` (`npx expo export --platform web`) : écrans, styles, icônes
   Ionicons et barre d'onglets sont ceux d'`App.js` du 13/10/2026. La retouche d'`App.js`
   du 18/10/2026 (liens « Confidentialité » et « Conditions générales » des écrans
   d'accueil et de connexion, désormais ouverts dans l'app) ne touche aucun de ces
   8 écrans. Les retouches suivantes du même jour (appui long sur un message, accord
   pour l'IA, connexion seule sur iPhone, téléchargement des exports) ne changent rien de
   visible sur ces 8 écrans pour un compte qui n'a ni masqué de messages ni refusé l'IA,
   et DEMO F n'est pas en essai : aucune pastille d'essai n'y apparaissait déjà. Celle du
   19/10/2026 (demande de création d'espace sur iPhone : bouton « Créer l'espace de ma
   structure » de l'écran d'accueil vert, lien de l'écran de connexion) ne touche que ces
   deux écrans d'avant la connexion, qui ne figurent pas parmi les captures.
2. **Données réelles du serveur.** La WebView est remplacée par une doublure qui exécute
   les requêtes `fetch('/api/app-*.php')` injectées par l'app. Elles partent vers un banc
   PHP local (base `assokit_t_ios`), peuplé par `seed-demo-formation.php` puis par les
   migrations du 09 au 12/10/2026, avec la session de `admin@demo-f.assokit.fr`. Les
   réponses JSON affichées sont donc les vraies réponses du serveur. Lecture seule :
   les 3 écritures tentées par l'app (marquer une notification comme lue) ont été
   bloquées, rien n'a été modifié sur le banc.
3. **Rendu iPhone 6,9/6,7 pouces** : 430 × 932 points, densité × 3, soit
   1290 × 2796 px. Marges sûres d'iOS émulées dans le navigateur : 59 points en haut,
   34 en bas. Horloge du navigateur réglée sur 9 h 41 (d'où « Bonjour Sophie »). Barre
   d'état iOS (9:41, réseau, Wi-Fi, batterie) et barre d'accueil (*home indicator*)
   ajoutées par-dessus.
4. **Branches iOS d'`App.js`.** Dans la copie de travail seulement (rien n'est changé
   dans le dépôt) :
   - `Platform.OS` y vaut `'ios'`, pour que l'app prenne ses réglages iPhone (marges du
     bas de la barre d'onglets, de la zone de saisie des messages, des champs) ;
   - la première `SafeAreaView` d'`AppShell` (bande colorée sous la barre d'état) ne
     reçoit que la marge du haut, comme sur iPhone : la version navigateur de React
     Native lui ajoutait aussi celle du bas ;
   - `flexShrink: 0` sur la rangée de filtres de la boîte mail, comme pour Google Play
     (le navigateur l'écrasait, le moteur de mise en page natif ne le fait pas).
5. **Polices.** L'app n'impose aucune police : sur iPhone, elle s'affiche en SF Pro, la
   police système d'Apple, indisponible ici. Les captures utilisent **Inter**, police
   libre (licence SIL OFL) au dessin très proche. Les emojis (👋, ⚡, ☀️, 📞) sont ceux de
   Noto, ceux d'Apple sont un peu différents.

Vérifications faites sur chaque image :
- 1290 × 2796 px, PNG 8 bits par canal, type « RVB » sans canal alpha ni transparence
  (lu dans l'en-tête de chaque fichier) ;
- aucune erreur JavaScript, aucune erreur HTTP pendant la capture ;
- les 84 icônes Ionicons affichées correspondent toutes à un glyphe de la police ;
- polices chargées (Inter pour l'app, Geist pour les légendes) et chaque caractère
  affiché, emojis mis à part, présent dans sa police ;
- textes visibles relevés écran par écran : entièrement en français, sans « lorem »,
  sans « undefined » ni « NaN », sans prix Assokit, essai, abonnement ou offre ;
- relecture visuelle des 16 images.

Données personnelles : seules les données fictives de DEMO F apparaissent (personnes,
clients, « Démoville », « Région Île-de-Démo », « Préfecture (démo) », « Urssaf
(démo) »…). Les adresses e-mail de démonstration sont en `@demo-f.assokit.fr`.

## À vérifier sur un vrai iPhone avant publication

Les captures montrent le rendu prévu par le code. À contrôler sur le build TestFlight,
sur un iPhone à îlot dynamique (15 Pro Max, 15 Plus, 16 Plus ou 16 Pro Max) :

1. **Haut de l'accueil.** Sous la barre d'état, l'app pose une bande vert foncé
   (`#0B3B2A`) avant le dégradé de l'en-tête (deux `SafeAreaView` superposées, voir
   `AppShell`). La capture 1 la montre telle que le code la produit. Si l'iPhone affiche
   autre chose, refaire la capture 1.
2. **Bas de l'écran.** La barre d'onglets doit rester au-dessus de la barre d'accueil,
   avec le même écart que sur les captures ; dans un canal de discussion, la zone
   « Écrire un message… » doit rester au-dessus de la barre d'onglets (capture 6).
3. **Coupures de texte.** SF Pro est un peu plus étroite qu'Inter : sur l'iPhone,
   certaines fins de ligne coupées par « … » (« 10 non lus » sur l'accueil, lieux de
   l'agenda, objets des e-mails) peuvent tenir en entier. Ce n'est pas gênant ; les
   captures restent fidèles.
4. **Données du jour.** DEMO F est recréée chaque nuit avec des dates recalées sur le
   jour même : les dates et les chiffres d'une capture faite plus tard seront
   légèrement différents. C'est normal.

Si un écran diffère, refaites la capture concernée directement sur l'iPhone (bouton
latéral + volume haut) : un iPhone 15 Pro Max, 15 Plus ou 16 Plus donne exactement
1290 × 2796 ; un iPhone 16 Pro Max donne 1320 × 2868, taille acceptée elle aussi pour
l'écran 6,9 pouces. Une capture brute peut remplacer la version légendée, à condition
que toute la série soit du même type.
