# Assokit — Roadmap fonctionnalités (analyse concurrentielle 5 experts)

**Date :** 18 août 2026
**Méthode :** panel de 5 experts indépendants (marché associations FR, marché TPE/PME FR, fintech/paiement, conformité/facture électronique, produit/IA), recherche web des concurrents + expertise niche. Fusion et priorisation par impact × effort × dépendances.

> Étiquettes : 🔴 **Prérequis marché** (ne pas faire = perdre la base) · 🟢 **Game-changer** (acquisition/rétention/revenu) · 🔵 **Différenciation** (avantage défendable) · ⚡ **Quick win** (effort faible, ROI rapide).
> Support : **App** = application mobile · **Site** = web · **Les deux**.

---

## 1. Synthèse exécutive — la convergence des 5 experts

Une même conclusion revient dans **4 rapports sur 5** : Assokit sait aujourd'hui **représenter et sortir** l'argent (facturation, Factur-X, compta analytique, OCR) mais **n'encaisse pas l'argent entrant côté client**, et n'a **pas de rapprochement bancaire**. C'est le trou n°1, et c'est aussi la plus grosse opportunité.

**Les 3 convergences fortes (à retenir absolument) :**

1. **La boucle cash** — *cité par asso + TPE + fintech + produit.* Encaissement en ligne (CB + SEPA récurrent) des cotisations/dons/factures + **reçu fiscal automatique (CERFA 11580)** + relances d'impayés automatiques. C'est le prix d'entrée du marché ET le premier revenu transactionnel d'Assokit.
2. **Le rapprochement bancaire automatique** (agrégation open banking) — *cité par TPE + fintech.* Le verrou anti-churn ultime : il ferme la boucle « facture → paiement → écriture → rapprochement » avec l'existant (OCR + compta analytique).
3. **La conformité facture électronique 2026-2027** — *cité par conformité + TPE.* Raccordement à une **Plateforme Agréée (PA/ex-PDP)** + Factur-X **EN 16931** + e-reporting. **Enjeu de survie** : sans ça, plus aucun client conforme au 1er sept. 2026 (réception) puis 2027 (émission TPE).

**Le fil rouge stratégique** : Assokit ne peut pas gagner sur le prix face à HelloAsso (gratuit, 0 % commission). Il gagne sur **le tout-en-un + l'IA + l'app mobile + la compta analytique + l'encaissement rapproché**. Positionnement cible : *« le seul outil qui va chercher, encaisse ET pilote l'argent de votre asso/TPE, augmenté par l'IA — et conforme à la facture électronique. »*

---

## 2. Roadmap priorisée par lots

### 🔴 LOT 0 — Prérequis marché : conformité facture électronique *(à lancer immédiatement — le plus long)*

| # | Fonctionnalité | Support | Impact | Effort | Étiquette |
|---|----------------|---------|:---:|:---:|---|
| C1 | **Raccordement à une Plateforme Agréée** (émission + **réception** + statuts du cycle de vie) via partenaire immatriculé (Docaposte, Iopole, Pennylane, Esker… — à valider sur liste DGFiP officielle) | Les deux | 5 | L | 🔴 |
| C2 | **Factur-X MINIMUM → EN 16931** (lignes détaillées, TVA par taux) + export UBL/CII | App/back | 5 | M | 🔴 |
| C3 | **e-reporting** (transactions B2C, international, données de paiement) via la PA | App/back | 4 | M | 🔴 |
| C4 | **4 nouvelles mentions obligatoires** (SIREN client, type d'opération, option TVA débits, adresse livraison) + validateur bloquant | App/back | 3 | S | 🔴⚡ |

> Pourquoi en premier : c'est le seul chantier où **ne pas agir = perdre 100 % de la base**. Réception obligatoire pour toutes les entreprises au **1er sept. 2026**. Le PPF ne fait plus l'échange → une PA est indispensable. *(Dates à revérifier — la réforme a déjà glissé ; renvoyer aux sources DGFiP.)*

### 🟢 LOT 1 — La boucle cash *(meilleur ROI, converge sur 4 experts)*

| # | Fonctionnalité | Support | Impact | Effort | Étiquette |
|---|----------------|---------|:---:|:---:|---|
| P1 | **Encaissement en ligne** cotisations / adhésions / dons (page + QR, CB via **Stripe Connect**, option HelloAsso) | Les deux | 5 | M | 🟢 |
| P2 | **Reçu fiscal automatique CERFA 11580** (66 %/75 %, registre + déclaration annuelle 2041-SD) — réutilise l'infra Factur-X/PDF | Les deux | 5 | M | 🟢 |
| P3 | **Paiement de facture en ligne** (lien/QR « Payer » CB + virement, statut auto « payée ») | Les deux | 4 | S/M | 🟢 |
| P4 | **Relances d'impayés automatiques** multi-canal (email + SMS + push), scénarios J-3/J+7/+15/+30, avec lien de paiement | Les deux | 5 | M | 🟢 |
| P5 | **Prélèvement SEPA récurrent** + mandat électronique (cotisations & abonnements clients) via **GoCardless / Stripe SEPA** | Les deux | 4 | M | 🟢 |

> Garde-fou réglementaire **permanent** : tout passe par un **PSP partenaire agréé** (Stripe Connect, GoCardless). Les fonds **ne transitent jamais** par un compte Assokit ; KYC/LCB-FT portés par le PSP. Assokit reste **agent/distributeur**, jamais établissement de paiement.

### 🟢🔵 LOT 2 — Le verrou rétention

| # | Fonctionnalité | Support | Impact | Effort | Étiquette |
|---|----------------|---------|:---:|:---:|---|
| B1 | **Rapprochement bancaire automatique** (agrégation **Bridge/Powens** AISP agréé ACPR, catégorisation IA, lettrage auto) | Les deux | 5 | L | 🟢 |
| B2 | **Portail adhérent / espace membre self-service** (réadhésion, MAJ infos, reçu, inscription événement, carte membre) — « propulsé par Assokit » = acquisition virale | Les deux | 5 | M/L | 🟢🔵 |
| B3 | **Import & migration 1 clic** (Excel / HelloAsso / AssoConnect, mapping IA) — écrase le time-to-value | Site | 5 | M | 🟢 |

### 🔵 LOT 3 — Différenciation IA & effets réseau *(l'ADN Assokit)*

| # | Fonctionnalité | Support | Impact | Effort | Étiquette |
|---|----------------|---------|:---:|:---:|---|
| I1 | **Copilote « Pose ta question à ton asso »** — chat IA sur compta + adhérents + événements, répond + tableau + action (garde-fous anti-hallucination : chiffres = requêtes déterministes) | Les deux | 5 | L | 🔵 |
| I2 | **Relances intelligentes** (l'IA cible qui/quand/canal + rédige dans le ton) — couche au-dessus de P4 | Les deux | 4 | M | 🔵 |
| I3 | **Base de subventions à alertes IA** (agrégation appels à projets + échéances + éligibilité estimée + pré-remplissage) — **zone blanche du marché** | Les deux | 4 | M | 🔵 |
| I4 | **Crowdfunding / collecte de dons par projet** (rattaché à la compta analytique projet existante) | Les deux | 4 | M | 🟢🔵 |
| I5 | **Détection d'anomalies + alertes** (double paiement, écart de caisse, cotisation manquante) | Les deux | 4 | M | 🔵 |
| I6 | **Tableau de bord prédictif « santé »** (trésorerie projetée, réadhésions) + digest IA hebdo push | Les deux | 3-4 | M | 🔵 |

### ⚡🔵 LOT 4 — Approfondissement & crédibilité comptable

| # | Fonctionnalité | Support | Impact | Effort | Étiquette |
|---|----------------|---------|:---:|:---:|---|
| A1 | **Export FEC** (Fichier des Écritures Comptables) — standard exigé à tout contrôle fiscal | App/back | 5 | M | 🔴 |
| A2 | **Billetterie payante + contrôle d'accès QR mobile** (extension du module événements + app) | Les deux | 5 | M/L | 🟢 |
| A3 | **Signature électronique eIDAS** des devis/contrats (Yousign/Universign) + acceptation en ligne | Les deux | 3-4 | M | 🔵 |
| A4 | **Cycle comptable complet** (journal, grand livre, balance, clôture) + export expert-comptable (FEC, Sage/Cegid/Quadratus) | App/back | 4 | L | 🔵 |
| A5 | **Compta associative ANC 2018-06** (fonds dédiés, contributions en nature, CER) | App/back | 4 | M | 🔴 |
| A6 | **Notes de frais complètes + barème kilométrique (IK)** — capitalise sur l'OCR existant | App | 3 | M | ⚡ |
| A7 | **Mode micro-entreprise** (seuils, franchise TVA, livre des recettes, URSSAF) — ouvre le plus gros segment indépendant | Les deux | 4 | M | 🟢 |
| A8 | **Renouvellement auto des adhésions + relances SMS** | Les deux | 4 | S/M | ⚡ |
| A9 | **Mini-site public + formulaires embarquables** (adhésion/don/billetterie) | Site | 3-4 | M | 🔵 |
| A10 | **Archivage à valeur probante** (NF Z42-013 / coffre-fort) — renforce la piste d'audit fiable | App/back | 3 | M/L | 🔵 |
| A11 | **Notes de frais / achats fournisseurs** (au-delà du scan dépense) | App | 3 | M | 🔵 |
| A12 | **Signature élec. + acceptation devis avec acompte payable** (combine A3 + P3) | Les deux | 4 | M | 🟢 |

---

## 3. Concurrents de référence (par segment)

**Associations :** HelloAsso (gratuit, 0 % — leader collecte), AssoConnect (suite pro payante + compte pro + SEPA), Yapla, Weezevent (billetterie), Petzi, Payasso, Galette (open source), Le Compte Asso (portail État).

**TPE/PME/indépendants :** Pennylane (compta collaborative + moat expert-comptable), Indy (déclarations auto, agréé DGFiP), Tiime, Axonaut (ERP TPE), Sellsy (CRM+facturation), Abby/Freebe (micro, agréés DGFiP), Evoliz, Qonto/Shine (néobanque + facturation).

**Ce qu'Assokit a et que peu ont :** 19 outils IA « qui font », app mobile native, compta analytique par projet, hébergement France, Factur-X déjà en place, piste d'audit fiable.

---

## 4. Recommandation d'action

1. **Lancer tout de suite le LOT 0 (conformité PA)** — le plus long, non négociable, échéance 2026. Décision structurante : **partenariat avec une PA** (rapide) plutôt qu'immatriculation propre (lourde : ISO 27001, audit).
2. **Enchaîner le LOT 1 (boucle cash)** — meilleur ratio impact/effort, se démontre en 30 s en démo (« vos cotisations et factures se paient toutes seules, le reçu fiscal part tout seul »), et ouvre un **revenu transactionnel** en plus de l'abonnement.
3. **Puis LOT 2 (rapprochement + portail)** pour verrouiller la rétention.
4. Le **LOT 3 (IA)** est l'accélérateur de différenciation à saupoudrer en parallèle dès que la donnée financière circule (l'IA a besoin des flux du LOT 1/2 pour briller).

**Site vs App :** l'encaissement, le reçu fiscal, l'import, le mini-site et la compta vivent surtout côté **site** ; le portail adhérent, la billetterie/QR, les relances, le copilote et les alertes brillent sur **l'app** (avantage mobile qu'aucun concurrent généraliste n'exploite autant).

---

*Synthèse de 5 rapports d'experts (sources web citées dans chaque rapport source). Les dates réglementaires et le statut du profil Factur-X MINIMUM sont à revérifier sur les sources officielles DGFiP avant tout engagement d'ingénierie.*
