<?php
/**
 * INSTALLATEUR TOUT-EN-UN — Backend Admin Blog Assokit
 * 
 * Ce fichier remplace _setup.sql + renommage config + _setup.php.
 * Il fait tout en une seule visite web, via formulaire.
 * 
 * USAGE:
 *   1. Uploader ce fichier dans /admin-blog/
 *   2. Visiter https://assokit.fr/admin-blog/install.php
 *   3. Remplir le formulaire
 *   4. Le fichier s'auto-supprime à la fin
 */

// ============================================================
// SÉCURITÉ : refuser si déjà installé
// ============================================================
$config_exists = file_exists(__DIR__ . '/config.php');
$step = $_GET['step'] ?? '1';
$errors = [];
$success_messages = [];

// ============================================================
// ÉTAPE 1 : Vérifications préalables
// ============================================================
if ($step === '1') {
    $checks = [
        'PHP 8.0+' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'Extension PDO MySQL' => extension_loaded('pdo_mysql'),
        'Extension cURL (pour API Claude)' => extension_loaded('curl'),
        'Extension JSON' => extension_loaded('json'),
        'Dossier writable' => is_writable(__DIR__),
        'config.php n\'existe pas encore' => !$config_exists,
    ];
    $all_ok = !in_array(false, $checks, true);
}

// ============================================================
// ÉTAPE 2 : Traitement du formulaire d'installation
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    
    // 1. Récupérer les données
    $db_host  = trim($_POST['db_host'] ?? 'localhost');
    $db_name  = trim($_POST['db_name'] ?? '');
    $db_user  = trim($_POST['db_user'] ?? '');
    $db_pass  = $_POST['db_pass'] ?? '';
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    $claude_key  = trim($_POST['claude_key'] ?? '');
    
    // 2. Validation
    if (!$db_name)    $errors[] = "Nom de la base de données requis.";
    if (!$db_user)    $errors[] = "Utilisateur MySQL requis.";
    if (!$db_pass)    $errors[] = "Mot de passe MySQL requis.";
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email fondateur invalide.";
    }
    if (strlen($admin_pass) < 12) {
        $errors[] = "Mot de passe fondateur : 12 caractères minimum.";
    }
    if ($admin_pass !== $admin_pass2) {
        $errors[] = "Les deux mots de passe fondateur ne correspondent pas.";
    }
    if ($claude_key && !str_starts_with($claude_key, 'sk-ant-')) {
        $errors[] = "Clé API Claude invalide (doit commencer par sk-ant-).";
    }
    
    // 3. Test connexion BDD
    $pdo = null;
    if (!$errors) {
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $errors[] = "Connexion BDD impossible : " . $e->getMessage();
        }
    }
    
    // 4. Création des tables
    if (!$errors && $pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `asso_blog_admin_config` (
                `config_key` VARCHAR(100) NOT NULL,
                `config_value` TEXT,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS `asso_blog_topics` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `topic_title` VARCHAR(255) NOT NULL,
                `category` VARCHAR(50) NOT NULL,
                `target_keywords` VARCHAR(500) DEFAULT NULL,
                `briefing_extra` TEXT DEFAULT NULL,
                `priority` TINYINT NOT NULL DEFAULT 5,
                `status` ENUM('pending','generated','skipped') NOT NULL DEFAULT 'pending',
                `generated_slug` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `generated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_status_priority` (`status`, `priority`),
                KEY `idx_category` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS `asso_blog_admin_logs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `action` VARCHAR(100) NOT NULL,
                `details` TEXT,
                `status` ENUM('success','error','warning','info') NOT NULL DEFAULT 'info',
                `ip` VARCHAR(45) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_action` (`action`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $success_messages[] = "✅ 3 tables admin créées";
        } catch (PDOException $e) {
            $errors[] = "Création tables échouée : " . $e->getMessage();
        }
    }
    
    // 5. Insertion config par défaut + password + clé API
    if (!$errors && $pdo) {
        try {
            $pwd_hash    = password_hash($admin_pass, PASSWORD_DEFAULT);
            $cron_token  = bin2hex(random_bytes(24));
            
            $configs = [
                'admin_password_hash' => $pwd_hash,
                'claude_api_key'      => $claude_key,
                'claude_model'        => 'claude-sonnet-4-5',
                'cron_token'          => $cron_token,
                'ip_whitelist'        => '',
                'articles_per_day'    => '3',
                'default_author'      => "L'équipe Assokit",
                'founder_email'       => $admin_email,
                'last_cron_run'       => '',
                'cron_enabled'        => '0',
            ];
            
            $stmt = $pdo->prepare("INSERT INTO asso_blog_admin_config (config_key, config_value) 
                                   VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            foreach ($configs as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            $success_messages[] = "✅ Compte fondateur créé pour {$admin_email}";
            if ($claude_key) {
                $success_messages[] = "✅ Clé API Claude enregistrée";
            }
        } catch (PDOException $e) {
            $errors[] = "Configuration échouée : " . $e->getMessage();
        }
    }
    
    // 6. Génération de config.php
    if (!$errors) {
        $config_content = "<?php\n";
        $config_content .= "/**\n * Configuration Assokit Admin Blog\n * Généré automatiquement le " . date('Y-m-d H:i:s') . "\n */\n\n";
        $config_content .= "// Base de données\n";
        $config_content .= "define('DB_HOST', " . var_export($db_host, true) . ");\n";
        $config_content .= "define('DB_NAME', " . var_export($db_name, true) . ");\n";
        $config_content .= "define('DB_USER', " . var_export($db_user, true) . ");\n";
        $config_content .= "define('DB_PASS', " . var_export($db_pass, true) . ");\n\n";
        $config_content .= "// Sessions\n";
        $config_content .= "define('SESSION_LIFETIME', 7200);\n";
        $config_content .= "define('SESSION_REGENERATE_INTERVAL', 600);\n\n";
        $config_content .= "// Rate limiting génération IA\n";
        $config_content .= "define('MAX_ARTICLES_PER_HOUR', 10);\n";
        $config_content .= "define('MAX_ARTICLES_PER_DAY', 50);\n\n";
        $config_content .= "// Debug\n";
        $config_content .= "define('DEBUG_MODE', false);\n";
        
        $config_path = __DIR__ . '/config.php';
        if (file_put_contents($config_path, $config_content) !== false) {
            chmod($config_path, 0600);
            $success_messages[] = "✅ Fichier config.php créé (mode 0600 lecture seule)";
        } else {
            $errors[] = "Impossible d'écrire config.php — vérifie les permissions du dossier.";
        }
    }
    
    // 7. Auto-suppression de install.php
    if (!$errors) {
        @unlink(__DIR__ . '/_setup.sql');
        @unlink(__DIR__ . '/_setup.php');
        // install.php se supprimera après affichage du résumé
        $step = '3';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation — Assokit Admin Blog</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #1a1a2e;
        }
        .container {
            max-width: 640px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        header h1 { margin: 0 0 8px; font-size: 28px; }
        header p { margin: 0; opacity: 0.9; font-size: 15px; }
        .content { padding: 32px; }
        h2 { font-size: 20px; margin: 0 0 20px; color: #2d3748; }
        .check-list { list-style: none; padding: 0; margin: 20px 0; }
        .check-list li {
            padding: 10px 14px;
            margin-bottom: 8px;
            background: #f7fafc;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .check-ok { color: #38a169; font-weight: 600; }
        .check-ko { color: #e53e3e; font-weight: 600; }
        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #2d3748;
        }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .help {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        .section {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #edf2f7;
        }
        .section:first-child { border-top: none; padding-top: 0; }
        .section h3 {
            font-size: 16px;
            margin: 0 0 4px;
            color: #4a5568;
        }
        .section .desc {
            font-size: 13px;
            color: #718096;
            margin: 0 0 12px;
        }
        button {
            width: 100%;
            padding: 14px;
            margin-top: 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s;
        }
        button:hover { transform: translateY(-1px); }
        button:active { transform: translateY(0); }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #e53e3e;
        }
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #38a169;
        }
        .alert-info {
            background: #bee3f8;
            color: #2a4365;
            border-left: 4px solid #3182ce;
        }
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-primary {
            flex: 1;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-secondary {
            flex: 1;
            padding: 14px;
            background: #edf2f7;
            color: #2d3748;
            text-decoration: none;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
        }
        code {
            background: #edf2f7;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🚀 Installation Assokit Admin</h1>
            <p>Backend de génération IA pour le blog</p>
        </header>
        <div class="content">

<?php if ($step === '1'): ?>
    <h2>Étape 1 / 2 — Vérifications</h2>
    <ul class="check-list">
        <?php foreach ($checks as $label => $ok): ?>
            <li>
                <span><?= htmlspecialchars($label) ?></span>
                <span class="<?= $ok ? 'check-ok' : 'check-ko' ?>">
                    <?= $ok ? '✓ OK' : '✗ MANQUANT' ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($config_exists): ?>
        <div class="alert alert-error">
            ⚠️ <code>config.php</code> existe déjà. Si tu veux refaire l'installation, supprime-le d'abord.
        </div>
    <?php elseif ($all_ok): ?>
        <div class="alert alert-success">
            Tout est en ordre. Tu peux passer à l'étape 2.
        </div>
        <a href="?step=2" class="btn-primary" style="display:block;">Continuer →</a>
    <?php else: ?>
        <div class="alert alert-error">
            Il manque des prérequis. Contacte O2switch si besoin (PHP 8+ requis).
        </div>
    <?php endif; ?>

<?php elseif ($step === '2'): ?>
    <h2>Étape 2 / 2 — Configuration</h2>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <strong>Erreurs :</strong>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="?step=2">
        <div class="section">
            <h3>🗄️ Base de données MySQL</h3>
            <p class="desc">Identifiants O2switch (cPanel → Bases de données MySQL)</p>

            <label for="db_host">Hôte</label>
            <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>

            <label for="db_name">Nom de la base</label>
            <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'pura7044_assokit') ?>" required>

            <label for="db_user">Utilisateur MySQL</label>
            <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" placeholder="pura7044_xxxxx" required>

            <label for="db_pass">Mot de passe MySQL</label>
            <input type="password" id="db_pass" name="db_pass" required>
            <p class="help">Tu l'as utilisé pour importer les 73 articles. Dans cPanel → Bases MySQL si oublié.</p>
        </div>

        <div class="section">
            <h3>👤 Compte fondateur</h3>
            <p class="desc">Tes identifiants personnels pour te connecter à /admin-blog</p>

            <label for="admin_email">Email fondateur</label>
            <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>

            <label for="admin_pass">Mot de passe (12+ caractères)</label>
            <input type="password" id="admin_pass" name="admin_pass" minlength="12" required>

            <label for="admin_pass2">Confirme le mot de passe</label>
            <input type="password" id="admin_pass2" name="admin_pass2" minlength="12" required>
        </div>

        <div class="section">
            <h3>🤖 Clé API Claude (optionnel)</h3>
            <p class="desc">Tu pourras l'ajouter plus tard dans Paramètres si tu préfères.</p>

            <label for="claude_key">Clé API <code>sk-ant-...</code></label>
            <input type="password" id="claude_key" name="claude_key" placeholder="sk-ant-api03-..." value="<?= htmlspecialchars($_POST['claude_key'] ?? '') ?>">
        </div>

        <button type="submit">🔥 Lancer l'installation</button>
    </form>

<?php elseif ($step === '3'): ?>
    <h2>🎉 Installation terminée !</h2>

    <?php foreach ($success_messages as $msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <div class="alert alert-info">
        <strong>⚠️ Action de sécurité :</strong> ce fichier <code>install.php</code> va s'auto-supprimer.
        Si pour une raison quelconque il reste, supprime-le manuellement via le Gestionnaire de fichiers.
    </div>

    <h3 style="margin-top: 28px;">🚀 Prochaines étapes</h3>
    <ol style="line-height: 1.8;">
        <li>Connecte-toi sur <code>/admin-blog/login.php</code></li>
        <li>Va dans <strong>💡 Sujets</strong> pour ajouter ta file de sujets</li>
        <li>Teste la génération via <strong>✨ Générer</strong></li>
        <li>Active le cron quotidien dans <strong>⚙️ Paramètres</strong></li>
    </ol>

    <div class="actions">
        <a href="login.php" class="btn-primary">→ Aller sur le login</a>
    </div>

    <?php
    // AUTO-SUPPRESSION du fichier d'installation
    register_shutdown_function(function() {
        @unlink(__FILE__);
    });
    ?>

<?php endif; ?>

        </div>
    </div>
</body>
</html>
