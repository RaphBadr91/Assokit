# LOT 0 — Voie retenue : PDP tierce (Assokit reste Opérateur de Dématérialisation)

Décision prise : **s'appuyer sur une Plateforme de Dématérialisation Partenaire (PDP) tierce** plutôt que viser l'agrément propre. Assokit reste **Opérateur de Dématérialisation (OD)** : il produit la facture au bon format et la **remet à une PDP agréée** qui assure l'émission, la réception et le e-reporting vers l'administration.

C'est la voie **rapide et sans audit lourd** (pas d'ISO 27001 / PASSI à porter soi-même).

---

## Ce qui est déjà prêt côté Assokit

- ✅ **XML Factur-X (CII, profil EN 16931)** généré par facture (`/facturx`) — le format structuré attendu par toute PDP.
- ✅ **Totaux conformes** aux règles métier EN 16931 (BR-CO-13/14/15).
- ✅ **Diagnostic de préparation** (page `/facturx`) : SIRET, adresse structure, adresses clients.
- ✅ **Numérotation séquentielle + immuabilité** des factures (piste d'audit).

---

## Étapes concrètes (à ta main)

### 1. Compléter les données (maintenant, gratuit)
Vise **100 %** au diagnostic `/facturx` :
- SIRET/SIREN de la structure (Paramètres → informations légales),
- adresse postale complète de la structure,
- code postal + ville sur chaque fiche client.

### 2. Choisir une PDP partenaire
Shortlist (PDP immatriculées / en cours, à revérifier sur la liste officielle DGFiP au moment du choix) :

| PDP | Profil | À demander |
|---|---|---|
| **Docaposte** | Grand tiers de confiance (La Poste) | API d'émission/réception, tarif au volume, support Factur-X |
| **Iopole** | Spécialiste e-invoicing, orienté partenaires/éditeurs | API partenaire, bac à sable (sandbox), onboarding éditeur |
| **Pennylane** | Compta + facturation, écosystème expert-comptable | API, si compatible avec un rôle « OD » externe |

Questions clés à poser à chaque PDP :
- API REST d'**émission** (dépôt d'un Factur-X) et de **réception** (récupération des factures fournisseurs entrantes) ?
- **Sandbox** de test + jeu de certification Factur-X ?
- Gestion du **cycle de vie** (statuts : émise, reçue, encaissée, litige) et du **e-reporting** ?
- Tarification (au document / à l'abonnement) et **modèle partenaire éditeur** (pour refacturer ou intégrer proprement) ?
- Format accepté : **Factur-X (PDF/A-3 + XML)** — confirmer qu'un dépôt XML seul ou PDF/A-3 est supporté.

### 3. Ce qu'il restera à développer une fois la PDP choisie
- **PDF/A-3** : embarquer le XML dans le PDF de facture (fichier Factur-X complet). Étape technique dédiée (librairie PDF/A-3).
- **Connecteur PDP** : brancher l'API de la PDP retenue (dépôt + récupération + statuts), avec ses **clés API** (à obtenir après contrat). Le XML étant déjà produit, ce connecteur est un module d'intégration ciblé.
- **Annuaire** : identification des destinataires (routage) — géré par la PDP.

> Dès que tu as choisi la PDP et obtenu un accès sandbox + clés API, on développe le connecteur et l'embarquement PDF/A-3. Le plus dur (le format normatif) est déjà fait.
