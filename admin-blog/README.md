# 🔧 Backend Admin Blog Assokit — README

Backend autonome pour le **fondateur** d'Assokit. Permet de :
- 📊 Visualiser le dashboard (stats, KPI, progression)
- 📰 Gérer les 73 articles existants (lister, éditer, supprimer)
- ✨ **Générer des articles via Claude IA** (sujet libre ou file de sujets)
- 💡 Maintenir une file de sujets candidats à venir
- ⏰ **Générer automatiquement 3 articles/jour** via cron O2switch
- ⚙️ Configurer la clé API Claude, le modèle, l'IP whitelist, le cron token

---

## 📦 Contenu du dossier

```
admin-blog/
├── _setup.sql              ← Tables admin (à importer 1 fois)
├── _setup.php              ← Définit le mot de passe (à supprimer après usage)
├── config.sample.php       ← Modèle de config → renommer en config.php
├── login.php / logout.php  ← Authentification
├── index.php               ← Dashboard
├── articles.php            ← Liste des articles
├── article-edit.php        ← Création/édition manuelle
├── generate.php            ← Interface génération IA
├── topics.php              ← Gestion des sujets candidats
├── settings.php            ← Paramètres (API key, cron, sécurité, password)
├── cron.php                ← Endpoint cron sécurisé
├── .htaccess               ← Protection (HTTPS, blocage config.php)
├── api/
│   ├── generate-article.php ← AJAX génération IA
│   └── delete-article.php   ← AJAX suppression
├── includes/
│   ├── .htaccess           ← Bloque accès direct
│   ├── db.php              ← Connexion PDO
│   ├── auth.php            ← Sessions, CSRF, IP whitelist
│   ├── article-helper.php  ← Slug, articles liés, bloc Assokit, CTA
│   ├── claude.php          ← Wrapper API Anthropic
│   ├── header.php / footer.php
└── assets/
    ├── admin.css           ← Style sobre type Linear
    └── admin.js            ← AJAX
```

---

## 🚀 Installation (10 minutes)

### Étape 1 — Importer les tables admin

Dans phpMyAdmin (cPanel O2switch) :
- Ouvre la base `pura7044_assokit`
- Onglet **SQL**
- Colle et exécute le contenu de **`_setup.sql`**

Cela crée 3 tables :
- `asso_blog_admin_config` (configuration : password, clé API, cron token, etc.)
- `asso_blog_topics` (file des sujets candidats)
- `asso_blog_admin_logs` (logs des générations et connexions)

### Étape 2 — Uploader le dossier

Via FTP ou Gestionnaire de fichiers cPanel O2switch :
- Upload tout le dossier **`admin-blog/`** à la racine de **`assokit.fr/`**
- Tu dois obtenir : `assokit.fr/admin-blog/index.php`

### Étape 3 — Configurer la BDD

- Renomme **`config.sample.php`** → **`config.php`**
- Édite-le et remplis :
  ```php
  define('DB_USER', 'pura7044_xxxxx');     // ton user MySQL
  define('DB_PASS', 'xxxxxxxxxxxxxxx');    // ton password MySQL
  ```

### Étape 4 — Définir le mot de passe fondateur

- Visite : **https://assokit.fr/admin-blog/_setup.php**
- Saisis ton email + un mot de passe (12+ caractères)
- Soumets le formulaire
- ⚠️ **SUPPRIME `_setup.php`** immédiatement après usage (sécurité)

### Étape 5 — Se connecter

- Va sur **https://assokit.fr/admin-blog/login.php**
- Tape ton mot de passe → tu arrives sur le dashboard 🎉

### Étape 6 — Configurer la clé API Claude

- Menu **⚙️ Paramètres**
- Colle ta clé API (commence par `sk-ant-…`)
- Choisis le modèle (recommandé : **Claude Sonnet 4.5** — excellent ratio qualité/prix pour articles SEO)
- Sauvegarde

### Étape 7 — Premier test de génération

- Menu **✨ Générer**
- Saisis un sujet test (ex: "Comment organiser une AG d'association loi 1901")
- Choisis catégorie "associations"
- Clique **Générer l'article** → attends 30-90 secondes
- L'article apparaît dans la liste, déjà publié sur `/blog/`

---

## ⏰ Activation du cron automatique (3 articles/jour)

### Étape A — Ajouter des sujets dans la file

- Menu **💡 Sujets**
- Onglet **Ajouter en masse** : colle une liste (un sujet par ligne)
- Format simple : `Comment recruter un trésorier d'association`
- Format avancé : `Titre | catégorie | mots-clés`

Le cron consommera automatiquement les sujets en attente, par priorité.

### Étape B — Activer le cron dans les paramètres

- Menu **⚙️ Paramètres**
- Coche **« Activer le cron de génération automatique »**
- Vérifie « Articles par jour » (3 par défaut)
- Note l'URL cron affichée :
  ```
  https://assokit.fr/admin-blog/cron.php?token=XXXXXXXX
  ```

### Étape C — Programmer le cron O2switch

Dans cPanel O2switch :
- **Tâches Cron** → nouvelle tâche
- Heure : tous les jours à 9h → `0 9 * * *`
- Commande :
  ```bash
  curl -s "https://assokit.fr/admin-blog/cron.php?token=TONTOKEN" > /dev/null
  ```
- Sauvegarder

✅ Chaque matin à 9h, 3 articles seront générés automatiquement.

### Étape D — Test manuel du cron

Ouvre dans ton navigateur :
```
https://assokit.fr/admin-blog/cron.php?token=TONTOKEN
```
Tu dois voir le détail de la génération s'afficher en texte brut.

---

## 🔐 Sécurité — Best practices

- ✅ Mot de passe **12+ caractères**, unique, stocké en bcrypt
- ✅ Sessions HTTPS-only, SameSite=Strict, régénérées toutes les 10 min
- ✅ CSRF token sur tous les formulaires
- ✅ IP whitelist optionnelle dans **Paramètres → Sécurité**
- ✅ Cron token séparé du password (révocable indépendamment)
- ✅ `meta robots noindex` sur toutes les pages admin
- ✅ Headers : `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`
- ✅ `.htaccess` bloque l'accès direct à `config.php` et au dossier `includes/`
- ✅ Rate limiting : max **10 articles/heure** et **50/jour** (anti-fugue facture API)

### Recommandations supplémentaires

1. **Active la 2FA O2switch** sur ton cPanel
2. **Ajoute ton IP fixe** dans la whitelist (si tu as une IP fixe)
3. **Régénère le token cron** tous les 3 mois (Paramètres → Régénérer)
4. **Surveille les logs** : Dashboard → "Activité récente"

---

## 🧠 Modèles Claude supportés

| Modèle | Qualité | Coût | Vitesse | Recommandé pour |
|---|---|---|---|---|
| `claude-opus-4-7` | ★★★★★ | $$$$ | Lent | Articles premium / piliers SEO |
| `claude-opus-4-6` | ★★★★★ | $$$$ | Lent | Idem |
| `claude-sonnet-4-6` | ★★★★ | $$ | Rapide | Production hebdo |
| **`claude-sonnet-4-5`** ⭐ | ★★★★ | $$ | Rapide | **Défaut recommandé** |
| `claude-haiku-4-5-20251001` | ★★★ | $ | Très rapide | Articles courts, FAQ |

💡 **Astuce coût** : Sonnet 4.5 produit ~700 mots de qualité pour environ **$0.03-0.05/article** soit **~$3-5/mois** pour 100 articles.

---

## 🧪 Modes de génération disponibles

### 1️⃣ Sujet libre (`/admin-blog/generate.php`)
Tu écris le sujet, la catégorie, les mots-clés, un briefing optionnel, et Claude rédige.

### 2️⃣ Depuis un sujet en file
Tu pré-charges des sujets dans `/topics`. Sur `/generate`, tu cliques "Générer" sur la ligne souhaitée.

### 3️⃣ Cron automatique
Le cron pioche les N premiers sujets pending (par priorité ASC) et les génère automatiquement à 9h chaque jour.

---

## 📝 Édition manuelle d'un article

Tous les articles (générés IA ou manuels) peuvent être édités via `/admin-blog/article-edit.php?slug=...`

- Le **bloc Assokit** + **articles liés** + **CTA final** ne sont ajoutés qu'à la création (génération IA ou nouveau manuel)
- En édition, le contenu Markdown reste tel quel — tu peux les modifier librement
- Chaque modification met à jour `reading_time_min` et `updated_at`

---

## 🐛 Dépannage

| Problème | Solution |
|---|---|
| **« Clé API Claude non configurée »** | Paramètres → coller la clé `sk-ant-…` |
| **« Format de clé API invalide »** | La clé doit commencer par `sk-ant-` |
| **« Limite horaire atteinte »** | Rate limiting (max 10/h). Attends ou ajuste `MAX_ARTICLES_PER_HOUR` dans `config.php` |
| **Cron renvoie 403** | Token manquant ou invalide. Vérifie l'URL exacte dans Paramètres |
| **Cron ne fait rien** | Cron désactivé dans Paramètres OU file de sujets vide |
| **HTTP 500 sur les pages** | Active `DEBUG_MODE` dans `config.php`, regarde l'erreur |
| **Session perdue rapidement** | Augmente `SESSION_LIFETIME` dans `config.php` (en secondes) |
| **Article généré sans bloc Assokit** | Vérifie que la section `## FAQ` existe (le bloc est inséré juste avant). Sinon il est ajouté en fin |

---

## 🛠️ Constantes ajustables (`config.php`)

```php
SESSION_LIFETIME           = 7200    // 2 heures
SESSION_REGENERATE_INTERVAL = 600    // 10 minutes
MAX_ARTICLES_PER_HOUR      = 10      // anti-fugue facture
MAX_ARTICLES_PER_DAY       = 50      // plafond strict
DEBUG_MODE                 = false   // true = affiche erreurs
```

---

## 📊 Trajectoire SEO

| Mois | Articles cumulés | Visites estimées |
|---|---|---|
| M0 (actuel) | 73 | ~500-1 000 |
| M1 | 73 + 90 = **163** | ~2 500-3 500 |
| M2 | **253** | ~5 000-7 000 |
| M3 | **343** | ~9 000-11 000 |
| M4 | **433** | **~15 000-18 000** ✅ |

À 3 articles/jour de 700-800 mots qualité, en 4 mois → objectif atteint.

---

## 🆘 Support

Toutes les actions sont loguées dans `asso_blog_admin_logs` (visible sur le dashboard). En cas de souci :

1. Active `DEBUG_MODE = true` dans `config.php`
2. Reproduis l'action
3. Note le message d'erreur
4. Désactive `DEBUG_MODE` après debug

---

**Version 1.0** · Créé pour Assokit · PHP 8+ · MySQL · Pas de framework, du PHP propre.
