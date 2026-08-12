<?php
/**
 * blog-helpers.php
 * --------------------------------------------------------------
 * Helpers pour le blog Assokit
 * --------------------------------------------------------------
 */

if (!function_exists('blog_categories')) {
    function blog_categories(): array {
        return [
            'associations'   => ['label' => 'Associations',     'icon' => '🏛️', 'color' => '#059669'],
            'tpe'            => ['label' => 'TPE & indépendants','icon' => '🛠️', 'color' => '#7E22CE'],
            'comptabilite'   => ['label' => 'Comptabilité',     'icon' => '📊', 'color' => '#F59E0B'],
            'juridique'      => ['label' => 'Juridique',        'icon' => '⚖️', 'color' => '#0EA5E9'],
            'communication'  => ['label' => 'Communication',    'icon' => '📣', 'color' => '#EC4899'],
            'gestion'        => ['label' => 'Gestion',          'icon' => '📋', 'color' => '#0F172A'],
            'general'        => ['label' => 'Général',          'icon' => '🌿', 'color' => '#059669'],
        ];
    }
}

if (!function_exists('blog_category')) {
    function blog_category(string $key): array {
        $cats = blog_categories();
        return $cats[$key] ?? $cats['general'];
    }
}

if (!function_exists('blog_list')) {
    function blog_list(PDO $pdo, ?string $category = null, ?string $q = null, int $limit = 50, int $offset = 0): array {
        try {
            $where = ['is_published = 1'];
            $params = [];
            if ($category) { $where[] = 'category = :c'; $params[':c'] = $category; }
            if ($q) { $where[] = '(title LIKE :q OR excerpt LIKE :q OR content_md LIKE :q)'; $params[':q'] = '%' . $q . '%'; }

            $sql = "SELECT id, slug, title, excerpt, cover_emoji, cover_color_from, cover_color_to,
                           category, tags, author, reading_time_min, published_at
                    FROM asso_blog_articles
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY published_at DESC
                    LIMIT " . (int)$limit . " OFFSET " . max(0, (int)$offset);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[blog_list] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('blog_count')) {
    function blog_count(PDO $pdo, ?string $category = null, ?string $q = null): int {
        try {
            $where = ['is_published = 1'];
            $params = [];
            if ($category) { $where[] = 'category = :c'; $params[':c'] = $category; }
            if ($q) { $where[] = '(title LIKE :q OR excerpt LIKE :q OR content_md LIKE :q)'; $params[':q'] = '%' . $q . '%'; }
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_blog_articles WHERE " . implode(' AND ', $where));
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[blog_count] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('blog_get_by_slug')) {
    function blog_get_by_slug(PDO $pdo, string $slug): ?array {
        try {
            $st = $pdo->prepare("SELECT * FROM asso_blog_articles WHERE slug = :s AND is_published = 1 LIMIT 1");
            $st->execute([':s' => $slug]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('blog_related')) {
    function blog_related(PDO $pdo, array $article, int $limit = 3): array {
        try {
            $cols = "id, slug, title, excerpt, cover_emoji, cover_color_from, cover_color_to, category, reading_time_min, published_at";
            $tags = array_filter(array_map('trim', explode(',', (string)($article['tags'] ?? ''))));
            $picked = []; $seen = [(int)$article['id']];

            // 1) Priorité : articles partageant le plus de tags (vrai cluster thématique)
            if ($tags) {
                $like = []; $params = [':id' => (int)$article['id']];
                foreach (array_slice($tags, 0, 8) as $i => $t) { $like[] = "tags LIKE :t$i"; $params[":t$i"] = '%' . $t . '%'; }
                $score = implode(' + ', array_map(fn($c) => "($c)", $like));
                $sql = "SELECT $cols, ($score) AS score
                        FROM asso_blog_articles
                        WHERE is_published = 1 AND id != :id
                        HAVING score > 0
                        ORDER BY score DESC, published_at DESC
                        LIMIT " . (int)$limit;
                $st = $pdo->prepare($sql); $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $picked[] = $r; $seen[] = (int)$r['id']; }
            }

            // 2) Complément par catégorie (varié) pour éviter de toujours ressortir les mêmes
            if (count($picked) < $limit) {
                $ph = implode(',', array_fill(0, count($seen), '?'));
                $sql = "SELECT $cols FROM asso_blog_articles
                        WHERE is_published = 1 AND category = ? AND id NOT IN ($ph)
                        ORDER BY RAND() LIMIT " . (int)($limit - count($picked));
                $st = $pdo->prepare($sql);
                $st->execute(array_merge([$article['category']], $seen));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $picked[] = $r;
            }
            return array_slice($picked, 0, $limit);
        } catch (Throwable $e) { error_log('[blog_related] ' . $e->getMessage()); return []; }
    }
}

/**
 * Markdown vers HTML (simple mais efficace, pas de dépendance)
 */
if (!function_exists('blog_md_to_html')) {
    function blog_md_to_html(string $md): string {
        $md = (string)$md;
        $h = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

        // Code blocks
        $h = preg_replace_callback('/```(.*?)```/s', function($m) {
            return '<pre><code>' . trim($m[1]) . '</code></pre>';
        }, $h);

        // Inline code
        $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);

        // Headings
        $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
        $h = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $h);
        $h = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $h);

        // Bold / italic
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
        $h = preg_replace('/\*(.+?)\*/s',     '<em>$1</em>', $h);

        // Links [text](url)
        $h = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $h);

        // Blockquotes
        $h = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $h);

        // Lists
        $h = preg_replace('/^- (.+)$/m', '<li>$1</li>', $h);
        $h = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $h);
        $h = preg_replace_callback('/(<li>.*?<\/li>(\n|$))+/s', function($m) {
            return "<ul>\n" . $m[0] . "</ul>\n";
        }, $h);

        // Paragraphs (double newline)
        $blocks = preg_split("/\n\s*\n/", $h);
        $out = [];
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') continue;
            // Si le bloc commence déjà par une balise html → ne pas envelopper
            if (preg_match('/^<(h[1-6]|ul|ol|blockquote|pre|p|div|figure|table)/i', $b)) {
                $out[] = $b;
            } else {
                $out[] = '<p>' . str_replace("\n", '<br>', $b) . '</p>';
            }
        }
        return implode("\n", $out);
    }
}

if (!function_exists('blog_format_date_fr')) {
    function blog_format_date_fr(?string $datetime): string {
        if (!$datetime) return '';
        $months = ['', 'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        $ts = strtotime($datetime);
        if (!$ts) return '';
        return date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    }
}
