<?php
/**
 * ENDPOINT CRON
 * 
 * Appelé quotidiennement pour générer les articles définis dans "Articles par jour".
 * 
 * URL : /admin-blog/cron.php?token=XXXXXX
 * 
 * Sécurité :
 * - Token cron obligatoire (différent du password admin)
 * - Pas de session (donc pas de CSRF, mais token suffit)
 * - Verrou pour éviter les exécutions concurrentes
 */

// Pas de header HTML - réponse texte simple
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/claude.php';
require_once __DIR__ . '/includes/seo-notifier.php';

set_time_limit(900); // 15 minutes max
ignore_user_abort(true);

// --- 1. Vérification du token ---
$provided_token = (string) ($_GET['token'] ?? '');
$expected_token = (string) config_get('cron_token', '');

if ($expected_token === '' || $provided_token === '' || !hash_equals($expected_token, $provided_token)) {
    http_response_code(403);
    admin_log('cron_unauthorized', 'Token invalide ou manquant', 'warning');
    die("403 Unauthorized\n");
}

// --- 2. Vérification que le cron est activé ---
$cron_enabled = (string) config_get('cron_enabled', '0') === '1';
if (!$cron_enabled) {
    echo "Cron désactivé dans les paramètres.\n";
    admin_log('cron_skipped', 'Cron désactivé', 'info');
    exit;
}

// --- 3. Verrou (évite les exécutions concurrentes) ---
$lock_file = sys_get_temp_dir() . '/assokit_admin_cron.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Cron déjà en cours d'exécution.\n";
    admin_log('cron_locked', 'Une autre exécution est en cours', 'warning');
    exit;
}

$start_time = time();
$articles_per_day = max(1, min(10, (int) config_get('articles_per_day', 3)));
$generated = [];
$errors = [];
$urls_to_notify = [];

echo "=== Cron Assokit Blog · " . date('Y-m-d H:i:s') . " ===\n";
echo "Articles à générer : {$articles_per_day}\n\n";

admin_log('cron_started', "Cible: {$articles_per_day} articles", 'info');

// --- 4. Récupération des sujets en attente ---
$topics = DB::fetchAll(
    "SELECT id, topic_title, category, target_keywords, briefing_extra
     FROM asso_blog_topics
     WHERE status = 'pending'
     ORDER BY priority ASC, created_at ASC
     LIMIT ?",
    [$articles_per_day]
);

if (count($topics) === 0) {
    echo "⚠️  Aucun sujet en attente. Ajoute des sujets via /admin-blog/topics.php.\n";
    admin_log('cron_no_topics', 'File de sujets vide', 'warning');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit;
}

// --- 5. Génération article par article ---
foreach ($topics as $topic) {
    echo "\n--- Article : {$topic['topic_title']} ---\n";
    try {
        $result = generate_article_from_topic(
            $topic['topic_title'],
            $topic['category'],
            $topic['target_keywords'],
            $topic['briefing_extra'],
            ['is_published' => 1]
        );
        DB::execute(
            "UPDATE asso_blog_topics SET status = 'generated', generated_slug = ?, generated_at = NOW() WHERE id = ?",
            [$result['slug'], $topic['id']]
        );
        $generated[] = $result;
        $urls_to_notify[] = SITE_URL . '/blog/' . $result['slug'];
        echo "✅ Créé : {$result['slug']} ({$result['word_count']} mots, {$result['reading_time_min']} min)\n";

        // Pause entre 2 générations pour éviter rate limit
        sleep(3);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        echo "❌ Erreur : {$e->getMessage()}\n";
        admin_log('cron_article_failed', "Topic #{$topic['id']}: " . $e->getMessage(), 'error');
        // On continue avec les autres sujets
    }
}

$duration = time() - $start_time;

// --- 6. Bilan ---
echo "\n=== BILAN ===\n";
echo "Articles générés : " . count($generated) . " / " . count($topics) . "\n";
echo "Erreurs : " . count($errors) . "\n";
echo "Durée : {$duration}s\n";

if (count($errors) > 0) {
    echo "\nErreurs détaillées :\n";
    foreach ($errors as $err) {
        echo "- {$err}\n";
    }
}

// --- 6.5 Notification SEO (IndexNow) ---
if (!empty($urls_to_notify)) {
    echo "\n=== NOTIFICATION SEO (IndexNow) ===\n";
    try {
        $seo = notify_seo_new_article($urls_to_notify);
        if ($seo['indexnow']['ok']) {
            echo "✅ IndexNow : {$seo['indexnow']['count']} URLs notifiées (HTTP {$seo['indexnow']['http']})\n";
        } else {
            echo "❌ IndexNow : {$seo['indexnow']['message']} (HTTP {$seo['indexnow']['http']})\n";
        }
        echo "ℹ️  Google : sitemap.xml revisité automatiquement (déclaré dans GSC)\n";
    } catch (Throwable $e) {
        echo "❌ Erreur notification : {$e->getMessage()}\n";
        admin_log('seo_notify_error', $e->getMessage(), 'error');
    }
}

config_set('last_cron_run', date('Y-m-d H:i:s'));

$summary = sprintf(
    'Cron terminé : %d créés, %d erreurs, %ds',
    count($generated), count($errors), $duration
);
admin_log('cron_completed', $summary, count($errors) > 0 ? 'warning' : 'success');

// --- 7. Libération du verrou ---
flock($lock, LOCK_UN);
fclose($lock);

echo "\nFin du cron.\n";
