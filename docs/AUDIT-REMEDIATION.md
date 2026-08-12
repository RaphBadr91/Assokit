# Audit 20 experts — Remédiation & lecture DGFiP / facture électronique

**Date :** 12 août 2026
**Périmètre :** code du site (monolithe PHP) + code de l'application mobile (Expo/React Native), en vue de la demande de référencement DGFiP pour la **facture électronique** (réforme 2026-2027, EN 16931 / Factur-X, art. 289 CGI, piste d'audit fiable).
**Méthode :** 20 audits statiques indépendants (10 site / 10 app), pondérés sur : conformité facture électronique, intégrité/piste d'audit fiable, sécurité niveau PASSI, RGPD/archivage, fonctionnel end-to-end, cohérence app↔site, accessibilité.

> **Statut des correctifs** — chaque point porte un marqueur :
> - ✅ **Corrigé (code)** — appliqué et livré dans cette branche, `php -l` / `node --check` OK.
> - 🔧 **Migration à passer** — SQL fourni dans `migrations/`, à exécuter en base (schéma hors dépôt).
> - 🖥️ **À vérifier serveur** — dépend de `config.php` / `.htaccess` / conf serveur (non versionnés).
> - 🏛️ **Externe** — ne peut être clos que par un tiers (cabinet PASSI, expert-comptable, validateur Factur-X officiel).

---

## 1. Synthèse pour la demande DGFiP

**Verdict global des 20 experts :** aucune anomalie 🔴 **bloquant un parcours métier** sur le site comme sur l'app. Le câblage fonctionnel est sain (formulaires ↔ handlers, CSRF, permissions, routing). Les risques identifiés relevaient de **sécurité**, d'**intégrité de la numérotation** et de **conformité documentaire** — traités ci-dessous.

**Ce qui est désormais en place côté code pour la facture électronique :**
- Numérotation **atomique, unique, continue** (source de vérité unique + garde-fou anti-doublon).
- **Immutabilité** des factures finalisées (correction uniquement par avoir).
- Base technique de **piste d'audit fiable** (colonnes de scellement + table de journal d'audit via migration).
- Factur-X profil **MINIMUM** déjà opérationnel (PDF/A-3 + XML CII embarqué) — cf. `docs/FACTURATION-ELECTRONIQUE.md`.

**Ce qui reste à obtenir en externe** (non automatisable) : audit **PASSI** par cabinet qualifié ANSSI, validation de la **conformité Factur-X** par le validateur FNFE-MPE/officiel, et validation **comptable/juridique** du dispositif de piste d'audit fiable par l'expert-comptable. Voir §6.

---

## 2. Sécurité — corrigé (code) ✅

| # | Sujet | Fichier | Correctif |
|---|-------|---------|-----------|
| S1 | 🔴 Installateur web non authentifié (écrasement `config.php`, création compte fondateur, injection DB) | `install.php` | **Supprimé** (`git rm`). |
| S2 | 🟠 Endpoint de debug non authentifié (dump `$_SESSION`, inclut `config.php`) | `session-debug.php` | **Supprimé** (`git rm`). |
| S3 | 🔴 Crons contournables via `argv`/secret en dur | `cron-*.php` | Bypass `!empty($_SERVER['argv'])` retiré, secret démo en dur retiré, `hash_equals` + token de config. |
| S4 | 🟠 Exécution PHP possible dans `uploads/` | `uploads/.htaccess`, `uploads/asso-invoices/.htaccess` | `FilesMatch` deny PHP/phtml + `Options -Indexes`. |
| S5 | 🟠 Incarnation d'un compte fondateur/super-admin possible | `impersonation-helpers.php` | Blocage explicite des cibles `founder`/`super_admin`/`is_founder`. |
| S6 | 🟠 CSRF manquant (annulation d'abonnement) | `mon-asso-annuler-abonnement.php` | `check_csrf` + token dans le formulaire. |
| S7 | 🟠 CSRF manquant (suppression/favori génération IA) | `mon-asso-ia-action.php` + `mon-asso-ia-historique.php` | `check_csrf` + tokens dans les 2 formulaires. |
| S8 | 🟡 CSRF manquant (fin d'onboarding, AJAX) | `guide-onboarding-done.php` + `_guide-onboarding.php` | Vérif token via header `X-CSRF-Token`. |

### Application mobile ✅
| # | Sujet | Fichier | Correctif |
|---|-------|---------|-----------|
| M1 | 🔴 Auto-login biométrique **fail-open** (toute erreur = déverrouillage) | `mobile/App.js` | **Fail-closed** : `ok=false` par défaut, `catch → ok=false`. Auto-login uniquement si biométrie réussie (ou appareil sans biométrie, SecureStore restant protégé par le verrou d'appareil). |
| M2 | 🟠 Validation de domaine par sous-chaîne (`indexOf('assokit.fr')`) → `assokit.fr.attaquant.com` accepté | `mobile/App.js` | Helper `isAssokitUrl()` : test strict `hostname === 'assokit.fr' || .endsWith('.assokit.fr')`, appliqué aux 3 points (nav, ouverture externe, `onShouldStartLoadWithRequest`). |
| M3 | 🟠 `originWhitelist` autorisait `http://*` | `mobile/App.js` | `['https://*']` uniquement. |
| M4 | 🟡 Permission Android superflue | `mobile/app.json` | `READ_EXTERNAL_STORAGE` retirée (l'image-picker moderne n'en a pas besoin). |

---

## 3. Facturation — intégrité & piste d'audit fiable

### Corrigé (code) ✅
- **F1 — Numérotation unifiée (critique DGFiP).** Deux systèmes divergeaient : la voie manuelle utilisait le compteur atomique `org_invoice_settings` (`FOR UPDATE`) ; la **récurrence** faisait `MAX(invoice_sequence)+1` **sans incrémenter le compteur** → risque de **doublons** et de **trous** de séquence (interdit en facture électronique). Désormais **une seule source de vérité** : `ak_asso_invoice_next_number_parts()` (atomique, réentrante, **auto-réparante** : `max(next_sequence, MAX(seq)+1)`). La récurrence y est routée. Fichiers : `asso-invoice-helpers.php`, `asso-recurrence-helpers.php`.
- **F2 — Immutabilité des factures émises.** Une facture non-brouillon ne peut plus être modifiée :
  - `mon-asso-facture-save.php` : édition refusée si `status != 'draft'` (message orientant vers l'avoir).
  - `super-admin-facture-edit-save.php` : contenu financier (montants, description, TVA, période, échéance) **gelé** si finalisée ; seul le cycle de vie (statut, règlement, notes internes) reste modifiable.
- **F3 — Factur-X `AFRelationship`.** Corrigé `'Data'` → `'Alternative'` (conformité PDF/A-3) dans `asso-invoice-helpers.php` et `facture-abonnement-pdf.php`.
- **F4 — TypeError génération immédiate.** `ak_recurrence_generate_invoice($pdo, $rec)` (passait `$rec_id`) — `mon-asso-recurrence-run-now.php`.

### Migration à passer 🔧 — `migrations/2026-08-12-facture-electronique-integrite.sql`
- **UNIQUE** `(org_id, invoice_year, invoice_sequence)` et `(org_id, invoice_number)` — anti-doublon définitif (ceinture + bretelles avec F1).
- Colonnes **avoir** : `invoice_type` (`invoice`/`credit_note`) + `parent_invoice_id`.
- Colonnes de **scellement** : `finalized_at`, `content_hash` (empreinte SHA-256 du contenu figé).
- Table **`asso_invoice_audit`** (append-only) : journal inaltérable des événements (création, finalisation, changement de statut, avoir, envoi, paiement) avec acteur, empreinte, IP.
- ⚠️ **Pré-check doublons obligatoire** avant d'ajouter les UNIQUE (requêtes fournies en tête du fichier).

### À implémenter ensuite (code, sur base migrée) 🔧
- Générer et **envoyer** un avoir depuis l'UI (aujourd'hui pas de handler « avoir » — écart fonctionnel, non-bug) : réutiliser `invoice_type='credit_note'` + `parent_invoice_id`, montants négatifs, même numérotation atomique.
- Écrire dans `asso_invoice_audit` à chaque événement (création/finalisation/statut/avoir/paiement) et calculer `content_hash` à la finalisation.
- Passer le profil Factur-X **MINIMUM → BASIC** (EN 16931 complet) — cf. roadmap `docs/FACTURATION-ELECTRONIQUE.md`.

### Mentions légales PDF — à vérifier 🖥️
Contrôler que le gabarit PDF facture porte **toutes** les mentions obligatoires (SIREN/SIRET émetteur, TVA intracom ou mention de franchise « TVA non applicable, art. 293 B du CGI » si association non assujettie, conditions de paiement, pénalités de retard, indemnité forfaitaire 40 €). À valider avec l'expert-comptable (§6).

---

## 4. Fonctionnel & cohérence (frictions, non bloquant)

**Site (espace association)** — aucun 🔴. À traiter (dette) :
- Ré-ajout d'un adhérent **soft-deleted** → viole l'unique email → message d'erreur cryptique. Proposer la restauration via corbeille (`nouveau-adherent.php`).
- `strftime()` **déprécié** (PHP 8.4, supprimé en 9.0) + dates en **anglais** dans les emails de convocation AG / événement — migrer vers `IntlDateFormatter` locale `fr_FR` (`action-assemblee.php`, `communication-evenement.php`).
- Requêtes `FROM adherents` (5 fichiers) vs table `users` ailleurs — **à vérifier** : la vue/table `adherents` existe-t-elle en prod ? (requêtes en try/catch, non bloquant).

**App mobile** — aucun 🔴. À traiter :
- Chat : message **perdu en silence** si l'envoi échoue (canal `announce` en lecture seule, réseau) — afficher une alerte, ne vider le champ que sur succès. Masquer le composer sur canal lecture seule.
- Scan facture : MIME/extension figés en JPEG → images **galerie PNG/HEIC** mal étiquetées → l'API vision peut rejeter. Lire `mimeType`/`fileName` réels.
- Raccourcis TPE « Devis »/« Recettes » basculent en WebView au lieu des écrans natifs existants (`NativeQuotes`/`NativeStats`).
- Menu « Plus » non filtré par profil (un TPE voit Adhérents/Cotisations/Assemblées).
- Auto-login à froid avec mot de passe périmé → écran de login muet (pas de message « identifiants expirés »).

> Ces points sont des **améliorations UX / dette**, sans impact sur le référencement DGFiP. Je peux les traiter dans un lot dédié sur demande.

---

## 5. Sécurité — à vérifier côté serveur 🖥️

Ces points dépendent de `config.php` / `.htaccess` / conf serveur, **absents du dépôt** (gitignorés) — à confirmer par l'hébergeur/admin :
- **Cookies de session** : `HttpOnly`, `Secure`, `SameSite=Lax/Strict` sur le cookie de session PHP.
- **En-têtes de sécurité** : `Content-Security-Policy` en **enforce** (et non Report-Only), `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy`.
- **HTTPS** forcé (HSTS + redirection 301 http→https) sur tout le domaine.
- **Comptes/secrets** : rotation des tokens CRON (cf. `docs/`), clés Stripe/Claude hors dépôt, mots de passe DB robustes.
- **Archivage légal** des factures : conservation **10 ans**, stockage inaltérable/horodaté (WORM ou équivalent), sauvegardes testées.
- **RGPD** : registre des traitements, DPA hébergeur/Stripe, procédure d'export/suppression (déjà présente côté code), durées de conservation.

---

## 6. Étapes externes — non automatisables 🏛️

Le référencement DGFiP et la conformité facture électronique exigent des validations par des tiers que le code seul ne peut pas fournir :

1. **Audit PASSI** par un cabinet qualifié ANSSI (pentest applicatif + revue d'architecture). Les correctifs §2 réduisent la surface, mais l'attestation est délivrée par le cabinet. Cf. `docs/AGREMENT-PDP-DOSSIER.md`.
2. **Validation Factur-X officielle** : passer les PDF générés au **validateur FNFE-MPE** et à **veraPDF** (PDF/A-3), obtenir un rapport de conformité EN 16931 sans erreur.
3. **Validation comptable/juridique** par l'**expert-comptable** : dispositif de piste d'audit fiable (documentation du chemin devis→facture→paiement→avoir), mentions légales PDF, régime TVA de l'association.
4. **Interopérabilité PDP/Chorus Pro** : tests d'émission/réception selon le rôle visé (PDP vs simple émetteur via PDP tierce). Décision de positionnement à acter (cf. `docs/AGREMENT-PDP-DOSSIER.md`).
5. **Publication de l'app** : les durcissements mobiles (§2) doivent être **rebuild + resoumis** (EAS build) pour être effectifs en production ; la suppression de `READ_EXTERNAL_STORAGE` sera visible dans la fiche store.

---

## 7. À vérifier / points ouverts (base non versionnée) 🖥️🔧

- **Abonnements** : confirmer qu'une org n'a jamais **deux abonnements actifs** simultanés. Une contrainte `UNIQUE(org_id)` casserait l'historique (annulés + actif) — à traiter par **logique applicative** ou colonne générée filtrée, pas par un UNIQUE brut. (Non inclus dans la migration pour cette raison.)
- **Doublons de numérotation existants** : lancer le pré-check du fichier de migration ; si des doublons legacy existent, les résoudre avant d'ajouter les contraintes UNIQUE.
- **CSP** : aucune directive CSP trouvée en PHP — vérifier si elle est posée en `.htaccess` (serveur) et la passer en enforce.

---

## 8. Journal des commits de cette campagne

- `c195962` — Sécurité batch 1 : crons, uploads, incarnation, Factur-X, CSRF annulation.
- `3484453` — Sécurité batch 2 : suppression `install.php`/`session-debug.php`, durcissement app mobile, CSRF IA/onboarding.
- `16063e8` — Facturation : numérotation unifiée + immutabilité des factures.
- *(ce document + migration)* — Remédiation consolidée & lecture DGFiP.

---

*Document de synthèse interne. Les attestations officielles (PASSI, Factur-X, expert-comptable) restent à obtenir auprès des tiers concernés — voir §6.*
