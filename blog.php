<?php
/**
 * blog.php
 * --------------------------------------------------------------
 * Liste des articles de blog
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/blog-helpers.php';

$cat_filter = trim((string)($_GET['categorie'] ?? ''));
$q          = trim((string)($_GET['q'] ?? ''));
$cats       = blog_categories();

if ($cat_filter && !isset($cats[$cat_filter])) $cat_filter = '';

$per_page = 12;
$page     = max(1, (int)($_GET['page'] ?? 1));
$total    = blog_count($pdo, $cat_filter ?: null, $q ?: null);
$last_page = max(1, (int)ceil($total / $per_page));
if ($page > $last_page) $page = $last_page;
$articles = blog_list($pdo, $cat_filter ?: null, $q ?: null, $per_page, ($page - 1) * $per_page);

$page_title = 'Blog';
if ($cat_filter) $page_title = 'Blog · ' . $cats[$cat_filter]['label'];
elseif ($q)      $page_title = 'Blog · Recherche "' . $q . '"';
if ($page > 1)   $page_title .= ' — Page ' . $page;

// Chemin canonique : on garde categorie + page (self-canonical pour que les pages 2+ soient explorées),
// mais PAS le paramètre de recherche interne (?q=), lui mis en noindex.
$canon_path = '/blog';
$qp = [];
if ($cat_filter) $qp[] = 'categorie=' . rawurlencode($cat_filter);
if ($page > 1)   $qp[] = 'page=' . $page;
if ($qp) $canon_path .= '?' . implode('&', $qp);

$blog_desc = $cat_filter
    ? ('Nos guides et conseils « ' . $cats[$cat_filter]['label'] . ' » pour les associations loi 1901, les TPE et les indépendants.')
    : 'Le blog Assokit : conseils, guides et bonnes pratiques pour les associations loi 1901, les TPE et les indépendants. Pensé pour vous faire gagner du temps.';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Blog',    'url' => '/blog'],
]);

render_public_head([
    'title'       => $page_title,
    'description' => $blog_desc,
    'path'        => $canon_path,
    'noindex'     => ($q !== ''), // les résultats de recherche interne ne sont pas indexés
    'schema_jsonld' => [$breadcrumb],
]);
render_public_nav('blog');
?>

<section class="pub-hero" style="padding: 60px 0 30px;">
  <div class="pub-container">
    <div class="pub-breadcrumb">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <?php if ($cat_filter): ?>
        <a href="/blog">Blog</a><span class="pub-breadcrumb-sep">›</span>
        <strong style="color:var(--c-encre);"><?= pub_h($cats[$cat_filter]['label']) ?></strong>
      <?php else: ?>
        <strong style="color:var(--c-encre);">Blog</strong>
      <?php endif; ?>
    </div>
    <span class="pub-eyebrow">📚 Le blog Assokit</span>
    <h1 class="pub-h1" style="max-width:780px;">
      <?php if ($cat_filter): ?>
        Articles · <em><?= pub_h($cats[$cat_filter]['label']) ?></em>
      <?php else: ?>
        Conseils, guides et bonnes pratiques pour les <em>assos</em> et les <em>TPE</em>.
      <?php endif; ?>
    </h1>
    <p class="pub-tagline" style="max-width:680px;">
      Tout ce qu'on aurait aimé lire <strong>quand on a démarré</strong>. Mis à jour régulièrement, sans bla-bla.
    </p>
  </div>
</section>

<!-- FILTRES CATÉGORIES -->
<section style="padding: 0 0 30px;">
  <div class="pub-container">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <a href="/blog" class="pub-btn <?= $cat_filter === '' ? 'pub-btn-primary' : 'pub-btn-ghost' ?>" style="padding:8px 14px;font-size:13px;<?= $cat_filter === '' ? '' : 'border:1px solid var(--c-border);' ?>">Tous</a>
      <?php foreach ($cats as $key => $cat): if ($key === 'general') continue; ?>
        <a href="/blog?categorie=<?= pub_h($key) ?>" class="pub-btn <?= $cat_filter === $key ? 'pub-btn-primary' : 'pub-btn-ghost' ?>" style="padding:8px 14px;font-size:13px;<?= $cat_filter === $key ? '' : 'border:1px solid var(--c-border);' ?>">
          <?= $cat['icon'] ?> <?= pub_h($cat['label']) ?>
        </a>
      <?php endforeach; ?>

      <form method="get" action="/blog" style="margin-left:auto;display:flex;gap:8px;">
        <?php if ($cat_filter): ?><input type="hidden" name="categorie" value="<?= pub_h($cat_filter) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= pub_h($q) ?>" placeholder="🔍 Rechercher…" style="padding:8px 14px;border:1px solid var(--c-border);border-radius:8px;font-size:13px;background:white;font-family:inherit;width:200px;">
      </form>
    </div>
  </div>
</section>

<!-- LISTE ARTICLES -->
<section class="pub-section" style="padding-top: 0;">
  <div class="pub-container">
    <?php if (empty($articles)): ?>
      <div style="background:white;border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:60px;text-align:center;">
        <div style="font-size:48px;margin-bottom:14px;">🌱</div>
        <h3 style="margin:0 0 10px;color:var(--c-encre);">Aucun article pour cette recherche</h3>
        <p style="color:var(--c-text-2);margin:0 0 20px;">Essayez d'autres mots-clés ou parcourez toutes les catégories.</p>
        <a href="/blog" class="pub-btn pub-btn-primary">Voir tous les articles</a>
      </div>
    <?php else: ?>
      <div class="pub-article-list">
        <?php foreach ($articles as $a):
          $cat = blog_category((string)$a['category']);
        ?>
          <article class="pub-article-card">
            <a href="/blog/<?= pub_h($a['slug']) ?>">
              <div class="pub-article-cover" style="background:linear-gradient(135deg, <?= pub_h($a['cover_color_from'] ?: '#059669') ?> 0%, <?= pub_h($a['cover_color_to'] ?: '#0F172A') ?> 100%);">
                <?= pub_h($a['cover_emoji'] ?: $cat['icon']) ?>
              </div>
              <div class="pub-article-body">
                <div class="pub-article-cat"><?= $cat['icon'] ?> <?= pub_h($cat['label']) ?></div>
                <h3 class="pub-article-title"><?= pub_h($a['title']) ?></h3>
                <p class="pub-article-excerpt"><?= pub_h($a['excerpt'] ?: '') ?></p>
                <div class="pub-article-meta">
                  <span>📅 <?= pub_h(blog_format_date_fr($a['published_at'])) ?></span>
                  <span>⏱ <?= (int)$a['reading_time_min'] ?> min</span>
                </div>
              </div>
            </a>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($last_page > 1): ?>
      <nav class="pub-pagination" aria-label="Pagination du blog" style="display:flex;gap:6px;justify-content:center;margin-top:34px;flex-wrap:wrap;">
        <?php
          $base_q = [];
          if ($cat_filter) $base_q[] = 'categorie=' . rawurlencode($cat_filter);
          $mk = function($p) use ($base_q) {
              $q = $base_q; if ($p > 1) $q[] = 'page=' . $p;
              return '/blog' . ($q ? '?' . implode('&', $q) : '');
          };
        ?>
        <?php if ($page > 1): ?><a href="<?= pub_h($mk($page - 1)) ?>" class="pub-btn pub-btn-ghost" style="padding:6px 12px;font-size:13px;" rel="prev">← Précédent</a><?php endif; ?>
        <?php for ($p = 1; $p <= $last_page; $p++): ?>
          <a href="<?= pub_h($mk($p)) ?>" class="pub-btn <?= $p === $page ? 'pub-btn-primary' : 'pub-btn-ghost' ?>" style="padding:6px 12px;font-size:13px;" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $last_page): ?><a href="<?= pub_h($mk($page + 1)) ?>" class="pub-btn pub-btn-ghost" style="padding:6px 12px;font-size:13px;" rel="next">Suivant →</a><?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Envie d'un outil aussi clair que nos articles ?</h2>
      <p>Assokit aide les assos et les TPE à passer plus de temps sur leur projet, moins sur la paperasse.</p>
      <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo</a>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
