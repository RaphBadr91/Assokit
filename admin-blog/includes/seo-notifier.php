<?php
/**
 * seo-notifier.php — Notification automatique des nouveaux articles
 * 
 * MODE 2026 :
 *   1. IndexNow (Bing, Yandex, Naver, Seznam) — indexation en quelques heures
 *   2. Google : découvre tes articles via le sitemap.xml déjà déclaré dans GSC.
 *      Les endpoints google.com/ping et bing.com/ping sont DÉPRÉCIÉS depuis 2024.
 *      Pas besoin de les remplacer : Google revisite ton sitemap automatiquement.
 * 
 * USAGE :
 *   require_once __DIR__ . '/seo-notifier.php';
 *   notify_seo_new_article('https://assokit.fr/blog/mon-nouveau-slug');
 *   notify_seo_inspect_url('https://assokit.fr/blog/mon-slug'); // vérifie présence dans Google
 */

if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://assokit.fr');
}

/**
 * Récupère/génère la clé IndexNow persistée
 */
function seo_get_indexnow_key(): string
{
    $key = trim((string) config_get('indexnow_key', ''));
    if ($key === '' || !preg_match('/^[a-f0-9]{32}$/', $key)) {
        $key = bin2hex(random_bytes(16));
        config_set('indexnow_key', $key);
    }
    return $key;
}

/**
 * Notifier un ou plusieurs nouveaux articles aux moteurs (IndexNow)
 * 
 * @param string|array $urls URL ou liste d'URLs absolues à notifier
 * @return array
 */
function notify_seo_new_article($urls): array
{
    if (is_string($urls)) $urls = [$urls];
    $urls = array_values(array_filter(array_map('trim', $urls)));
    if (empty($urls)) return ['ok' => false, 'error' => 'Aucune URL'];
    
    $results = [
        'urls'       => $urls,
        'count'      => count($urls),
        'indexnow'   => null,
        'google_sc'  => 'Le sitemap.xml est revisité automatiquement par Google. Aucune action requise.',
        'started_at' => date('c'),
    ];
    
    $results['indexnow'] = _seo_submit_indexnow($urls);
    $results['ended_at'] = date('c');
    
    $summary = sprintf(
        '%d URL(s) · IndexNow: %s (HTTP %d)',
        count($urls),
        $results['indexnow']['ok'] ? 'OK' : 'KO',
        $results['indexnow']['http']
    );
    admin_log('seo_notify', $summary . ' · ' . json_encode($urls, JSON_UNESCAPED_SLASHES), $results['indexnow']['ok'] ? 'success' : 'warning');
    
    return $results;
}

/**
 * Notification "globale" : on rappelle aux moteurs IndexNow l'URL du sitemap.
 * Utile si on a fait beaucoup de modifs et qu'on veut un rafraîchissement large.
 */
function notify_seo_sitemap_updated(): array
{
    $sitemap_url = SITE_URL . '/sitemap.xml';
    $home_url    = SITE_URL . '/blog';
    
    // On notifie via IndexNow le sitemap + la page liste — ils suivront les liens
    $result = _seo_submit_indexnow([$sitemap_url, $home_url]);
    
    $results = [
        'sitemap'  => $sitemap_url,
        'indexnow' => $result,
        'note'     => 'Pour Google : le sitemap déclaré dans Search Console est revisité périodiquement. Aucune action manuelle requise.',
        'at'       => date('c'),
    ];
    
    admin_log(
        'seo_sitemap_ping',
        sprintf('IndexNow sitemap: %s (HTTP %d)', $result['ok'] ? 'OK' : 'KO', $result['http']),
        $result['ok'] ? 'success' : 'warning'
    );
    
    return $results;
}

/**
 * Vérifie rapidement la présence d'une URL dans l'index Google via une requête site:
 * (méthode pragmatique sans nécessiter d'API key)
 */
function notify_seo_inspect_url(string $url): array
{
    $query    = 'site:' . $url;
    $endpoint = 'https://www.google.com/search?q=' . urlencode($query);
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AssokitSEOBot/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Méthode heuristique : si le HTML contient l'URL recherchée hors du form, c'est qu'elle est indexée
    // (pas 100% fiable mais donne une indication ; pour un suivi précis, utiliser GSC manuellement)
    $found = (is_string($body) && stripos($body, $url) !== false && stripos($body, 'aucun document') === false);
    
    return [
        'url'     => $url,
        'http'    => $http,
        'in_google' => $found,
        'note'    => 'Méthode heuristique. Pour précision, utilise l\'inspection d\'URL dans Search Console.',
        'gsc_url' => 'https://search.google.com/search-console/inspect?resource_id=' . urlencode(SITE_URL) . '&id=' . urlencode($url),
    ];
}

// ============================================================
// IMPLÉMENTATION INDEXNOW
// ============================================================

function _seo_submit_indexnow(array $urls): array
{
    $key  = seo_get_indexnow_key();
    $host = parse_url(SITE_URL, PHP_URL_HOST);
    
    $payload = [
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => SITE_URL . '/' . $key . '.txt',
        'urlList'     => array_values($urls),
    ];
    
    $endpoint = 'https://api.indexnow.org/indexnow';
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Assokit-SEO-Notifier/2.0',
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    
    // IndexNow renvoie : 200 (OK), 202 (Accepted), 400 (mauvais payload),
    // 403 (clé non validée), 422 (URLs invalides), 429 (rate limit)
    $ok = ($http >= 200 && $http < 300);
    return [
        'ok'      => $ok,
        'http'    => $http,
        'message' => _seo_indexnow_message($http),
        'error'   => $err ?: null,
        'body'    => is_string($body) ? mb_substr($body, 0, 500) : null,
        'count'   => count($urls),
    ];
}

function _seo_indexnow_message(int $http): string
{
    return match (true) {
        $http === 200 => '✅ URLs reçues et acceptées',
        $http === 202 => '✅ URLs acceptées (en cours de traitement)',
        $http === 400 => '❌ Format de requête invalide',
        $http === 403 => '❌ Clé non validée — vérifier que la clé est accessible à la racine',
        $http === 422 => '❌ Une ou plusieurs URLs sont invalides',
        $http === 429 => '⚠️ Trop de requêtes (rate limit). Réessaie dans quelques minutes.',
        default       => "Réponse inattendue (HTTP {$http})",
    };
}

/**
 * Helper : récupère les statistiques de notifications récentes
 */
function seo_recent_notifications(int $limit = 20): array
{
    try {
        return DB::fetchAll(
            "SELECT action, details, status, created_at 
             FROM asso_blog_admin_logs 
             WHERE action IN ('seo_notify', 'seo_sitemap_ping') 
             ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    } catch (Throwable $e) {
        return [];
    }
}
