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
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_single') {
            $topic_title = trim($_POST['topic_title'] ?? '');
            $category    = $_POST['category'] ?? '';
            $keywords    = trim($_POST['target_keywords'] ?? '');
            $briefing    = trim($_POST['briefing_extra'] ?? '');
            $priority    = max(1, min(10, (int) ($_POST['priority'] ?? 5)));

            if ($topic_title === '' || !in_array($category, CATEGORIES, true)) {
                throw new InvalidArgumentException('Titre et catégorie requis');
            }
            DB::execute(
                'INSERT INTO asso_blog_topics (topic_title, category, target_keywords, briefing_extra, priority) VALUES (?, ?, ?, ?, ?)',
                [$topic_title, $category, $keywords, $briefing, $priority]
            );
            $success = "Sujet ajouté : {$topic_title}";

        } elseif ($action === 'add_bulk') {
            $bulk = $_POST['bulk_topics'] ?? '';
            $default_category = $_POST['bulk_category'] ?? 'associations';
            if (!in_array($default_category, CATEGORIES, true)) {
                throw new InvalidArgumentException('Catégorie invalide');
            }
            $lines = array_filter(array_map('trim', explode("\n", $bulk)));
            $added = 0;
            foreach ($lines as $line) {
                if ($line === '') continue;
                // Support du format "titre | catégorie | mots-clés"
                $parts = array_map('trim', explode('|', $line));
                $title = $parts[0];
                $cat   = $parts[1] ?? $default_category;
                $kw    = $parts[2] ?? '';
                if (!in_array($cat, CATEGORIES, true)) {
                    $cat = $default_category;
                }
                DB::execute(
                    'INSERT INTO asso_blog_topics (topic_title, category, target_keywords, priority) VALUES (?, ?, ?, 5)',
                    [$title, $cat, $kw]
                );
                $added++;
            }
            $success = "{$added} sujet(s) ajouté(s)";

        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            DB::execute('DELETE FROM asso_blog_topics WHERE id = ?', [$id]);
            $success = "Sujet supprimé";

        } elseif ($action === 'skip') {
            $id = (int) ($_POST['id'] ?? 0);
            DB::execute("UPDATE asso_blog_topics SET status = 'skipped' WHERE id = ?", [$id]);
            $success = "Sujet ignoré";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pending = DB::fetchAll("SELECT * FROM asso_blog_topics WHERE status = 'pending' ORDER BY priority ASC, created_at ASC");
$generated = DB::fetchAll("SELECT * FROM asso_blog_topics WHERE status = 'generated' ORDER BY generated_at DESC LIMIT 20");
$skipped = DB::fetchAll("SELECT * FROM asso_blog_topics WHERE status = 'skipped' ORDER BY created_at DESC LIMIT 20");

$page_title = 'Sujets';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>💡 Sujets candidats</h1>
    <p class="dim">File d'attente pour la génération automatique. Le cron pioche dans les sujets en attente, par priorité.</p>
</div>

<?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2>➕ Ajouter un sujet</h2>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_single">
            <label class="form-label">
                Titre du sujet
                <input type="text" name="topic_title" required placeholder="Comment créer un budget prévisionnel d'association">
            </label>
            <label class="form-label">
                Catégorie
                <select name="category" required>
                    <?php foreach (CATEGORIES as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(CATEGORY_LABELS[$c]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-label">
                Mots-clés SEO <span class="dim small">(optionnel)</span>
                <input type="text" name="target_keywords" placeholder="budget association, prévisionnel, modèle gratuit">
            </label>
            <label class="form-label">
                Briefing supplémentaire <span class="dim small">(optionnel)</span>
                <textarea name="briefing_extra" rows="2"></textarea>
            </label>
            <label class="form-label">
                Priorité <span class="dim small">(1=urgent, 10=basse)</span>
                <input type="number" name="priority" min="1" max="10" value="5">
            </label>
            <button type="submit" class="btn-primary">Ajouter le sujet</button>
        </form>
    </section>

    <section class="card">
        <h2>📦 Ajouter en masse</h2>
        <p class="dim small">Un sujet par ligne. Format optionnel : <code>titre | catégorie | mots-clés</code></p>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_bulk">
            <label class="form-label">
                Catégorie par défaut
                <select name="bulk_category">
                    <?php foreach (CATEGORIES as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(CATEGORY_LABELS[$c]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-label">
                Liste des sujets
                <textarea name="bulk_topics" rows="10" placeholder="Comment créer un compte bancaire pour son association | associations | banque, compte
Modèle de PV d'AG d'association
Comment recruter un trésorier
..."></textarea>
            </label>
            <button type="submit" class="btn-primary">Ajouter tous</button>
        </form>
    </section>
</div>

<section class="card">
    <h2>🟠 En attente (<?= count($pending) ?>)</h2>
    <?php if (count($pending) === 0): ?>
        <p class="dim center" style="padding:24px">Aucun sujet en attente.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Sujet</th><th>Catégorie</th><th>Priorité</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $t): ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['topic_title']) ?></strong>
                    <?php if ($t['target_keywords']): ?><div class="small dim"><?= htmlspecialchars($t['target_keywords']) ?></div><?php endif; ?>
                </td>
                <td><span class="cat-pill cat-<?= htmlspecialchars($t['category']) ?>"><?= htmlspecialchars($t['category']) ?></span></td>
                <td><?= (int) $t['priority'] ?></td>
                <td class="row-actions">
                    <a href="/admin-blog/generate.php" title="Générer">✨</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Ignorer ce sujet ?')">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="skip">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="link" title="Ignorer">⏭️</button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce sujet ?')">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="link-danger" title="Supprimer">🗑️</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php if (count($generated) > 0): ?>
<section class="card">
    <h2>✅ Générés récemment (<?= count($generated) ?>)</h2>
    <table class="data-table">
        <thead><tr><th>Sujet</th><th>Article créé</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($generated as $t): ?>
            <tr>
                <td class="dim"><?= htmlspecialchars($t['topic_title']) ?></td>
                <td>
                    <?php if ($t['generated_slug']): ?>
                        <a href="/admin-blog/article-edit.php?slug=<?= urlencode($t['generated_slug']) ?>"><?= htmlspecialchars($t['generated_slug']) ?></a>
                    <?php endif; ?>
                </td>
                <td class="dim small"><?= $t['generated_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($t['generated_at']))) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
