<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_require();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_general') {
            $api_key = trim((string) ($_POST['claude_api_key'] ?? ''));
            $model   = trim((string) ($_POST['claude_model'] ?? 'claude-sonnet-4-5'));
            $author  = trim((string) ($_POST['default_author'] ?? 'L\'équipe Assokit'));
            $apd     = max(1, min(10, (int) ($_POST['articles_per_day'] ?? 3)));
            $cron_on = !empty($_POST['cron_enabled']) ? '1' : '0';

            if ($api_key !== '') {
                if (!preg_match('/^sk-ant-/', $api_key)) {
                    throw new InvalidArgumentException('Format de clé API invalide (doit commencer par sk-ant-).');
                }
                config_set('claude_api_key', $api_key);
            }
            config_set('claude_model', $model);
            config_set('default_author', $author);
            config_set('articles_per_day', (string) $apd);
            config_set('cron_enabled', $cron_on);
            $success = 'Paramètres généraux sauvegardés.';

        } elseif ($action === 'rotate_cron_token') {
            $new = bin2hex(random_bytes(24));
            config_set('cron_token', $new);
            admin_log('cron_token_rotated', 'Token cron régénéré', 'success');
            $success = "Nouveau token cron généré.";

        } elseif ($action === 'save_security') {
            $whitelist = trim((string) ($_POST['ip_whitelist'] ?? ''));
            config_set('ip_whitelist', $whitelist);
            $success = 'Paramètres sécurité sauvegardés.';

        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $hash    = (string) config_get('admin_password_hash', '');
            if (!password_verify($current, $hash)) {
                throw new InvalidArgumentException('Mot de passe actuel incorrect.');
            }
            if (mb_strlen($new) < 12) {
                throw new InvalidArgumentException('Nouveau mot de passe trop court (12+ caractères).');
            }
            if ($new !== $confirm) {
                throw new InvalidArgumentException('La confirmation ne correspond pas.');
            }
            config_set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
            admin_log('password_changed', 'Mot de passe admin modifié', 'success');
            $success = 'Mot de passe modifié.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$current_api_key = (string) config_get('claude_api_key', '');
$masked_api_key = $current_api_key ? substr($current_api_key, 0, 12) . str_repeat('•', 20) : '';
$cron_token = (string) config_get('cron_token', '');
$cron_url = SITE_URL . '/admin-blog/cron.php?token=' . $cron_token;

$page_title = 'Paramètres';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>⚙️ Paramètres</h1>
</div>

<?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Paramètres généraux -->
<section class="card">
    <h2>🌐 Configuration générale</h2>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_general">

        <label class="form-label">
            Clé API Claude (Anthropic)
            <?php if ($masked_api_key): ?>
                <div class="dim small">Actuelle : <code><?= htmlspecialchars($masked_api_key) ?></code></div>
            <?php endif; ?>
            <input type="password" name="claude_api_key" placeholder="<?= $masked_api_key ? 'Laisser vide pour conserver' : 'sk-ant-api03-...' ?>" autocomplete="off">
            <span class="dim small">Récupère ta clé sur <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></span>
        </label>

        <label class="form-label">
            Modèle Claude
            <select name="claude_model">
                <option value="claude-opus-4-7" <?= config_get('claude_model') === 'claude-opus-4-7' ? 'selected' : '' ?>>Claude Opus 4.7 (le plus puissant, plus cher)</option>
                <option value="claude-opus-4-6" <?= config_get('claude_model') === 'claude-opus-4-6' ? 'selected' : '' ?>>Claude Opus 4.6</option>
                <option value="claude-sonnet-4-6" <?= config_get('claude_model') === 'claude-sonnet-4-6' ? 'selected' : '' ?>>Claude Sonnet 4.6 (équilibre qualité/prix)</option>
                <option value="claude-sonnet-4-5" <?= in_array((string) config_get('claude_model'), ['claude-sonnet-4-5', '', 'claude-sonnet-4-5-20250929'], true) ? 'selected' : '' ?>>Claude Sonnet 4.5 (recommandé pour articles)</option>
                <option value="claude-haiku-4-5-20251001" <?= config_get('claude_model') === 'claude-haiku-4-5-20251001' ? 'selected' : '' ?>>Claude Haiku 4.5 (rapide, économique)</option>
            </select>
            <span class="dim small">Sonnet 4.5 = excellent rapport qualité/prix pour articles SEO. Opus = qualité max si budget OK.</span>
        </label>

        <label class="form-label">
            Auteur par défaut
            <input type="text" name="default_author" value="<?= htmlspecialchars((string) config_get('default_author', 'L\'équipe Assokit')) ?>">
        </label>

        <label class="form-label">
            Articles par jour (cron)
            <input type="number" name="articles_per_day" min="1" max="10" value="<?= (int) config_get('articles_per_day', 3) ?>">
        </label>

        <label class="form-checkbox">
            <input type="checkbox" name="cron_enabled" value="1" <?= (string) config_get('cron_enabled', '0') === '1' ? 'checked' : '' ?>>
            Activer le cron de génération automatique
        </label>

        <button type="submit" class="btn-primary">💾 Sauvegarder</button>
    </form>
</section>

<!-- Cron token -->
<section class="card">
    <h2>🔐 Endpoint cron</h2>
    <p class="dim small">URL à appeler quotidiennement pour générer les articles automatiquement.</p>
    <pre class="code-block"><?= htmlspecialchars($cron_url) ?></pre>
    <div class="card-actions">
        <button type="button" class="btn-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling?.textContent || ''); this.textContent='✓ Copié'">📋 Copier l'URL</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Régénérer le token ? L\'ancien deviendra invalide.')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="rotate_cron_token">
            <button type="submit" class="btn-ghost-sm">🔄 Régénérer le token</button>
        </form>
    </div>
    <details style="margin-top:16px">
        <summary>📖 Comment programmer le cron sur O2switch</summary>
        <ol class="dim small" style="margin-top:8px">
            <li>cPanel → <strong>Tâches Cron</strong></li>
            <li>Tâche : tous les jours à 09:00 → <code>0 9 * * *</code></li>
            <li>Commande : <code>curl -s "<?= htmlspecialchars($cron_url) ?>" &gt; /dev/null</code></li>
            <li>Sauvegarder. Le cron tournera tous les jours et générera les articles définis dans "Articles par jour".</li>
        </ol>
    </details>
</section>

<!-- Sécurité -->
<section class="card">
    <h2>🛡️ Sécurité</h2>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_security">

        <label class="form-label">
            IP whitelist <span class="dim small">(optionnel, séparées par virgule ou espace)</span>
            <textarea name="ip_whitelist" rows="2" placeholder="Ex: 90.84.xxx.xxx, 81.224.xxx.xxx (laisser vide pour pas de restriction)"><?= htmlspecialchars((string) config_get('ip_whitelist', '')) ?></textarea>
            <span class="dim small">Ton IP actuelle : <code><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'inconnue') ?></code></span>
        </label>

        <button type="submit" class="btn-primary">💾 Sauvegarder</button>
    </form>
</section>

<!-- Mot de passe -->
<section class="card">
    <h2>🔑 Mot de passe</h2>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">

        <label class="form-label">
            Mot de passe actuel
            <input type="password" name="current_password" required>
        </label>
        <label class="form-label">
            Nouveau mot de passe (12+ caractères)
            <input type="password" name="new_password" required minlength="12">
        </label>
        <label class="form-label">
            Confirmation
            <input type="password" name="confirm_password" required minlength="12">
        </label>
        <button type="submit" class="btn-primary">🔄 Changer le mot de passe</button>
    </form>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
