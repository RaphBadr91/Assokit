# 📧 Sous-domaine d'envoi dédié + warm-up (prospection rentrée)

Objectif : envoyer les campagnes de prospection depuis **`send.assokit.fr`**, isolé du
domaine principal `assokit.fr`. Ainsi, si une campagne à froid prend un coup de
réputation, **tes emails clients et tes factures ne sont pas touchés**.

Ta stack : **Resend** (API d'envoi) + **O2switch** (DNS via cPanel).

---

## Étape 1 — Choisir le sous-domaine

On utilise **`send.assokit.fr`**.
- Adresse d'expédition prospection : `contact@send.assokit.fr`
- Les **réponses** reviennent sur `contact@assokit.fr` (ton inbox habituelle) grâce au `reply_to`.
- Les **liens** dans l'email (découverte, désinscription) restent sur `assokit.fr` (déjà configuré).

> Déjà câblé dans le code (`AK_PROSPECT_FROM`, `AK_PROSPECT_REPLY_TO`). Rien à coder.

---

## Étape 2 — Ajouter le domaine dans Resend

1. Va sur **resend.com → Domains → Add Domain**.
2. Saisis **`send.assokit.fr`** (region : EU si proposé, pour la France).
3. Resend génère **3 blocs d'enregistrements DNS** à copier :
   - **MX** (return-path / bounces) → ex. `feedback-smtp.eu-west-1.amazonses.com`
   - **TXT SPF** → ex. `v=spf1 include:amazonses.com ~all`
   - **TXT DKIM** (clé générée, propre à toi) → nom type `resend._domainkey`

> ⚠️ Les valeurs DKIM sont **uniques à ton compte** : copie-les EXACTEMENT depuis Resend.

---

## Étape 3 — Créer les enregistrements DNS dans O2switch

1. cPanel O2switch → **Éditeur de zone DNS** (Zone Editor) → domaine `assokit.fr`.
2. Ajoute chaque enregistrement donné par Resend. **Le nom d'hôte contient bien `send`** :

| Type | Nom (hôte) | Valeur | Priorité |
|---|---|---|---|
| **MX** | `send.assokit.fr` | *(valeur MX de Resend)* | 10 |
| **TXT** | `send.assokit.fr` | `v=spf1 include:amazonses.com ~all` | — |
| **TXT** | `resend._domainkey.send.assokit.fr` | *(longue clé DKIM de Resend)* | — |

> Si cPanel ajoute automatiquement `.assokit.fr` à la fin, ne saisis que la partie
> gauche (`send`, `send`, `resend._domainkey.send`). En cas de doute, mets le nom
> complet — cPanel dédoublonne rarement, vérifie juste qu'il n'y a pas `send.assokit.fr.assokit.fr`.

---

## Étape 4 — Ajouter le DMARC (obligatoire aujourd'hui pour Gmail/Yahoo)

Ajoute **1 enregistrement TXT** :

| Type | Nom (hôte) | Valeur |
|---|---|---|
| **TXT** | `_dmarc.send.assokit.fr` | `v=DMARC1; p=none; rua=mailto:contact@assokit.fr; adkim=r; aspf=r` |

> `p=none` = mode observation (on ne bloque rien, on collecte). Une fois que tout est
> vert et stable (≈ 2 semaines), tu pourras passer à `p=quarantine`.

**Vérifie aussi que le domaine principal a un DMARC** (`_dmarc.assokit.fr`). S'il n'existe pas, ajoute le même en remplaçant `send.` par rien.

---

## Étape 5 — Valider

1. Attends la propagation DNS (**15 min à 2 h**, parfois plus).
2. Dans Resend → Domains → `send.assokit.fr` → clique **Verify**.
3. Objectif : **3 pastilles vertes** (SPF, DKIM, MX). Tant que ce n'est pas vert, n'envoie pas.

---

## Étape 6 — (Option) Fixer les adresses dans config.php

Les valeurs par défaut conviennent. Si tu veux les rendre explicites, ajoute dans
`config.php` :

```php
define('AK_PROSPECT_FROM', 'contact@send.assokit.fr');
define('AK_PROSPECT_FROM_NAME', 'Raphael · Assokit');
define('AK_PROSPECT_REPLY_TO', 'contact@assokit.fr');
```

---

## Étape 7 — Warm-up (montée en puissance) 🔥

**Ne jamais partir à 1000/jour depuis un domaine neuf** : Gmail te classe en spam et
grille le sous-domaine. On monte progressivement. Règle le plafond dans `config.php` :

```php
define('AK_PROSPECT_DAILY_CAP', 20);   // on commence bas, on augmente chaque semaine
```

Calendrier conseillé (ajuste `AK_PROSPECT_DAILY_CAP` chaque semaine) :

| Semaine | Emails / jour | Note |
|---|---|---|
| **Semaine 1** | 20 | Surveille : taux d'ouverture, aucun blocage |
| **Semaine 2** | 40 | Si < 2 % de plaintes/désinscriptions, on continue |
| **Semaine 3** | 80 | Vérifie que tu n'atterris pas en spam (test sur Gmail/Outlook perso) |
| **Semaine 4** | 150 | |
| **Semaine 5+** | +50/semaine | Plafond réaliste solo : 200–400/jour |

> **Repères santé** à surveiller dans Resend : taux de délivrance > 95 %, taux de
> plainte < 0,1 %, taux de bounce < 3 %. Si un seuil dérape → **baisse le volume**,
> nettoie la liste, vérifie la pertinence des cibles.

---

## Étape 8 — Activer les envois + le CRON

1. Une fois le domaine vert **et** le warm-up prêt, active l'automatisation dans `config.php` :
   ```php
   define('AK_PROSPECT_SENDING_ENABLED', true);
   ```
2. Programme le CRON sur O2switch (cPanel → **Tâches planifiées / Cron Jobs**) :
   ```
   */30 * * * *   /usr/bin/php /home/TON_USER/public_html/cron-prospect-send.php >/dev/null 2>&1
   ```
   (Toutes les 30 min. Remplace `TON_USER` et le chemin par le vrai chemin O2switch.)

> Tant que `AK_PROSPECT_SENDING_ENABLED` reste `false`, le CRON tourne en **DRY-RUN**
> (il logue ce qu'il ferait, sans rien envoyer) : parfait pour tester avant d'ouvrir les vannes.

---

## Récap : l'ordre à suivre

1. Resend → Add Domain `send.assokit.fr` → copier les records.
2. O2switch → Zone DNS → coller MX + SPF + DKIM + DMARC.
3. Resend → Verify → 3 pastilles vertes.
4. `AK_PROSPECT_DAILY_CAP = 20`, warm-up semaine par semaine.
5. `AK_PROSPECT_SENDING_ENABLED = true` + CRON.
6. Piloter depuis **assokit.fr/fondateur-prospection**.

> Bonnes pratiques anti-spam déjà intégrées : lien de désinscription dans chaque email,
> jamais de recontact après désinscription/réponse, plafond quotidien, personnalisation
> réelle (catégorie + ville), reply-to sur inbox surveillée.
