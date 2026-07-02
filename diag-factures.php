<?php
/**
 * diag-factures.php — Reproduction du crash de mon-asso-factures
 * À supprimer après diagnostic
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>";

echo "=== ÉTAPE 1 : config + helpers ===\n";
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes-layout.php';
    require_once __DIR__ . '/asso-invoice-helpers.php';
    require_once __DIR__ . '/asso-tags-helpers.php';
    require_once __DIR__ . '/asso-search-helpers.php';
    echo "✅ Tous les requires OK\n";
} catch (Throwable $e) {
    die("❌ Crash require : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 2 : require_login() ===\n";
try {
    require_login();
    echo "✅ login OK\n";
} catch (Throwable $e) {
    die("❌ Crash login : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 3 : current_user() ===\n";
try {
    $user = current_user();
    $org_id = (int)$user['org_id'];
    echo "✅ user_id=" . (int)$user['id'] . " org_id=$org_id\n";
} catch (Throwable $e) {
    die("❌ Crash current_user : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 4 : Requête SQL principale ===\n";
try {
    $sql = "SELECT i.*, c.display_name AS client_name, c.email AS client_email
            FROM asso_invoices i
            LEFT JOIN asso_clients c ON c.id = i.client_id
            WHERE i.org_id = :org
            ORDER BY i.id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':org' => $org_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Requête OK, " . count($invoices) . " factures trouvées\n";
} catch (Throwable $e) {
    die("❌ Crash SQL factures : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 5 : Tags par facture ===\n";
try {
    $ids = array_map(fn($i)=>(int)$i['id'], $invoices);
    $tags_by_invoice = ak_tag_get_for_entities($pdo, 'invoice', $ids);
    echo "✅ Tags par facture OK\n";
} catch (Throwable $e) {
    die("❌ Crash tags par facture : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 6 : Liste tags ===\n";
try {
    $all_tags = ak_tag_list($pdo, $org_id);
    echo "✅ " . count($all_tags) . " tags\n";
} catch (Throwable $e) {
    die("❌ Crash ak_tag_list : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 7 : Période dates ===\n";
try {
    [$dateStart, $dateEnd] = ak_search_period_dates('all', null, null);
    echo "✅ Période OK\n";
} catch (Throwable $e) {
    die("❌ Crash période : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 8 : render_head ===\n";
try {
    ob_start();
    render_head('Test');
    $head = ob_get_clean();
    echo "✅ render_head OK (" . strlen($head) . " octets)\n";
} catch (Throwable $e) {
    die("❌ Crash render_head : " . $e->getMessage() . "\n");
}

echo "\n=== ÉTAPE 9 : render_sidebar ===\n";
try {
    ob_start();
    render_sidebar('factures');
    $sidebar = ob_get_clean();
    echo "✅ render_sidebar OK (" . strlen($sidebar) . " octets)\n";
} catch (Throwable $e) {
    die("❌ Crash render_sidebar : " . $e->getMessage() . "\n");
}

echo "\n=== ✅ TOUT OK ===\n";
echo "Si tout est vert, alors le crash de /mon-asso-factures vient d'autre chose.\n";
echo "</pre>";
