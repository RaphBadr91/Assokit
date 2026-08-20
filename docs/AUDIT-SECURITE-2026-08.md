# Audit de cybersécurité — Assokit (site + app mobile)

Audit mené par **6 experts** (analyse en lecture seule, cadre OWASP Top 10 + API + mobile) sur le code applicatif (hors `wp-content/` WordPress tiers, `vendor/`, `node_modules/`). `config.php` étant hors dépôt, `check_csrf()`/`current_user()`/`can()` sont audités par leur usage.

## Verdict global
Codebase **solide et disciplinée** : 0 injection SQL, isolation multi-tenant systématique, CSRF présent sur quasiment toutes les mutations, authentification robuste (bcrypt, anti-brute-force, régénération de session, 2FA), app mobile bien durcie. Les failles trouvées ont été **corrigées** ; il reste des recommandations de durcissement.

---

## ✅ Failles corrigées (commit sécurité)

| Gravité | Faille | Fichier(s) | Correctif |
|---|---|---|---|
| **ÉLEVÉ** | BOLA cross-tenant : `client_id` non validé → fuite PII clients entre orgs (factures & devis) | `asso-invoice-helpers.php`, `asso-quote-helpers.php` | Validation `WHERE id=? AND org_id=?` avant usage |
| **ÉLEVÉ** | XSS réfléchi `?view=` | `emploi-du-temps.php` | Whitelist `week/month` |
| **ÉLEVÉ** (mitigé) | Justificatifs/factures uploadés au nom devinable (accès direct Apache) | `action-facture.php` | Jeton CSPRNG dans le nom (voir « reste à faire ») |
| **MOYEN** | XSS stocké blog (HTML brut + `javascript:`) | `blog-markdown.php` | Échappement `<` après extraction code/tableaux + filtre de schéma |
| **MOYEN** | XSS réfléchi `?filter=` | `super-admin-mairies.php` | `h()` |
| **MOYEN** | CSRF manquant sur endpoint IA payant | `mon-asso-ia-generate.php` (+ `mon-asso-ia-tool.php`) | `check_csrf()` + jeton front |
| **MOYEN** | Upload SVG (XSS stocké) | `action-parametres.php`, `super-admin-parametres-societe-save.php` | SVG retiré (PNG/JPG/GIF) |
| **MOYEN** | Secret en dur sur endpoint d'écriture | `api/seed-demo-account.php` | Secret retiré ; accès web réservé fondateur |
| **FAIBLE** | `public_uuid` prédictible (`mt_rand`) | `asso-invoice-helpers.php`, `asso-quote-helpers.php` | UUID v4 via `random_bytes` |

---

## ⏳ Reste à faire (durcissement — décision/effort requis)

1. **[ÉLEVÉ – prioritaire] Accès direct aux justificatifs/factures.** Les fichiers sous `/uploads/projet_<id>/…` sont servis directement par Apache (pas de contrôle d'accès à la lecture). Le jeton CSPRNG limite l'énumération pour les **nouveaux** fichiers, mais la vraie correction est de :
   - servir ces fichiers via un **proxy PHP authentifié** (`require_login()` + vérification d'appartenance à l'org, comme `download-justificatifs.php`) ;
   - **interdire l'accès direct** au dossier (`.htaccess` : `Require all denied` sur `/uploads/projet_*`).
   *(À implémenter proprement — impacte les liens existants dans `projet.php`.)*
2. **[MOYEN] CSP en `Report-Only`** (`.htaccess`) → passer en CSP **bloquante** et retirer `unsafe-inline`/`unsafe-eval` (nécessite des nonces pour les scripts inline).
3. **[FAIBLE] Outil de dev en prod** : retirer `dev-test-plans.php` (ou le gater derrière un flag DEBUG).
4. **[FAIBLE] Sauvegardes `.htaccess`** sur le webroot (`.htaccess OLD`, `.htaccess.bak.*`, `.SECURE-bak-*`) : les supprimer.
5. **[FAIBLE] App mobile** : restreindre `originWhitelist` à `['https://assokit.fr','https://*.assokit.fr']` (à tester avec les flux Stripe/OAuth éventuels) ; envisager le stockage d'un **token de session** plutôt que du mot de passe dans SecureStore.
6. **[FAIBLE] 2 écritures « accusé de lecture » sur GET** (`api/app-channel-messages.php`, `api/app-founder-contact-thread.php`) : passer en POST.
7. **[à vérifier] `config.php::check_csrf()`** : confirmer un `hash_equals()` (constant-time) rejetant les jetons vides. `stripe-create-payment-intent.php` s'appuie sur l'en-tête `X-Requested-With` — ajouter le jeton CSRF en défense en profondeur.

---

## 🛡️ Points déjà solides (vérifiés)
- **0 injection SQL** : requêtes préparées partout, `IN()` paramétrés, colonnes dynamiques whitelistées.
- **Isolation multi-tenant** systématique (`org_id` dans les loaders/mutations), API mobile re-scopée par org.
- **Anti-élévation** : rôle relu côté serveur, jamais depuis le client ; pas d'auto-promotion.
- **CSRF** : `hash_equals` sur les mutations web et mobile ; pas de CORS permissif.
- **Auth** : bcrypt, anti-flood + verrou brute-force, `session_regenerate_id`, 2FA/TOTP.
- **En-têtes** : X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS.
- **Uploads (hors point 1)** : MIME réel `finfo`, taille, noms assainis, extensions forcées, exécution PHP bloquée dans `/uploads`.
- **SSRF / LFI / désérialisation / RCE** : aucun vecteur trouvé.
- **Mobile** : `isAssokitUrl` strict, navigation gatée, injection JS via `JSON.stringify`, biométrie fail-closed, secrets en SecureStore.
