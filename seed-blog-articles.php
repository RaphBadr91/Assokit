<?php
/**
 * seed-blog-articles.php
 * --------------------------------------------------------------
 * À EXÉCUTER UNE FOIS, après la migration v45.
 * Insère 12 articles initiaux dans la table asso_blog_articles.
 *
 * Usage : visiter https://assokit.fr/seed-blog-articles.php?token=XXX
 *         ou en CLI : php seed-blog-articles.php
 *
 * IMPORTANT : à supprimer après exécution.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden — utiliser ?token=CRON_TOKEN');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

echo "=== SEED BLOG ARTICLES ===\n\n";

$articles = [
    // ==========================================================
    // 1. ASSOCIATIONS
    // ==========================================================
    [
        'slug' => 'creer-association-loi-1901-guide-complet',
        'title' => 'Comment créer une association loi 1901 en 2026 : le guide complet',
        'category' => 'associations',
        'cover_emoji' => '🏛️', 'cover_color_from' => '#059669', 'cover_color_to' => '#0F172A',
        'reading_time' => 8,
        'tags' => 'création asso, loi 1901, statuts, déclaration préfecture',
        'excerpt' => 'Toutes les étapes pour créer votre association loi 1901 : rédaction des statuts, déclaration en préfecture, publication au JO, premières démarches obligatoires.',
        'content' => "## Pourquoi créer une association loi 1901 ?

La loi du 1er juillet 1901 a posé un cadre simple, libre et accessible pour permettre à des personnes de se regrouper autour d'un projet **sans but lucratif**. Plus de 1,5 million d'associations existent aujourd'hui en France — preuve que ce statut reste, 125 ans après, une formidable boîte à outils pour agir collectivement.

Que ce soit pour défendre une cause, animer un quartier, organiser des activités sportives ou culturelles, ou porter un projet d'utilité sociale, la loi 1901 offre un cadre **gratuit, rapide et flexible**.

## Les 3 conditions pour créer une asso

1. **Au moins 2 personnes** (3 recommandé pour le bureau : président, trésorier, secrétaire)
2. **Un objet licite et non lucratif** (l'asso peut générer des revenus, mais ne peut pas les redistribuer aux membres)
3. **Une volonté de s'inscrire dans la durée** (sinon, un simple contrat suffit)

## Étape 1 : rédiger les statuts

Les statuts sont le **document fondateur** de votre association. Ils doivent contenir au minimum :

- Le nom (titre) de l'association
- L'objet social (la mission)
- Le siège social (une adresse postale en France)
- La durée (généralement illimitée)
- Les conditions d'adhésion et de radiation
- Les organes de direction (bureau, conseil d'administration, AG)
- Les modalités de modification des statuts et de dissolution

> 💡 **Conseil pratique** : ne copiez-collez pas des statuts trouvés en ligne sans les adapter. Prenez le temps de définir votre objet social précisément — c'est lui qui déterminera ce que votre asso peut faire (et ce qu'elle ne peut pas faire).

## Étape 2 : tenir l'AG constitutive

Une réunion fondatrice (Assemblée Générale Constitutive) doit acter :
- L'adoption des statuts
- La désignation des premiers dirigeants
- La fixation du montant de la cotisation (si cotisation)

Un **procès-verbal** est rédigé et signé. Conservez-le précieusement.

## Étape 3 : déclarer l'association en préfecture

La déclaration se fait :
- **En ligne** sur [service-public.fr](https://www.service-public.fr) (le plus rapide)
- **Par courrier** à la préfecture du département du siège social

Documents à joindre :
- Formulaire Cerfa n°13973
- Liste des dirigeants (nom, prénom, profession, domicile, nationalité)
- Exemplaire des statuts signé par au moins deux dirigeants
- Procès-verbal de l'AG constitutive

**Délai** : la préfecture vous délivre un récépissé sous 5 jours. Votre asso a alors une **personnalité juridique**.

## Étape 4 : publication au Journal Officiel (JOAFE)

C'est automatique après la déclaration. La préfecture transmet à la DILA, qui publie un avis de création au **Journal Officiel des Associations et Fondations d'Entreprise** sous 1 mois.

Cette publication officialise l'existence de l'association et déclenche son numéro RNA (Répertoire National des Associations).

## Étape 5 : les premières démarches après création

Une fois l'asso officielle, il y a quelques formalités à anticiper :

- **Ouvrir un compte bancaire associatif** (obligatoire pour la transparence)
- **Souscrire une assurance** responsabilité civile (souvent indispensable)
- **Demander un n° SIRET** auprès de l'INSEE (obligatoire pour recevoir des subventions ou employer des salariés)
- **S'inscrire au registre des bénéficiaires effectifs** au tribunal de commerce
- **Mettre en place une comptabilité** (au minimum un livre de recettes-dépenses)

## Erreurs fréquentes à éviter

- ❌ Rédiger un objet social trop vague ou trop large
- ❌ Oublier la rubrique « modification des statuts » (vous serez bloqué·e plus tard)
- ❌ Désigner un siège social qui n'est pas réellement le vôtre
- ❌ Confondre président·e et représentant·e légal (mentionner clairement qui peut signer)
- ❌ Négliger la rédaction du règlement intérieur (il vient compléter les statuts)

## Combien ça coûte ?

**0 €** pour la création stricto sensu. La déclaration et la publication au JO sont gratuites depuis 2020.

Les coûts à anticiper :
- Compte bancaire associatif : 0-15 €/mois selon les banques
- Assurance RC : à partir de 80 €/an
- Outils de gestion (comme Assokit) : à partir de 13 €/mois pour le plan Démarrage avec tarif réduit asso

## En résumé

Créer une asso loi 1901, c'est :
1. Rédiger des statuts adaptés à votre projet
2. Tenir une AG constitutive et désigner les dirigeants
3. Déclarer en préfecture (en ligne ou papier)
4. Attendre la publication au JO
5. Anticiper les démarches post-création

Le tout en **moins de 2 semaines**, et **gratuitement**.

> 🌿 Une fois votre asso créée, équipez-vous d'un outil simple pour gérer adhérents, communication et trésorerie. C'est exactement ce pour quoi nous avons créé **Assokit**.",
    ],

    [
        'slug' => 'statuts-association-guide-redaction',
        'title' => 'Statuts d\'association : le guide complet pour les rédiger sereinement',
        'category' => 'juridique',
        'cover_emoji' => '⚖️', 'cover_color_from' => '#0EA5E9', 'cover_color_to' => '#0F172A',
        'reading_time' => 10,
        'tags' => 'statuts, juridique, loi 1901, gouvernance',
        'excerpt' => 'Les statuts sont le pilier de votre association. Voici comment les rédiger pour qu\'ils servent réellement votre projet, sans pièges juridiques.',
        'content' => "## Pourquoi vos statuts comptent vraiment

Les statuts ne sont **pas juste un document administratif**. Ce sont les **règles du jeu** de votre association. Ils définissent ce qu'elle peut faire, qui décide quoi, et comment les conflits se règlent.

Mal rédigés, ils causent des blocages : refus de subventions, conflits internes, impossibilité de modifier la gouvernance, dissolution mal préparée…

Bien rédigés, ils sont **invisibles** au quotidien et **précieux** quand un problème survient.

## Les 9 articles indispensables

### Article 1 — Dénomination
Le nom de votre association. Vérifiez qu'il n'est pas déjà utilisé sur le [site des associations](https://www.journal-officiel.gouv.fr/associations).

### Article 2 — Objet social
**LA partie cruciale**. Décrivez précisément ce que fera votre association.

> 💡 **Astuce** : formulez votre objet de manière **suffisamment large** pour anticiper l'évolution de votre projet, mais **suffisamment précise** pour éviter les abus.

Exemple correct : *« Promouvoir la pratique du basket-ball en Île-de-France, organiser des entraînements et compétitions, et favoriser la mixité dans le sport. »*

Exemple trop vague : *« Promouvoir le sport. »*

### Article 3 — Siège social
Une adresse postale en France. Peut être au domicile du président, dans un local associatif, ou en domiciliation. **Privilégiez une adresse stable** : changer de siège social demande une démarche formelle.

### Article 4 — Durée
Généralement *« illimitée »*.

### Article 5 — Composition
Quels types de membres : adhérents, bienfaiteurs, d'honneur, fondateurs… Définissez les conditions d'adhésion et la cotisation éventuelle.

### Article 6 — Perte de la qualité de membre
Démission, décès, radiation pour non-paiement de cotisation, exclusion (avec procédure contradictoire).

### Article 7 — Ressources
Cotisations, subventions, dons, ventes de biens/services, mécénat… Listez **toutes les sources possibles** pour ne pas être limité plus tard.

### Article 8 — Gouvernance
Le cœur du fonctionnement :
- **L'Assemblée Générale** (ordinaire et extraordinaire) : qui peut voter, comment, à quelle majorité
- **Le Conseil d'Administration** (facultatif mais recommandé) : composition, élection, durée du mandat
- **Le Bureau** : président, trésorier, secrétaire — pouvoirs respectifs

### Article 9 — Dissolution
Procédure de dissolution et **dévolution des biens** (vers une autre asso à but similaire, jamais vers les membres).

## Articles complémentaires recommandés

- **Modification des statuts** : à quelle majorité (souvent 2/3 ou 3/4 en AGE)
- **Règlement intérieur** : précisez qu'il complète les statuts et est adopté par le bureau
- **Pouvoir de représentation** : qui signe au nom de l'association ?
- **Conflits d'intérêts** : si vous voulez prévenir les abus

## Les pièges fréquents

### ❌ « Les décisions sont prises à la majorité »
**Insuffisant**. À quelle majorité ? Simple ? Absolue ? Qualifiée ? Par tête, par voix exprimée, par cotisation ?

### ❌ « Le président représente l'association »
**Trop vague**. Pour signer un bail ? Un emprunt ? Précisez les pouvoirs et les autorisations préalables nécessaires.

### ❌ « Les modifications nécessitent un vote en AG »
**Risqué**. À quelle majorité ? Avec quel quorum ? Précisez ces points sinon vous serez bloqué·e à la première modification.

### ❌ « Membres : toute personne intéressée par l'objet »
**Imprécis**. Définissez clairement les conditions d'adhésion (parrainage ? validation par le bureau ? simple cotisation ?).

## Modèles disponibles

- [Service-Public.fr](https://www.service-public.fr) propose un modèle officiel
- Les fédérations sportives, culturelles ou sociales ont souvent leurs propres modèles
- Évitez les modèles génériques sans adaptation à votre projet

## Faut-il un avocat ?

Pour 90% des associations, **non**. Un modèle bien adapté, lu par 2-3 membres expérimentés, suffit.

Faites appel à un avocat si :
- Vous prévoyez d'employer des salariés rapidement
- Vous gérez un budget &gt; 200 000 € / an dès le départ
- Votre objet social est sensible (politique, religieux, lobbying)
- Vous prévoyez une fusion ou une scission

## Et après ? La vie des statuts

Vos statuts doivent **vivre** avec votre asso :
- Modification de l'objet, du siège, de la gouvernance → AGE + déclaration préfecture
- Évolution du nombre de membres → ajustement éventuel des règles de quorum
- Salariés ? Activité commerciale accessoire ? → Penser à adapter les ressources

> 🌿 Conservez toujours vos statuts dans un endroit accessible (cloud, drive partagé). Avec **Assokit**, vous pouvez stocker et partager facilement tous vos documents officiels.",
    ],

    // ==========================================================
    // 2. COMPTABILITÉ
    // ==========================================================
    [
        'slug' => 'gerer-tresorerie-association',
        'title' => 'Comment gérer la trésorerie d\'une association sans s\'arracher les cheveux',
        'category' => 'comptabilite',
        'cover_emoji' => '💰', 'cover_color_from' => '#F59E0B', 'cover_color_to' => '#92400E',
        'reading_time' => 7,
        'tags' => 'trésorerie, comptabilité asso, budget, suivi financier',
        'excerpt' => 'Pas besoin d\'être expert-comptable pour gérer la trésorerie de votre asso. Voici les outils et bonnes pratiques pour garder le contrôle.',
        'content' => "## La trésorerie : ce truc qui inquiète tout le monde

Vous êtes trésorier·e fraîchement élu·e. Personne ne vous a vraiment expliqué le rôle. Vous découvrez Excel, des relevés bancaires, des factures, des reçus de caisse… et vous vous demandez par où commencer.

**Bonne nouvelle** : la trésorerie d'une asso, c'est avant tout du **bon sens**. Voici comment la maîtriser sans diplôme de compta.

## Ce que doit faire un·e trésorier·e

### 1. Tenir une comptabilité simple
Au minimum : un **livre de recettes-dépenses** chronologique. C'est juste la liste de tout ce qui rentre et de tout ce qui sort.

### 2. Suivre la trésorerie en temps réel
Vous devez savoir à tout moment :
- Combien il y a sur le compte bancaire
- Combien il y a en caisse (si vous avez du liquide)
- Quelles dépenses sont à venir
- Quelles recettes sont attendues

### 3. Préparer le budget prévisionnel
Une fois par an (avant l'AG), vous projetez les recettes et dépenses de l'année à venir. C'est ce qui permet de prendre des décisions éclairées.

### 4. Présenter les comptes à l'AG
Compte de résultat (recettes - dépenses) + bilan (actif/passif). Selon votre taille, ça peut être très simple ou nécessiter un commissaire aux comptes.

## Les 5 outils indispensables

### 📋 1. Un compte bancaire dédié
**Obligatoire**. Aucun mélange avec un compte personnel. Préférez une banque associative ou éthique (Crédit Coopératif, Crédit Mutuel, La NEF…).

### 💳 2. Un système de paiements clair
- Carte bancaire associative (1 ou 2 max)
- Virements pour les gros montants
- Caisse espèces seulement si nécessaire (et avec rigueur)

### 📊 3. Un outil de suivi
- **Tableur** : OK pour démarrer, mais devient vite un cauchemar (formules cassées, pertes de données)
- **Logiciel dédié** : recommandé dès que votre asso dépasse 5 000 € de budget annuel

### 📁 4. Un système de classement
Conservez **tous les justificatifs** (factures, reçus, relevés) pendant 10 ans. Numérisez-les pour ne rien perdre.

### 📅 5. Un calendrier d'échéances
Cotisations à appeler, factures à régler, déclarations à faire… Anticipez.

## Les bonnes pratiques de pro

### ⚖️ La règle des 4 yeux
Aucune dépense importante (généralement &gt; 500 €) ne devrait être validée par une seule personne. **Trésorier + président** signent ensemble.

### 📅 Le rapprochement bancaire mensuel
Une fois par mois, vous pointez **chaque ligne** du relevé bancaire avec votre comptabilité. Toute différence doit être expliquée.

### 🎯 Le budget prévisionnel n'est pas un tabou
Souvent, le budget est fait à la louche en début d'année et oublié. **Vérifiez tous les trimestres** où vous en êtes par rapport au prévisionnel.

### 💡 Constituez une réserve
Une asso saine a **3 à 6 mois de fonctionnement** d'avance en trésorerie. Pas pour faire fructifier l'argent, mais pour absorber les imprévus.

## Les erreurs qui coûtent cher

- ❌ **Mélanger compte perso et compte asso** : interdit, dangereux, et fait perdre la confiance
- ❌ **Régler en espèces sans facture** : impossible à justifier ensuite
- ❌ **Ne pas suivre les cotisations impayées** : c'est de l'argent qui dort
- ❌ **Oublier les charges sociales si vous avez des salariés** : URSSAF n'est pas patiente
- ❌ **Faire les comptes uniquement en fin d'année** : trop tard pour corriger

## Les obligations légales à connaître

Selon la taille de votre asso :

- **Petite asso (&lt; 153 000 € de budget)** : comptabilité simple suffisante
- **Asso recevant subventions publiques &gt; 153 000 €** : comptabilité d'engagement obligatoire
- **Asso recevant &gt; 153 000 € de dons annuels** : commissaire aux comptes obligatoire
- **Asso d'utilité publique** : règles spécifiques

## Quand faire appel à un expert-comptable ?

- Budget annuel &gt; 50 000 €
- Vous avez 1 ou plusieurs salariés
- Vous bénéficiez de subventions publiques importantes
- Vous avez une activité commerciale accessoire

**Coût indicatif** : 800-2500 €/an pour une asso simple, plus pour des structures complexes.

## En résumé

Bien gérer la trésorerie d'une asso, c'est :
1. **Un compte dédié + un outil de suivi clair**
2. **Le réflexe rapprochement bancaire mensuel**
3. **Anticiper avec un budget prévisionnel suivi**
4. **La règle des 4 yeux pour les dépenses importantes**
5. **Garder une réserve raisonnable**

Pas besoin d'être expert. Juste rigoureux et régulier.

> 🌿 Avec **Assokit**, vous tenez vos comptes en quelques clics : factures, suivi des cotisations, tableau de bord en temps réel. Conçu pour les trésoriers qui veulent garder le contrôle sans y passer leurs week-ends.",
    ],

    // ==========================================================
    // 3. JURIDIQUE
    // ==========================================================
    [
        'slug' => 'recu-fiscal-association-tout-savoir',
        'title' => 'Reçu fiscal : tout ce qu\'une asso doit savoir pour bien faire',
        'category' => 'juridique',
        'cover_emoji' => '🧾', 'cover_color_from' => '#0EA5E9', 'cover_color_to' => '#0F172A',
        'reading_time' => 6,
        'tags' => 'reçu fiscal, dons, déduction fiscale, cerfa',
        'excerpt' => 'Les reçus fiscaux, c\'est ce qui permet à vos donateurs de réduire leurs impôts. Mais toutes les associations ne peuvent pas en émettre. Voici les règles.',
        'content' => "## Pourquoi le reçu fiscal change tout

Un don de 100 € avec reçu fiscal = **66 € pour le particulier**, ou **40 € pour l'entreprise**, après déduction. **Sans reçu fiscal**, c'est 100 € net.

Pour un donateur, c'est donc **un facteur clé** dans sa décision. Pour votre asso, savoir bien gérer les reçus fiscaux est un **levier majeur de financement**.

## Toutes les assos peuvent-elles émettre des reçus fiscaux ?

**Non.** Seules les associations remplissant des conditions précises peuvent émettre des reçus fiscaux ouvrant droit à réduction d'impôt.

### Les 3 conditions cumulatives :

1. **Être d'intérêt général**, c'est-à-dire :
   - Avoir une activité non lucrative (l'objet social principal)
   - Avoir une gestion désintéressée (dirigeants bénévoles, pas de distribution de bénéfices)
   - Ne pas fonctionner au profit d'un cercle restreint de personnes

2. **Avoir une activité parmi celles éligibles** :
   - Caractère philanthropique, éducatif, scientifique, social, humanitaire, sportif, familial, culturel
   - Concourir à la mise en valeur du patrimoine, à la défense de l'environnement
   - Diffusion de la culture, de la langue ou des connaissances scientifiques françaises

3. **Ne pas fonctionner au bénéfice d'un cercle restreint**

## Comment vérifier l'éligibilité ?

### Méthode 1 : la procédure de rescrit fiscal
Vous demandez à l'administration fiscale de **se prononcer officiellement** sur votre éligibilité. Réponse en 6 mois, valable plusieurs années.

C'est **la méthode la plus sûre**, surtout si vous comptez communiquer largement sur vos reçus fiscaux.

[Service public · rescrit fiscal](https://www.service-public.fr/associations/vosdroits/F32130)

### Méthode 2 : l'auto-évaluation
Vous évaluez vous-même votre éligibilité. **Risque** : si l'administration estime ensuite que vous n'étiez pas éligible, vous devrez rembourser les avantages fiscaux indus + amende.

## Que doit contenir un reçu fiscal ?

Le formulaire **Cerfa n°11580*04** est obligatoire. Il doit comporter :

- Vos coordonnées complètes (nom, adresse, n° SIRET)
- L'identité du donateur (nom, prénom, adresse)
- Le **montant** du don (en chiffres et en lettres)
- La **date** du don
- La **forme** du don (espèces, chèque, virement, don en nature…)
- La mention « *L'association certifie que les dons reçus ouvrent droit à la réduction d'impôt sur le revenu prévue à l'article 200 du CGI* »
- La **signature** d'un dirigeant habilité

## Pour quels types de dons ?

✅ Dons en numéraire (espèces, chèque, virement)
✅ Dons en nature (matériel, biens — valorisation à justifier)
✅ Cotisations **si pas de contrepartie significative**
✅ Frais engagés bénévolement par les membres (déplacements, achats personnels…)
✅ Abandon de créances (factures non encaissées au profit de l'asso)

## Les pièges à éviter

### ❌ Considérer la cotisation comme un don
Si la cotisation donne droit à des avantages (entrées gratuites, services, etc.), elle n'est **pas déductible**. Sauf si la valeur des contreparties est faible (généralement &lt; 25% de la cotisation, plafonnée à 73 €).

### ❌ Émettre un reçu pour un sponsor
Le sponsoring est une **opération commerciale** (le sponsor reçoit une visibilité en échange). Ce n'est **pas un don**. Pas de reçu fiscal.

### ❌ Ne pas conserver de trace des dons
Vous devez conserver la **trace de chaque don pendant 6 ans** : copies des reçus, justificatifs bancaires, etc.

## Les nouveautés à connaître en 2026

- Plafond de la réduction IR : **66% jusqu'à 1000 €** pour les organismes d'aide aux personnes en difficulté
- Au-delà : **66% dans la limite de 20% du revenu imposable**
- IFI : réduction de **75% sur les dons à certains organismes**, plafonnée à 50 000 €
- Mécénat d'entreprise : réduction de **60% jusqu'à 2 M€**, puis **40%**

## Comment optimiser vos reçus fiscaux ?

1. **Émettre rapidement** après le don (idéalement le mois même)
2. **Personnaliser le mail** d'envoi (un mot de remerciement, pas juste un PDF)
3. **Centraliser** la gestion (un seul point d'envoi, pas plusieurs personnes qui envoient en parallèle)
4. **Communiquer** sur la déductibilité dans toutes vos campagnes
5. **Faciliter** la collecte numérique avec QR codes ou plateformes en ligne

> 🌿 Avec **Assokit**, vous générez vos reçus fiscaux en un clic, vous les envoyez par email automatiquement, et vous gardez la trace de chaque don. L'IA peut même rédiger le mot de remerciement personnalisé.

## En résumé

Le reçu fiscal est un **levier puissant** pour vos campagnes de dons. À condition de :
1. Vérifier votre **éligibilité** (rescrit fiscal recommandé)
2. Utiliser le **bon formulaire** (Cerfa 11580*04)
3. Conserver les **traces** de tous les dons et reçus
4. **Communiquer clairement** sur les avantages fiscaux à vos donateurs",
    ],

    // ==========================================================
    // 4. COMMUNICATION
    // ==========================================================
    [
        'slug' => 'attirer-benevoles-strategies',
        'title' => 'Attirer (et garder) des bénévoles : 8 stratégies qui marchent vraiment',
        'category' => 'communication',
        'cover_emoji' => '🤝', 'cover_color_from' => '#EC4899', 'cover_color_to' => '#9D174D',
        'reading_time' => 9,
        'tags' => 'bénévoles, recrutement, engagement, fidélisation',
        'excerpt' => 'Trouver des bénévoles, c\'est une chose. Les garder, c\'en est une autre. Voici 8 stratégies testées par des assos qui réussissent.',
        'content' => "## Le constat amer

71% des associations françaises déclarent **manquer de bénévoles**. Et celles qui en ont voient **30% partir chaque année**.

Pourtant, ce n'est pas la générosité qui manque : 16 millions de Français déclarent vouloir s'engager. Le problème vient souvent du côté des associations : recrutement flou, accueil expéditif, missions mal définies.

Voici 8 stratégies concrètes pour inverser la tendance.

## 1. Définir clairement vos missions bénévoles

Le bénévole le plus motivé fuira un *« Venez nous donner un coup de main, on a besoin de tout ! »*. **Précisez** :

- Quelle mission ? (animer un atelier, gérer la com, accompagner des bénéficiaires…)
- Quel temps demandé ? (2h/semaine ? 1 weekend par mois ?)
- Quelles compétences requises ?
- Avec qui travailler ?
- Quels résultats attendus ?

> 💡 Créez **une fiche de poste** par type de bénévolat. Comme pour un emploi.

## 2. Sortir des sentiers battus pour le recrutement

Beaucoup d'assos recrutent en publiant *« On cherche des bénévoles »* sur leur page Facebook. Résultat : 0 retour.

**Diversifiez** :

- **Plateformes dédiées** : France Bénévolat, Tous Bénévoles, JeVeuxAider.gouv.fr
- **Réseaux pros** : LinkedIn (efficace pour les compétences spécifiques)
- **Universités et grandes écoles** : étudiants en quête d'engagement et d'expérience
- **Entreprises** : mécénat de compétences, journées solidaires
- **Bouche à oreille** : votre membre actuel est votre meilleur ambassadeur

## 3. Soigner le premier contact

Quand quelqu'un manifeste de l'intérêt, **chaque heure compte** :

- **Réponse en moins de 48h** (idéalement le jour même)
- **Mail personnalisé**, pas un copier-coller
- **Proposer un café/visio** pour faire connaissance
- **Présenter le projet ET la mission**, pas juste l'organisation

## 4. Organiser un vrai onboarding

Les bénévoles qui partent dans les 3 mois ont souvent eu un **accueil bâclé**. Investissez :

- **Une session d'accueil collective** (mensuelle ou trimestrielle)
- **Un parrain/marraine** qui suit les nouveaux pendant 3 mois
- **Des outils** : guide d'accueil, accès aux outils, présentation de l'équipe
- **Une première mission simple** pour réussir vite et prendre confiance

## 5. Reconnaître l'engagement

Le bénévole donne du temps gratuitement. Le minimum, c'est **reconnaître ce don**.

- **Dire merci**, souvent, sincèrement, individuellement
- **Valoriser publiquement** lors d'événements, sur les réseaux, en AG
- **Délivrer un certificat de bénévolat** (utile pour les CV étudiants)
- **Offrir des moments conviviaux** : repas d'équipe, sortie, week-end
- **Reconnaître les compétences acquises** via le passeport bénévole

## 6. Faire évoluer les missions

Un bénévole ne veut pas faire la même chose pendant 5 ans. **Proposez** :

- De nouveaux projets
- Des responsabilités croissantes (chef d'équipe, référent thématique)
- Des formations (interne ou externes)
- L'entrée au bureau ou au CA

## 7. Créer du lien entre bénévoles

L'association n'est pas qu'un lieu de mission. C'est aussi un **lieu de rencontres**. Beaucoup de bénévoles restent **pour les amitiés** autant que pour la cause.

- Groupes WhatsApp ou Slack actifs
- Soirées informelles trimestrielles
- Apéros post-réunion
- Activités hors-cadre (rando, ciné…)

## 8. Mesurer et améliorer

Les associations qui réussissent **mesurent** :

- Combien de bénévoles cette année ?
- Quel taux de rétention à 6 mois ? À 1 an ?
- Quels canaux de recrutement marchent le mieux ?
- Quelles missions sont les plus appréciées ?

Faites un **bilan annuel** avec votre équipe. Identifiez ce qui marche, ce qui coince, et ajustez.

## L'erreur n°1 : confondre bénévolat et corvée

Trop souvent, le bénévolat est présenté comme un sacrifice : *« Si vous saviez tout ce qu'on fait pour la cause… »*.

Le bénévolat moderne, c'est **un échange** :
- L'asso reçoit du temps et des compétences
- Le bénévole reçoit du sens, de l'apprentissage, des liens, de la reconnaissance

**Cultivez cette vision d'échange.** Vous attirerez des gens engagés, durablement.

## En résumé

Pour attirer et garder des bénévoles :
1. **Précisez** vos missions
2. **Diversifiez** vos canaux de recrutement
3. **Réagissez vite** aux candidatures
4. **Soignez** l'onboarding
5. **Reconnaissez** régulièrement l'engagement
6. **Faites évoluer** les missions
7. **Créez du lien** entre bénévoles
8. **Mesurez** ce qui marche

> 🌿 Avec **Assokit**, gérez votre vivier de bénévoles, communiquez avec eux par email ciblé, et utilisez l'IA pour rédiger vos appels à candidatures. Tout le monde y gagne.",
    ],

    // ==========================================================
    // 5. TPE / GESTION
    // ==========================================================
    [
        'slug' => 'facturation-tpe-pieges-eviter',
        'title' => 'Facturation TPE : 7 pièges qui peuvent coûter cher (et comment les éviter)',
        'category' => 'tpe',
        'cover_emoji' => '🛠️', 'cover_color_from' => '#7E22CE', 'cover_color_to' => '#0F172A',
        'reading_time' => 7,
        'tags' => 'facturation, TPE, indépendants, mentions obligatoires',
        'excerpt' => 'Une facture mal rédigée peut coûter très cher : pénalités fiscales, refus de paiement, contestation client. Voici les erreurs à ne pas commettre.',
        'content' => "## Pourquoi la facturation est un sujet sérieux

Pour beaucoup d'indépendants et de TPE, la facture est *« juste un document Word »*. Erreur. C'est un **document légal** qui engage votre responsabilité, et qui peut vous **coûter très cher** s'il est mal rédigé.

Sanctions possibles :
- **Amende de 15 € par mention manquante** (article 1737 du CGI)
- **Plafond : 25% du montant facturé**
- **Refus de déductibilité** par l'administration fiscale du client
- **Action en paiement plus difficile** en cas de litige

Voyons les 7 pièges les plus courants.

## Piège 1 : oublier des mentions obligatoires

Une facture B2B doit comporter **TOUTES** ces mentions :

- Date d'émission
- Numéro unique et chronologique
- Identité du vendeur (nom, adresse, SIREN/SIRET, forme juridique, capital social pour les sociétés)
- Identité de l'acheteur (raison sociale, adresse)
- Description précise des biens/services
- Quantités et prix unitaires HT
- Taux de TVA applicables
- Montant HT, TVA, TTC
- Date de règlement et conditions de paiement
- Pénalités de retard (taux et conditions)
- Mention de l'indemnité forfaitaire de 40 € pour frais de recouvrement
- Pour les auto-entrepreneurs : mention *« TVA non applicable, art. 293 B du CGI »*

> 💡 **Astuce** : utilisez un logiciel de facturation qui inclut **automatiquement** toutes ces mentions. Plus aucun risque d'oubli.

## Piège 2 : la numérotation chaotique

Vos factures doivent être **numérotées chronologiquement et sans rupture**. Pas de saut, pas de doublon.

❌ FACT-001, FACT-002, FACT-005…
❌ FACT-2025-001, puis FACT-2024-007 (régression dans l'ordre)
✅ FACT-2026-000001, FACT-2026-000002, FACT-2026-000003…

En cas de contrôle, des numéros manquants font présumer une **dissimulation**.

## Piège 3 : facturer sans bon de commande / devis signé

Pour un litige, vous devrez prouver :
- Que la prestation a été commandée
- À quel prix
- Avec quelles conditions

Un **devis signé** ou un **bon de commande accepté par écrit** est votre meilleure protection.

## Piège 4 : oublier de mentionner les pénalités de retard

**Obligatoire** pour les factures B2B (article L441-10 du Code de commerce). Si vous oubliez :
- Vous **perdez le droit** de réclamer ces pénalités
- Vous risquez une amende de 75 000 € (personne morale)

Mention type : *« En cas de retard de paiement, des pénalités de retard au taux de [X]% par an seront exigibles, ainsi qu'une indemnité forfaitaire de 40 € pour frais de recouvrement. »*

## Piège 5 : envoyer la facture trop tard

Vous avez livré il y a 3 mois et vous facturez maintenant ? Risques :

- **Délai de prescription** réduit de fait
- **Trésorerie en souffrance** chez vous
- **Difficulté à se faire payer** (le client a oublié, le service comptable a changé…)

**Règle d'or** : facturer au plus tard le mois M+1 après la livraison.

## Piège 6 : ne pas relancer les impayés

Quand un client ne paie pas à l'échéance, **chaque jour qui passe** réduit vos chances de récupérer l'argent.

Processus de relance recommandé :
1. **J+0** : facture envoyée, échéance 30 jours
2. **J+30** : 1ère relance amicale (mail)
3. **J+45** : 2ème relance ferme (mail + appel)
4. **J+60** : mise en demeure (LRAR)
5. **J+75+** : action en recouvrement (huissier ou avocat)

> 💡 **Automatisez** ces relances. C'est désagréable à faire manuellement, et 80% des paiements arrivent dans les 7 jours suivant la première relance.

## Piège 7 : ne pas archiver correctement

Vous devez conserver vos factures (émises et reçues) pendant **10 ans**. Sur support papier ou numérique, peu importe, mais elles doivent être **lisibles et accessibles**.

Recommandations :
- Sauvegarde quotidienne automatique
- Format PDF non-modifiable
- Hébergement français pour la conformité RGPD
- Sauvegarde hors-site (pas que sur votre ordinateur)

## Bonus : les bonnes pratiques de pro

### ✅ Éditer en PDF (jamais en Word modifiable)
Le PDF est le standard. Word peut être modifié sans laisser de trace.

### ✅ Envoyer par email professionnel
Pas depuis votre adresse perso Gmail. Utilisez votre nom de domaine.

### ✅ Inclure un message personnalisé
*« Bonjour [Prénom], voici la facture pour [prestation]. Merci de me confirmer la bonne réception. À bientôt. »*

### ✅ Demander confirmation de réception
Évite les *« je ne l'ai jamais reçue »* trois mois plus tard.

### ✅ Proposer plusieurs moyens de paiement
Virement, prélèvement, carte… Plus c'est facile, plus c'est rapide.

## En résumé

Pour une facturation pro et sans risque :
1. **Toutes les mentions obligatoires** (utilisez un logiciel pour ne rien oublier)
2. **Numérotation chronologique stricte**
3. **Devis signé** systématique avant prestation
4. **Mention pénalités de retard** présente
5. **Facturation rapide** après livraison
6. **Relances automatisées** dès le 1er jour de retard
7. **Archivage 10 ans** sécurisé

> 🌿 Avec **Assokit**, vos factures sont conformes par défaut, numérotées automatiquement, archivées en France, et les relances partent toutes seules. Une corvée en moins.",
    ],

    [
        'slug' => 'rgpd-association-actions-prioritaires',
        'title' => 'RGPD et association : 7 actions à faire absolument (et comment)',
        'category' => 'juridique',
        'cover_emoji' => '🔒', 'cover_color_from' => '#0EA5E9', 'cover_color_to' => '#0F172A',
        'reading_time' => 8,
        'tags' => 'RGPD, données personnelles, CNIL, conformité',
        'excerpt' => 'Le RGPD ne concerne pas que les multinationales. Toute association manipulant des données personnelles doit s\'y conformer. Voici les 7 actions prioritaires.',
        'content' => "## Le RGPD vous concerne. Vraiment.

Vous gérez une asso. Vous avez forcément :
- Un fichier d'adhérents
- Une newsletter
- Des inscriptions à des événements
- Des dons enregistrés

**Toutes ces données sont \« personnelles\ »** au sens du RGPD. Et oui, vous êtes concerné·e, même si votre asso ne fait que 1 500 € de budget annuel.

Bonne nouvelle : la mise en conformité est **moins compliquée que vous ne le pensez**, surtout pour une petite structure.

## Les 7 actions prioritaires

### 1. Cartographier vos données

Faites le tour de **tous les endroits** où vous stockez des données personnelles :

- Fichier Excel des adhérents
- Liste de diffusion email
- Inscriptions aux événements
- CRM, plateforme de dons
- Compte bancaire (donateurs identifiés)
- Photos des événements
- Comptes-rendus de réunion (avec noms)

Listez : **quelles données**, **où**, **qui y a accès**, **combien de temps conservées**.

### 2. Identifier vos bases légales

Pour chaque traitement, vous devez justifier **pourquoi** vous traitez ces données :

- **Consentement** : la personne a explicitement coché *« J'accepte »*
- **Contrat** : nécessaire pour exécuter un contrat (cotisation, billetterie…)
- **Obligation légale** : comptabilité, fiscalité…
- **Intérêt légitime** : ex. communication aux adhérents existants
- **Mission d'intérêt public** : associations habilitées (rare)

> 💡 Pour une newsletter à des prospects (non adhérents) : **consentement explicite obligatoire**. Pour vos adhérents existants : **intérêt légitime** suffit.

### 3. Tenir un registre des traitements

**Obligatoire** dès que vous avez plus de 250 personnes en base, ou des données sensibles, ou des traitements réguliers.

Le registre liste :
- Le type de traitement (ex. *« Gestion des adhérents »*)
- Sa finalité
- Les catégories de données concernées
- Les destinataires
- La durée de conservation
- Les mesures de sécurité

[Modèle CNIL gratuit](https://www.cnil.fr/fr/registre-des-activites-de-traitement)

### 4. Informer vos personnes

**Toute personne** dont vous collectez des données doit être informée de :

- L'identité du responsable de traitement (votre asso)
- La finalité du traitement
- La base légale
- Les destinataires
- La durée de conservation
- Ses droits (accès, rectification, effacement, opposition…)

➡️ Concrètement : **une mention en bas de chaque formulaire** + **une politique de confidentialité accessible** sur votre site.

### 5. Sécuriser les données

Les bases :
- **Mots de passe robustes** sur tous les comptes (12 caractères minimum, gestionnaire de mots de passe)
- **Double authentification** activée sur les comptes sensibles
- **Hébergement de qualité** (préférez un hébergeur français RGPD-compliant)
- **Sauvegardes régulières et chiffrées**
- **Accès limité** aux personnes qui en ont besoin
- **Wifi sécurisé** sur les ordinateurs qui contiennent les données

### 6. Respecter les droits des personnes

Toute personne peut vous demander :
- **L'accès** à ses données (vous devez les fournir sous 1 mois)
- **La rectification** si elles sont fausses
- **L'effacement** (sous conditions)
- **L'opposition** au traitement
- **La portabilité** (récupérer ses données dans un format réutilisable)

➡️ Désignez **un point de contact** clair (un email type rgpd@votreasso.fr).

### 7. Notifier les fuites

En cas de **violation de données** (piratage, perte d'un ordinateur, envoi groupé d'emails sans BCC…) :

- **Notifier la CNIL sous 72h** si la fuite présente un risque pour les personnes
- **Notifier les personnes concernées** si le risque est élevé
- **Documenter l'incident** dans votre registre

## Faut-il un DPO (Délégué à la Protection des Données) ?

**Pas obligatoire** pour la plupart des assos, sauf :
- Vous traitez à grande échelle des données sensibles (santé, opinions politiques…)
- Vous faites du suivi régulier et systématique de personnes
- Vous êtes un organisme public

Pour les autres : un **référent informatique-libertés** désigné en interne suffit.

## Les sanctions : ce que vous risquez

- **Avertissement** ou mise en demeure (le plus fréquent)
- **Amende administrative** : jusqu'à **20 millions €** ou **4% du chiffre d'affaires** (rare pour les petites assos, mais possible)
- **Atteinte à la réputation** en cas de fuite médiatisée

Pour une petite asso, le risque réel d'amende est **faible**. Mais une mise en demeure publique fait du dégât à votre image.

## Checklist express RGPD pour asso

- [ ] Cartographie des données faite
- [ ] Registre des traitements à jour
- [ ] Politique de confidentialité publiée
- [ ] Mentions sur les formulaires
- [ ] Mots de passe robustes
- [ ] Sauvegardes en place
- [ ] Accès limité aux personnes habilitées
- [ ] Procédure en cas de violation rédigée

## En résumé

Le RGPD n'est pas un mur. C'est une **bonne pratique** qui protège vos adhérents et votre asso. Avec quelques actions simples, vous êtes en conformité.

> 🌿 **Assokit est conçu RGPD-compliant par défaut** : hébergement en France, journal des accès, droit à l'effacement intégré, exports faciles. Vous économisez des semaines de travail.",
    ],

    [
        'slug' => 'communication-association-outils-essentiels',
        'title' => 'Communication d\'asso : les 6 outils vraiment essentiels (et le reste, oubliez)',
        'category' => 'communication',
        'cover_emoji' => '📣', 'cover_color_from' => '#EC4899', 'cover_color_to' => '#9D174D',
        'reading_time' => 7,
        'tags' => 'communication, outils, réseaux sociaux, newsletter',
        'excerpt' => 'Pas besoin de 15 outils différents pour bien communiquer. Voici les 6 indispensables et la stratégie pour les utiliser efficacement.',
        'content' => "## La fausse bonne idée

*« On va se créer un Facebook, un Instagram, un Twitter, un TikTok, un LinkedIn, une newsletter, un blog, et puis pourquoi pas un podcast tant qu'on y est. »*

Trois mois plus tard : tout est à l'abandon.

**La vérité** : 90% des associations gagneraient à se concentrer sur **moins d'outils, mieux utilisés**.

Voici les 6 essentiels (les autres sont des bonus).

## 1. Un site web (oui, c'est encore essentiel)

C'est votre **vitrine officielle**. Le seul espace 100% sous votre contrôle.

À avoir :
- Présentation claire de votre mission
- Page *« Adhérer »* avec formulaire
- Page *« Faire un don »* (avec reçu fiscal)
- Page *« Nous contacter »*
- Quelques actualités récentes
- Mentions légales et politique RGPD

**Pas besoin** d'un site à 5000 €. Un site simple à 0-50 €/mois suffit largement.

## 2. Une newsletter régulière

L'outil **le plus rentable** pour fidéliser. Bien plus que les réseaux sociaux qui changent leurs algorithmes tous les 6 mois.

Recommandations :
- **Cadence** : 1 fois par mois minimum, 1 fois par semaine maximum
- **Format court** : 200-400 mots
- **Une seule idée par newsletter**
- **Un seul appel à l'action**
- **Mesurer** : taux d'ouverture (objectif &gt; 25%), taux de clic (&gt; 2%)

Outils : Mailchimp, Brevo, Resend, Sendinblue.

## 3. Un réseau social principal (un seul !)

Choisissez celui où **est votre communauté** :

- **Facebook** : familles, retraités, locales, sport, culture populaire
- **Instagram** : jeunes adultes, lifestyle, visuel, événementiel
- **LinkedIn** : pro, mécénat d'entreprise, partenariats institutionnels
- **TikTok** : ado/jeunes adultes, plaidoyer, mobilisation
- **YouTube** : pédagogie, témoignages longs, événements filmés

**Mieux vaut être excellent sur 1 que médiocre sur 5.**

Postez **2 à 3 fois par semaine maximum**. La régularité prime sur la fréquence.

## 4. Une plateforme de dons

Pour collecter en ligne efficacement :

- **HelloAsso** (gratuit, populaire en France)
- **GoFundMe** (international, frais)
- **Lydia, PayPal** (simple mais moins pro)
- **Stripe** (intégration sur votre site)

Critères de choix :
- Frais transparents (HelloAsso : 0% pour l'asso, optionnel pour le donateur)
- Génération automatique de reçus fiscaux
- Interface mobile-friendly

## 5. WhatsApp / Slack pour l'équipe

**Communication interne ≠ communication externe.**

Pour vos bénévoles, votre bureau, vos commissions :
- **WhatsApp Business** : simple, accessible à tous, perçu comme moins formel
- **Slack** ou **Discord** : si vous êtes &gt; 20 personnes actives ou si vous avez besoin de canaux thématiques
- **Signal** : pour les sujets sensibles (vie privée, santé…)

> 💡 Évitez les emails internes pour les conversations rapides. Réservez l'email aux contenus structurés et aux échanges externes.

## 6. Un outil pour gérer vos adhérents

Pas un tableur. Un vrai outil qui :
- Centralise les contacts adhérents
- Suit les cotisations
- Permet l'envoi groupé ciblé
- S'intègre à votre site et votre newsletter
- Respecte le RGPD

C'est exactement ce que fait **Assokit** 🌿

## Les outils dont vous n'avez pas (encore) besoin

- ❌ **Compte Twitter/X** sauf cause politique ou événementielle pointue
- ❌ **TikTok** sauf cible jeune et capacité à produire de la vidéo régulière
- ❌ **Podcast** : énorme charge pour peu d'impact au début
- ❌ **Application mobile** : utiliser le web mobile suffit dans 99% des cas
- ❌ **Snapchat** sauf cible adolescente très spécifique

## La stratégie qui marche : la matrice de contenu

Pour chaque message important, déclinez sur 3 canaux :

1. **Article long** sur votre site/blog → 800 mots, SEO
2. **Newsletter** envoyée à vos abonnés → résumé + lien
3. **Post réseau social** → punchy, visuel, lien

C'est **3x plus de portée** pour 1.5x plus de travail.

## Combien de temps consacrer à la com ?

Pour une asso active :
- **Création de contenu** : 4-6h/semaine
- **Réponse / animation** : 2-3h/semaine
- **Stratégie / pilotage** : 1h/semaine
- **Total** : 7-10h/semaine

Si une seule personne porte tout, c'est trop. **Constituez une mini-équipe com** (2-3 personnes), même bénévoles.

## Mesurer ce qui compte

Au lieu de regarder des likes, regardez :

- Combien de **nouveaux adhérents/donateurs** par mois ?
- Combien de **bénévoles actifs** ?
- Combien d'**inscrits à votre newsletter** ?
- Combien d'**ouvertures par newsletter** ?

Ce sont les seuls indicateurs qui prédisent la santé long terme de votre asso.

## En résumé

Communication d'asso efficace =
1. **Un site clair**
2. **Une newsletter régulière**
3. **Un seul réseau social bien fait**
4. **Une plateforme de dons**
5. **Un canal interne pour l'équipe**
6. **Un outil de gestion d'adhérents**

Et c'est largement assez pour faire 80% du travail.

> 🌿 **Assokit** intègre la gestion des adhérents, la diffusion email ciblée et l'IA pour rédiger vos contenus en quelques clics. Toute votre com, dans un seul outil.",
    ],

    [
        'slug' => 'assemblee-generale-association-guide',
        'title' => 'Assemblée générale d\'association : le guide ultime sans stress',
        'category' => 'associations',
        'cover_emoji' => '📋', 'cover_color_from' => '#059669', 'cover_color_to' => '#0F172A',
        'reading_time' => 8,
        'tags' => 'AG, assemblée générale, gouvernance, AGE',
        'excerpt' => 'Préparer une AG, ce n\'est pas que cocher des cases. C\'est aussi l\'occasion de mobiliser vos membres. Voici comment réussir la vôtre.',
        'content' => "## Pourquoi l'AG est plus qu'une formalité

L'Assemblée Générale est **le seul moment de l'année** où :

- L'ensemble des adhérents se retrouvent
- Les décisions importantes sont prises
- Le bureau rend compte de son action
- Les nouveaux projets sont validés
- Les liens entre membres se renforcent

**Bien menée**, elle galvanise. **Mal menée**, elle démobilise.

## Les 2 types d'AG

### Assemblée Générale Ordinaire (AGO)
**Annuelle** (obligatoire). Elle :
- Approuve les comptes de l'année écoulée
- Donne quitus aux dirigeants
- Vote le budget prévisionnel
- Renouvelle (ou non) les mandats
- Fixe la cotisation annuelle

### Assemblée Générale Extraordinaire (AGE)
**Convocable à tout moment** pour :
- Modifier les statuts
- Changer le siège social
- Fusionner ou dissoudre l'association

## La préparation : 6 semaines avant

### Semaine -6 : fixer la date
Choisissez une date qui maximise la présence. Évitez :
- Les vacances scolaires
- Les longs weekends
- La période d'AG concurrentes (si vous êtes en réseau)

Idéal : **un samedi matin** de mars-avril (pour les AG de comptes) ou en fin d'année.

### Semaine -5 : préparer l'ordre du jour
L'ordre du jour type d'une AGO :
1. Émargement et vérification du quorum
2. Présentation du rapport moral (président)
3. Présentation du rapport d'activité (secrétaire)
4. Présentation du rapport financier (trésorier)
5. Vote pour approbation des rapports
6. Présentation du budget prévisionnel
7. Vote du budget
8. Élections (si renouvellement)
9. Questions diverses

### Semaine -4 : convoquer
**Délai légal** selon vos statuts (généralement 15 à 30 jours avant).

Convocation par :
- **Email** (le plus simple, valable si vos statuts le prévoient)
- **Courrier** (le plus formel)
- **Affichage local** (pour les petites assos)

La convocation doit contenir :
- Date, heure, lieu (ou modalités visio)
- Ordre du jour précis
- Documents préparatoires (rapport moral, comptes…)
- Modalités de procuration

### Semaine -3 : préparer les documents
- Rapport moral (1-2 pages)
- Rapport d'activité (avec photos, chiffres-clés)
- Rapport financier (compte de résultat, bilan, commentaires)
- Budget prévisionnel
- Liste des candidats (si élections)

### Semaine -2 : relances
Email de rappel à J-15 et J-7. Téléphone aux principaux contributeurs si nécessaire.

### Semaine -1 : logistique
- Réservation salle confirmée
- Émargement préparé
- Bulletins de vote prêts
- Café/croissants commandés
- Vidéoprojecteur testé

## Le jour J : déroulé optimal

### Avant le démarrage (8h-9h ou 14h-15h)
- Accueil chaleureux
- Émargement à l'entrée
- Café/biscuits
- Distribution des documents

### Vérification du quorum
Comptez les présents et les procurations. Vérifiez que vous atteignez le quorum prévu par vos statuts (souvent 1/3 des membres).

> ⚠️ **Sans quorum, pas de vote possible**. Prévoyez ce cas dans vos statuts (AG sur seconde convocation, etc.).

### Désignation du bureau de l'AG
- Président de séance (souvent le président de l'asso)
- Secrétaire de séance (souvent le secrétaire)
- Scrutateurs (2 membres pour les votes)

### Présentation des rapports (60 min)
Chaque rapport en 10-15 min, avec :
- Une présentation visuelle (slides simples)
- Des chiffres-clés
- Des photos / témoignages
- Du temps pour les questions

### Votes
Plusieurs modes possibles :
- **À main levée** (le plus rapide)
- **Bulletin secret** (obligatoire pour les élections de personnes, sur demande)
- **Vote électronique** (de plus en plus utilisé)

### Élections (si applicable)
- Présentation des candidats (3 min chacun)
- Questions
- Vote
- Dépouillement
- Annonce des résultats

### Questions diverses (30 min)
**Pas de votes** sur des points non inscrits à l'ordre du jour.

### Clôture conviviale
Apéritif, échange libre, photos. Ce moment est **aussi important que le formel**.

## Le post-AG : 7 jours après

### Procès-verbal d'AG
Document officiel, rédigé par le secrétaire, contenant :
- Date, lieu, heure
- Membres présents et représentés
- Ordre du jour
- Synthèse des présentations
- Détail des votes (résultats chiffrés)
- Décisions adoptées

### Démarches officielles
Si vous avez modifié des éléments en AGE :
- Modification statuts → déclaration en préfecture (sous 3 mois)
- Changement de bureau → déclaration en préfecture
- Modification siège → déclaration en préfecture

### Communication post-AG
- Email de remerciement aux participants
- Synthèse publiée sur le site web
- Compte-rendu accessible aux adhérents
- Annonce des décisions principales

## Erreurs fréquentes à éviter

- ❌ **Convoquer trop tard** : la loi impose un délai (vérifiez vos statuts)
- ❌ **Présenter des comptes peu compréhensibles** : adaptez aux non-experts
- ❌ **Étouffer les questions** : laissez le temps de la discussion
- ❌ **Ne pas tenir le PV** : le PV est un document juridique
- ❌ **Oublier les procurations** : elles comptent dans le quorum

## Petits trucs qui font la différence

- ✅ **Une AG en 2h** maximum (au-delà, les gens décrochent)
- ✅ **Un livret papier** distribué à l'entrée avec les rapports
- ✅ **Témoignages d'adhérents** intégrés au rapport moral
- ✅ **Photos de l'année** projetées en boucle pendant l'accueil
- ✅ **Café + viennoiseries** (oui, ça compte vraiment)

## En résumé

Une AG réussie =
1. **6 semaines de préparation**
2. **Convocations dans les délais** (avec documents)
3. **Ordre du jour clair**
4. **Présentations vivantes** (10-15 min max)
5. **Votes formalisés**
6. **PV rédigé sous 7 jours**
7. **Communication post-AG**

> 🌿 Avec **Assokit**, vous gérez vos convocations, vos votes et vos PV en quelques clics. L'IA peut même rédiger votre rapport moral et votre compte-rendu d'AG.",
    ],

    [
        'slug' => 'subventions-association-comment-obtenir',
        'title' => 'Subventions associatives : 5 étapes concrètes pour en décrocher',
        'category' => 'associations',
        'cover_emoji' => '💼', 'cover_color_from' => '#059669', 'cover_color_to' => '#0F172A',
        'reading_time' => 8,
        'tags' => 'subventions, financement, dossier, collectivités',
        'excerpt' => 'Les subventions ne tombent pas du ciel. Voici la méthode pour identifier les bonnes, monter un dossier solide, et augmenter vos chances de succès.',
        'content' => "## Le mythe de la subvention facile

*« On va demander une subvention »*. Cette phrase, prononcée chaque année dans des milliers d'assos, cache une réalité : **80% des dossiers sont rejetés**.

Pas par manque d'intérêt du projet. Mais par dossier mal monté, mauvais ciblage, ou absence de suivi.

Voici la méthode pour faire partie des 20% qui décrochent.

## Étape 1 : connaître les sources de financement

### Subventions publiques
- **État** : ministères selon votre champ (Sport, Culture, Affaires sociales…)
- **Région** : cadres pluriannuels, souvent gros budgets
- **Département** : action sociale, culture, sport
- **Commune et intercommunalité** : fonctionnement, événements
- **Europe** : Erasmus+, FSE+, programmes thématiques

### Subventions privées
- **Fondations d'entreprise** : EDF, Vinci, Crédit Agricole, BNP Paribas, etc.
- **Fondations indépendantes** : Fondation de France, Fondation Bettencourt, etc.
- **Mécénat de compétences** : entreprises locales

### Appels à projets thématiques
Surveillez sites comme :
- [Carenews](https://www.carenews.com)
- [HelloAsso · Appels à projets](https://www.helloasso.com)
- Sites des ministères et collectivités

## Étape 2 : cibler les bonnes subventions

**Erreur classique** : envoyer le même dossier partout.

**Méthode** :
1. Listez **toutes les subventions possibles**
2. Pour chacune, vérifiez :
   - Vos critères d'éligibilité
   - Les types de projets financés
   - Les montants moyens accordés
   - Le calendrier de l'appel
   - Les pièces demandées
3. Notez de 1 à 5 votre **probabilité de succès**
4. Concentrez-vous sur les top 3-5

> 💡 **Mieux vaut 3 dossiers excellents que 10 médiocres.**

## Étape 3 : monter un dossier béton

### La structure type d'un dossier
1. **Présentation de l'asso** (1 page)
2. **Présentation du projet** (2-3 pages)
3. **Budget prévisionnel** (1 page)
4. **Plan de financement** (1 page)
5. **Calendrier** (0.5 page)
6. **Indicateurs d'évaluation** (0.5 page)
7. **Annexes** : statuts, RIB, dernier compte de résultat, attestation d'assurance, lettres de soutien

### Les 7 questions clés à traiter
1. **Quoi ?** — Nature du projet
2. **Pourquoi ?** — Diagnostic du besoin
3. **Pour qui ?** — Public cible précis
4. **Comment ?** — Méthodologie
5. **Avec qui ?** — Partenaires
6. **Quand ?** — Calendrier
7. **Combien ?** — Budget réaliste

### Les ingrédients d'un dossier qui passe
- ✅ Un **objectif précis et mesurable**
- ✅ Un **budget équilibré et justifié** (chaque ligne expliquée)
- ✅ Un **diagnostic fondé** (chiffres, études, témoignages)
- ✅ Un **public cible quantifié** (« 200 jeunes de 15-25 ans à Lyon »)
- ✅ Des **indicateurs de réussite** (« avant : X / après : Y »)
- ✅ Un **co-financement** (rarement 100% accordé d'un coup)
- ✅ Une **durabilité** (que devient le projet après la subvention ?)

### Les rouges qui font rejeter
- ❌ Budget approximatif (« divers : 5 000 € »)
- ❌ Aucun co-financeur (signe que personne d'autre n'y croit)
- ❌ Public cible flou (« la population locale »)
- ❌ Pas d'évaluation prévue
- ❌ Mauvaise orthographe / mise en page bâclée
- ❌ Hors-sujet par rapport à l'appel à projet

## Étape 4 : déposer et suivre

### Avant l'envoi
- **Relecture** par 2-3 personnes
- **Vérification des pièces** demandées
- **Respect du format** (pdf, word, plateforme en ligne…)
- **Respect de la date limite** — ne déposez **jamais** le dernier jour

### Après l'envoi
- **Accusé de réception** : conservez-le
- **Suivi** : un mail à mi-parcours pour voir où en est l'instruction
- **Réponse** : positive, négative ou demande de complément

### Si refus
- Demandez **les motifs** par mail
- Ne le prenez pas personnellement
- **Améliorez le dossier** pour la prochaine fois
- Repostulez l'année suivante (souvent c'est le bon timing)

## Étape 5 : utiliser et justifier

Une fois la subvention obtenue, **votre travail commence** :

### Pendant le projet
- **Comptabilité analytique** : isolez les dépenses du projet
- **Conservez** tous les justificatifs
- **Communiquez** sur l'opération (avec mention du financeur)
- **Suivez** vos indicateurs au fil de l'eau

### À la fin du projet
- **Bilan financier** détaillé (chaque dépense justifiée)
- **Bilan d'activité** (objectifs atteints, indicateurs)
- **Bilan qualitatif** (témoignages, photos, presse)

> ⚠️ Une subvention non-justifiée peut être **demandée en remboursement**. Soyez rigoureux.

## Les bons plans pour augmenter vos chances

### 1. Construisez des partenariats
Une lettre de soutien d'un partenaire crédible (collectivité, association reconnue, entreprise) peut faire la différence.

### 2. Cumulez les co-financements
Un projet avec **3 co-financeurs** est jugé plus solide qu'un projet financé à 100% par une seule source.

### 3. Personnalisez vos dossiers
Un dossier *« copié-collé »* se voit à 1 km. Réécrivez la partie *« lien avec les priorités du financeur »* à chaque fois.

### 4. Cultivez la relation
Rencontrez les agents instructeurs avant de déposer. Un dossier porté par une connaissance vaut 10 dossiers anonymes.

### 5. Démarrez petit
Une asso jeune n'aura pas 50 000 € directement. Visez des subventions de **2 000 - 5 000 €** la première année, montez progressivement.

## En résumé

Décrocher une subvention =
1. **Identifier** les bonnes sources (ciblage)
2. **Monter** un dossier béton (structure type, budget précis)
3. **Déposer** dans les délais avec toutes les pièces
4. **Suivre** activement l'instruction
5. **Justifier** rigoureusement après obtention

> 🌿 Avec **Assokit**, l'IA vous aide à rédiger vos dossiers de subvention, à structurer votre argumentaire et à formaliser vos indicateurs. Vous gagnez des heures sur chaque demande.",
    ],

    [
        'slug' => 'comptabilite-association-bases',
        'title' => 'Comptabilité associative : les 5 bases à maîtriser absolument',
        'category' => 'comptabilite',
        'cover_emoji' => '📊', 'cover_color_from' => '#F59E0B', 'cover_color_to' => '#92400E',
        'reading_time' => 7,
        'tags' => 'comptabilité asso, plan comptable, bilan, compte de résultat',
        'excerpt' => 'La comptabilité d\'une asso n\'a rien à voir avec celle d\'une entreprise. Voici les vraies bases pour ne pas se planter.',
        'content' => "## Pourquoi la compta asso est un sujet à part

Les associations ont leurs **règles propres**, distinctes de celles des entreprises commerciales. Confondre les deux mène à des erreurs coûteuses.

Spécificités à connaître :
- Plan comptable spécifique : **PCG associatif (règlement ANC 2018-06)**
- Pas de TVA collectée si pas d'activité commerciale
- Subventions à inscrire en produits constatés d'avance jusqu'à utilisation
- Dons et legs traités spécifiquement
- Bénévolat à valoriser en compta

## Base 1 : connaître votre régime comptable

Selon la taille et l'activité de votre asso :

### Régime simplifié (recettes-dépenses)
**Suffit pour** : petites assos, &lt; 50 000 € de budget annuel, sans subventions publiques importantes, sans salariés.

**Vous tenez** :
- Un livre des recettes
- Un livre des dépenses

C'est tout.

### Régime de droit commun (comptabilité d'engagement)
**Obligatoire dès que** :
- Vous recevez plus de 153 000 € de subventions publiques par an
- Vous avez une activité commerciale habituelle
- Vous bénéficiez de dons défiscalisés &gt; 153 000 €/an
- Vous employez des salariés

**Vous tenez** :
- Un journal des opérations
- Un grand livre des comptes
- Une balance générale
- Un bilan
- Un compte de résultat
- Une annexe

## Base 2 : le compte de résultat

Document qui présente, sur un exercice (généralement 12 mois) :
- Les **produits** (entrées)
- Les **charges** (sorties)
- Le **résultat** (bénéfice ou déficit)

### Produits typiques d'une asso
- Cotisations
- Subventions reçues
- Dons et legs
- Recettes d'événements (billetterie, buvette…)
- Ventes de biens ou services
- Sponsoring (attention au régime fiscal)
- Prestations annexes
- Produits financiers (intérêts du livret)

### Charges typiques
- Achats (matériel, fournitures)
- Loyers, énergies, communications
- Salaires + charges (si salariés)
- Indemnités bénévoles, frais bénévoles
- Honoraires (compta, avocat)
- Assurances
- Cotisations à des fédérations
- Amortissements (si gros matériel)
- Frais bancaires

### L'équation
**Produits − Charges = Résultat**
- Positif → **excédent** (vous mettez en réserves)
- Négatif → **déficit** (vous puisez dans les réserves)

## Base 3 : le bilan

Photo du **patrimoine** de l'asso à un instant T.

### Actif (ce que possède l'asso)
- **Actif immobilisé** : bâtiments, gros matériel, véhicules…
- **Actif circulant** : stocks, créances clients/adhérents non encaissées
- **Trésorerie** : comptes bancaires, caisse

### Passif (d'où vient l'argent)
- **Fonds propres** : réserves, résultat de l'exercice
- **Provisions** : pour risques, pour charges
- **Dettes** : emprunts, fournisseurs non payés, charges sociales dues
- **Subventions reçues** : non encore utilisées

**Total actif = Total passif** (toujours).

## Base 4 : les écritures comptables types

### Une cotisation reçue
Débit : Banque (512) — 50 €
Crédit : Cotisations (756) — 50 €

### Une subvention reçue mais non utilisée
Débit : Banque (512) — 5 000 €
Crédit : Subvention en attente (487) — 5 000 €

Quand vous l'utilisez :
Débit : Subvention en attente (487) — 5 000 €
Crédit : Subvention d'exploitation (74) — 5 000 €

### Un don reçu avec reçu fiscal
Débit : Banque (512) — 200 €
Crédit : Dons reçus (754) — 200 €

### Une facture fournisseur
Débit : Fournitures (606) — 100 €
Crédit : Fournisseurs (401) — 100 €

Quand vous payez :
Débit : Fournisseurs (401) — 100 €
Crédit : Banque (512) — 100 €

## Base 5 : valoriser le bénévolat

C'est **spécifique aux assos**. Vous pouvez (et devez si subventions publiques) **valoriser le temps de bénévolat** dans vos comptes :

### Pourquoi c'est important
- Montre la **vraie taille** de votre projet
- Argumente vos **demandes de subvention**
- Donne de la crédibilité face aux partenaires
- Reconnaît la valeur des bénévoles

### Comment valoriser
- **Heures bénévoles** × **taux horaire** (souvent SMIC ou taux marché)
- **Mises à disposition gratuites** (locaux, matériel)
- **Compétences expertes offertes** (juriste, comptable…)

### Comptabilisation
Inscrit en :
- **Charges** : *« Personnel bénévole »* (compte 86)
- **Produits** : *« Contributions volontaires en nature »* (compte 87)

C'est neutre sur le résultat, mais montre l'ampleur réelle.

## Les obligations annuelles

### Pour toutes les assos
- Tenir une comptabilité (régime adapté)
- Présenter les comptes à l'AG
- Conserver les pièces 10 ans

### Pour les assos &gt; 153 000 € de subventions / dons
- Comptabilité d'engagement obligatoire
- Désignation d'un commissaire aux comptes
- Publication des comptes au JOAFE (sous 3 mois après l'AG)

### Pour les assos avec salariés
- DSN mensuelles
- Établir des bulletins de paie
- Reverser charges sociales (URSSAF, Pôle Emploi, retraite, prévoyance)

## Logiciels recommandés

- **Excel/Google Sheets** : OK pour assos &lt; 20 000 € de budget
- **Logiciel asso dédié** (Assokit, Sage Asso, Iris Asso…) : recommandé dès qu'on dépasse les 20 K€
- **Logiciel pro complet** (Sage 100, Cegid Quadra…) : pour les grosses structures avec salariés

## En résumé

Maîtriser la compta asso, c'est :
1. Connaître **votre régime** (simplifié ou droit commun)
2. Comprendre **compte de résultat et bilan**
3. Savoir **passer les écritures** types
4. Valoriser le **bénévolat** dans vos comptes
5. Respecter les **obligations annuelles**

> 🌿 Avec **Assokit**, vous gérez factures, cotisations et subventions en quelques clics. Le tableau de bord vous donne en temps réel votre compte de résultat simplifié et votre trésorerie disponible.",
    ],

    [
        'slug' => 'ia-association-opportunites-pieges',
        'title' => 'IA pour les associations : 6 vrais usages utiles (et 3 fausses bonnes idées)',
        'category' => 'gestion',
        'cover_emoji' => '🤖', 'cover_color_from' => '#7E22CE', 'cover_color_to' => '#0F172A',
        'reading_time' => 8,
        'tags' => 'IA, intelligence artificielle, productivité, ChatGPT, automatisation',
        'excerpt' => 'L\'IA peut faire gagner des heures à votre asso. Mais pas n\'importe comment. Voici les usages qui marchent et ceux qu\'on déconseille.',
        'content' => "## L'IA dans les assos : où en sommes-nous ?

En 2026, l'IA générative est partout : ChatGPT, Claude, Gemini, Mistral. Pour les associations, c'est une **révolution silencieuse** : ce qui prenait 2h prend maintenant 15 minutes.

Mais attention : l'IA n'est ni magique, ni neutre. Mal utilisée, elle vous fait **perdre du temps** et **perdre la voix authentique** de votre asso.

Voici **6 usages qui marchent vraiment** et **3 pièges à éviter**.

## ✅ Les 6 usages qui font gagner du temps

### 1. Rédaction de communications standard

L'IA excelle pour les contenus **structurés et factuels** :
- Convocations d'AG
- Comptes-rendus de réunion
- Communiqués de presse
- Lettres administratives
- Réponses types aux questions fréquentes

**Méthode** : donnez à l'IA les éléments factuels (date, ordre du jour, contexte), elle structure et rédige. Vous relisez et personnalisez.

**Gain** : 30-60 minutes par document.

### 2. Newsletters thématiques

L'IA aide à :
- Trouver des angles de rédaction
- Adapter le ton pour différents publics
- Générer des objets accrocheurs
- Décliner un même message en formats courts/longs

**Méthode** : donnez vos infos brutes, l'IA propose plusieurs versions, vous gardez ce qui sonne juste.

**Gain** : 1-2 heures par newsletter.

### 3. Posts réseaux sociaux

L'IA décline rapidement un même message en :
- Post LinkedIn pro
- Post Instagram visuel
- Post Facebook narratif
- Tweet/X concis

**Méthode** : indiquez le réseau, le ton souhaité (formel, complice, militant), le call-to-action. L'IA produit 3-5 variantes.

**Gain** : 30-45 min par publication multi-canale.

### 4. Recrutement de bénévoles

L'IA rédige :
- Des fiches de poste claires
- Des annonces motivantes
- Des emails de réponse personnalisés aux candidatures
- Des questions d'entretien adaptées à la mission

**Méthode** : décrivez la mission, le profil idéal, le contexte. L'IA propose un descriptif structuré.

**Gain** : 2-3 heures sur un cycle de recrutement.

### 5. Demandes de subvention

L'IA aide à :
- Structurer un dossier
- Reformuler le projet selon les priorités du financeur
- Renforcer l'argumentaire
- Vérifier la cohérence

**Méthode** : donnez votre projet brut + les priorités du financeur. L'IA produit une version alignée.

**Gain** : 4-6 heures par dossier.

### 6. Synthèse de documents

L'IA résume :
- Comptes-rendus longs
- Rapports d'activité
- Études et veilles
- Réglementations

**Méthode** : copiez le document, demandez une synthèse de X mots. L'IA l'extrait.

**Gain** : 1-2 heures par document.

## ❌ Les 3 pièges à éviter

### Piège 1 : confier la stratégie à l'IA

L'IA est **excellente pour exécuter**. Elle est **médiocre pour décider**.

❌ *« IA, propose-moi la stratégie de mon asso pour 2027 »* → réponse générique
✅ *« IA, formule ma stratégie déjà décidée [X] de manière percutante »* → réponse utile

La stratégie reste **votre travail**.

### Piège 2 : copier-coller sans relire

L'IA fait des erreurs :
- Faits inventés (les fameuses *« hallucinations »*)
- Ton parfois bancal
- Spécificités françaises ratées (cadre légal, fiscal…)
- Sources fictives

**Toujours relire. Toujours vérifier.** Pour les sujets sensibles (juridique, fiscal, médical), faites valider par un humain expert.

### Piège 3 : perdre votre voix authentique

Si toutes vos communications sont écrites par l'IA, elles vont **se ressembler à celles de toutes les autres assos**.

Solution : utilisez l'IA comme **un assistant**, pas comme un remplaçant. Reprenez les mots, l'humour, les expressions qui sont **vraiment les vôtres**.

## RGPD et IA : ce qu'il faut savoir

L'IA est sous-traitante. Vérifiez :
- **Où sont traitées les données** (hors UE = vigilance)
- **Combien de temps elles sont stockées**
- **Si elles servent à entraîner les modèles** (à éviter)

**Ne donnez jamais à une IA grand public** :
- Données de santé
- Données nominatives sans accord
- Documents confidentiels (statuts, comptes, procès-verbaux nominatifs)

Préférez des solutions **avec garanties RGPD** (Mistral en France, Claude pour les usages sensibles via API contractualisée).

## Les outils à connaître en 2026

### Assistants conversationnels
- **ChatGPT** (OpenAI) : le plus connu, polyvalent
- **Claude** (Anthropic) : excellent en français, qualité d'écriture
- **Mistral** (France) : européen, RGPD-compliant
- **Gemini** (Google) : intégré à Google Workspace

### Génération d'images
- **DALL-E 3** (intégré à ChatGPT)
- **Midjourney** : qualité artistique élevée
- **Adobe Firefly** : intégré à la suite Adobe

### Automatisation avec IA
- **Zapier + IA** : connecter outils + IA
- **Make.com** : workflows visuels
- **Notion AI** : assistant intégré à Notion

### Outils dédiés aux assos
- **Assokit** : IA intégrée pour communication asso (convocations, dons, posts, courriers…)

## Comment se former (rapidement)

- **Lire 1-2 articles par semaine** sur l'IA appliquée
- **Tester** régulièrement avec vos cas d'usage
- **Échanger** avec d'autres assos qui utilisent l'IA
- **Formations courtes** : MOOCs gratuits (FUN, France Université Numérique)

## La règle d'or : commencer petit

Ne tentez pas de **tout transformer d'un coup**. Choisissez **1 cas d'usage** (par exemple : la newsletter mensuelle), maîtrisez-le, mesurez le gain de temps.

Puis passez au suivant.

En 6 mois, vous économisez facilement **5-10 heures par mois**. Précieuses heures à reverser sur ce qui compte vraiment : votre projet.

## En résumé

L'IA pour une asso, c'est :
- ✅ Excellente pour la **rédaction structurée**
- ✅ Précieuse pour la **diversification de canaux**
- ✅ Utile pour les **dossiers de subvention**
- ❌ Inadaptée pour la **stratégie**
- ❌ Dangereuse en **copier-coller aveugle**
- ❌ À utiliser avec **vigilance RGPD**

> 🌿 **Assokit IA** propose 19 outils dédiés aux associations et aux TPE : convocations, communiqués, posts réseaux, dons, recrutement bénévoles… L'IA travaille avec vous, pas à votre place. Conformité française, qualité au rendez-vous.",
    ],

    [
        'slug' => 'tpe-gerer-clients-cle-reussite',
        'title' => 'TPE : 5 règles d\'or pour gérer ses clients (et les fidéliser durablement)',
        'category' => 'tpe',
        'cover_emoji' => '🤝', 'cover_color_from' => '#7E22CE', 'cover_color_to' => '#0F172A',
        'reading_time' => 7,
        'tags' => 'clients, fidélisation, TPE, indépendants, relation',
        'excerpt' => 'Acquérir un client coûte 5 fois plus cher que de le garder. Voici les 5 règles d\'or pour développer une relation client durable et profitable.',
        'content' => "## Le constat qui change tout

Une étude Bain & Company l'a prouvé : **augmenter de 5% le taux de fidélisation client peut augmenter les profits de 25 à 95%**.

Pour une TPE, cette donnée est **vitale**. Voici 5 règles concrètes pour fidéliser durablement.

## Règle 1 : connaître ses clients

Vous ne pouvez pas fidéliser des inconnus.

### Les infos minimales à avoir
- Nom, prénom, email, téléphone
- Société (si B2B)
- Date du premier contact
- Historique des interactions
- Préférences exprimées
- Date du dernier contact

### Les infos qui font la différence
- Anniversaire (perso ou société)
- Centres d'intérêt mentionnés
- Réseau social préféré
- Détails personnels (enfants, hobbies)

> 💡 **Astuce** : un CRM, même simple, vaut mieux qu'un Excel. Vous y centralisez tout, vous ne perdez rien.

## Règle 2 : être réactif (vraiment)

**90% des clients abandonnent** un fournisseur qui met &gt; 24h à répondre à une question simple.

### Les standards à respecter
- **Email pro** : réponse sous 4h en jours ouvrés
- **Message via le site** : réponse sous 24h
- **Réseaux sociaux** : réponse sous 12h
- **Téléphone manqué** : rappel dans la journée

Mieux vaut **répondre brièvement vite** que **rédiger longuement tard**.

### Quand vous ne pouvez pas répondre tout de suite
Envoyez un mot court : *« Bien reçu, je reviens vers vous d'ici demain matin avec une réponse complète. »*

Le client se sent considéré. C'est ce qui compte.

## Règle 3 : sur-livrer

La fidélité naît de la **surprise positive**, jamais du *« juste-comme-promis »*.

### Comment sur-livrer concrètement
- **Livrer en avance** quand c'est possible
- **Inclure un petit plus** non facturé (un conseil, un bonus)
- **Anticiper** les besoins avant que le client les exprime
- **Soigner le détail** (emballage, présentation, mot manuscrit)

> 💡 **Test** : à la fin d'une prestation, demandez-vous *« Le client va-t-il avoir envie de me recommander ? »*. Si la réponse n'est pas un *« oui ! »* clair, vous avez sous-livré.

## Règle 4 : entretenir le lien hors-vente

Si vous ne contactez vos clients que pour vendre, vous êtes vu comme un **vendeur**, pas comme un partenaire.

### Touchpoints non-commerciaux
- **Newsletter mensuelle** avec contenu utile
- **Vœux** en fin d'année (personnels, pas un copier-coller)
- **Relayer leur actu** sur les réseaux sociaux
- **Envoyer un article** pertinent pour eux
- **Saluer un anniversaire** (perso ou société)
- **Inviter à un événement** sans contrepartie

### La règle 80/20
- **80% du contact** = valeur ajoutée pour le client
- **20% du contact** = sollicitation commerciale

Vous serez 10x plus écouté quand vous vendrez.

## Règle 5 : recueillir et utiliser les feedbacks

Les clients qui partent ne le disent **pas toujours**. Ils disparaissent simplement.

### Comment forcer le feedback
- **Email de satisfaction** systématique après chaque prestation (3 questions max)
- **Appel** au bout de 3 mois pour les gros clients
- **Demande d'avis Google** pour les avis publics
- **Réunions trimestrielles** de fond pour les comptes stratégiques

### Que faire des retours
- **Positif** : remercier + demander un témoignage / avis Google
- **Négatif** : réagir vite, comprendre, corriger, remercier
- **Neutre** : c'est un signal d'alerte → creuser

### Le pouvoir du témoignage
Un client qui a écrit publiquement du bien de vous **renforce sa propre fidélité**. Effet de cohérence cognitive : il ne pourra plus partir sans se contredire.

## Bonus : automatiser sans déshumaniser

Vous ne pouvez pas tout faire à la main. Mais vous ne pouvez pas non plus tout robotiser.

### À automatiser
- Relances de paiement
- Emails de suivi post-prestation
- Newsletters
- Anniversaires (mais avec template personnalisé)

### À garder manuel
- Réponses aux questions complexes
- Discussions stratégiques
- Vœux de fin d'année (au moins le top 20)
- Réponses aux réclamations

L'automatisation **sert** la relation. Elle ne la remplace pas.

## Les outils recommandés

### Pour les TPE débutants
- **Tableur Excel/Google Sheets** : OK pour &lt; 50 clients
- **Outlook/Gmail** : avec tags et règles automatiques
- **Calendrier Google** : pour les anniversaires et relances

### Pour les TPE en croissance
- **Brevo** : email + CRM gratuits
- **HubSpot Free** : CRM puissant gratuit
- **Notion** : flexible mais demande de la rigueur

### Outils tout-en-un
- **Assokit** : facturation + clients + communication IA + relances automatiques

## Indicateurs à suivre

- **Taux de rétention** : % de clients gardés sur 1 an
- **Customer Lifetime Value (CLV)** : combien rapporte un client sur sa vie
- **NPS (Net Promoter Score)** : *« recommanderiez-vous nos services ? »*
- **Délai moyen de paiement** : indicateur de la qualité relation
- **Taux d'avis positifs** : Google, Trustpilot, etc.

## En résumé

Pour fidéliser durablement vos clients :
1. **Les connaître** vraiment (CRM)
2. **Être réactif** (4h en pro, 24h ailleurs)
3. **Sur-livrer** systématiquement
4. **Entretenir le lien** hors-vente (80/20)
5. **Recueillir les feedbacks** et y répondre

> 🌿 Avec **Assokit**, vous centralisez vos clients, automatisez vos relances et utilisez l'IA pour envoyer des messages personnalisés en quelques clics. Tout ce qu'il faut pour que vos clients ne partent jamais.",
    ],
];

// Insertion en BDD
$inserted = 0;
$skipped = 0;
$errors = [];

foreach ($articles as $art) {
    try {
        // Vérifier si le slug existe déjà
        $check = $pdo->prepare("SELECT id FROM asso_blog_articles WHERE slug = :s LIMIT 1");
        $check->execute([':s' => $art['slug']]);
        if ($check->fetchColumn()) {
            echo "⏭  Skipped (slug existant) : {$art['slug']}\n";
            $skipped++;
            continue;
        }

        $st = $pdo->prepare("
            INSERT INTO asso_blog_articles
                (slug, title, excerpt, content_md, cover_emoji, cover_color_from, cover_color_to,
                 category, tags, author, meta_title, meta_description, reading_time_min,
                 is_published, published_at, created_at, updated_at)
            VALUES
                (:slug, :title, :excerpt, :content, :emoji, :cfrom, :cto,
                 :cat, :tags, :author, :mtitle, :mdesc, :rtime,
                 1, NOW(), NOW(), NOW())
        ");
        $st->execute([
            ':slug'    => $art['slug'],
            ':title'   => $art['title'],
            ':excerpt' => $art['excerpt'],
            ':content' => $art['content'],
            ':emoji'   => $art['cover_emoji'],
            ':cfrom'   => $art['cover_color_from'],
            ':cto'     => $art['cover_color_to'],
            ':cat'     => $art['category'],
            ':tags'    => $art['tags'],
            ':author'  => 'L\'équipe Assokit',
            ':mtitle'  => mb_substr($art['title'], 0, 250),
            ':mdesc'   => mb_substr($art['excerpt'], 0, 315),
            ':rtime'   => $art['reading_time'],
        ]);
        echo "✅ Inséré : {$art['slug']}\n";
        $inserted++;
    } catch (Throwable $e) {
        $errors[] = $art['slug'] . ' : ' . $e->getMessage();
        echo "❌ Erreur sur {$art['slug']} : " . $e->getMessage() . "\n";
    }
}

echo "\n=== RÉSUMÉ ===\n";
echo "Articles insérés : {$inserted}\n";
echo "Articles déjà existants (skipped) : {$skipped}\n";
echo "Erreurs : " . count($errors) . "\n";
if (!empty($errors)) {
    echo "\nDétail erreurs :\n";
    foreach ($errors as $e) echo " - {$e}\n";
}
echo "\n🌿 Terminé. Pensez à supprimer ce fichier après exécution.\n";
