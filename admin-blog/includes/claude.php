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

// ============================================================
// PARAMÈTRES SEO DE GÉNÉRATION (garde-fous)
// ============================================================
const SEO_WORDS_MIN        = 1100;  // en-deçà : article trop léger -> régénération
const SEO_WORDS_TARGET_LO  = 1200;  // fourchette cible communiquée à l'IA
const SEO_WORDS_TARGET_HI  = 1700;
const SEO_META_TITLE_MAX   = 60;    // caractères
const SEO_META_DESC_MIN    = 120;
const SEO_META_DESC_MAX    = 155;
const SEO_MIN_H2           = 4;
const SEO_MIN_FAQ          = 4;

function build_system_prompt_for_article(): string
{
    $wlo = SEO_WORDS_TARGET_LO;
    $whi = SEO_WORDS_TARGET_HI;
    $tmax = SEO_META_TITLE_MAX;
    $dmax = SEO_META_DESC_MAX;
    $minh2 = SEO_MIN_H2;
    $minfaq = SEO_MIN_FAQ;

    return <<<PROMPT
Tu es rédacteur SEO senior pour Assokit, un logiciel SaaS français qui aide les associations loi 1901 et les TPE françaises à se gérer (adhérents, comptabilité, communication, événements). Assokit est conçu et hébergé à Évry (France), RGPD natif, avec 19 outils IA intégrés.

OBJECTIF : produire un article de blog de {$wlo} à {$whi} mots, à l'état de l'art du SEO, en français, pour des dirigeants d'association loi 1901 ou de TPE françaises. L'article doit viser la première page Google sur son mot-clé principal et être éligible au featured snippet et aux rich results FAQ.

FORMAT DE SORTIE — DEUX PARTIES, RIEN D'AUTRE :

PARTIE 1 — un bloc de métadonnées SEO, tout en premier, EXACTEMENT ce format :
---META---
meta_title: <titre SEO de {$tmax} caractères MAXIMUM, contient le mot-clé principal, accrocheur>
meta_description: <description de {$dmax} caractères MAXIMUM, contient le mot-clé principal + un bénéfice concret + une incitation douce ; sans guillemets>
primary_keyword: <le mot-clé principal exact, en minuscules>
---END---

PARTIE 2 — l'article en Markdown, commençant IMMÉDIATEMENT par le H1 (rien entre ---END--- et le #).

STRUCTURE OBLIGATOIRE DE L'ARTICLE :
1. Un titre H1 (# ...) — distinct du meta_title, contenant le mot-clé principal.
2. JUSTE sous le H1 : un encadré "En bref" en blockquote : > **En bref** : ... (40 à 55 mots) qui répond DIRECTEMENT à l'intention de recherche (cible le featured snippet / position zéro).
3. Une intro courte : "**Le problème** : ..." puis "**La solution** : ...".
4. {$minh2} à 6 sections H2 (## ...) au contenu concret ; utilise des H3 (### ...) pour détailler quand c'est pertinent.
5. Au moins UN tableau Markdown OU une liste numérotée actionnable (checklist).
6. Une section "## FAQ" avec {$minfaq} à 6 questions, chacune en gras (**Question … ?**) suivie d'une réponse de 1 à 3 phrases (format optimisé pour le schema FAQPage).

RÈGLES SEO (garde-fous stricts) :
- MOT-CLÉ PRINCIPAL présent dans : le meta_title, le H1, le premier paragraphe, au moins 2 titres H2, et 4 à 8 fois au total (densité naturelle, JAMAIS de bourrage).
- Emploie les mots-clés secondaires, variantes sémantiques et entités liées pour la pertinence thématique.
- FRAÎCHEUR : mentionne l'année en cours quand c'est pertinent ; sur une règle, une loi, un montant ou une date, précise que l'info doit être vérifiée à sa source officielle.
- E-E-A-T : n'invente JAMAIS un chiffre, une loi, un article de loi ou une date. En cas de doute, reste général et renvoie à la source officielle. Sur les sujets fiscaux/juridiques, cite quand c'est pertinent une source d'autorité en lien Markdown (service-public.fr, impots.gouv.fr, associations.gouv.fr, legifrance.gouv.fr).
- MAILLAGE INTERNE : insère 2 à 4 liens internes contextuels UNIQUEMENT vers les articles listés dans le briefing (ancre = expression descriptive riche en mots-clés, jamais "cliquez ici"). N'invente aucune URL.
- LISIBILITÉ : phrases courtes, paragraphes de 2 à 4 lignes, listes à puces. Ton direct, concret, chaleureux mais professionnel. Choisis "vous" OU "tu" et reste cohérent dans tout l'article.

INTERDICTIONS :
- N'ajoute PAS de bloc CTA de fin, PAS de section "Articles liés", PAS de bloc "Comment Assokit…" (ajoutés automatiquement ensuite).
- Pas d'auto-promotion d'Assokit dans le corps de l'article.
- Pas de méta-commentaire ("Voici un article…"), pas de balises de code ```.
- Ne mets rien avant ---META--- ni entre ---END--- et le H1.
PROMPT;
}

function build_user_prompt_for_topic(string $topic_title, string $category, ?string $keywords = null, ?string $extra_briefing = null): string
{
    $cat_label = CATEGORY_LABELS[$category] ?? $category;

    $prompt = "Rédige un article de blog SEO sur le sujet suivant :\n\n";
    $prompt .= "**Sujet** : {$topic_title}\n";
    $prompt .= "**Catégorie** : {$cat_label}\n";

    if ($keywords) {
        $prompt .= "**Mot-clé principal + mots-clés secondaires** : {$keywords}\n";
        $prompt .= "→ Le PREMIER mot-clé de cette liste est le mot-clé principal à optimiser en priorité.\n";
    }

    $prompt .= "\nLe public cible est constitué de **dirigeants d'associations loi 1901 et de TPE françaises** (présidents, trésoriers, secrétaires, gérants).\n";
    $prompt .= "L'année courante est **" . date('Y') . "** : tes informations doivent être à jour et tu peux mentionner l'année quand c'est pertinent.\n";

    // Maillage interne : fournir de vraies URL d'articles existants
    $candidates = get_internal_link_candidates($category, null, 8);
    if (!empty($candidates)) {
        $prompt .= "\n**Articles existants pour le maillage interne** (choisis-en 2 à 4 réellement pertinents, ancre descriptive) :\n";
        foreach ($candidates as $c) {
            $prompt .= "- [{$c['title']}](/blog/{$c['slug']})\n";
        }
    }

    if ($extra_briefing) {
        $prompt .= "\n**Briefing supplémentaire** :\n{$extra_briefing}\n";
    }

    $prompt .= "\nProduis maintenant la PARTIE 1 (bloc ---META---) puis la PARTIE 2 (l'article), directement, sans préambule.";

    return $prompt;
}

// ============================================================
// PARSING DU BLOC MÉTA + SÉPARATION DE L'ARTICLE
// ============================================================
function parse_article_output(string $raw): array
{
    $meta_title = null;
    $meta_description = null;
    $primary_keyword = null;
    $markdown = trim($raw);

    // Filet : retirer d'éventuelles clôtures ```/```markdown
    $markdown = preg_replace('/^```[a-zA-Z]*\s*/', '', $markdown);
    $markdown = preg_replace('/\s*```\s*$/', '', $markdown);

    if (preg_match('/---\s*META\s*---(.*?)---\s*END\s*---/is', $markdown, $m)) {
        $block = $m[1];
        if (preg_match('/meta_title\s*:\s*(.+)/i', $block, $mm)) {
            $meta_title = trim($mm[1]);
        }
        if (preg_match('/meta_description\s*:\s*(.+)/i', $block, $mm)) {
            $meta_description = trim($mm[1]);
        }
        if (preg_match('/primary_keyword\s*:\s*(.+)/i', $block, $mm)) {
            $primary_keyword = trim($mm[1]);
        }
        // Retirer le bloc méta de l'article
        $markdown = trim((string) preg_replace('/^.*?---\s*END\s*---/is', '', $markdown, 1));
    }

    // Nettoyage des guillemets/chevrons résiduels autour des valeurs
    $clean = static function (?string $s): ?string {
        if ($s === null) return null;
        $s = trim($s, " \t\"'<>");
        return $s !== '' ? $s : null;
    };

    return [
        'markdown'         => $markdown,
        'meta_title'       => $clean($meta_title),
        'meta_description' => $clean($meta_description),
        'primary_keyword'  => $clean($primary_keyword),
    ];
}

// ============================================================
// VALIDATION SEO (garde-fou) — erreurs = régénération ; alertes = log
// ============================================================
function validate_article_seo(string $markdown, ?string $keyword): array
{
    $errors = [];
    $warnings = [];

    $title = extract_h1_title($markdown);
    if (!$title) {
        $errors[] = 'H1 (# titre) manquant';
    }

    $wc = word_count($markdown);
    if ($wc < SEO_WORDS_MIN) {
        $errors[] = "Article trop court ({$wc} mots < " . SEO_WORDS_MIN . ')';
    }

    $h2_count = preg_match_all('/^##\s+/m', $markdown);
    if ($h2_count < SEO_MIN_H2) {
        $errors[] = "Trop peu de sections H2 ({$h2_count} < " . SEO_MIN_H2 . ')';
    }

    if (stripos($markdown, '## FAQ') === false) {
        $errors[] = 'Section FAQ manquante';
    } else {
        // Compte les questions en gras sous la FAQ
        $faq_part = substr($markdown, (int) stripos($markdown, '## FAQ'));
        $q = preg_match_all('/\*\*[^*]+\?\*\*/', $faq_part);
        if ($q < SEO_MIN_FAQ) {
            $warnings[] = "FAQ : {$q} question(s) détectée(s) (< " . SEO_MIN_FAQ . ')';
        }
    }

    // Encadré "En bref" (featured snippet)
    if (stripos($markdown, 'En bref') === false) {
        $warnings[] = 'Encadré "En bref" (featured snippet) absent';
    }

    // Présence du mot-clé principal
    if ($keyword) {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $body = mb_strtolower($markdown, 'UTF-8');
        $occ = substr_count($body, $kw);
        if ($occ === 0) {
            $errors[] = "Mot-clé principal \"{$keyword}\" absent de l'article";
        } elseif ($title && mb_stripos($title, $keyword) === false) {
            $warnings[] = "Mot-clé principal absent du H1";
        }
    }

    // Maillage interne
    if (preg_match_all('#\]\(/blog/#', $markdown) < 1) {
        $warnings[] = 'Aucun lien interne /blog/ détecté';
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'word_count' => $wc];
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

    // 2. Appel Claude — avec 1 régénération si les garde-fous SEO échouent
    $system = build_system_prompt_for_article();
    $user   = build_user_prompt_for_topic($topic_title, $category, $keywords, $extra_briefing);

    admin_log('claude_request', "Génération article: {$topic_title}", 'info');

    $parsed = null;
    $seo    = null;
    $max_attempts = 2;
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $u = $user;
        if ($attempt > 1 && $seo) {
            // Régénération ciblée : on rappelle les manquements à corriger
            $u .= "\n\n⚠️ La version précédente a été REFUSÉE pour non-conformité SEO :\n- "
                . implode("\n- ", $seo['errors'])
                . "\nCorrige impérativement ces points et respecte la longueur, le bloc ---META---, l'encadré \"En bref\" et la section FAQ.";
        }

        $raw    = ClaudeAPI::callMessages($system, $u, 8192);
        $parsed = parse_article_output($raw);

        // Mot-clé de contrôle : priorité au primary_keyword de l'IA, sinon 1er mot-clé fourni
        $check_kw = $parsed['primary_keyword'];
        if (!$check_kw && $keywords) {
            $parts = preg_split('/[,;]/', $keywords);
            $check_kw = $parts ? trim((string) $parts[0]) : null;
        }

        $seo = validate_article_seo($parsed['markdown'], $check_kw ?: null);
        if ($seo['ok']) {
            break;
        }
        admin_log('article_seo_retry', "Tentative {$attempt} refusée: " . implode(' | ', $seo['errors']), 'warning');
    }

    $markdown = $parsed['markdown'];

    // 3. Titre H1
    $title = extract_h1_title($markdown) ?: $topic_title;

    // 4. Sauvegarde via le helper standard (qui ajoute bloc Assokit + related + CTA).
    //    On passe les métadonnées SEO produites par l'IA (avec garde-fou de longueur).
    $article = [
        'title'        => $title,
        'category'     => $category,
        'content_md'   => $markdown,
        'tags'         => $keywords ?? '',
        'is_published' => 1,
    ];
    if (!empty($parsed['meta_title'])) {
        $article['meta_title'] = generate_meta_title($parsed['meta_title'], SEO_META_TITLE_MAX);
    }
    if (!empty($parsed['meta_description'])) {
        $article['meta_description'] = generate_meta_description($parsed['meta_description'], SEO_META_DESC_MAX);
        $article['excerpt'] = $article['meta_description'];
    }
    $article = array_merge($article, $extra);

    $result = save_article($article);

    // 5. Journalisation SEO (alertes non bloquantes visibles dans les logs admin)
    $wc = $seo['word_count'] ?? $result['word_count'];
    if (!empty($seo['warnings'])) {
        admin_log('article_seo_warnings', "{$result['slug']} : " . implode(' | ', $seo['warnings']), 'warning');
    }
    $status_seo = ($seo && $seo['ok']) ? 'success' : 'warning';
    admin_log('article_generated', "Article créé: {$result['slug']} ({$wc} mots, SEO " . ($seo && $seo['ok'] ? 'OK' : 'partiel') . ')', $status_seo);
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
