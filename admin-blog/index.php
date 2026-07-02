<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_require();

// --- STATISTIQUES ---
$total_articles = (int) DB::fetch('SELECT COUNT(*) AS n FROM asso_blog_articles')['n'];
$published     = (int) DB::fetch('SELECT COUNT(*) AS n FROM asso_blog_articles WHERE is_published = 1')['n'];
$total_words   = (int) DB::fetch("SELECT COALESCE(SUM(reading_time_min * 200), 0) AS n FROM asso_blog_articles WHERE is_published = 1")['n'];

$by_category = DB::fetchAll('SELECT category, COUNT(*) AS n FROM asso_blog_articles WHERE is_published = 1 GROUP BY category ORDER BY n DESC');

$articles_today = (int) DB::fetch("SELECT COUNT(*) AS n FROM asso_blog_articles WHERE DATE(created_at) = CURDATE()")['n'];
$articles_week  = (int) DB::fetch("SELECT COUNT(*) AS n FROM asso_blog_articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['n'];

$pending_topics = (int) DB::fetch("SELECT COUNT(*) AS n FROM asso_blog_topics WHERE status = 'pending'")['n'];
$last_cron      = (string) config_get('last_cron_run', '');
$cron_enabled   = (string) config_get('cron_enabled', '0') === '1';
$has_api_key    = trim((string) config_get('claude_api_key', '')) !== '';

// --- DERNIERS ARTICLES ---
$recent = DB::fetchAll('SELECT slug, title, category, created_at, reading_time_min FROM asso_blog_articles ORDER BY created_at DESC LIMIT 10');

// --- DERNIERS LOGS ---
$recent_logs = DB::fetchAll("SELECT action, details, status, created_at FROM asso_blog_admin_logs ORDER BY created_at DESC LIMIT 10");

// --- TRAJECTOIRE ---
// 73 articles maintenant, objectif 420 articles dans 4 mois → 3/jour × 120 jours = 360 nouveaux + 73 = 433
$target_articles = 433;
$progress_pct = min(100, round($total_articles / $target_articles * 100));

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>📊 Dashboard</h1>
    <p class="dim">Vue d'ensemble du blog Assokit</p>
</div>

<?php if (!$has_api_key): ?>
<div class="alert alert-warning">
    ⚠️ <strong>Clé API Claude non configurée.</strong> 
    <a href="/admin-blog/settings.php">→ Configure-la dans les paramètres</a> pour activer la génération IA.
</div>
<?php endif; ?>

<!-- Cards stats principales -->
<section class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Articles publiés</div>
        <div class="stat-value"><?= number_format($published, 0, ',', ' ') ?></div>
        <div class="stat-sub"><?= number_format($total_articles - $published, 0, ',', ' ') ?> brouillons</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Mots totaux</div>
        <div class="stat-value"><?= number_format($total_words, 0, ',', ' ') ?></div>
        <div class="stat-sub">~<?= number_format($total_words / max(1,$published), 0, ',', ' ') ?> mots/article</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cette semaine</div>
        <div class="stat-value"><?= $articles_week ?></div>
        <div class="stat-sub"><?= $articles_today ?> aujourd'hui</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sujets en attente</div>
        <div class="stat-value"><?= $pending_topics ?></div>
        <div class="stat-sub"><a href="/admin-blog/topics.php">Gérer →</a></div>
    </div>
</section>

<!-- Progression vers 15K visites -->
<section class="card">
    <div class="card-head">
        <h2>🎯 Trajectoire vers 15 000 visites/mois</h2>
        <span class="badge"><?= $progress_pct ?>%</span>
    </div>
    <div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $progress_pct ?>%"></div></div>
    <p class="dim small">
        <?= $total_articles ?> articles · objectif <?= $target_articles ?> articles à 4 mois (3/jour)
    </p>
</section>

<!-- Génération auto -->
<section class="card">
    <div class="card-head">
        <h2>⚙️ Génération automatique</h2>
        <span class="badge <?= $cron_enabled ? 'badge-ok' : 'badge-warn' ?>">
            <?= $cron_enabled ? '✅ Activée' : '⏸️ Désactivée' ?>
        </span>
    </div>
    <div class="kv-grid">
        <div><span class="kv-key">Articles/jour ciblés</span><span class="kv-val"><?= htmlspecialchars((string) config_get('articles_per_day', '3')) ?></span></div>
        <div><span class="kv-key">Dernier cron</span><span class="kv-val"><?= $last_cron ? htmlspecialchars($last_cron) : '<em class="dim">jamais</em>' ?></span></div>
        <div><span class="kv-key">Sujets en file</span><span class="kv-val"><?= $pending_topics ?></span></div>
    </div>
    <div class="card-actions">
        <a href="/admin-blog/generate.php" class="btn-primary">✨ Générer un article maintenant</a>
        <a href="/admin-blog/settings.php" class="btn-secondary">⚙️ Configurer le cron</a>
    </div>
</section>

<!-- Répartition par catégorie -->
<section class="card">
    <div class="card-head">
        <h2>📂 Répartition par catégorie</h2>
    </div>
    <table class="data-table">
        <thead><tr><th>Catégorie</th><th>Articles</th><th>Visualisation</th></tr></thead>
        <tbody>
        <?php foreach ($by_category as $row): ?>
            <?php $pct = round($row['n'] / max(1, $published) * 100); ?>
            <tr>
                <td><?= CATEGORY_LABELS[$row['category']] ?? htmlspecialchars($row['category']) ?></td>
                <td><strong><?= $row['n'] ?></strong></td>
                <td><div class="bar-mini"><div class="bar-mini-fill" style="width:<?= $pct ?>%"></div></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<!-- Derniers articles -->
<section class="card">
    <div class="card-head">
        <h2>📰 Derniers articles</h2>
        <a href="/admin-blog/articles.php" class="btn-ghost-sm">Tout voir →</a>
    </div>
    <table class="data-table">
        <thead><tr><th>Titre</th><th>Catégorie</th><th>Date</th><th>Durée</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $a): ?>
        <tr>
            <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
            <td><span class="cat-pill cat-<?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars($a['category']) ?></span></td>
            <td class="dim"><?= htmlspecialchars(date('d/m H:i', strtotime($a['created_at']))) ?></td>
            <td class="dim"><?= (int) $a['reading_time_min'] ?> min</td>
            <td class="row-actions">
                <a href="https://assokit.fr/blog/<?= htmlspecialchars($a['slug']) ?>" target="_blank" class="link">↗</a>
                <a href="/admin-blog/article-edit.php?slug=<?= urlencode($a['slug']) ?>" class="link">✏️</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<!-- Logs récents -->
<section class="card">
    <div class="card-head">
        <h2>📜 Activité récente</h2>
    </div>
    <table class="data-table">
        <thead><tr><th>Date</th><th>Action</th><th>Détails</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($recent_logs as $log): ?>
        <tr>
            <td class="dim small"><?= htmlspecialchars(date('d/m H:i:s', strtotime($log['created_at']))) ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td class="dim"><?= htmlspecialchars(mb_substr($log['details'] ?? '', 0, 80)) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars($log['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
