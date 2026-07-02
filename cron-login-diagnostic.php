<?php
/**
 * ============================================================
 * ASSOKIT — cron-login-diagnostic.php (TEMPORAIRE)
 * ============================================================
 * Affiche TOUTES les erreurs PHP en essayant d'exécuter
 * admin-cron-login.php étape par étape.
 * À SUPPRIMER après usage.
 * ============================================================
 */

// Force l'affichage de toutes les erreurs
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre style='font-family:monospace;font-size:13px;padding:20px;background:#f5f5f5;white-space:pre-wrap;'>";
echo "=== DIAGNOSTIC admin-cron-login.php ===\n\n";

// ÉTAPE 1 : session
echo "1) Session status avant start : " . session_status() . "\n";
if (session_status() !== PHP_SESSION_ACTIVE) {
    $ok = @session_start();
    echo "   session_start() : " . ($ok ? "✅ OK" : "❌ ÉCHEC") . "\n";
}
echo "   Session status après : " . session_status() . "\n\n";

// ÉTAPE 2 : définir la constante
define('CRON_ADMIN_UI', true);
echo "2) Constante CRON_ADMIN_UI définie : ✅\n\n";

// ÉTAPE 3 : inclure cron-includes.php
echo "3) Inclusion cron-includes.php...\n";
try {
    require_once __DIR__ . '/cron-includes.php';
    echo "   ✅ Inclus sans erreur\n\n";
} catch (Throwable $e) {
    echo "   ❌ ERREUR : " . $e->getMessage() . "\n";
    echo "   Fichier : " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit;
}

// ÉTAPE 4 : vérifier que les fonctions existent
echo "4) Fonctions attendues :\n";
$fns = [
    'cron_is_super_admin',
    'cron_verify_cockpit_cookie',
    'cron_issue_cockpit_cookie',
    'cron_clear_cockpit_cookie',
    'cron_current_cockpit_user',
    'cron_cockpit_time_left',
    'cron_cockpit_secret',
    'cron_format_euros',
];
foreach ($fns as $fn) {
    echo "   " . (function_exists($fn) ? "✅" : "❌") . " {$fn}()\n";
}
echo "\n";

// ÉTAPE 5 : tester cron_is_super_admin()
echo "5) Test cron_is_super_admin() :\n";
try {
    $res = cron_is_super_admin();
    echo "   Résultat : " . var_export($res, true) . "\n\n";
} catch (Throwable $e) {
    echo "   ❌ ERREUR : " . $e->getMessage() . "\n";
    echo "   Fichier : " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

// ÉTAPE 6 : tester une insertion dans cron_admin_logins
echo "6) Test INSERT dans cron_admin_logins...\n";
try {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO cron_admin_logins
            (email_attempted, user_id, status, ip_address, user_agent, error_detail, attempted_at)
        VALUES
            (:email, NULL, 'fail_unknown', :ip, :ua, 'Test diagnostic', NOW())
    ");
    $stmt->execute([
        ':email' => 'test-diagnostic@example.com',
        ':ip'    => '127.0.0.1',
        ':ua'    => 'Diagnostic',
    ]);
    echo "   ✅ Insertion OK (id " . $pdo->lastInsertId() . ")\n";
    // On le supprime tout de suite pour ne pas polluer
    $pdo->prepare("DELETE FROM cron_admin_logins WHERE id = ?")->execute([$pdo->lastInsertId()]);
    echo "   ✅ Ligne de test supprimée\n\n";
} catch (Throwable $e) {
    echo "   ❌ ERREUR SQL : " . $e->getMessage() . "\n\n";
}

// ÉTAPE 7 : tester h() / conflit
echo "7) Fonction h() :\n";
if (function_exists('h')) {
    $ref = new ReflectionFunction('h');
    echo "   Définie dans : " . $ref->getFileName() . " ligne " . $ref->getStartLine() . "\n";
    echo "   Test : h('<b>test</b>') = " . h('<b>test</b>') . "\n";
} else {
    echo "   ❌ h() non définie (admin-cron-login.php en déclare une avec function_exists check ok)\n";
}
echo "\n";

// ÉTAPE 8 : inclure admin-cron-login.php en capturant l'erreur
echo "8) Tentative d'inclusion de admin-cron-login.php ...\n";
echo "   (Si ça crashe, l'erreur ci-dessous sera la cause du 500)\n";
echo "   ─────────────────────────────────────────\n";
try {
    // On intercepte la sortie pour l'analyser sans polluer
    ob_start();
    include __DIR__ . '/admin-cron-login.php';
    $output = ob_get_clean();
    echo "   ✅ Inclus sans crash\n";
    echo "   Longueur output : " . strlen($output) . " octets\n";
    if (strlen($output) > 0 && strlen($output) < 200) {
        echo "   Premiers octets : " . htmlspecialchars(substr($output, 0, 200)) . "\n";
    }
} catch (Throwable $e) {
    @ob_end_clean();
    echo "   ❌ ERREUR : " . $e->getMessage() . "\n";
    echo "   Fichier : " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace :\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== FIN DIAGNOSTIC ===\n";
echo "⚠️ SUPPRIME CE FICHIER APRÈS USAGE\n";
echo "</pre>";
