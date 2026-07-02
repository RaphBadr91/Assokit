<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_require();

$has_api_key = trim((string) config_get('claude_api_key', '')) !== '';

// Sujets en attente (priorité par défaut)
$pending_topics = DB::fetchAll(
    "SELECT id, topic_title, category, target_keywords, briefing_extra, priority
     FROM asso_blog_topics
     WHERE status = 'pending'
     ORDER BY priority ASC, created_at ASC
     LIMIT 30"
);

$page_title = 'Générer un article';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>✨ Génération IA</h1>
    <p class="dim">Crée un article via Claude — 700-900 mots, structure SEO, bloc Assokit et CTA ajoutés automatiquement.</p>
</div>

<?php if (!$has_api_key): ?>
<div class="alert alert-error">
    ❌ <strong>Clé API Claude non configurée.</strong> 
    <a href="/admin-blog/settings.php">→ Configure-la dans les paramètres</a> avant de générer.
</div>
<?php else: ?>

<div class="grid-2">
    <!-- Génération à partir d'un sujet libre -->
    <section class="card">
        <h2>🎯 Sujet libre</h2>
        <p class="dim small">Écris ton sujet et Claude rédige l'article complet.</p>
        
        <form id="generate-form" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

            <label class="form-label">
                Sujet de l'article
                <input type="text" name="topic_title" required placeholder="Ex: Comment déclarer une association loi 1901 en 2026">
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
                Mots-clés SEO ciblés <span class="dim small">(optionnel, séparés par virgules)</span>
                <input type="text" name="keywords" placeholder="déclaration association, préfecture, statuts">
            </label>

            <label class="form-label">
                Briefing supplémentaire <span class="dim small">(optionnel, pour orienter Claude)</span>
                <textarea name="briefing_extra" rows="3" placeholder="Ex: insiste sur la procédure dématérialisée, mentionne le service e-création"></textarea>
            </label>

            <label class="form-checkbox">
                <input type="checkbox" name="is_published" value="1" checked>
                Publier directement (sinon : brouillon)
            </label>

            <button type="submit" class="btn-primary btn-block">✨ Générer l'article</button>
        </form>

        <div id="generate-status" class="generate-status" style="display:none"></div>
    </section>

    <!-- Sujets en attente -->
    <section class="card">
        <div class="card-head">
            <h2>📋 Sujets en attente (<?= count($pending_topics) ?>)</h2>
            <a href="/admin-blog/topics.php" class="btn-ghost-sm">Gérer →</a>
        </div>
        <p class="dim small">Génère depuis ta file de sujets. Les sujets sont marqués "generated" automatiquement.</p>

        <?php if (count($pending_topics) === 0): ?>
            <div class="empty-state">
                <p class="dim">Aucun sujet en attente.</p>
                <a href="/admin-blog/topics.php" class="btn-secondary">+ Ajouter des sujets</a>
            </div>
        <?php else: ?>
            <ul class="topic-list">
            <?php foreach ($pending_topics as $t): ?>
                <li>
                    <div>
                        <strong><?= htmlspecialchars($t['topic_title']) ?></strong>
                        <div class="small dim">
                            <span class="cat-pill cat-<?= htmlspecialchars($t['category']) ?>"><?= htmlspecialchars($t['category']) ?></span>
                            <?php if ($t['target_keywords']): ?> · <?= htmlspecialchars(mb_substr($t['target_keywords'], 0, 60)) ?><?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="btn-ghost-sm" data-generate-topic="<?= (int) $t['id'] ?>">Générer →</button>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
