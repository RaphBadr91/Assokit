<?php
/**
 * diag-factures-v2.php — Diag SANS output avant le require_login
 */

// Pas de session_start ici, config.php le fera
$_DIAG_OUTPUT = [];

function diag_log($msg) {
    global $_DIAG_OUTPUT;
    $_DIAG_OUTPUT[] = $msg;
}

ini_set('display_errors', 0); // pas d'echo direct
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    diag_log("⚠️ PHP [$errno] $errstr in " . basename($errfile) . ":$errline");
    return true;
});

try {
    diag_log("=== ÉTAPE 1 : config ===");
    require_once __DIR__ . '/config.php';
    diag_log("✅ config OK");

    diag_log("=== ÉTAPE 2 : helpers ===");
    require_once __DIR__ . '/includes-layout.php';
    require_once __DIR__ . '/asso-invoice-helpers.php';
    require_once __DIR__ . '/asso-tags-helpers.php';
    require_once __DIR__ . '/asso-search-helpers.php';
    diag_log("✅ Tous helpers OK");

    diag_log("=== ÉTAPE 3 : Session/Login check (sans redirect) ===");
    diag_log("session_status = " . session_status());
    diag_log("user_id session = " . ($_SESSION['user_id'] ?? 'AUCUN'));

    if (empty($_SESSION['user_id'])) {
        diag_log("❌ PAS DE SESSION USER ! Tu n'es pas connecté.");
        diag_log("→ Connecte-toi sur https://assokit.fr/login puis reviens.");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            diag_log("❌ user_id en session mais pas en BDD : " . $_SESSION['user_id']);
        } else {
            diag_log("✅ user trouvé : id=" . $user['id'] . " org_id=" . $user['org_id'] . " role=" . $user['role']);

            $org_id = (int)$user['org_id'];

            diag_log("=== ÉTAPE 4 : Requête SQL factures ===");
            $sql = "SELECT i.*, c.display_name AS client_name, c.email AS client_email
                    FROM asso_invoices i
                    LEFT JOIN asso_clients c ON c.id = i.client_id
                    WHERE i.org_id = :org
                    ORDER BY i.id DESC LIMIT 500";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':org' => $org_id]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            diag_log("✅ " . count($invoices) . " factures trouvées");

            diag_log("=== ÉTAPE 5 : Tags ===");
            $ids = array_map(fn($i)=>(int)$i['id'], $invoices);
            $tags_by_invoice = ak_tag_get_for_entities($pdo, 'invoice', $ids);
            diag_log("✅ Tags par facture OK");

            $all_tags = ak_tag_list($pdo, $org_id);
            diag_log("✅ " . count($all_tags) . " tags");

            diag_log("=== ÉTAPE 6 : Période ===");
            [$dateStart, $dateEnd] = ak_search_period_dates('all', null, null);
            diag_log("✅ Période OK");

            diag_log("=== ÉTAPE 7 : render_head ===");
            ob_start();
            render_head('Test');
            $head = ob_get_clean();
            diag_log("✅ render_head OK (" . strlen($head) . " octets)");

            diag_log("=== ÉTAPE 8 : render_sidebar ===");
            ob_start();
            render_sidebar('factures');
            $sidebar = ob_get_clean();
            diag_log("✅ render_sidebar OK (" . strlen($sidebar) . " octets)");

            diag_log("\n=== ✅ TOUT FONCTIONNE PARFAITEMENT ===");
        }
    }
} catch (Throwable $e) {
    diag_log("❌ EXCEPTION : " . $e->getMessage());
    diag_log("   File : " . basename($e->getFile()) . ":" . $e->getLine());
    diag_log("   Trace : " . $e->getTraceAsString());
}

// Maintenant on peut afficher
header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><body style='font-family:monospace;'><h1>Diag Factures v2</h1><pre>";
foreach ($_DIAG_OUTPUT as $line) {
    echo htmlspecialchars($line) . "\n";
}
echo "</pre></body></html>";
