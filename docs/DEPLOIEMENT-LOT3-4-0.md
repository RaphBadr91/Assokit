# Déploiement — LOT 3, LOT 4 & LOT 0 (session icônes + différenciation + conformité)

Guide unique pour mettre en production tout ce qui a été livré. Branche : `claude/new-session-36m7pw`.

---

## 1. Récupérer tout le code (une seule commande)

Depuis le serveur (O2switch), dans `~/public_html` :

```bash
cd ~/public_html \
  && git fetch origin claude/new-session-36m7pw \
  && git checkout origin/claude/new-session-36m7pw -- .
```

> `checkout … -- .` met à jour **tous les fichiers suivis** à la version de la branche, sans toucher aux fichiers ignorés (dont `config.php`) ni aux fichiers non suivis. Puis attendre ~1 min (OPcache) ou faire un hard-refresh navigateur (`Cmd/Ctrl+Shift+R`).

---

## 2. Passer les migrations SQL

Lanceur en ligne de commande (lit les identifiants dans `config.php`) :

```bash
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-18-subventions-catalogue.sql
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-19-grant-alerts.sql
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-19-relances.sql
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-19-anomalies.sql
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-19-forecast.sql
/usr/local/bin/php ~/public_html/migrations/run.php 2026-08-20-notes-de-frais.sql
```

Chaque commande doit finir par `Terminé : N OK, 0 erreur(s)`. Toutes sont idempotentes (`IF NOT EXISTS`) : ré-exécutables sans risque.

> **FEC, Comptabilité et Factur-X ne nécessitent AUCUNE migration** : ils sont dérivés en lecture seule des données existantes (factures, cotisations, achats).

---

## 3. Amorcer le catalogue de subventions (une fois)

Connecté en **fondateur**, ouvrir dans le navigateur :

```
https://assokit.fr/api/seed-grants-catalog.php
```

Doit afficher `✅ Catalogue amorcé : 20 dispositif(s)`.

---

## 4. Installer les crons (cPanel → Tâches Cron)

Binaire CLI = `/usr/local/bin/php` (le `/usr/bin/php` est le binaire CGI, à éviter).

```
0 7  * * *   /usr/local/bin/php /home/pura7044/public_html/cron-grant-radar.php  >> /home/pura7044/logs/cron-grant-radar.log 2>&1
17 7 * * *   /usr/local/bin/php /home/pura7044/public_html/cron-relances.php     >> /home/pura7044/logs/cron-relances.log 2>&1
```

> `cron-relances.php` n'envoie rien tant que l'auto-relance n'est pas activée dans `/relances` (opt-in par asso).

---

## 5. Ce qui est livré

### Phase 1 — Identité visuelle (icônes maison)
- Bibliothèque `ak-icons.php` (~80 icônes SVG « ligne ») chargée globalement.
- Emoji remplacés par les icônes maison sur ~30 pages de l'app authentifiée + le chrome natif mobile (Ionicons).
- Menu latéral regroupé : pôle **Finances** en menu déroulant unique.

### LOT 3 — Différenciation IA
- **Copilote IA**, **Radar de subventions** (+ alertes cron), **Relances intelligentes** (+ auto-cron), **Détection d'anomalies**, **Dashboard prédictif**.

### LOT 4 — Crédibilité comptable & dépenses
- **Export FEC** (`/export-fec`) — Fichier des Écritures Comptables conforme (art. A47 A-1 LPF), écritures reconstituées et équilibrées, contrôle d'intégrité (blocage si données incomplètes).
- **Comptabilité** (`/comptabilite`) — Balance, Grand livre, Journaux.
- **Notes de frais** (`/notes-de-frais`) — justificatifs + indemnités kilométriques (barème administratif), workflow brouillon → soumise → approuvée → remboursée.

### LOT 0 — Conformité facture électronique 2026
- **Factur-X** (`/facturx`) — génération du **XML CII (profil EN 16931)** par facture, le cœur technique de la réforme, indépendant du choix de PDP. Diagnostic de complétude des données par facture.

---

## 6. Reste à décider / intégrer (hors périmètre autonome)

Ces éléments nécessitent une **décision** ou une **intégration tierce payante** :

- **LOT 0 — finalisation** : (a) choix de la voie PDP — s'appuyer sur une **PDP tierce** (rapide) vs viser l'**agrément propre** (audit PASSI/ISO 27001, long) ; (b) intégration **PDF/A-3** (embarquer le XML dans le PDF) ; (c) raccordement à l'annuaire/PDP.
- **LOT 1 — boucle cash** : encaissement en ligne (Stripe/GoCardless), reçu fiscal CERFA 11580. Nécessite un PSP.
- **LOT 4 restants** : billetterie QR (A2), signature eIDAS (A3/A12 — Yousign/Universign), relances SMS (A8 — Twilio), mini-site public (A9).

Ces briques sont documentées dans `docs/ROADMAP-FONCTIONNALITES.md`, `docs/FACTURATION-ELECTRONIQUE.md` et `docs/AGREMENT-PDP-DOSSIER.md`.
