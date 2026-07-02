<?php
/**
 * ============================================================
 * PATCH sa-permissions.php — Niveau 2 sécurité SA
 * ============================================================
 * Ce fichier est un GUIDE de patch, pas un fichier à uploader.
 *
 * OBJECTIF : Ajouter la double authentification SA en UN SEUL
 * endroit (sa-permissions.php) plutôt que dans chaque fichier
 * /super-admin/*.
 *
 * Avantage : toutes les pages qui appellent
 * sa_require_super_admin() bénéficient automatiquement de la
 * protection, sans avoir à patcher fichier par fichier.
 * ============================================================
 */

// ============================================================
// ÉTAPE 1 — Ouvre sa-permissions.php dans cPanel
// ============================================================

/*
Dans le Gestionnaire de fichiers → public_html/ → sa-permissions.php
→ Clic droit → Modifier.
*/

// ============================================================
// ÉTAPE 2 — Trouve la fonction sa_require_super_admin()
// ============================================================

/*
Cherche (Ctrl+F) : function sa_require_super_admin

Tu devrais voir quelque chose comme :

    function sa_require_super_admin(): array
    {
        require_login();
        $user = current_user();
        if (!$user) {
            header('Location: /connexion');
            exit;
        }
        // ... checks is_super_admin / is_founder ...
        return $user;
    }
*/

// ============================================================
// ÉTAPE 3 — Insère la double auth au début de la fonction
// ============================================================

/*
Juste APRÈS l'accolade { d'ouverture de la fonction
sa_require_super_admin(), ajoute ces 5 lignes :

    // ===== NIVEAU 2 SÉCURITÉ — Double auth SA =====
    if (!function_exists('sa_auth_require')) {
        require_once __DIR__ . '/sa-auth-helpers.php';
    }
    sa_auth_require();  // ← redirige vers /super-admin-login si cookie absent
    // =============================================

Résultat final :

    function sa_require_super_admin(): array
    {
        // ===== NIVEAU 2 SÉCURITÉ — Double auth SA =====
        if (!function_exists('sa_auth_require')) {
            require_once __DIR__ . '/sa-auth-helpers.php';
        }
        sa_auth_require();
        // =============================================

        require_login();
        $user = current_user();
        if (!$user) {
            header('Location: /connexion');
            exit;
        }
        // ... le reste inchangé ...
        return $user;
    }

C'est tout. Toutes tes pages SA sont maintenant protégées.
*/

// ============================================================
// ÉTAPE 4 — Que fait sa_auth_require() exactement ?
// ============================================================

/*
1. Vérifie que l'user est connecté au site Assokit
   → Sinon redirection /connexion

2. Vérifie le cookie ak_sa_session (HMAC signé + non expiré 30 min)
   → Sinon redirection /super-admin-login

3. Vérifie LIVE en BDD que is_super_admin=1 OU is_founder=1
   → Sinon efface cookie + 403

4. Log l'accès dans sa_access_log (qui, quelle URL, quand, IP)

5. Retourne les infos user (array avec id, email, etc.)

→ La fonction fait exit() si une condition échoue, donc le code
après cette ligne ne s'exécute QUE si la double auth est OK.
*/

// ============================================================
// AFFICHAGE BANNIÈRE TIMER (optionnel mais recommandé)
// ============================================================

/*
Pour afficher la bannière violette/dorée avec le timer 30 min
sur toutes les pages SA, ouvre superadmin-layout.php.

Cherche la fonction sa_render_head() et juste après l'ouverture
du <body>, ajoute :

    <?php include __DIR__ . '/sa-session-banner.php'; ?>

La bannière se placera automatiquement en haut de chaque page
cockpit avec :
  - Le timer 30:00 qui décompte en temps réel
  - Le bouton "Déconnexion" (logout cockpit uniquement)
  - Redirection auto vers /super-admin-login à l'expiration
*/

echo "Ce fichier est un GUIDE. Ne pas l'uploader.\n";
echo "Lis les commentaires et applique les modifs dans sa-permissions.php\n";
