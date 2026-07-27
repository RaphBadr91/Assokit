# 🧾 Facturation électronique (Factur-X / DGFiP) — Assokit

## Ce qui a été mis en place

Les factures Assokit sont désormais générées au format **Factur-X** : un PDF/A-3
classique (lisible normalement) qui **embarque un fichier XML** `factur-x.xml`
conforme à la norme **EN 16931 / CII**, profil **MINIMUM** — le profil d'entrée
**accepté par l'administration fiscale française** (DGFiP).

- `facturx-helpers.php` : génère le XML (identités, SIREN, totaux HT/TVA/TTC) + les métadonnées XMP.
- `asso-invoice-helpers.php` : embarque le XML dans le PDF via mPDF (PDF/A-3), avec
  **repli automatique** vers un PDF classique si l'embarquement échoue (aucune facture ne casse).

> Un PDF Factur-X reste un PDF ouvrable partout : le XML est un simple attachement lu par les logiciels comptables et les plateformes (PDP).

## Condition pour que ce soit conforme : le SIREN

Le XML n'est produit que si **l'organisation émettrice a un SIREN renseigné**
(sinon repli PDF classique, car un XML sans identifiant légal serait non conforme).

👉 **À faire** : vérifier que chaque organisation a bien rempli, dans ses paramètres :
- **SIREN** (obligatoire), idéalement SIRET
- **Nom légal**, **adresse**, **pays**
- **N° TVA intracommunautaire** si assujettie

Idem pour l'organisation Assokit elle-même (pour ses propres factures d'abonnement).

## Vérifier sur le serveur

Après `git pull`, régénère une facture de test et ouvre le PDF :
- Il s'ouvre normalement dans un lecteur PDF.
- Dans Acrobat : panneau « Pièces jointes » → tu dois voir **`factur-x.xml`**.
- Pour valider la conformité : téléverse le PDF sur un **validateur Factur-X**
  (ex. outils en ligne FNFE-MPE / Chorus Pro) — il doit reconnaître le profil MINIMUM.

## Feuille de route (comme convenu)

1. ✅ **Factur-X MINIMUM** — fait (ce commit). Tes factures sont au bon format.
2. ⏳ **Profil BASIC / EN 16931** — ajouter le détail des lignes + ventilation TVA
   par taux (utile quand on branche une PDP). Faisable ensuite.
3. ⏳ **Brancher une PDP** (Plateforme de Dématérialisation Partenaire immatriculée)
   via son API pour la transmission + e-reporting.
4. 🎯 **Agrément PDP** pour Assokit (immatriculation DGFiP, audit sécurité, annuaire,
   interopérabilité) — objectif plateforme, à préparer avec un expert-comptable.

## Rappels réglementaires (calendrier — vérifier impots.gouv.fr)

| Échéance | Obligation |
|---|---|
| 1er sept. 2026 | **Recevoir** des factures électroniques : toutes les entreprises |
| 1er sept. 2026 | **Émettre** : grandes entreprises + ETI |
| 1er sept. 2027 | **Émettre** : PME, TPE, micro-entreprises |

> Ce module te met en conformité de **format**. La transmission officielle passera
> par une PDP. Fais valider l'ensemble par ton **expert-comptable** — les modalités
> précises DGFiP évoluent.

## Désactiver si besoin

Le format est activé par défaut. Pour revenir au PDF classique, définir dans `config.php` :
```php
define('AK_FACTURX_ENABLED', false);
```
