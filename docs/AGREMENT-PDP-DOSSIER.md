# 🏛️ Dossier de préparation à l'agrément PDP (immatriculation DGFiP)

> Objectif : préparer Assokit à devenir **Plateforme de Dématérialisation Partenaire
> (PDP)** immatriculée par l'administration fiscale. Ce document est une **feuille de
> route de conformité** — l'immatriculation elle-même est délivrée par la DGFiP après
> **audit externe** ; elle ne peut pas être « auto-validée ».

⚠️ **À faire valider par un expert-comptable + un cabinet de sécurité (PASSI). Les
modalités précises évoluent : source officielle = impots.gouv.fr + le portail
« immatriculation PDP ».**

---

## 1. Ce que l'État exige pour immatriculer une PDP

| Exigence | Nature | Statut Assokit |
|---|---|---|
| **Audit de sécurité** : certification **ISO/IEC 27001** couvrant le service, **OU** qualification **SecNumCloud**, **OU** rapport d'un auditeur **PASSI** (qualifié ANSSI) | Obligatoire (à fournir dans les ~12 mois après immatriculation provisoire) | ⏳ **À lancer** (prestataire externe) |
| **Hébergement des données dans l'UE** | Obligatoire | ✅ O2switch = France |
| **Conformité RGPD** (registre, DPO, durées de conservation, droits) | Obligatoire | 🟠 À formaliser |
| **Vérification d'identité** des entreprises clientes (contrôle SIREN/SIRENE) | Obligatoire | 🟠 Base présente (API recherche-entreprises intégrée) |
| **Formats** : Factur-X, UBL, CII (socle **EN 16931**) | Obligatoire | 🟡 Factur-X MINIMUM ✅ ; BASIC/EN16931 + UBL/CII à ajouter |
| **Interopérabilité** avec le PPF (annuaire) et les autres PDP | Obligatoire | ⏳ À développer (raccordement) |
| **Cycle de vie des factures** (statuts obligatoires : déposée, refusée, encaissée…) | Obligatoire | ⏳ À développer |
| **e-reporting** (transmission des données de transaction/paiement à la DGFiP) | Obligatoire | ⏳ À développer |
| **Archivage à valeur probante** (intégrité, durée légale 10 ans) | Obligatoire | 🟠 À renforcer |
| **Traçabilité / non-répudiation / disponibilité** | Obligatoire | 🟠 À documenter |

Légende : ✅ fait · 🟡 partiel · 🟠 à formaliser · ⏳ à développer

---

## 2. Le volet SÉCURITÉ (le plus structurant)

C'est le cœur de l'agrément. Trois voies possibles pour l'attestation :

1. **ISO/IEC 27001** — certification d'un organisme accrédité (COFRAC). Périmètre =
   le service de dématérialisation. Délai typique : 6–12 mois de mise en place + audit.
2. **PASSI** — mission d'audit par un **Prestataire d'Audit de Sécurité qualifié ANSSI**
   (audit d'architecture, de configuration, tests d'intrusion, revue de code). Souvent
   la voie la plus rapide pour une première immatriculation.
3. **SecNumCloud** — qualification ANSSI de l'hébergement (lourd ; pertinent si tu
   héberges toi-même l'infra critique).

**Recommandation** : partir sur un **audit PASSI** + une démarche ISO 27001 en parallèle.

### Pré-requis techniques à avoir AVANT l'audit (pour ne pas le rater)
- [ ] Chiffrement en transit **TLS 1.2+** partout (HTTPS forcé) et au repos pour les données sensibles.
- [ ] **Contrôle d'accès** robuste : séparation des rôles (déjà : fondateur / super-admin / admin org), MFA pour les accès d'administration.
- [ ] **Journalisation** des accès et opérations sensibles (audit trail immuable).
- [ ] **Gestion des secrets** hors code (variables d'environnement / coffre), rotation.
- [ ] **Sauvegardes** chiffrées, testées, avec plan de reprise (PRA/PCA).
- [ ] **Cloisonnement** prod/dev, principe du moindre privilège sur la base.
- [ ] **Gestion des vulnérabilités** : mises à jour, scans réguliers, revue de dépendances.
- [ ] **Politique de sécurité** (PSSI) écrite + procédures d'incident.

> L'audit de code interne réalisé sur cette branche (voir le rapport de revue de
> sécurité) alimente ce dossier, mais **ne remplace pas** l'audit PASSI/ISO officiel.

---

## 3. Le volet TECHNIQUE / FONCTIONNEL

### Déjà en place
- ✅ Génération **Factur-X** (PDF/A-3 + XML CII), profil MINIMUM, sur les factures
  produites par les utilisateurs **et** sur les factures d'abonnement Assokit.
- ✅ Données d'identité (SIREN/SIRET/TVA) structurées, intégration SIRENE (recherche-entreprises).

### À développer pour l'agrément
- [ ] Profils **BASIC / EN 16931** (détail des lignes + ventilation TVA par taux) et
      formats **UBL** et **CII** en émission/réception.
- [ ] **Raccordement à l'annuaire** central (PPF) : routage des factures vers la PDP
      destinataire du client.
- [ ] **Cycle de vie** : gérer et transmettre les **statuts obligatoires** de facture.
- [ ] **e-reporting** : transmission périodique des données de transaction et de paiement.
- [ ] **Réception** de factures entrantes (pas seulement l'émission).
- [ ] **Archivage probant** (horodatage, intégrité, 10 ans).
- [ ] **Interopérabilité** : tests de bout en bout avec le PPF et d'autres PDP.

---

## 4. Feuille de route réaliste

| Phase | Contenu | Qui |
|---|---|---|
| **0. Maintenant** | Factur-X MINIMUM (fait) ; renseigner SIREN/TVA d'Assokit et des orgs | ✅ |
| **1. Court terme** | Profil BASIC/EN16931 + UBL/CII ; formaliser RGPD ; durcir sécurité (checklist §2) | Dev + toi |
| **2. Choisir la voie** | Décider : rester **OD** branché à une PDP tierce, OU viser l'agrément PDP | Toi + expert-comptable |
| **3. Sécurité** | Lancer l'audit **PASSI** (et/ou ISO 27001) | Cabinet externe |
| **4. Raccordement** | Annuaire PPF, cycle de vie, e-reporting, réception, interopérabilité | Dev |
| **5. Immatriculation** | Dossier DGFiP → immatriculation **provisoire** → audit → **définitive** (3 ans) | Toi + DGFiP |

> **Conseil stratégique** : pour « aider au décollage » sans bloquer le lancement,
> beaucoup de plateformes démarrent en **s'appuyant sur une PDP tierce** (route B),
> puis obtiennent leur **propre agrément** quand le volume le justifie. Les deux ne
> sont pas exclusifs.

---

## 5. Valider le format Factur-X techniquement (à faire maintenant)

Sur une facture générée après déploiement :
1. **PDF/A-3** : valider avec **veraPDF** (open source) → doit être conforme PDF/A-3.
2. **XML Factur-X** : téléverser le PDF sur le **validateur FNFE-MPE** (ou Chorus Pro
   en sandbox) → doit reconnaître le profil **MINIMUM** sans erreur bloquante.
3. Vérifier la présence de l'attachement `factur-x.xml` (Acrobat → Pièces jointes).

Si le validateur remonte des erreurs, elles porteront quasi toujours sur des **données
manquantes côté organisation** (SIREN, adresse, TVA) → à compléter dans les paramètres.

---

## 6. Points de contact officiels

- **impots.gouv.fr** → rubrique « Facturation électronique ».
- Portail **immatriculation PDP** (DGFiP).
- **FNFE-MPE** (Forum National de la Facture Électronique) : spécifications Factur-X, validateur.
- **ANSSI** : annuaire des prestataires **PASSI** qualifiés.

---

**En résumé** : le **format** est prêt (Factur-X). L'**agrément** est un projet à part
entière dont le chemin critique est l'**audit de sécurité externe** (PASSI/ISO 27001)
et le **raccordement technique** (annuaire, cycle de vie, e-reporting). Ce document te
donne la carte complète pour y arriver.
