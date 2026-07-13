<?php
/**
 * api/app-founder-blog-bulk.php — Génération EN MASSE d'articles (Fondateur, app).
 * Demande N idées de sujets à Claude (comme le site) puis les met en file
 * (asso_blog_topics, status=pending) : le cron du site rédige ensuite chaque article.
 * POST { theme, count (1..20), category?, csrf }. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$blog = __DIR__ . '/../admin-blog';
if (!is_file($blog . '/config.php')) app_fail(500, 'blog_config', 'Blog non configuré.');
require_once $blog . '/config.php';
require_once $blog . '/includes/db.php';
require_once $blog . '/includes/article-helper.php';
require_once $blog . '/includes/claude.php';

$theme    = trim((string) ($input['theme'] ?? ''));
$count    = max(1, min(20, (int) ($input['count'] ?? 5)));
$category = (string) ($input['category'] ?? '');
if (mb_strlen($theme) < 3) app_fail(400, 'theme', 'Thème trop court.');
if (!class_exists('ClaudeAPI')) app_fail(500, 'engine', 'Moteur IA indisponible.');

$CATS = defined('CATEGORIES') ? CATEGORIES : ['associations', 'tpe', 'comptabilite', 'juridique', 'communication', 'gestion'];
$cats_list = implode(', ', $CATS);
$cat_constraint = ($category !== '' && in_array($category, $CATS, true))
    ? "Utilise EXCLUSIVEMENT la catégorie \"{$category}\" pour toutes les suggestions."
    : "Choisis la catégorie la plus pertinente pour chaque sujet.";
$exclude_block = '';

$system_prompt = <<<PROMPT
Tu es un expert en stratégie de contenu SEO B2B francophone, spécialisé dans les associations loi 1901 et les TPE françaises (chiffre d'affaires < 2M€).

Tu vas proposer {$count} idées d'articles de blog à fort potentiel SEO sur un thème donné par l'utilisateur.

CONTRAINTES DE SORTIE — TRÈS IMPORTANT :
- Tu réponds UNIQUEMENT avec du JSON valide, sans aucun texte avant ou après.
- AUCUN markdown, AUCUN bloc de code, juste le JSON brut.
- Format strict :
{"suggestions":[{"title":"...","category":"...","keywords":"...","briefing":"...","priority":N},...]}

RÈGLES POUR CHAQUE SUGGESTION :
1. title (string, 40-70 caractères) : titre optimisé SEO en français, pas de point final.
2. category (string) : EXACTEMENT une parmi : {$cats_list}. {$cat_constraint}
3. keywords (string, 4-6 mots-clés séparés par virgules).
4. briefing (string, 1-2 phrases, 80-180 caractères) : angle éditorial concret.
5. priority (int, 1-10) : 1=urgent, 10=basse priorité.

DIVERSITÉ : varie les angles (guides, comparatifs, listes, FAQ, cas pratiques, erreurs).
Tu produis EXACTEMENT {$count} suggestions, ni plus ni moins.
PROMPT;

$user_prompt = "Thème : {$theme}\n\nProduis {$count} suggestions de sujets d'articles selon les règles. Réponds UNIQUEMENT en JSON valide.";

@set_time_limit(120);

try {
    $raw = ClaudeAPI::callMessages($system_prompt, $user_prompt, 4096);
    $raw = preg_replace('/^```(?:json)?\s*/m', '', (string) $raw);
    $raw = preg_replace('/\s*```$/m', '', $raw);
    $raw = trim($raw);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
        app_fail(502, 'ia', 'Réponse IA invalide, réessaie.');
    }

    $added = 0;
    foreach ($parsed['suggestions'] as $s) {
        $title = trim((string) ($s['title'] ?? ''));
        if ($title === '') continue;
        $cat = (string) ($s['category'] ?? $category);
        if (!in_array($cat, $CATS, true)) $cat = $category !== '' ? $category : $CATS[0];
        $kw = mb_substr(trim((string) ($s['keywords'] ?? '')), 0, 500);
        $br = mb_substr(trim((string) ($s['briefing'] ?? '')), 0, 1000);
        $pr = (int) ($s['priority'] ?? 5); if ($pr < 1 || $pr > 10) $pr = 5;

        $dup = $pdo->prepare("SELECT id FROM asso_blog_topics WHERE topic_title = ? AND status = 'pending' LIMIT 1");
        $dup->execute([$title]);
        if ($dup->fetchColumn()) continue;

        $pdo->prepare("INSERT INTO asso_blog_topics (topic_title, category, target_keywords, briefing_extra, priority, status, created_at)
                       VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
            ->execute([$title, $cat, $kw, $br, $pr]);
        $added++;
    }

    echo json_encode(['ok' => true, 'added' => $added, 'requested' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-blog-bulk] ' . $e->getMessage());
    app_fail(500, 'server', 'Génération en masse impossible.');
}
