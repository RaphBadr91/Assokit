<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_require();

$category_filter = $_GET['category'] ?? '';
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = ['1=1'];
$params = [];

if ($category_filter && in_array($category_filter, CATEGORIES, true)) {
    $where[] = 'category = ?';
    $params[] = $category_filter;
}
if ($search !== '') {
    $where[] = '(title LIKE ? OR slug LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status === 'published') {
    $where[] = 'is_published = 1';
} elseif ($status === 'draft') {
    $where[] = 'is_published = 0';
}

$articles = DB::fetchAll(
    'SELECT id, slug, title, category, is_published, reading_time_min, created_at, published_at
     FROM asso_blog_articles
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY created_at DESC
     LIMIT 200',
    $params
);

$total = (int) DB::fetch(
    'SELECT COUNT(*) AS n FROM asso_blog_articles WHERE ' . implode(' AND ', $where),
    $params
)['n'];

$page_title = 'Articles';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>📰 Articles</h1>
    <p class="dim"><?= $total ?> article<?= $total > 1 ? 's' : '' ?> au total</p>
</div>

<form method="GET" class="filters">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Rechercher un titre ou slug…">
    <select name="category">
        <option value="">Toutes catégories</option>
        <?php foreach (CATEGORIES as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= htmlspecialchars(CATEGORY_LABELS[$c]) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Tous</option>
        <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Publiés</option>
        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Brouillons</option>
    </select>
    <button type="submit" class="btn-primary">Filtrer</button>
    <a href="/admin-blog/articles.php" class="btn-ghost-sm">Reset</a>
    <a href="/admin-blog/article-edit.php" class="btn-secondary" style="margin-left:auto">+ Nouvel article</a>
</form>

<section class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Titre</th>
                <th>Catégorie</th>
                <th>Statut</th>
                <th>Date</th>
                <th>Durée</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($articles) === 0): ?>
            <tr><td colspan="6" class="dim center" style="padding:32px">Aucun article ne correspond à ces critères.</td></tr>
        <?php endif; ?>
        <?php foreach ($articles as $a): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($a['title']) ?></strong>
                    <div class="small dim"><code><?= htmlspecialchars($a['slug']) ?></code></div>
                </td>
                <td><span class="cat-pill cat-<?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars($a['category']) ?></span></td>
                <td>
                    <?php if ($a['is_published']): ?>
                        <span class="badge badge-ok">Publié</span>
                    <?php else: ?>
                        <span class="badge badge-warn">Brouillon</span>
                    <?php endif; ?>
                </td>
                <td class="dim small"><?= htmlspecialchars(date('d/m/Y', strtotime($a['created_at']))) ?></td>
                <td class="dim small"><?= (int) $a['reading_time_min'] ?> min</td>
                <td class="row-actions">
                    <a href="https://assokit.fr/blog/<?= htmlspecialchars($a['slug']) ?>" target="_blank" title="Voir">↗</a>
                    <a href="/admin-blog/article-edit.php?slug=<?= urlencode($a['slug']) ?>" title="Éditer">✏️</a>
                    <button type="button" class="link-danger" data-delete-slug="<?= htmlspecialchars($a['slug']) ?>" data-delete-title="<?= htmlspecialchars($a['title']) ?>" title="Supprimer">🗑️</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
