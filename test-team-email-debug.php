<?php
/**
 * ============================================================
 * DIAGNOSTIC FLOW AJOUT MEMBRE → EMAIL
 * ============================================================
 * Va sur https://assokit.fr/test-team-email-debug.php?project_id=X&user_id=Y
 * 
 * Ex: https://assokit.fr/test-team-email-debug.php?project_id=1&user_id=18
 * 
 * Ça simule l'envoi d'email à un user pour un projet, étape par étape,
 * et te dit EXACTEMENT où ça bloque.
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/projet-email-helpers.php';

echo "<pre style='font-family:monospace; padding:20px; background:#f5f5f5;'>";
echo "<h2>🔬 DIAGNOSTIC FLOW MEMBRE → EMAIL</h2>\n\n";

$project_id = (int)($_GET['project_id'] ?? 0);
$user_id = (int)($_GET['user_id'] ?? 0);

if ($project_id <= 0 || $user_id <= 0) {
    echo "❌ Manque les paramètres.\n";
    echo "Usage : test-team-email-debug.php?project_id=X&user_id=Y\n\n";
    echo "📋 Voici tes projets et users disponibles :\n\n";
    
    echo "PROJETS :\n";
    $stmt = $pdo->query("SELECT p.id, p.name, f.name AS folder_name FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.archived_at IS NULL ORDER BY p.id DESC LIMIT 10");
    foreach ($stmt as $p) {
        echo "  • Projet #{$p['id']} : {$p['name']} ({$p['folder_name']})\n";
    }
    
    echo "\nUSERS :\n";
    $stmt = $pdo->query("SELECT id, first_name, last_name, email, role FROM users WHERE is_active = 1 ORDER BY id DESC LIMIT 10");
    foreach ($stmt as $u) {
        echo "  • User #{$u['id']} : {$u['first_name']} {$u['last_name']} ({$u['email']}) [{$u['role']}]\n";
    }
    
    echo "\n👉 Choisis un projet ID + un user ID, et relance avec ces paramètres.\n";
    exit;
}

echo "═══════════════════════════════════════════════\n";
echo "ÉTAPE 1 : Charger le projet #$project_id\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.referent_id, f.org_id, f.name AS folder_name
    FROM projects p 
    JOIN folders f ON p.folder_id = f.id 
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    echo "❌ Projet introuvable\n";
    exit;
}

echo "  ✅ Projet trouvé : {$project['name']}\n";
echo "  📁 Dossier : {$project['folder_name']}\n";
echo "  🏢 Org ID : {$project['org_id']}\n";
echo "  👤 Référent ID : " . ($project['referent_id'] ?? 'aucun') . "\n";

echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 2 : Charger le user #$user_id\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("SELECT id, email, first_name, last_name, is_active FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "❌ User introuvable\n";
    exit;
}

echo "  ✅ User trouvé : {$user['first_name']} {$user['last_name']}\n";
echo "  📧 Email : " . (!empty($user['email']) ? $user['email'] : '❌ VIDE') . "\n";
echo "  🟢 Actif : " . ($user['is_active'] ? '✅ OUI' : '❌ NON') . "\n";

if (empty($user['email'])) {
    echo "\n❌ PROBLÈME : Ce user n'a pas d'email, l'envoi sera silencieusement skip.\n";
    exit;
}

if (!$user['is_active']) {
    echo "\n❌ PROBLÈME : User désactivé, l'envoi sera skip.\n";
    exit;
}

echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 3 : Vérifier si déjà dans l'équipe\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("SELECT * FROM project_members WHERE project_id = ? AND user_id = ?");
$stmt->execute([$project_id, $user_id]);
$existing = $stmt->fetch();

if ($existing) {
    echo "  ⚠️  Le user est DÉJÀ dans l'équipe :\n";
    echo "      • role_in_project : {$existing['role_in_project']}\n";
    echo "      • joined_at       : {$existing['joined_at']}\n\n";
    echo "  ⚠️  C'EST PEUT-ÊTRE LE PROBLÈME : si tu décoches puis recoches le membre\n";
    echo "      via /modifier-projet, action-equipe.php fait DELETE + INSERT,\n";
    echo "      mais entre les deux, le user est temporairement absent. Mon code\n";
    echo "      compare la liste 'avant DELETE' et 'après INSERT' pour détecter\n";
    echo "      les nouveaux. Donc en théorie, ça devrait quand même envoyer.\n";
    echo "      \n";
    echo "      Pour forcer le test, on va simuler comme si c'était un nouveau.\n";
} else {
    echo "  ✅ User PAS dans l'équipe (donc 'nouveau' = email serait envoyé)\n";
}

echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 4 : Test d'envoi DIRECT de l'email\n";
echo "═══════════════════════════════════════════════\n";
echo "  Appel : ak_email_project_team_added(\$pdo, $project_id, $user_id, null)\n\n";

// Capturer les error_log pour voir les messages cachés
$log_file = tempnam(sys_get_temp_dir(), 'phperr_');
ini_set('error_log', $log_file);

try {
    $result = ak_email_project_team_added($pdo, $project_id, $user_id, null);
    
    if ($result === true) {
        echo "  ✅ <strong style='color:green;'>EMAIL ENVOYÉ AVEC SUCCÈS !</strong>\n";
        echo "  → Vérifie la boîte {$user['email']}\n";
    } else {
        echo "  ⚠️  La fonction a renvoyé FALSE (sans exception)\n";
        echo "  → Donc une erreur a été silencieusement attrapée\n\n";
        
        // Lire les logs capturés
        $logs = file_get_contents($log_file);
        if (!empty($logs)) {
            echo "  📋 Logs PHP capturés :\n";
            echo "  ────────────────────────────────────\n";
            echo "  " . str_replace("\n", "\n  ", trim($logs)) . "\n";
            echo "  ────────────────────────────────────\n";
        } else {
            echo "  ❌ Aucun log capturé. Le problème est plus subtil.\n";
        }
    }
} catch (Throwable $e) {
    echo "  ❌ EXCEPTION : " . htmlspecialchars($e->getMessage()) . "\n";
    echo "  Fichier : " . $e->getFile() . "\n";
    echo "  Ligne   : " . $e->getLine() . "\n";
}

@unlink($log_file);

echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 5 : Test BAS-NIVEAU (sans le helper)\n";
echo "═══════════════════════════════════════════════\n";
echo "  Appel direct ak_asso_send_resend()...\n\n";

try {
    $sent = ak_asso_send_resend(
        $user['email'],
        '🧪 Test ÉQUIPE projet — ' . date('H:i:s'),
        '<h1>Test direct</h1><p>Si tu reçois ça, l\'envoi marche.</p><p><a href="https://assokit.fr/projet/' . $project_id . '">Voir le projet</a></p>',
        null,
        null,
        'Test AssoKit'
    );
    if ($sent) {
        echo "  ✅ Email envoyé directement\n";
    } else {
        echo "  ⚠️  False sans exception\n";
    }
} catch (Throwable $e) {
    echo "  ❌ ERREUR : " . htmlspecialchars($e->getMessage()) . "\n";
}

echo "\n═══════════════════════════════════════════════\n";
echo "🏁 FIN DU DIAGNOSTIC\n";
echo "═══════════════════════════════════════════════\n";
echo "\n⚠️  Supprime ce fichier quand c'est résolu.\n";
echo "</pre>";
