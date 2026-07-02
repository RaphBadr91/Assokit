<?php
/**
 * blog-article.php
 * --------------------------------------------------------------
 * Page d'article single
 * URL : /blog/{slug} (via .htaccess rewrite)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/blog-helpers.php';
require_once __DIR__ . '/blog-markdown.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') { header('Location: /blog'); exit; }

$article = blog_get_by_slug($pdo, $slug);
if (!$article) {
    http_response_code(404);
    render_public_head([
        'title'       => '404 · Article introuvable',
        'description' => 'Cet article n\'existe pas ou plus.',
        'path'        => '/blog/' . $slug,
        'noindex'     => true,
    ]);
    render_public_nav('blog');
    ?>
    <section class="pub-section pub-text-center" style="padding: 100px 0;">
      <div class="pub-container-narrow">
        <div style="font-size:80px;margin-bottom:14px;">🌱</div>
        <h1 class="pub-h2">Article introuvable</h1>
        <p style="color:var(--c-text-2);font-size:17px;">Cet article n'existe pas ou a été retiré.</p>
        <a href="/blog" class="pub-btn pub-btn-primary pub-btn-lg" style="margin-top:20px;">← Retour au blog</a>
      </div>
    </section>
    <?php
    render_public_footer();
    render_public_foot();
    exit;
}

$cat = blog_category((string)$article['category']);
$related = blog_related($pdo, $article, 3);
$tags = $article['tags'] ? array_map('trim', explode(',', $article['tags'])) : [];

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Blog',    'url' => '/blog'],
    ['name' => $cat['label'], 'url' => '/blog?categorie=' . $article['category']],
    ['name' => $article['title'], 'url' => '/blog/' . $article['slug']],
]);

// Schema Article
$article_jsonld = [
    '@context' => 'https://schema.org',
    '@type'    => 'Article',
    'headline' => $article['title'],
    'description' => $article['excerpt'],
    'datePublished' => $article['published_at'],
    'dateModified'  => $article['updated_at'] ?: $article['published_at'],
    'author' => [
        '@type' => 'Organization',
        'name'  => $article['author'] ?: 'L\'équipe Assokit',
        'url'   => AK_PUBLIC_DOMAIN,
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name'  => 'Assokit',
        'logo'  => ['@type' => 'ImageObject', 'url' => AK_PUBLIC_DOMAIN . '/assets/logo-assokit.png'],
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id'   => pub_url('/blog/' . $article['slug']),
    ],
    'articleSection' => $cat['label'],
    'keywords' => implode(', ', $tags),
];

render_public_head([
    'title'       => $article['meta_title'] ?: $article['title'],
    'description' => $article['meta_description'] ?: $article['excerpt'],
    'path'        => '/blog/' . $article['slug'],
    'og_type'     => 'article',
    'article_data' => [
        'published_time' => $article['published_at'],
        'modified_time'  => $article['updated_at'],
        'author'         => $article['author'] ?: 'L\'équipe Assokit',
        'section'        => $cat['label'],
        'tags'           => $tags,
    ],
    'schema_jsonld' => [$breadcrumb, $article_jsonld],
]);
render_public_nav('blog');
?>

<!-- HERO -->
<section style="background:linear-gradient(135deg, <?= pub_h($article['cover_color_from'] ?: '#059669') ?> 0%, <?= pub_h($article['cover_color_to'] ?: '#0F172A') ?> 100%); color: white; padding: 60px 0;">
  <div class="pub-container-narrow">
    <div class="pub-breadcrumb" style="color:rgba(255,255,255,0.7);">
      <a href="/" style="color:rgba(255,255,255,0.85);">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <a href="/blog" style="color:rgba(255,255,255,0.85);">Blog</a><span class="pub-breadcrumb-sep">›</span>
      <a href="/blog?categorie=<?= pub_h($article['category']) ?>" style="color:rgba(255,255,255,0.85);"><?= pub_h($cat['label']) ?></a>
    </div>
    <div style="font-size:60px;margin: 20px 0 14px;"><?= pub_h($article['cover_emoji'] ?: $cat['icon']) ?></div>
    <h1 style="font-size:42px;font-weight:700;letter-spacing:-0.02em;line-height:1.15;margin:0 0 18px;color:white;"><?= pub_h($article['title']) ?></h1>
    <p style="font-size:18px;line-height:1.55;color:rgba(255,255,255,0.85);margin:0 0 24px;max-width:680px;"><?= pub_h($article['excerpt']) ?></p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:14px;color:rgba(255,255,255,0.75);">
      <span>👤 <?= pub_h($article['author'] ?: 'L\'équipe Assokit') ?></span>
      <span>📅 <?= pub_h(blog_format_date_fr($article['published_at'])) ?></span>
      <span>⏱ <?= (int)$article['reading_time_min'] ?> min de lecture</span>
    </div>
  </div>
</section>

<!-- CONTENU -->
<section style="padding: 0 0 60px;">
  <div class="pub-container-narrow">
    <article class="pub-article-content" style="margin-top:-20px;position:relative;">
      <?= render_blog_markdown_css() ?>
      <?= render_blog_markdown((string)$article['content_md']) ?>

      <?php if (!empty($tags)): ?>
        <hr style="border:none;border-top:1px solid var(--c-border);margin:36px 0 20px;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
          <span style="font-size:13px;color:var(--c-text-3);">🏷 Tags :</span>
          <?php foreach ($tags as $tag): ?>
            <span style="display:inline-flex;align-items:center;padding:4px 10px;background:var(--c-creme-2);border-radius:var(--radius-pill);font-size:12px;color:var(--c-text-2);"><?= pub_h($tag) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Bloc CTA in-article -->
      <div style="background:linear-gradient(135deg,var(--c-encre),var(--c-emeraude-dark));border-radius:var(--radius-lg);padding:32px;margin-top:40px;color:white;text-align:center;">
        <h3 style="margin:0 0 10px;color:white;font-size:20px;">Vous gérez une asso ou une TPE ?</h3>
        <p style="color:rgba(255,255,255,0.85);margin:0 0 20px;font-size:15px;">Découvrez Assokit, l'outil pensé pour vous faire gagner du temps au quotidien.</p>
        <a href="/contact" style="display:inline-block;background:var(--c-ambre);color:var(--c-encre);padding:12px 24px;border-radius:var(--radius-md);text-decoration:none;font-weight:600;">Réserver une démo →</a>
      </div>
    </article>

    <!-- ARTICLES LIÉS -->
    <?php if (!empty($related)): ?>
      <section style="margin-top:60px;">
        <h2 style="font-size:24px;color:var(--c-encre);margin-bottom:24px;">📚 À lire aussi</h2>
        <div class="pub-article-list">
          <?php foreach ($related as $r):
            $rcat = blog_category((string)$r['category']);
          ?>
            <article class="pub-article-card">
              <a href="/blog/<?= pub_h($r['slug']) ?>">
                <div class="pub-article-cover" style="background:linear-gradient(135deg, <?= pub_h($r['cover_color_from'] ?: '#059669') ?>, <?= pub_h($r['cover_color_to'] ?: '#0F172A') ?>);">
                  <?= pub_h($r['cover_emoji'] ?: $rcat['icon']) ?>
                </div>
                <div class="pub-article-body">
                  <div class="pub-article-cat"><?= $rcat['icon'] ?> <?= pub_h($rcat['label']) ?></div>
                  <h3 class="pub-article-title"><?= pub_h($r['title']) ?></h3>
                  <p class="pub-article-excerpt"><?= pub_h($r['excerpt'] ?: '') ?></p>
                  <div class="pub-article-meta">
                    <span>⏱ <?= (int)$r['reading_time_min'] ?> min</span>
                  </div>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
