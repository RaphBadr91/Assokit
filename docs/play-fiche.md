# Fiche Google Play — Assokit

Tout ce qu'il faut coller dans la Play Console. Les longueurs sont vérifiées
contre les limites de Google (voir en fin de fichier).

> Les champs de Play ne sont **pas** ceux d'Apple : Play a un nom de 30
> caractères, une description courte de 80 et une description complète de 4 000 ;
> il n'a ni sous-titre ni champ de mots-clés. Ne recopiez pas
> `docs/app-store-fiche.md` ici — c'est ce fichier qui fait foi pour Play.

---

## Nom de l'application

```
Assokit — Gestion asso & TPE
```

Play indexe fortement le nom : contrairement à Apple, il vaut la peine d'y
porter les deux mots qui décrivent le produit.

## Description courte

Les 80 caractères affichés sous le nom, avant que l'utilisateur ne déplie.
C'est la ligne la plus lue de la fiche.

```
Adhérents, cotisations, factures, projets : votre association en une app.
```

## Description complète

```
Assokit réunit dans une seule application tout ce qui fait tourner une association loi 1901 ou une TPE : les adhérents, les cotisations, la facturation, les projets, l'agenda et la communication.

Fini les tableurs qui circulent par e-mail et les relances oubliées.

ADHÉRENTS ET COTISATIONS
Votre fichier d'adhérents à jour, consultable partout. Créez une campagne de cotisation, enregistrez les paiements, suivez qui est à jour et qui ne l'est pas. Les relances partent sans que vous ayez à y penser.

FACTURATION ET DEVIS
Établissez un devis, transformez-le en facture, envoyez-la par e-mail. Suivez les impayés et relancez d'un geste. Vos documents portent votre logo et vos mentions légales.

PROJETS ET BÉNÉVOLES
Organisez vos actions en projets avec leurs étapes, leur budget et leurs participants. Chacun voit ce qu'il a à faire.

AGENDA ET ASSEMBLÉES
Vos événements, vos réunions, vos assemblées générales au même endroit. Émargement numérique, convocations, comptes rendus.

SUBVENTIONS
Ne laissez plus passer une date limite. Assokit suit vos demandes, leurs échéances de dépôt et de compte rendu.

COMMUNICATION
Des canaux de discussion par projet, pour remplacer les groupes de messagerie où tout se perd.

CODES QR
Créez un code QR pour votre stand : les visiteurs enregistrent vos coordonnées et vous laissent les leurs. Plus besoin de cartes de visite.

PROSPECTION
Suivez vos appels et vos e-mails, programmez vos rappels, gardez l'historique de chaque échange.

FAIT EN FRANCE
Vos données sont hébergées en France. Assokit est pensé pour le droit français : loi 1901, mentions légales obligatoires, facturation conforme.

L'application nécessite un compte Assokit. Les abonnements se souscrivent sur assokit.fr.

Conditions d'utilisation : https://assokit.fr/cgu
Confidentialité : https://assokit.fr/confidentialite
```

---

## Catégorisation

| Champ | Valeur |
|---|---|
| Type d'application | Application (pas un jeu) |
| Catégorie | **Entreprise** |
| Tags | gestion d'entreprise, productivité, comptabilité |
| E-mail de contact | psiwaneraph@gmail.com |
| Site web | https://assokit.fr |
| Politique de confidentialité | https://assokit.fr/confidentialite |

---

## Éléments graphiques

| Élément | Format exigé | Fichier |
|---|---|---|
| Icône | 512 × 512, PNG 32 bits | `assets/app-store/play-icone-512.png` |
| Image de présentation | 1024 × 500, JPEG ou PNG | `assets/app-store/play-feature-graphic.jpg` |
| Captures téléphone | 9:16, min. 1080 × 1920, **4 minimum**, 8 maximum | `assets/app-store/play/capture-01.jpg` … `capture-08.jpg` |

Les huit captures sont prêtes, en 1080 × 1920 exactement.

> ⚠️ N'utilisez **pas** les captures d'`assets/app-store/capture-*.jpg` ni celles
> de `brut/` : elles sont au format d'Apple (1290 × 2796, soit 2,167:1). Play
> refuse qu'une dimension dépasse le double de l'autre, et les rejetterait au
> téléversement.

Pour les regénérer :

```bash
cd mobile && npx expo export --platform web --output-dir dist-web
cd .. && node assets/app-store/generer-play-captures.js
node assets/app-store/generer-play.js          # icône + image de présentation
```

---

## Contenu de l'application (les formulaires obligatoires)

Le détail — accès pour la revue, Data safety, classification — est dans
**`docs/APP-REVIEW-APPLE-GOOGLE.md`, section 4**. En résumé :

| Formulaire | Réponse |
|---|---|
| App access | Accès restreint → fournir `apple.review@assokit.fr` / `AppleReview2026!` |
| Data safety | Collecte oui, partage non, chiffrement en transit oui, suppression in-app oui |
| Content rating | Questionnaire IARC — outil professionnel, aucun contenu sensible |
| Public cible | 18 ans et plus |
| Publicités | Non |
| Application financière | Non — Assokit ne traite aucun paiement dans l'app |
| Sécurité des données / permissions sensibles | Seule la caméra est demandée, pour scanner une facture |

---

## Avant de cliquer sur « Envoyer pour examen »

- [ ] `php seed-compte-apple-review.php` lancé sur le serveur, sortie sans erreur
- [ ] Connexion au compte de démo testée **depuis l'app**, pas depuis le navigateur
- [ ] Les 8 captures 1080 × 1920 relues puis importées
- [ ] Icône 512 et image de présentation 1024 × 500 importées
- [ ] Data safety, content rating, public cible, app access : les quatre remplis
- [ ] Publication d'abord en **test interne**, vérifiée sur un vrai téléphone
- [ ] Promotion en production seulement après cette vérification

---

## Limites de Google, pour mémoire

Longueurs mesurées sur les textes ci-dessus, en caractères Unicode.

| Champ | Limite | Ce fichier |
|---|---|---|
| Nom de l'application | 30 | 28 |
| Description courte | 80 | 73 |
| Description complète | 4 000 | 1 833 |

La description complète a de la marge : Play l'indexe, donc un paragraphe
supplémentaire sur un usage précis (« club sportif », « amicale », « comité des
fêtes ») y gagnerait en visibilité. À faire évoluer après les premiers retours.
