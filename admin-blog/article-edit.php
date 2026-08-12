<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_require();

$slug = $_GET['slug'] ?? '';
$is_edit = $slug !== '';
$article = null;
$success = '';
$error = '';

if ($is_edit) {
    $article = DB::fetch('SELECT * FROM asso_blog_articles WHERE slug = ?', [$slug]);
    if (!$article) {
        header('Location: /admin-blog/articles.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $data = [
            'slug'             => trim($_POST['slug'] ?? ''),
            'title'            => trim($_POST['title'] ?? ''),
            'meta_title'       => trim($_POST['meta_title'] ?? ''),
            'meta_description' => trim($_POST['meta_description'] ?? ''),
            'excerpt'          => trim($_POST['excerpt'] ?? ''),
            'content_md'       => $_POST['content_md'] ?? '',
            'category'         => $_POST['category'] ?? '',
            'tags'             => trim($_POST['tags'] ?? ''),
            'cover_emoji'      => trim($_POST['cover_emoji'] ?? '📝'),
            'is_published'     => isset($_POST['is_published']) ? 1 : 0,
        ];
        if (!$is_edit) {
            // Nouvel article : enrichissement complet
            $result = save_article($data);
            admin_log('article_created_manual', "Slug: {$result['slug']}", 'success');
            // IndexNow : soumission immédiate à Bing/Yandex si l'article est publié
            if ((int)$data['is_published'] === 1 && !empty($result['slug'])) {
                require_once __DIR__ . '/includes/seo-notifier.php';
                try { notify_seo_new_article(SITE_URL . '/blog/' . $result['slug']); } catch (Throwable $e) {}
            }
            header("Location: /admin-blog/article-edit.php?slug=" . urlencode($result['slug']) . "&saved=1");
            exit;
        } else {
            // Édition : on n'enrichit pas (le contenu peut déjà contenir bloc Assokit + CTA)
            $now = date('Y-m-d H:i:s');
            DB::execute(
                'UPDATE asso_blog_articles SET
                    title = ?, meta_title = ?, meta_description = ?, excerpt = ?,
                    content_md = ?, category = ?, tags = ?, cover_emoji = ?,
                    is_published = ?, reading_time_min = ?, updated_at = ?
                 WHERE slug = ?',
                [
                    $data['title'],
                    $data['meta_title'] ?: generate_meta_title($data['title']),
                    $data['meta_description'] ?: generate_meta_description($data['excerpt']),
                    $data['excerpt'] ?: generate_excerpt($data['content_md']),
                    $data['content_md'],
                    $data['category'],
                    $data['tags'],
                    $data['cover_emoji'],
                    $data['is_published'],
                    reading_time($data['content_md']),
                    $now,
                    $slug,
                ]
            );
            admin_log('article_updated', "Slug: {$slug}", 'success');
            // IndexNow : re-soumission à la mise à jour d'un article publié
            if ((int)$data['is_published'] === 1 && !empty($slug)) {
                require_once __DIR__ . '/includes/seo-notifier.php';
                try { notify_seo_new_article(SITE_URL . '/blog/' . $slug); } catch (Throwable $e) {}
            }
            header("Location: /admin-blog/article-edit.php?slug=" . urlencode($slug) . "&saved=1");
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$saved_flag = !empty($_GET['saved']);

$page_title = $is_edit ? 'Éditer · ' . $article['title'] : 'Nouvel article';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1><?= $is_edit ? '✏️ Éditer' : '➕ Nouvel article' ?></h1>
    <?php if ($is_edit): ?>
        <p class="dim">
            <code><?= htmlspecialchars($article['slug']) ?></code> ·
            <a href="https://assokit.fr/blog/<?= htmlspecialchars($article['slug']) ?>" target="_blank">↗ Voir en ligne</a>
        </p>
    <?php endif; ?>
</div>

<?php if ($saved_flag): ?>
    <div class="alert alert-success">✅ Article sauvegardé.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" class="article-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div class="form-grid">
        <div class="form-main">
            <label class="form-label">
                Titre <span class="dim small">(H1 de l'article)</span>
                <input type="text" name="title" required value="<?= htmlspecialchars($article['title'] ?? '') ?>" placeholder="Comment créer une association loi 1901 en 2026">
            </label>

            <label class="form-label">
                Slug URL <?php if ($is_edit): ?><span class="dim small">(non modifiable)</span><?php endif; ?>
                <input type="text" name="slug" <?= $is_edit ? 'readonly' : '' ?> value="<?= htmlspecialchars($article['slug'] ?? '') ?>" placeholder="Auto-généré depuis le titre si vide">
            </label>

            <label class="form-label">
                Contenu Markdown <span class="dim small">(le bloc Assokit + articles liés + CTA sont ajoutés automatiquement à la création)</span>
                <textarea name="content_md" rows="30" required class="markdown-editor" placeholder="# Titre&#10;&#10;**Le problème** : ...&#10;**La solution** : ..."><?= htmlspecialchars($article['content_md'] ?? '') ?></textarea>
            </label>

            <details class="form-section">
                <summary>📝 Métadonnées SEO</summary>
                <label class="form-label">
                    Meta title <span class="dim small">(60 caractères max)</span>
                    <input type="text" name="meta_title" maxlength="80" value="<?= htmlspecialchars($article['meta_title'] ?? '') ?>" placeholder="Auto-généré si vide">
                </label>
                <label class="form-label">
                    Meta description <span class="dim small">(160 caractères max)</span>
                    <textarea name="meta_description" rows="2" maxlength="200"><?= htmlspecialchars($article['meta_description'] ?? '') ?></textarea>
                </label>
                <label class="form-label">
                    Extrait <span class="dim small">(résumé court visible dans la liste)</span>
                    <textarea name="excerpt" rows="3"><?= htmlspecialchars($article['excerpt'] ?? '') ?></textarea>
                </label>
                <label class="form-label">
                    Tags (séparés par virgule)
                    <input type="text" name="tags" value="<?= htmlspecialchars($article['tags'] ?? '') ?>">
                </label>
            </details>
        </div>

        <aside class="form-side">
            <div class="card">
                <h3>📋 Configuration</h3>
                <label class="form-label">
                    Catégorie
                    <select name="category" required>
                        <?php foreach (CATEGORIES as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($article['category'] ?? '') === $c ? 'selected' : '' ?>>
                                <?= htmlspecialchars(CATEGORY_LABELS[$c]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-label">
                    Emoji de couverture
                    <input type="text" name="cover_emoji" maxlength="4" value="<?= htmlspecialchars($article['cover_emoji'] ?? '📝') ?>">
                </label>
                <label class="form-checkbox">
                    <input type="checkbox" name="is_published" value="1" <?= ($article['is_published'] ?? 1) ? 'checked' : '' ?>>
                    Publié (visible sur le blog)
                </label>
            </div>

            <?php if ($is_edit): ?>
            <div class="card">
                <h3>📊 Stats</h3>
                <div class="kv-grid">
                    <div><span class="kv-key">Mots</span><span class="kv-val"><?= number_format(word_count($article['content_md']), 0, ',', ' ') ?></span></div>
                    <div><span class="kv-key">Durée lecture</span><span class="kv-val"><?= (int) $article['reading_time_min'] ?> min</span></div>
                    <div><span class="kv-key">Créé</span><span class="kv-val small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($article['created_at']))) ?></span></div>
                    <div><span class="kv-key">MAJ</span><span class="kv-val small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($article['updated_at']))) ?></span></div>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-primary btn-block">💾 Sauvegarder</button>
            <?php if ($is_edit): ?>
                <button type="button" class="btn-danger btn-block" data-delete-slug="<?= htmlspecialchars($slug) ?>" data-delete-title="<?= htmlspecialchars($article['title']) ?>">🗑️ Supprimer cet article</button>
            <?php endif; ?>
            <a href="/admin-blog/articles.php" class="btn-ghost btn-block">← Retour à la liste</a>
        </aside>
    </div>
</form>

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
