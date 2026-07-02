<?php
/**
 * Wrapper API Anthropic Claude
 * Documentation : https://docs.claude.com/en/api/messages
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/article-helper.php';

class ClaudeAPI
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MAX_RETRIES = 2;
    private const TIMEOUT = 120;

    public static function callMessages(string $system_prompt, string $user_prompt, int $max_tokens = 4096): string
    {
        $api_key = trim((string) config_get('claude_api_key', ''));
        if ($api_key === '') {
            throw new RuntimeException('Clé API Claude non configurée. Va dans Paramètres pour la définir.');
        }
        $model = trim((string) config_get('claude_model', 'claude-sonnet-4-5'));
        if ($model === '') {
            $model = 'claude-sonnet-4-5';
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $system_prompt,
            'messages'   => [
                ['role' => 'user', 'content' => $user_prompt],
            ],
        ];

        $last_error = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = self::httpPost(self::API_URL, $payload, [
                    'x-api-key: ' . $api_key,
                    'anthropic-version: ' . self::API_VERSION,
                    'content-type: application/json',
                ]);
                return self::extractText($response);
            } catch (Throwable $e) {
                $last_error = $e->getMessage();
                if ($attempt < self::MAX_RETRIES) {
                    sleep(2 * $attempt);
                }
            }
        }
        throw new RuntimeException('Erreur API Claude après ' . self::MAX_RETRIES . ' tentatives : ' . $last_error);
    }

    private static function httpPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("cURL error: {$err}");
        }
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException("HTTP {$http}: " . substr((string) $body, 0, 500));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response');
        }
        if (!empty($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'unknown';
            throw new RuntimeException("Claude error: {$msg}");
        }
        return $decoded;
    }

    private static function extractText(array $response): string
    {
        $content = $response['content'] ?? [];
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new RuntimeException('Réponse Claude vide');
        }
        return trim($text);
    }
}

// ============================================================
// GÉNÉRATION D'UN ARTICLE COMPLET
// ============================================================

function build_system_prompt_for_article(): string
{
    return <<<'PROMPT'
Tu es rédacteur SEO senior pour Assokit, un logiciel SaaS français qui aide les associations loi 1901 et les TPE françaises à se gérer (adhérents, comptabilité, communication, événements). Assokit est conçu et hébergé à Évry (France), RGPD natif, avec 19 outils IA intégrés.

OBJECTIF : produire un article de blog de **700-900 mots**, parfaitement référencé Google, en français, à destination de dirigeants d'association ou de TPE français.

STRUCTURE OBLIGATOIRE :
1. Un titre H1 (# Titre)
2. Une intro de 2-4 lignes commençant par "**Le problème** : ..." puis "**La solution** : ..."
3. 3 à 5 sections H2 (## Sous-titre) avec contenu concret
4. Si pertinent, un ou deux tableaux Markdown ou listes à puces
5. Une section "## FAQ" avec exactement 3 questions (en gras) et leurs réponses (1-3 phrases chacune)

CONSIGNES DE STYLE :
- Style direct, concret, ton chaleureux mais professionnel
- Utilise le tutoiement quand on parle au lecteur ("tu", "ton asso") OU le "vous" — choisis et reste cohérent
- Cite des chiffres et exemples concrets quand possible
- Évite les généralités creuses
- Évite l'auto-promotion d'Assokit dans le corps de l'article (un bloc dédié sera ajouté automatiquement plus tard)
- Pas de meta-commentaire sur l'article lui-même
- Pas d'introduction du type "Voici un article sur..."
- Démarre directement par le titre H1

⚠️ IMPORTANT — FORMAT DE SORTIE :
- Renvoie UNIQUEMENT le Markdown de l'article complet
- Pas de préambule, pas d'épilogue, pas de balises ```
- Pas de "Voici l'article :", commence directement par "# Titre"
- N'ajoute PAS de bloc CTA de fin, PAS de section "Articles liés", PAS de bloc "Comment Assokit..." — ils seront ajoutés automatiquement après

⚠️ MOT-CLÉ SEO :
Le mot-clé principal doit apparaître :
- Dans le H1
- Dans le premier paragraphe
- Au moins 2 fois dans les H2
- 5-8 fois au total (densité naturelle, pas de bourrage)
PROMPT;
}

function build_user_prompt_for_topic(string $topic_title, string $category, ?string $keywords = null, ?string $extra_briefing = null): string
{
    $cat_label = CATEGORY_LABELS[$category] ?? $category;

    $prompt = "Rédige un article de blog SEO sur le sujet suivant :\n\n";
    $prompt .= "**Sujet** : {$topic_title}\n";
    $prompt .= "**Catégorie** : {$cat_label}\n";

    if ($keywords) {
        $prompt .= "**Mots-clés à inclure naturellement** : {$keywords}\n";
    }

    $prompt .= "\nLe public cible est constitué de **dirigeants d'associations loi 1901 et de TPE françaises** (présidents, trésoriers, secrétaires, gérants).\n";
    $prompt .= "L'année courante est **" . date('Y') . "**, donc tes infos doivent être à jour.\n";

    if ($extra_briefing) {
        $prompt .= "\n**Briefing supplémentaire** :\n{$extra_briefing}\n";
    }

    $prompt .= "\nProduis maintenant l'article complet, directement, sans préambule.";

    return $prompt;
}

/**
 * Génère un article complet à partir d'un sujet.
 * Retourne un tableau ['slug', 'title', 'word_count', ...]
 */
function generate_article_from_topic(string $topic_title, string $category, ?string $keywords = null, ?string $extra_briefing = null, array $extra = []): array
{
    if (!in_array($category, CATEGORIES, true)) {
        throw new InvalidArgumentException("Catégorie invalide: {$category}");
    }

    // 1. Rate limiting (sécurité facture)
    enforce_rate_limit();

    // 2. Appel Claude
    $system = build_system_prompt_for_article();
    $user   = build_user_prompt_for_topic($topic_title, $category, $keywords, $extra_briefing);

    admin_log('claude_request', "Génération article: {$topic_title}", 'info');
    $markdown = ClaudeAPI::callMessages($system, $user, 4096);

    // 3. Extraction des métadonnées
    $title = extract_h1_title($markdown);
    if (!$title) {
        $title = $topic_title;
    }

    // 4. Sauvegarde via le helper standard (qui ajoute bloc Assokit + related + CTA)
    $article = [
        'title'        => $title,
        'category'     => $category,
        'content_md'   => $markdown,
        'tags'         => $keywords ?? '',
        'is_published' => 1,
    ];
    $article = array_merge($article, $extra);

    $result = save_article($article);
    admin_log('article_generated', "Article créé: {$result['slug']} ({$result['word_count']} mots)", 'success');
    return $result;
}

// ============================================================
// RATE LIMITING
// ============================================================
function enforce_rate_limit(): void
{
    $hour_count = (int) DB::fetch(
        "SELECT COUNT(*) AS n FROM asso_blog_admin_logs
         WHERE action = 'article_generated' AND status = 'success'
         AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    )['n'];

    if ($hour_count >= MAX_ARTICLES_PER_HOUR) {
        throw new RuntimeException("Limite horaire atteinte ({$hour_count}/" . MAX_ARTICLES_PER_HOUR . " articles cette heure). Réessaie plus tard.");
    }

    $day_count = (int) DB::fetch(
        "SELECT COUNT(*) AS n FROM asso_blog_admin_logs
         WHERE action = 'article_generated' AND status = 'success'
         AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )['n'];

    if ($day_count >= MAX_ARTICLES_PER_DAY) {
        throw new RuntimeException("Limite quotidienne atteinte ({$day_count}/" . MAX_ARTICLES_PER_DAY . " articles aujourd'hui).");
    }
}
