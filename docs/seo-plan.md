# Plan SEO Assokit — novembre 2026

Document de travail pour le fondateur. Il explique ce qui a été modifié sur le site le 6 novembre 2026, pourquoi, ce qu'il reste à faire de votre côté, et comment suivre les résultats dans la Search Console.

Point de départ : l'export Search Console des 7 derniers jours (au 6 novembre 2026).

- 154 clics pour environ 12 300 impressions, position moyenne 16.
- **Tous les clics viennent du blog.** Les pages commerciales (« logiciel-… », tarifs, fonctionnalités) totalisent environ 1 400 impressions et 0 clic.
- Les pages commerciales sont loin dans les résultats : /logiciel-association en position 62, /logiciel-comptabilite-association en 54, /logiciel-gestion-tpe en 57, /logiciel-facturation en 76.
- Plusieurs articles très vus sont peu cliqués : par exemple le bureau d'association (492 impressions, 1 clic) ou le calendrier comptable (432 impressions, 1 clic).

Il n'existe pas de recette pour « être premier partout ». Les positions au-delà de 50 sur « logiciel association » dépendent surtout de l'**autorité du site**, c'est-à-dire des liens venus d'autres sites, et le code seul ne peut pas la créer. Ce qui a été fait vise ce qui se règle dans le site :

- des titres qui donnent envie de cliquer ;
- une page claire par recherche ;
- des liens internes qui font circuler la visibilité du blog vers les pages commerciales ;
- des erreurs techniques en moins.

---

## 1. Ce qui a été fait

### Titres et descriptions des articles du blog (migration SQL)

Fichier : `migrations/2026-11-06-seo-titres-meta-blog.sql`.

- **31 articles** reçoivent un nouveau titre pour Google (60 caractères au plus, mots complets) et une nouvelle description (155 caractères au plus, phrases complètes). Chacun est écrit d'après les recherches réelles qui amènent sur l'article : par exemple « Bureau d'association loi 1901 : composition et rôles », ou « Erreur sur une facture : avoir ou facture rectificative ? ».
- Seules les colonnes « titre SEO » et « description SEO » changent. Le titre affiché dans l'article, l'adresse (URL), l'extrait et la date de mise à jour restent les mêmes. L'adresse ne doit jamais changer, car c'est elle que Google a déjà classée.
- Les titres SEO coupés par « … » sont vidés : la page reprend alors le titre complet de l'article.
- La migration est rejouable sans risque : elle réécrit les mêmes valeurs. Un article absent ne fait rien.

### Plus aucun titre coupé par « … »

Avant, tout titre de plus de 60 caractères était tronqué au dernier mot, puis complété par « … », aussi bien à l'affichage que dans l'outil de rédaction du blog. Google montrait donc des titres incomplets.

Désormais, le titre complet est envoyé et Google l'abrège lui-même si besoin. Les descriptions trop longues sont coupées de préférence à la fin d'une phrase entière (sinon au dernier mot entier). Une description d'article déjà coupée par « … » en base est ignorée : la page reprend l'extrait de l'article.

Fichiers : `includes-public.php`, `blog-article.php`, `admin-blog/includes/article-helper.php`. Les nouveaux articles n'auront plus non plus d'adresse qui finit par « …-pour » ou « …-de-la ».

### Une page commerciale par recherche

| Recherche visée | Page |
|---|---|
| logiciel association, logiciel gestion association, logiciel pour association | /logiciel-association |
| logiciel association gratuit, gestion association gratuit | /logiciel-association-gratuit |
| gestion des adhérents, gestion des adhésions, logiciel gestion des membres | /logiciel-adherents |
| gestion cotisation association | /logiciel-cotisation-association |
| logiciel comptabilité association, logiciel comptable association, Excel ou logiciel | /logiciel-comptabilite-association |
| logiciel facturation association / TPE | /logiciel-facturation |
| logiciel gestion TPE | /logiciel-gestion-tpe |

Sur chacune de ces pages :

- titre, H1 et description propres à la page, sans doublon avec les autres pages ;
- un bloc **« Tarif »** avec le vrai prix : essai gratuit de 14 jours sans carte bancaire, puis 49,99 € HT par mois, prix lu comme sur /tarifs ;
- un bloc **« Les autres outils Assokit »** qui relie les pages entre elles ;
- quelques questions ajoutées à la FAQ, tirées des recherches réelles (par exemple « Existe-t-il un logiciel de gestion d'association en ligne ? » ou « Comment corriger une facture déjà émise ? »). Les réponses ne décrivent que ce qu'Assokit fait aujourd'hui. Par exemple, la page cotisations dit toujours que le paiement en ligne n'est pas encore proposé.

/logiciel-association reçoit en plus une section « Pour quel type d'association ? » (club sportif, culturelle, comité des fêtes, étudiante, caritative, retraités) et une capture du tableau de bord, issue du compte de démonstration.

/logiciel-association-gratuit garde son angle honnête (« Assokit n'est pas gratuit à vie ») et renvoie vers /logiciel-association et vers les modèles gratuits.

### Données structurées (ce que Google lit en coulisses)

- Fil d'Ariane (BreadcrumbList) et FAQ (FAQPage) sur les pages commerciales. La FAQ structurée reprend exactement les questions affichées sur la page.
- Une seule fiche logicielle « Assokit » (même identifiant sur toutes les pages), **sans prix structuré ni note**.

Pourquoi pas de prix structuré : la « fiche logiciel » enrichie de Google exige à la fois un prix structuré et une note moyenne ou des avis. Sans avis notés vérifiables, aucune page n'y est éligible, avec ou sans prix. C'était déjà la décision prise sur l'accueil. Le prix reste affiché en clair sur chaque page. Trois pages (adhérents, facturation, TPE) envoyaient encore ce prix structuré : c'est corrigé.

À savoir : la Search Console peut afficher une alerte « champ manquant » (offers, review ou aggregateRating) sur les pages logiciel. C'est attendu et sans effet sur le classement : l'ignorer tant qu'il n'y a pas d'avis vérifiables.

Sur les articles du blog, l'auteur « L'équipe Assokit » est désormais déclaré comme une organisation, et non comme une personne.

### Liens du blog vers les pages commerciales

Fichier : `blog-article.php`.

- Les articles sur la facture, l'avoir, Chorus Pro, le devis ou le bon de commande menaient jusqu'ici vers la page comptabilité, jamais vers /logiciel-facturation. Ils pointent maintenant vers /logiciel-facturation.
- Les articles sur les cotisations pointent vers /logiciel-cotisation-association, ceux sur les adhésions et les membres vers /logiciel-adherents.
- Les 25 articles les plus visités reçoivent **une phrase avec un lien dans le texte**, après le premier paragraphe, avec une formulation différente à chaque fois. Exemple sur la passation du trésorier : « …un logiciel de comptabilité pour association… ». Un seul lien commercial par article, pour ne pas suroptimiser.
- Les articles sur l'AG, les statuts, le reçu fiscal ou la comptabilité renvoient aussi vers le modèle gratuit ou le guide correspondant.
- L'accueil et /pour-associations contiennent maintenant des liens dans le texte vers les pages commerciales. Avant, ces pages n'étaient reliées que par le menu et le pied de page.

### Corrections techniques

- **Erreurs 500** : toute adresse du type /blog/Xyz (majuscules, accents, tiret bas) ou /tarifs/foo provoquait une erreur serveur. Ces adresses renvoient maintenant une vraie page 404. Google ralentit son exploration d'un site qui renvoie des erreurs 500.
- **/index et /index.php** redirigent en un seul saut vers l'accueil.
- **Documents clients ouverts par lien** (devis, factures, AG, émargement, paiement), restes de flyers et dossier WordPress : ils sont marqués « ne pas indexer » (en-tête X-Robots-Tag), pour qu'un lien qui circule ne fasse jamais apparaître ces pages dans Google.
- **Sitemaps** : la date de modification de chaque page est maintenant la vraie date de son fichier ou des fichiers partagés qu'elle utilise (en-tête, blocs des pages commerciales, prix), et non la date du jour. Google ignore les dates qui changent à chaque passage. Limite : après un clone neuf du dépôt sur le serveur, toutes les pages prennent la même date ; avec git pull, seuls les fichiers modifiés changent de date.
- **/aide/paiements-en-ligne** est en « ne pas indexer » tant que le paiement en ligne n'est pas ouvert (voir la section 6).
- Titres de l'accueil (« Assokit · Gestion tout-en-un pour associations et TPE » : l'accueil garde l'angle association + TPE et laisse « logiciel de gestion d'association » à /logiciel-association, pour que deux pages ne se disputent pas la même recherche), du blog, des catégories et de /application ; descriptions de huit pages qui étaient coupées au milieu d'un mot.
- /comptabilite-analytique : titre avec « association » et description sans chiffre non sourcé. La question « Combien vais-je réellement économiser ? » (FAQ affichée et FAQ structurée) ne cite plus de montant. Le chiffre « ~900 €/an » reste affiché ailleurs sur cette page, sur l'accueil et sur /pour-associations : à sourcer ou à retirer. La marque est écrite « Assokit » partout, au lieu de « AssoKit ».
- Logo : dimensions déclarées (évite un décalage de la page au chargement) et chargement différé dans le pied de page. Organisation : taille réelle du logo, recherche interne retirée des données structurées.
- Pages « 404 » et recherche du blog : « noindex, follow », pour que Google suive quand même les liens vers les articles.

---

## 2. À faire de votre côté

1. **Appliquer la migration après le déploiement** (git pull) :

   ```
   php migrations/run.php 2026-11-06-seo-titres-meta-blog.sql
   ```

   Avant, ouvrez admin-blog → Articles et lisez les articles concernés. Chaque description annonce un contenu, par exemple « barème applicable, calcul pour les bénévoles ». Si un article ne traite pas ce point, modifiez sa description dans admin-blog après la migration.

2. **Vérifier en production** que /tarifs/foo renvoie bien une 404 et que /tarifs s'affiche normalement.

3. **Liens à vérifier en production.** Ces articles sont cités par les pages commerciales mais n'apparaissent pas dans la Search Console :
   - /blog/comptabilite-association-savoir-absolument
   - /blog/externaliser-sa-comptabilite-tpe-le-vrai-cout-compare-a-un-expert-comptable
   - /blog/compte-bancaire-association-quelle-banque-2026
   - /blog/rna-siren-association-tout-comprendre
   - /blog/fonds-dedies-association-comment-comptabiliser-une-subvention-affectee

   Ouvrez-les un par un : un article supprimé ou renommé donne une page 404 depuis une page commerciale.

4. **Le chiffre « environ 900 € par an »** (comptabilité analytique chez un comptable) n'a pas de source. Il a été retiré des descriptions et des FAQ des pages modifiées. Il reste visible :
   - dans les blocs visuels de l'accueil ;
   - dans les blocs visuels de /pour-associations ;
   - dans toute la page /comptabilite-analytique (simulateur compris).

   Si vous avez une source (devis de comptables, étude), indiquez-la sur la page. Sinon, il faut reformuler ces blocs, par exemple « incluse, sans module à acheter en plus ».

5. **Les 4 avis de /avis** : confirmez que chaque personne existe et a donné son accord écrit. Tant que ce n'est pas confirmé, aucune note ni aucun avis ne doit apparaître dans les données structurées. C'est le cas aujourd'hui, ne le changez pas. De vrais avis sur une plateforme tierce (fiche Google Business Profile, Capterra, Appvizer, GetApp) apportent en plus des liens et de la notoriété.

6. **Compte X/Twitter @assokit_fr** : s'il n'existe pas, le retirer du site (`includes-public.php`, ligne `twitter:site`).

7. **WordPress** : le dossier wp-content (environ 250 Mo) est encore dans le dépôt, alors qu'aucun code d'Assokit ne l'utilise. S'il ne sert plus, il peut être supprimé, ce qui ne relève plus du SEO.

8. **Liens venus d'autres sites** : c'est le levier principal pour remonter sur « logiciel association ». Pistes honnêtes :
   - fiches sur des comparateurs de logiciels, avec de vrais avis ;
   - pages partenaires des mairies qui utilisent l'offre collectivités ;
   - réseaux associatifs locaux ;
   - articles invités sur des sites associatifs.

   N'achetez jamais de liens.

---

## 3. Le cas « agenda association comptabilite »

C'est la recherche la plus vue du compte : 606 impressions en 7 jours, position 9,7, **0 clic**. La deuxième recherche n'en fait que 70.

Deux articles se la disputent :
- /blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer (432 impressions, position 6,4) ;
- /blog/echeancier-comptable-association-2026-le-calendrier-mensuel-pour-ne-rien-oublier (154 impressions, position 11,5).

Ce qui a été fait :
- le titre Google du premier devient « Agenda comptable d'une association : les 12 échéances », avec le mot « agenda » que tapent les internautes ;
- le second contient maintenant un lien « agenda comptable de l'association » vers le premier, pour indiquer à Google quelle page est la principale ;
- le premier renvoie vers le logiciel de comptabilité.

À faire par vous :

1. **Vérifier que ce trafic est humain.** Dans la Search Console : Performances → filtre Requête = « agenda association comptabilite » (exacte) → onglets Dates, Appareils, Pays. Un volume très régulier, environ 87 impressions par jour, presque uniquement sur ordinateur et sans aucun clic, signe plutôt un outil automatique de suivi de positions. Dans ce cas, inutile d'aller plus loin sur cette recherche.

2. **Décider de fusionner les deux articles** (recommandé si le trafic est humain).
   - Recopiez dans l'article « calendrier » ce que l'« échéancier » a d'utile.
   - Mettez les dates à jour pour 2027.
   - Ajoutez la redirection ci-dessous dans `.htaccess`, juste avant la ligne `RewriteRule ^blog/([a-z0-9-]+)/?$ …`. Elle n'a pas été ajoutée, car la fusion du contenu doit d'abord être faite à la main :

     ```
     RewriteRule ^blog/echeancier-comptable-association-2026-le-calendrier-mensuel-pour-ne-rien-oublier/?$ https://assokit.fr/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer [R=301,L]
     ```

   - Dépubliez ensuite l'échéancier dans admin-blog.

3. **Répondre à l'intention « agenda »** : un bouton « Ajouter ces échéances à mon agenda », qui télécharge un fichier .ics (Assokit sait déjà produire des fichiers d'agenda), plus une version imprimable. Ne l'annoncez dans la description qu'une fois en ligne.

---

## 4. Articles à écrire ensuite (5 fiches)

Ces fiches ne sont pas publiées : un article doit être écrit ou relu par un humain, avec des sources officielles. Les chiffres légaux (barèmes, seuils, durées) sont à vérifier sur la source officielle le jour de la rédaction.

### Fiche 1 — Notes de frais en association

- **Recherches visées** (impressions sur 7 jours, position) :
  - « note de frais association » (37, position 35) ;
  - « notes de frais association » (24) ;
  - « gestion notes de frais association » (18) ;
  - « note de frais bénévole » (13) ;
  - « modèle note de frais bénévole association » (9).
- **Titre** : « Notes de frais en association : règles et modèle »
- **Description** : « Frais des bénévoles et des salariés : justificatifs, indemnités kilométriques, remboursement ou abandon de frais avec reçu fiscal. Règles et modèle. »
- **Plan** :
  - qui peut être remboursé ;
  - justificatifs à garder ;
  - indemnités kilométriques (barème officiel en vigueur, lien impots.gouv.fr) ;
  - abandon de frais et reçu fiscal ;
  - modèle de note de frais ;
  - erreurs fréquentes.
- **FAQ** : « Un bénévole peut-il être remboursé sans justificatif ? » ; « Quel barème kilométrique pour un bénévole ? »
- **Liens** :
  - vers /logiciel-comptabilite-association#notes-de-frais (la fonction existe : justificatif photo ou PDF, calcul kilométrique) ;
  - vers l'article indemnités kilométriques et l'article note-frais-benevole, et inversement.
- **Ne pas promettre** un modèle téléchargeable s'il n'existe pas encore.

### Fiche 2 — Trésorier d'association : rôle, responsabilités, tâches

- **Recherches visées** :
  - « tresorier association » (13) ;
  - « responsabilite tresorier association » (11) ;
  - « tâches quotidien trésorier association » (11) ;
  - « trésorier association risques » (7, position 8).

  Le trésorier est la personne qui choisit le plus souvent Assokit.
- **Titre** : « Trésorier d'association : rôle, tâches et responsabilités »
- **Description** : « Ce que fait le trésorier d'une association loi 1901 : tenue des comptes, cotisations, banque, budget, AG. Ses responsabilités et les erreurs à éviter. »
- **Plan** :
  - rôle selon les statuts ;
  - tâches de l'année (lien vers l'agenda comptable) ;
  - responsabilité civile et pénale (source : service-public.fr) ;
  - passation (lien vers l'article passation, n° 1 du site) ;
  - outils.
- **Liens** : /logiciel-comptabilite-association, /logiciel-cotisation-association, articles passation et agenda-tresorier.

### Fiche 3 — Facture rectificative ou avoir

- **Recherches visées** :
  - « facture rectificative » (18 impressions, 3 clics : meilleur taux de clic du compte) ;
  - « erreur de facturation » (24) ;
  - « facture d'avoir » (26, position 72) ;
  - « avoir comptable » (8) ;
  - « avoir client » (8).
- **Option recommandée** : réécrire l'article avoir-ou-remboursement (229 impressions) plutôt que d'en créer un nouveau.
- **Titre** : « Facture rectificative ou avoir : que faire après une erreur ? »
- **Description** : « Une erreur sur une facture déjà émise ? Pourquoi on ne la modifie pas, quand émettre un avoir, mentions obligatoires et exemple pour TPE et associations. »
- **Plan** :
  - ce que dit la règle (article 289 du CGI : une facture émise ne se modifie pas) ;
  - avoir total ou partiel ;
  - mentions de l'avoir ;
  - TVA ;
  - cas de la facture électronique ;
  - exemple.
- **Liens** : /logiciel-facturation (l'avoir se crée depuis la facture, numérotation reprise).
- **À ne pas écrire** : Assokit ne gère aujourd'hui que l'avoir **total**. Ne parlez pas d'avoir partiel dans l'outil.

### Fiche 4 — Vendre en tant qu'association : ce qui est autorisé (article de référence)

- **Recherches visées** :
  - « boutique en ligne association » (47) ;
  - « bénéfices boutique en ligne association » (26) ;
  - « vente par une association » (26) ;
  - « organiser une vente pour une association » (15) ;
  - « association vente de produits » (14).
- **Le problème** : quatre articles se partagent ces recherches et se concurrencent :
  - boutique-en-ligne-d-association ;
  - lancer-une-boutique-en-ligne-rentable ;
  - vendre-des-prestations ;
  - vente-de-produits.
- **Ce qu'il faut faire** : réunir ces articles en un seul article de référence, puis rediriger les trois autres en 301, une fois le contenu repris.
- **Titre** : « Vente par une association : ce qui est autorisé »
- **Plan** :
  - le principe (gestion désintéressée) ;
  - activités lucratives accessoires et franchise des impôts commerciaux (seuil à vérifier sur impots.gouv.fr) ;
  - boutique en ligne et conditions générales de vente ;
  - six manifestations exonérées (à vérifier) ;
  - facturation.
- **Liens** : /logiciel-facturation, modèle de CGV de l'article existant.

### Fiche 5 — Bilan financier d'association : exemple et modèle

- **Recherches visées** :
  - « bilan financier association » (15) ;
  - « bilan association » (14) ;
  - « compte de résultat association » (14) ;
  - « rapport financier association » (10) ;
  - « exemple bilan association » (5).
- **Point de départ** : l'article bilan-financier-association-presenter (192 impressions, position 24) existe déjà. Le compléter vaut mieux qu'en créer un nouveau.
- **Titre** : « Bilan financier d'association : exemple et présentation en AG »
- **Plan** :
  - bilan ou compte de résultat ;
  - exemple chiffré fictif, présenté comme tel ;
  - rapport financier lu en AG ;
  - annexe ;
  - erreurs fréquentes.
- **Liens** : /logiciel-comptabilite-association (bilan en PDF et Excel produit par Assokit), /guide-comptabilite-association.

**Autre idée à envisager** : un vrai modèle Excel gratuit de comptabilité d'association (journal des recettes et des dépenses, plan comptable simplifié), à placer dans les modèles gratuits. Il répondrait à environ 200 impressions par semaine sur « … excel ». À ne publier qu'avec un vrai fichier téléchargeable.

---

## 5. Suivre les résultats dans la Search Console

Comptez **2 à 4 semaines** après le déploiement et la migration : Google doit repasser sur les pages.

1. **Taux de clic des 31 articles.**
   - Performances → Comparer → « 28 derniers jours » contre « 28 jours précédents ».
   - Filtre Page : l'adresse de l'article.
   - Ce qu'on attend : le taux de clic monte, à position égale. Par exemple, calendrier comptable : 0,2 % aujourd'hui, 2 à 4 % attendus en position 6.
2. **Pages commerciales.**
   - Filtre Page = /logiciel-association, puis onglet Requêtes.
   - Ce qu'on attend : « logiciel association » se déplace vers /logiciel-association au lieu de /logiciel-association-gratuit, et la position progresse.
   - Même chose pour /logiciel-facturation, qui reçoit maintenant les liens des articles sur la facture.
3. **Indexation** (Pages ou Indexation des pages) : les erreurs « serveur (5xx) » doivent disparaître. Les documents clients et les flyers apparaissent en « Exclue par la balise noindex » : c'est normal et voulu.
4. **Améliorations** : « Fil d'Ariane » et « FAQ » sans erreur. Aucune erreur « Extraits de logiciel » ne doit apparaître (pas de prix structuré).
5. **Sitemaps** : relancer la lecture de /sitemap.xml une fois après le déploiement.
6. **Inspection d'URL** : demander l'indexation des 7 pages commerciales et de l'accueil, une fois chacune.

Notez les chiffres du jour du déploiement (clics, impressions, position des pages commerciales) pour comparer.

**Dates « 2026 » dans les titres** : 29 % des impressions vont à des articles datés 2026. En janvier 2027, mettez à jour le contenu, puis le titre, des plus vus : budget prévisionnel, calendrier, AG, plan comptable. Ne changez jamais l'adresse. Pour le barème kilométrique, gardez « 2026 » tant que le barème 2027 n'est pas publié.

---

## 6. Le jour où le paiement en ligne (Stripe) est activé

Le paiement en ligne des cotisations n'est pas encore ouvert. Les pages le disent honnêtement, et il faut les garder ainsi jusqu'au jour de l'activation. Ce jour-là :

1. Dans `aide-paiements-en-ligne.php`, passer `'noindex' => true` à `false`.
2. Ajouter `['/aide/paiements-en-ligne', '0.6', 'monthly'],` dans la liste de `sitemap-pages.xml.php`.
3. Sur /logiciel-cotisation-association, mettre à jour la question « Peut-on encaisser les cotisations par carte bancaire ? ». Ajouter une section « Encaisser les cotisations en ligne », avec les frais réels de Stripe : ne jamais écrire « gratuit ».
4. Ajouter un lien vers /aide/paiements-en-ligne depuis /logiciel-cotisation-association et /fonctionnalites.

Ces recherches attendent cette fonction : « reglement cotisation association en ligne » (30 impressions), « cotisation en ligne association » (21), « paiement en ligne association gratuit » (12).
