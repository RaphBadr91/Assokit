<?php
/**
 * Helpers de génération d'articles : slug, reading_time, articles liés,
 * bloc Assokit, CTA. Utilisés par la génération IA et l'édition manuelle.
 */

require_once __DIR__ . '/db.php';

// ============================================================
// CATÉGORIES DISPONIBLES
// ============================================================
const CATEGORIES = ['associations', 'tpe', 'comptabilite', 'juridique', 'communication', 'gestion'];

const CATEGORY_LABELS = [
    'associations'  => '🏛️ Associations',
    'tpe'           => '🛠️ TPE & indépendants',
    'comptabilite'  => '📊 Comptabilité',
    'juridique'     => '⚖️ Juridique',
    'communication' => '📣 Communication',
    'gestion'       => '📋 Gestion',
];

const CATEGORY_COLORS = [
    'associations'  => ['from' => '#10b981', 'to' => '#059669'],
    'tpe'           => ['from' => '#8b5cf6', 'to' => '#6d28d9'],
    'comptabilite'  => ['from' => '#3b82f6', 'to' => '#1d4ed8'],
    'juridique'     => ['from' => '#f59e0b', 'to' => '#d97706'],
    'communication' => ['from' => '#ec4899', 'to' => '#be185d'],
    'gestion'       => ['from' => '#06b6d4', 'to' => '#0891b2'],
];

// ============================================================
// BLOC PROBLÈME → SOLUTION ASSOKIT (par catégorie)
// ============================================================
const ASSOKIT_BLOCS = [
    'comptabilite' => "\n\n> ### 💡 Comment Assokit simplifie tout ça\n>\n> **Le problème** : tenir une comptabilité associative rigoureuse demande des heures chaque mois — saisie, relances, justifications aux financeurs, préparation du bilan annuel.\n>\n> **Avec [Assokit](/fonctionnalites)**, votre comptabilité se construit **automatiquement** au fur et à mesure de votre activité : factures émises, cotisations encaissées, dépenses catégorisées, justificatifs centralisés. Plus besoin de tout recopier en fin d'année. **[Découvrez le plan gratuit](/tarifs)**.\n",
    'associations' => "\n\n> ### 💡 Comment Assokit allège cette charge\n>\n> **Le problème** : la gestion administrative d'une association loi 1901 absorbe énormément de temps bénévole — temps qui pourrait servir à votre vraie mission.\n>\n> **Avec [Assokit](/fonctionnalites)**, vous centralisez adhérents, projets, événements, communications et finances dans **un seul outil pensé pour les assos françaises**. Hébergement à Évry, RGPD natif, IA intégrée pour rédiger newsletter et compte-rendus. **[Voir nos tarifs](/tarifs)** ou **[demander une démo](/contact)**.\n",
    'juridique' => "\n\n> ### 💡 Comment Assokit sécurise votre association\n>\n> **Le problème** : se conformer à toutes les obligations légales (RGPD, registre, déclarations, traçabilité) sans outil dédié, c'est jouer avec le feu.\n>\n> **Avec [Assokit](/fonctionnalites)**, registres tenus automatiquement, traçabilité complète des décisions, gestion RGPD intégrée, archivage horodaté. Votre conformité est **construite par défaut**, pas en patch. **[En savoir plus](/a-propos)**.\n",
    'tpe' => "\n\n> ### 💡 Comment Assokit transforme votre quotidien TPE\n>\n> **Le problème** : éparpillement entre Excel, mails, agenda papier, relances manuelles — résultat : du temps perdu, des oublis et de la trésorerie qui dort chez vos clients.\n>\n> **Avec [Assokit](/fonctionnalites)**, devis, factures, relances automatiques et suivi de trésorerie en un seul outil. **Vous récupérez 5 à 10 heures par semaine**. **[Démarrez gratuitement](/tarifs)** ou **[contactez-nous](/contact)** pour une démo.\n",
    'communication' => "\n\n> ### 💡 Comment Assokit booste votre communication\n>\n> **Le problème** : rédiger newsletters, posts, mails de relance, comptes-rendus prend des heures chaque semaine. Et ce temps manque pour la vraie action.\n>\n> **Avec [Assokit](/fonctionnalites)**, **19 outils IA intégrés** pré-rédigent vos contenus en 30 secondes (newsletter, compte-rendu, lettre aux adhérents, post réseaux sociaux). Vous gardez la main, l'IA fait le brouillon. **[Voir toutes les fonctionnalités](/fonctionnalites)**.\n",
    'gestion' => "\n\n> ### 💡 Comment Assokit centralise tout ça\n>\n> **Le problème** : multiplier les outils (tableur, mailing, agenda, compta) crée des frictions, des erreurs de saisie, et un coût caché en temps perdu.\n>\n> **Avec [Assokit](/fonctionnalites)**, un seul outil pour adhérents, projets, communications, factures et stats. Conçu en France pour les assos et TPE françaises. **[Démarrez gratuitement](/tarifs)** — plan gratuit, sans engagement, sans carte bancaire.\n",
];

// ============================================================
// CTA FINAL (pied de chaque article)
// ============================================================
const CTA_BLOC = <<<MD


---

## 🚀 Prêt à passer à l'action ?

**[Assokit](https://assokit.fr/)** est le logiciel français pensé pour simplifier la gestion des associations loi 1901 et des TPE. Conçu et hébergé à Évry (France), il centralise dans un seul outil :

- 👥 **Gestion des adhérents** et des cotisations
- 💰 **Comptabilité** et facturation
- 📣 **Communication** avec 19 outils IA intégrés
- 📋 **Projets, événements, planning**
- 📊 **Tableaux de bord** en temps réel

🎁 **[Découvrir toutes les fonctionnalités](https://assokit.fr/fonctionnalites)** · 💸 **[Voir les tarifs](https://assokit.fr/tarifs)** · 📞 **[Nous contacter](https://assokit.fr/contact)** · 🔐 **[Se connecter](https://assokit.fr/connexion)** · 🇫🇷 **[À propos d'Assokit](https://assokit.fr/a-propos)**

> *Article rédigé par l'équipe Assokit · Logiciel français pour assos et TPE · Hébergement à Évry (91)*
MD;

// ============================================================
// SLUG (depuis un titre)
// ============================================================
function generate_slug(string $title): string
{
    $slug = mb_strtolower($title, 'UTF-8');

    // Translittération des accents
    $accents = [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y',
        'ñ'=>'n','ç'=>'c',
        '€'=>'euros','&'=>'et',
    ];
    $slug = strtr($slug, $accents);

    // Remplacer tout ce qui n'est pas alphanumérique par un tiret
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    // Limiter la longueur
    if (mb_strlen($slug) > 80) {
        $slug = mb_substr($slug, 0, 80);
        $slug = preg_replace('/-[^-]*$/', '', $slug);
    }

    return $slug ?: 'article-' . substr(md5((string) microtime(true)), 0, 8);
}

function ensure_unique_slug(string $base_slug): string
{
    $slug = $base_slug;
    $i = 2;
    while (DB::fetch('SELECT id FROM asso_blog_articles WHERE slug = ?', [$slug])) {
        $slug = $base_slug . '-' . $i;
        $i++;
        if ($i > 100) {
            $slug = $base_slug . '-' . substr(md5((string) microtime(true)), 0, 6);
            break;
        }
    }
    return $slug;
}

// ============================================================
// READING TIME (200 mots/min)
// ============================================================
function reading_time(string $text): int
{
    $words = str_word_count(strip_tags($text));
    return max(1, (int) round($words / 200));
}

function word_count(string $text): int
{
    return str_word_count(strip_tags($text));
}

// ============================================================
// ARTICLES LIÉS (3-4 articles de la même catégorie)
// ============================================================
function get_related_articles(string $category, ?string $exclude_slug = null, int $limit = 4): array
{
    $sql = 'SELECT slug, title FROM asso_blog_articles
            WHERE category = ? AND is_published = 1';
    $params = [$category];
    if ($exclude_slug) {
        $sql .= ' AND slug != ?';
        $params[] = $exclude_slug;
    }
    $sql .= ' ORDER BY RAND() LIMIT ' . (int) $limit;
    return DB::fetchAll($sql, $params);
}

/**
 * Candidats au maillage interne fournis à l'IA : articles publiés de la même
 * catégorie en priorité, complétés par d'autres catégories si besoin.
 * Sert à générer des liens internes contextuels vers de vraies URL existantes.
 */
function get_internal_link_candidates(string $category, ?string $exclude_slug = null, int $limit = 8): array
{
    $out = [];
    // Priorité : même catégorie
    $sql = 'SELECT slug, title, category FROM asso_blog_articles WHERE is_published = 1 AND category = ?';
    $params = [$category];
    if ($exclude_slug) { $sql .= ' AND slug != ?'; $params[] = $exclude_slug; }
    $sql .= ' ORDER BY published_at DESC LIMIT ' . (int) $limit;
    try { $out = DB::fetchAll($sql, $params) ?: []; } catch (Throwable $e) { $out = []; }

    // Complément : autres catégories (transversal), si on n'a pas assez de candidats
    if (count($out) < $limit) {
        $need = $limit - count($out);
        $sql2 = 'SELECT slug, title, category FROM asso_blog_articles WHERE is_published = 1 AND category != ?';
        $params2 = [$category];
        if ($exclude_slug) { $sql2 .= ' AND slug != ?'; $params2[] = $exclude_slug; }
        $sql2 .= ' ORDER BY published_at DESC LIMIT ' . (int) $need;
        try { $out = array_merge($out, DB::fetchAll($sql2, $params2) ?: []); } catch (Throwable $e) {}
    }
    return $out;
}

function build_related_section(string $category, ?string $exclude_slug = null): string
{
    $related = get_related_articles($category, $exclude_slug, 4);
    if (count($related) === 0) {
        return '';
    }
    $md = "\n\n---\n\n## 📚 Articles liés à lire ensemble\n\n";
    foreach ($related as $r) {
        $md .= "- [{$r['title']}](/blog/{$r['slug']})\n";
    }
    return $md;
}

// ============================================================
// ENRICHISSEMENT D'UN ARTICLE (bloc Assokit + articles liés + CTA)
// ============================================================
function enrich_article_content(string $content_md, string $category, ?string $current_slug = null): string
{
    // 1. Bloc Assokit avant la FAQ
    $bloc = ASSOKIT_BLOCS[$category] ?? '';
    if ($bloc !== '' && strpos($content_md, '## FAQ') !== false) {
        $content_md = str_replace('## FAQ', $bloc . "\n## FAQ", $content_md);
    } elseif ($bloc !== '') {
        $content_md .= $bloc;
    }

    // 2. Articles liés (uniquement s'ils ne sont pas déjà présents)
    if (strpos($content_md, '📚 Articles liés') === false) {
        $content_md .= build_related_section($category, $current_slug);
    }

    // 3. CTA final (uniquement s'il n'est pas déjà présent)
    if (strpos($content_md, 'Prêt à passer à l\'action') === false) {
        $content_md .= CTA_BLOC;
    }

    return $content_md;
}

// ============================================================
// EXTRACTION DES MÉTADONNÉES depuis un Markdown
// ============================================================
function extract_h1_title(string $markdown): ?string
{
    if (preg_match('/^#\s+(.+?)\s*$/m', $markdown, $m)) {
        return trim($m[1]);
    }
    return null;
}

function generate_excerpt(string $markdown, int $max_chars = 200): string
{
    // Retire le H1
    $text = preg_replace('/^#\s+.+$/m', '', $markdown);
    // Retire les en-têtes
    $text = preg_replace('/^#{1,6}\s+.+$/m', '', $text);
    // Retire les liens markdown : [texte](url) → texte
    $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
    // Retire le gras et l'italique
    $text = preg_replace('/[*_]{1,3}([^*_]+)[*_]{1,3}/', '$1', $text);
    // Retire les blockquotes
    $text = preg_replace('/^>\s*.+$/m', '', $text);
    // Retire les listes
    $text = preg_replace('/^[-*+]\s+/m', '', $text);
    // Compresse les espaces
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if (mb_strlen($text) <= $max_chars) {
        return $text;
    }
    $excerpt = mb_substr($text, 0, $max_chars);
    $excerpt = preg_replace('/\s\S*$/', '', $excerpt);
    return $excerpt . '…';
}

function generate_meta_title(string $title, int $max = 60): string
{
    if (mb_strlen($title) <= $max) {
        return $title;
    }
    $short = mb_substr($title, 0, $max);
    return preg_replace('/\s\S*$/', '', $short) . '…';
}

function generate_meta_description(string $excerpt, int $max = 160): string
{
    if (mb_strlen($excerpt) <= $max) {
        return $excerpt;
    }
    $short = mb_substr($excerpt, 0, $max);
    return preg_replace('/\s\S*$/', '', $short) . '…';
}

// ============================================================
// SAUVEGARDE D'UN ARTICLE EN BASE
// ============================================================
function save_article(array $article): array
{
    // Validation
    $required = ['title', 'category', 'content_md'];
    foreach ($required as $r) {
        if (empty($article[$r])) {
            throw new InvalidArgumentException("Champ '{$r}' requis");
        }
    }
    if (!in_array($article['category'], CATEGORIES, true)) {
        throw new InvalidArgumentException("Catégorie invalide: {$article['category']}");
    }

    // Slug
    if (empty($article['slug'])) {
        $base = generate_slug($article['title']);
        $article['slug'] = ensure_unique_slug($base);
    }

    // Couleurs depuis catégorie
    $colors = CATEGORY_COLORS[$article['category']];
    $cover_color_from = $article['cover_color_from'] ?? $colors['from'];
    $cover_color_to   = $article['cover_color_to']   ?? $colors['to'];

    // Enrichissement
    $content = enrich_article_content($article['content_md'], $article['category'], $article['slug']);

    // Métadonnées dérivées
    $excerpt        = $article['excerpt']          ?? generate_excerpt($article['content_md']);
    $meta_title     = $article['meta_title']       ?? generate_meta_title($article['title']);
    $meta_desc      = $article['meta_description'] ?? generate_meta_description($excerpt);
    $cover_emoji    = $article['cover_emoji']      ?? '📝';
    $tags           = $article['tags']             ?? '';
    $author         = $article['author']           ?? config_get('default_author', 'L\'équipe Assokit');
    $reading_min    = reading_time($content);
    $is_published   = (int) ($article['is_published'] ?? 1);
    $now            = date('Y-m-d H:i:s');
    $published_at   = $article['published_at'] ?? $now;

    // INSERT or REPLACE
    DB::execute(
        'REPLACE INTO asso_blog_articles
            (slug, title, meta_title, meta_description, excerpt, content_md, category, tags,
             author, cover_emoji, cover_color_from, cover_color_to,
             reading_time_min, is_published, published_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $article['slug'], $article['title'], $meta_title, $meta_desc, $excerpt,
            $content, $article['category'], $tags, $author, $cover_emoji,
            $cover_color_from, $cover_color_to, $reading_min, $is_published,
            $published_at, $now, $now,
        ]
    );

    return [
        'slug'              => $article['slug'],
        'title'             => $article['title'],
        'word_count'        => word_count($content),
        'reading_time_min'  => $reading_min,
    ];
}
