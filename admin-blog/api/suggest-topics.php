<?php
/**
 * api/suggest-topics.php
 * Reçoit un thème, demande N suggestions à Claude, retourne JSON.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/article-helper.php';
require_once __DIR__ . '/../includes/claude.php';

header('Content-Type: application/json; charset=utf-8');

send_security_headers();
auth_start_session();

if (!auth_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!csrf_check($input['_csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF invalide']);
    exit;
}

$theme    = trim((string)($input['theme'] ?? ''));
$category = trim((string)($input['category'] ?? ''));
$count    = max(1, min(20, (int)($input['count'] ?? 10)));

if (mb_strlen($theme) < 3) {
    echo json_encode(['success' => false, 'error' => 'Thème trop court (3 caractères minimum)']);
    exit;
}
if (mb_strlen($theme) > 200) {
    echo json_encode(['success' => false, 'error' => 'Thème trop long (200 caractères max)']);
    exit;
}

// Récupérer les sujets déjà existants (pour éviter doublons côté Claude)
$existing_titles = [];
try {
    $existing_titles = DB::fetchAll(
        "SELECT topic_title FROM asso_blog_topics WHERE status IN ('pending', 'generated') ORDER BY id DESC LIMIT 50"
    );
    $existing_titles = array_column($existing_titles, 'topic_title');
} catch (Throwable $e) {}

// Récupérer aussi les titres des articles déjà publiés
$existing_articles = [];
try {
    $existing_articles = DB::fetchAll("SELECT title FROM asso_blog_articles ORDER BY id DESC LIMIT 100");
    $existing_articles = array_column($existing_articles, 'title');
} catch (Throwable $e) {}

$exclude_list = array_slice(array_merge($existing_titles, $existing_articles), 0, 80);

// Construction du prompt
$cats_list = implode('|', CATEGORIES);
$cat_constraint = $category && in_array($category, CATEGORIES, true)
    ? "OBLIGATOIRE : utilise uniquement la catégorie \"{$category}\" pour TOUS les sujets."
    : "Choisis la catégorie la plus pertinente parmi : {$cats_list}.";

$exclude_block = '';
if (!empty($exclude_list)) {
    $exclude_block = "\n\nSUJETS DÉJÀ TRAITÉS (ne pas dupliquer, ne pas faire des variantes proches) :\n- " . implode("\n- ", array_map('strval', $exclude_list));
}

$system_prompt = <<<PROMPT
Tu es un expert en stratégie de contenu SEO B2B francophone, spécialisé dans les associations loi 1901 et les TPE françaises (chiffre d'affaires < 2M€).

Tu vas proposer {$count} idées d'articles de blog à fort potentiel SEO sur un thème donné par l'utilisateur.

CONTRAINTES DE SORTIE — TRÈS IMPORTANT :
- Tu réponds UNIQUEMENT avec du JSON valide, sans aucun texte avant ou après.
- AUCUN markdown, AUCUN ```json, juste le JSON brut.
- Format strict :
{"suggestions":[{"title":"...","category":"...","keywords":"...","briefing":"...","priority":N},...]}

RÈGLES POUR CHAQUE SUGGESTION :
1. **title** (string, 40-70 caractères) : Titre optimisé SEO, en français. Pas de clickbait. Inclut un mot-clé long-tail si possible. Pas de point final. Exemples de bonnes formes : "Comment X en 2026 : guide complet pour Y", "5 erreurs à éviter quand on Z", "X vs Y : que choisir pour W".
2. **category** (string) : EXACTEMENT une parmi : {$cats_list}. {$cat_constraint}
3. **keywords** (string, 4-6 mots-clés séparés par virgules) : mots-clés et expressions SEO pertinents.
4. **briefing** (string, 1-2 phrases, 80-180 caractères) : angle éditorial concret pour orienter la rédaction. Pas générique, doit donner une vraie direction.
5. **priority** (int, 1-10) : 1=urgent (potentiel SEO élevé, peu de concurrence), 10=basse priorité.

DIVERSITÉ :
- Varie les angles : guides, comparatifs, listes, FAQ, cas pratiques, erreurs à éviter.
- Pas plus de 2 sujets se ressemblant fortement.
- Pas de sujets génériques ("Tout savoir sur...") sauf si vraiment pertinent.
{$exclude_block}

Tu produis EXACTEMENT {$count} suggestions, ni plus ni moins.
PROMPT;

$user_prompt = "Thème : {$theme}\n\nProduis {$count} suggestions de sujets d'articles selon les règles. Réponds UNIQUEMENT en JSON valide.";

try {
    $raw = ClaudeAPI::callMessages($system_prompt, $user_prompt, 4096);
    
    // Nettoyer le JSON (enlever ```json ... ``` au cas où Claude désobéit)
    $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
    $raw = preg_replace('/\s*```$/m', '', $raw);
    $raw = trim($raw);
    
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
        throw new RuntimeException('Format JSON invalide reçu de Claude. Réessaie ou affine le thème.');
    }
    
    // Valider et nettoyer chaque suggestion
    $clean = [];
    foreach ($parsed['suggestions'] as $s) {
        if (!is_array($s)) continue;
        $title = trim((string)($s['title'] ?? ''));
        $cat   = trim((string)($s['category'] ?? ''));
        $kw    = trim((string)($s['keywords'] ?? ''));
        $brief = trim((string)($s['briefing'] ?? ''));
        $prio  = (int)($s['priority'] ?? 5);
        
        if ($title === '' || mb_strlen($title) > 255) continue;
        if (!in_array($cat, CATEGORIES, true)) {
            // Fallback : choisir une catégorie par défaut
            $cat = $category && in_array($category, CATEGORIES, true) ? $category : 'associations';
        }
        if ($prio < 1 || $prio > 10) $prio = 5;
        
        $clean[] = [
            'title'    => $title,
            'category' => $cat,
            'keywords' => mb_substr($kw, 0, 500),
            'briefing' => mb_substr($brief, 0, 500),
            'priority' => $prio,
        ];
    }
    
    if (empty($clean)) {
        throw new RuntimeException('Aucune suggestion exploitable. Réessaie avec un thème plus précis.');
    }
    
    admin_log('topics_suggested', "Thème: {$theme} · " . count($clean) . " sujets", 'success');
    
    echo json_encode([
        'success'     => true,
        'suggestions' => $clean,
        'count'       => count($clean),
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    admin_log('topics_suggest_failed', $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
