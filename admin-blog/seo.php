<?php
/**
 * seo.php — Dashboard SEO v2
 * Approche moderne : IndexNow pour Bing/Yandex + GSC pour Google
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/article-helper.php';
require_once __DIR__ . '/includes/seo-notifier.php';

send_security_headers();
auth_start_session();
auth_require();

$flash = '';
$flash_type = 'info';
$last_result = null;

if (($_POST['action'] ?? '') === 'rotate_key') {
    csrf_require();
    config_set('indexnow_key', bin2hex(random_bytes(16)));
    $flash = '🔑 Nouvelle clé IndexNow générée. Bing devra revérifier le fichier de validation.';
    $flash_type = 'success';
    admin_log('seo_key_rotated', 'IndexNow key regenerated', 'info');
}

if (($_POST['action'] ?? '') === 'ping_sitemap') {
    csrf_require();
    $last_result = notify_seo_sitemap_updated();
    $flash = '📡 Notification IndexNow envoyée. Voir détails ci-dessous.';
    $flash_type = $last_result['indexnow']['ok'] ? 'success' : 'warning';
}

if (($_POST['action'] ?? '') === 'notify_url') {
    csrf_require();
    $url = trim($_POST['custom_url'] ?? '');
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $last_result = notify_seo_new_article([$url]);
        $flash = '⚡ URL notifiée à IndexNow. Détails ci-dessous.';
        $flash_type = $last_result['indexnow']['ok'] ? 'success' : 'warning';
    } else {
        $flash = '❌ URL invalide.';
        $flash_type = 'error';
    }
}

if (($_POST['action'] ?? '') === 'notify_recent') {
    csrf_require();
    $recent = DB::fetchAll(
        "SELECT slug FROM asso_blog_articles WHERE is_published = 1 ORDER BY published_at DESC LIMIT 10"
    );
    $urls = array_map(fn($r) => SITE_URL . '/blog/' . $r['slug'], $recent);
    if ($urls) {
        $last_result = notify_seo_new_article($urls);
        $flash = '⚡ ' . count($urls) . ' derniers articles notifiés. Détails ci-dessous.';
        $flash_type = $last_result['indexnow']['ok'] ? 'success' : 'warning';
    } else {
        $flash = '⚠️ Aucun article publié.';
        $flash_type = 'warning';
    }
}

if (($_POST['action'] ?? '') === 'notify_all') {
    csrf_require();
    $all = DB::fetchAll(
        "SELECT slug FROM asso_blog_articles WHERE is_published = 1 ORDER BY published_at DESC LIMIT 100"
    );
    $urls = array_map(fn($r) => SITE_URL . '/blog/' . $r['slug'], $all);
    if ($urls) {
        $last_result = notify_seo_new_article($urls);
        $flash = '🔥 ' . count($urls) . ' articles notifiés en masse à IndexNow.';
        $flash_type = $last_result['indexnow']['ok'] ? 'success' : 'warning';
    }
}

// Données
$indexnow_key = seo_get_indexnow_key();
$indexnow_validation_url = SITE_URL . '/' . $indexnow_key . '.txt';
$last_cron = config_get('last_cron_run', '');
$recent_logs = seo_recent_notifications(15);

$nb_articles = (int) (DB::fetch("SELECT COUNT(*) AS n FROM asso_blog_articles WHERE is_published = 1")['n'] ?? 0);
$nb_notifs_30d = (int) (DB::fetch(
    "SELECT COUNT(*) AS n FROM asso_blog_admin_logs 
     WHERE action = 'seo_notify' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
)['n'] ?? 0);
$nb_success_30d = (int) (DB::fetch(
    "SELECT COUNT(*) AS n FROM asso_blog_admin_logs 
     WHERE action = 'seo_notify' AND status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
)['n'] ?? 0);

$gsc_url = 'https://search.google.com/search-console?resource_id=' . urlencode(SITE_URL);
$bing_url = 'https://www.bing.com/webmasters/sites/' . urlencode(parse_url(SITE_URL, PHP_URL_HOST));

$page_title = 'SEO · IndexNow & Search Console';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div>
            <h1>🔍 SEO &amp; Indexation</h1>
            <p class="dim">Notification IndexNow (Bing/Yandex) + monitoring Google Search Console.</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="seo-stats">
        <div class="seo-stat-card">
            <div class="seo-stat-label">Articles publiés</div>
            <div class="seo-stat-value"><?= $nb_articles ?></div>
        </div>
        <div class="seo-stat-card">
            <div class="seo-stat-label">Notifications (30j)</div>
            <div class="seo-stat-value"><?= $nb_notifs_30d ?></div>
            <div class="seo-stat-sub"><?= $nb_success_30d ?> réussies</div>
        </div>
        <div class="seo-stat-card">
            <div class="seo-stat-label">Dernier cron</div>
            <div class="seo-stat-value-sm"><?= $last_cron ? htmlspecialchars($last_cron) : '—' ?></div>
        </div>
    </div>

    <!-- Stratégie 2026 -->
    <div class="card" style="border-left: 4px solid #667eea;">
        <h2 style="margin-top:0;">📚 Comment fonctionne l'indexation aujourd'hui</h2>
        <div class="seo-strategy">
            <div class="strategy-block">
                <div class="strategy-icon">⚡</div>
                <div>
                    <strong>Bing &amp; Yandex (rapide)</strong>
                    <p>Notification automatique via <code>IndexNow</code> à chaque nouvel article. Indexation typique : <strong>2-6 heures</strong>.</p>
                </div>
            </div>
            <div class="strategy-block">
                <div class="strategy-icon">🐌</div>
                <div>
                    <strong>Google (plus lent)</strong>
                    <p>Google a déprécié son endpoint <code>/ping</code> en 2024. Ton sitemap déclaré dans Search Console est revisité automatiquement. Indexation typique : <strong>3-21 jours</strong>.</p>
                </div>
            </div>
            <div class="strategy-block">
                <div class="strategy-icon">🚀</div>
                <div>
                    <strong>Pour accélérer Google</strong>
                    <p>Utilise <a href="<?= htmlspecialchars($gsc_url) ?>" target="_blank">Search Console → Inspection d'URL → Demander une indexation</a> (10 demandes/jour max).</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Configuration IndexNow -->
    <div class="card">
        <h2>🔑 IndexNow (Bing, Yandex, Naver)</h2>
        <p class="dim">Protocole moderne accepté par Bing, Yandex, Seznam et Naver.</p>
        
        <div class="seo-info-grid">
            <div>
                <div class="form-label">Clé IndexNow</div>
                <code class="seo-key"><?= htmlspecialchars($indexnow_key) ?></code>
            </div>
            <div>
                <div class="form-label">URL de validation (test)</div>
                <a href="<?= htmlspecialchars($indexnow_validation_url) ?>" target="_blank" class="seo-link">
                    <?= htmlspecialchars($indexnow_validation_url) ?> ↗
                </a>
                <p class="dim" style="font-size:12px;margin-top:4px;">Doit retourner ta clé en texte brut. Si "Not Found", la règle <code>.htaccess</code> n'est pas active.</p>
            </div>
        </div>

        <form method="POST" style="margin-top:16px;" onsubmit="return confirm('Régénérer la clé IndexNow ?');">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="rotate_key">
            <button type="submit" class="btn-ghost-sm">🔄 Régénérer la clé</button>
        </form>
    </div>

    <!-- Tests / actions -->
    <div class="card">
        <h2>⚡ Notifications manuelles</h2>
        <p class="dim">Force la notification immédiate à IndexNow sans attendre le cron.</p>

        <div class="seo-actions-grid">
            <form method="POST" class="seo-action">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="notify_recent">
                <h3>⚡ Notifier les 10 derniers articles</h3>
                <p class="dim">Envoie tes 10 articles les plus récents à IndexNow.</p>
                <button type="submit" class="btn-primary">Notifier maintenant</button>
            </form>

            <form method="POST" class="seo-action" onsubmit="return confirm('Notifier TOUS les articles publiés (max 100) ? À utiliser pour le premier rattrapage.');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="notify_all">
                <h3>🔥 Rattrapage massif (100 max)</h3>
                <p class="dim">Notifie en lot tous les articles déjà publiés. À faire 1 fois après l'installation.</p>
                <button type="submit" class="btn-primary">Notifier en masse</button>
            </form>
        </div>

        <form method="POST" style="margin-top:20px;">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="notify_url">
            <label class="form-label">
                URL spécifique à notifier
                <input type="url" name="custom_url" placeholder="https://assokit.fr/blog/mon-article" required>
            </label>
            <button type="submit" class="btn-ghost-sm">Notifier cette URL</button>
        </form>
    </div>

    <!-- Liens externes -->
    <div class="card">
        <h2>🌐 Tableaux de bord externes</h2>
        <div class="seo-external-grid">
            <a href="<?= htmlspecialchars($gsc_url) ?>" target="_blank" class="seo-external">
                <div class="seo-ext-icon">🔵</div>
                <div>
                    <strong>Google Search Console ↗</strong>
                    <p class="dim">Vérifie l'indexation Google, demande l'indexation manuellement, surveille les erreurs.</p>
                </div>
            </a>
            <a href="<?= htmlspecialchars($bing_url) ?>" target="_blank" class="seo-external">
                <div class="seo-ext-icon">🟢</div>
                <div>
                    <strong>Bing Webmaster Tools ↗</strong>
                    <p class="dim">Voir les soumissions IndexNow en temps réel, statistiques Bing.</p>
                </div>
            </a>
            <a href="<?= htmlspecialchars(SITE_URL) ?>/sitemap.xml" target="_blank" class="seo-external">
                <div class="seo-ext-icon">🗺️</div>
                <div>
                    <strong>Ton sitemap ↗</strong>
                    <p class="dim">Vérifie que tous les articles y figurent avec un <code>&lt;lastmod&gt;</code> à jour.</p>
                </div>
            </a>
            <a href="<?= htmlspecialchars(SITE_URL) ?>/robots.txt" target="_blank" class="seo-external">
                <div class="seo-ext-icon">🤖</div>
                <div>
                    <strong>Ton robots.txt ↗</strong>
                    <p class="dim">Doit autoriser le crawl du blog. Doit pointer vers le sitemap.</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Résultat -->
    <?php if ($last_result): ?>
        <div class="card">
            <h2>📋 Résultat de la dernière notification</h2>
            <?php if (isset($last_result['indexnow'])): ?>
                <div class="seo-result-summary">
                    <div class="result-line">
                        <strong>IndexNow :</strong>
                        <span class="status-pill status-<?= $last_result['indexnow']['ok'] ? 'success' : 'error' ?>">
                            <?= htmlspecialchars($last_result['indexnow']['message']) ?>
                        </span>
                    </div>
                    <div class="result-line">
                        <strong>URLs notifiées :</strong> <?= (int)($last_result['indexnow']['count'] ?? 0) ?>
                    </div>
                </div>
            <?php endif; ?>
            <details>
                <summary class="dim" style="cursor:pointer;margin-top:12px;">Voir le JSON brut</summary>
                <pre class="seo-result"><?= htmlspecialchars(json_encode($last_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
        </div>
    <?php endif; ?>

    <!-- Historique -->
    <div class="card">
        <h2>📜 Historique récent</h2>
        <?php if (empty($recent_logs)): ?>
            <p class="dim">Aucune notification encore. Lance un test ci-dessus.</p>
        <?php else: ?>
            <table class="seo-history">
                <thead>
                    <tr><th>Date</th><th>Action</th><th>Détails</th><th>Statut</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['created_at']) ?></td>
                            <td><code><?= htmlspecialchars($log['action']) ?></code></td>
                            <td class="seo-history-details"><?= htmlspecialchars(mb_substr($log['details'], 0, 200)) ?></td>
                            <td><span class="status-pill status-<?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars($log['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.seo-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
.seo-stat-card { background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; }
.seo-stat-label { font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; font-weight: 600; }
.seo-stat-value { font-size: 32px; font-weight: 700; color: #1a1a2e; }
.seo-stat-value-sm { font-size: 14px; color: #1a1a2e; font-family: monospace; }
.seo-stat-sub { font-size: 12px; color: #38a169; margin-top: 4px; }

.seo-strategy { display: grid; gap: 16px; }
.strategy-block { display: flex; gap: 16px; padding: 14px; background: #f7fafc; border-radius: 10px; align-items: flex-start; }
.strategy-icon { font-size: 24px; flex-shrink: 0; }
.strategy-block strong { display: block; margin-bottom: 4px; color: #2d3748; }
.strategy-block p { margin: 0; font-size: 13px; color: #4a5568; line-height: 1.6; }

.seo-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 12px; }
.seo-key { display: inline-block; padding: 8px 12px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-family: monospace; font-size: 13px; color: #2d3748; word-break: break-all; }
.seo-link { color: #667eea; text-decoration: none; word-break: break-all; font-size: 13px; }
.seo-link:hover { text-decoration: underline; }

.seo-actions-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-top: 16px; }
.seo-action { padding: 16px; background: linear-gradient(135deg, rgba(102,126,234,0.05), rgba(118,75,162,0.05)); border-radius: 10px; border: 1px solid rgba(102,126,234,0.15); }
.seo-action h3 { margin: 0 0 6px; font-size: 14px; color: #2d3748; }
.seo-action p { margin: 0 0 12px; font-size: 12px; }

.seo-external-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.seo-external { display: flex; gap: 14px; padding: 14px; background: white; border: 1.5px solid #e2e8f0; border-radius: 10px; text-decoration: none; color: inherit; transition: all 0.15s; align-items: flex-start; }
.seo-external:hover { border-color: #667eea; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102,126,234,0.1); }
.seo-ext-icon { font-size: 24px; flex-shrink: 0; }
.seo-external strong { display: block; color: #2d3748; margin-bottom: 4px; font-size: 14px; }
.seo-external p { margin: 0; font-size: 12px; line-height: 1.5; }

.seo-result { background: #1a202c; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 12px; line-height: 1.6; overflow-x: auto; max-height: 400px; margin-top: 8px; }
.seo-result-summary { background: #f7fafc; padding: 14px; border-radius: 8px; }
.result-line { margin-bottom: 6px; }
.result-line:last-child { margin-bottom: 0; }

.seo-history { width: 100%; border-collapse: collapse; font-size: 13px; }
.seo-history th { text-align: left; padding: 10px; background: #f7fafc; color: #2d3748; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
.seo-history td { padding: 10px; border-bottom: 1px solid #edf2f7; vertical-align: top; }
.seo-history-details { color: #4a5568; max-width: 400px; word-break: break-all; }

.status-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.status-success { background: #c6f6d5; color: #22543d; }
.status-error { background: #fed7d7; color: #742a2a; }
.status-warning { background: #fef3c7; color: #78350f; }
.status-info { background: #bee3f8; color: #2a4365; }

@media (max-width: 720px) {
    .seo-stats, .seo-info-grid, .seo-actions-grid, .seo-external-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
