<?php
/**
 * ============================================================
 * ASSOKIT — session-debug.php (TEMPORAIRE)
 * ============================================================
 * Affiche la structure de $_SESSION pour identifier où sont
 * stockés les flags is_super_admin / is_founder.
 *
 * À SUPPRIMER après usage.
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// On se connecte à la BDD si besoin pour enrichir le diagnostic
require_once __DIR__ . '/config.php';

echo "<!doctype html><html><head><meta charset='utf-8'><title>Session Debug</title></head><body style='font-family:monospace;padding:20px;background:#f5f5f5;'>";
echo "<h2>🔎 Structure de ta session PHP</h2>";

echo "<h3>1) Contenu brut de \$_SESSION</h3>";
echo "<pre style='background:#fff;padding:15px;border:1px solid #ddd;overflow:auto;'>";

// Masque les valeurs sensibles (tokens, mdp...)
$safe = [];
function redact_sensitive(array $arr): array {
    $out = [];
    $sensitiveKeys = ['password', 'pwd', 'hash', 'token', 'secret', 'key', 'csrf'];
    foreach ($arr as $k => $v) {
        $lower = strtolower((string)$k);
        $isSensitive = false;
        foreach ($sensitiveKeys as $s) {
            if (strpos($lower, $s) !== false) { $isSensitive = true; break; }
        }
        if ($isSensitive && is_string($v)) {
            $out[$k] = '(masqué — ' . strlen($v) . ' caractères)';
        } elseif (is_array($v)) {
            $out[$k] = redact_sensitive($v);
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

$safe = redact_sensitive($_SESSION ?? []);
print_r($safe);
echo "</pre>";

echo "<h3>2) Tests de détection du user actuel</h3>";
echo "<table border='1' cellpadding='8' style='background:#fff;border-collapse:collapse;'>";
echo "<tr><th>Test</th><th>Valeur</th></tr>";

$tests = [
    "isset(\$_SESSION['user_id'])"                 => isset($_SESSION['user_id'])                 ? $_SESSION['user_id']                 : 'NON',
    "isset(\$_SESSION['user']['id'])"              => isset($_SESSION['user']['id'])              ? $_SESSION['user']['id']              : 'NON',
    "isset(\$_SESSION['current_user']['id'])"      => isset($_SESSION['current_user']['id'])      ? $_SESSION['current_user']['id']      : 'NON',
    "isset(\$_SESSION['uid'])"                      => isset($_SESSION['uid'])                      ? $_SESSION['uid']                      : 'NON',
    "isset(\$_SESSION['user']['is_founder'])"      => isset($_SESSION['user']['is_founder'])      ? var_export($_SESSION['user']['is_founder'], true)      : 'NON',
    "isset(\$_SESSION['user']['is_super_admin'])"  => isset($_SESSION['user']['is_super_admin'])  ? var_export($_SESSION['user']['is_super_admin'], true)  : 'NON',
    "isset(\$_SESSION['is_founder'])"              => isset($_SESSION['is_founder'])              ? var_export($_SESSION['is_founder'], true)              : 'NON',
    "isset(\$_SESSION['is_super_admin'])"          => isset($_SESSION['is_super_admin'])          ? var_export($_SESSION['is_super_admin'], true)          : 'NON',
    "isset(\$_SESSION['user']['role'])"            => isset($_SESSION['user']['role'])            ? $_SESSION['user']['role']            : 'NON',
    "isset(\$_SESSION['role'])"                     => isset($_SESSION['role'])                     ? $_SESSION['role']                     : 'NON',
    "isset(\$_SESSION['user']['email'])"           => isset($_SESSION['user']['email'])           ? $_SESSION['user']['email']           : 'NON',
    "isset(\$_SESSION['email'])"                    => isset($_SESSION['email'])                    ? $_SESSION['email']                    : 'NON',
];
foreach ($tests as $k => $v) {
    echo "<tr><td><code>{$k}</code></td><td><strong>" . htmlspecialchars((string)$v) . "</strong></td></tr>";
}
echo "</table>";

echo "<h3>3) Si une fonction current_user() existe dans config.php</h3>";
if (function_exists('current_user')) {
    echo "<pre style='background:#fff;padding:15px;border:1px solid #ddd;'>";
    $cu = current_user();
    if ($cu) {
        $safeCu = is_array($cu) ? redact_sensitive($cu) : $cu;
        print_r($safeCu);
    } else {
        echo "(current_user() retourne null — tu n'es peut-être pas connecté)";
    }
    echo "</pre>";
} else {
    echo "<p>Fonction current_user() non définie.</p>";
}

echo "<h3>4) Vérification BDD du user connecté</h3>";
if (isset($pdo) && $pdo instanceof PDO) {
    // Essaye de trouver l'ID du user courant dans la session
    $uid = null;
    if (isset($_SESSION['user']['id']))        $uid = (int)$_SESSION['user']['id'];
    elseif (isset($_SESSION['user_id']))       $uid = (int)$_SESSION['user_id'];
    elseif (isset($_SESSION['current_user']['id'])) $uid = (int)$_SESSION['current_user']['id'];
    elseif (isset($_SESSION['uid']))           $uid = (int)$_SESSION['uid'];

    if ($uid) {
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, role, is_super_admin, is_founder, is_active FROM users WHERE id = :id");
        $stmt->execute([':id' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "<pre style='background:#fff;padding:15px;border:1px solid #ddd;'>";
            print_r($row);
            echo "</pre>";

            if ((int)$row['is_founder'] !== 1 && (int)$row['is_super_admin'] !== 1) {
                echo "<p style='background:#fee;padding:10px;border-left:4px solid #c00;'>";
                echo "⚠️ <strong>PROBLÈME DÉTECTÉ</strong> : ce user n'a ni <code>is_founder=1</code> ni <code>is_super_admin=1</code> en BDD.<br>";
                echo "C'est pour ça que l'accès est refusé, même si l'UI affiche que tu es connecté comme Fondateur.";
                echo "</p>";
                echo "<p>Correction SQL à lancer dans phpMyAdmin :</p>";
                echo "<pre style='background:#fff;padding:15px;border:1px solid #ddd;'>";
                echo "UPDATE users SET is_founder = 1 WHERE id = " . $uid . ";";
                echo "</pre>";
            } else {
                echo "<p style='background:#efe;padding:10px;border-left:4px solid #080;'>";
                echo "✅ Les flags BDD sont bons. Le problème est ailleurs (structure de session).";
                echo "</p>";
            }
        } else {
            echo "<p>User #{$uid} introuvable en BDD.</p>";
        }
    } else {
        echo "<p style='background:#fee;padding:10px;border-left:4px solid #c00;'>";
        echo "❌ Impossible de trouver l'ID du user connecté dans \$_SESSION. Regarde la section 1 pour voir où il se cache.";
        echo "</p>";
    }
}

echo "<hr><p style='color:#c00;'>⚠️ <strong>Supprime ce fichier après usage !</strong></p>";
echo "</body></html>";
