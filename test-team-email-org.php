<?php
/**
 * ============================================================
 * DIAGNOSTIC EMAIL — Filtré par TON ORG
 * ============================================================
 * Va sur https://assokit.fr/test-team-email-org.php
 * 
 * Liste les projets et users de TON organisation (Latitude91)
 * et te permet de tester l'envoi.
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/projet-email-helpers.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

echo "<pre style='font-family:monospace; padding:20px; background:#f5f5f5;'>";
echo "<h2>🔬 DIAGNOSTIC EMAIL — TON ORG</h2>\n\n";

// =============================================================
// 1. INFO SUR TON ORG
// =============================================================
$stmt = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch();

echo "═══════════════════════════════════════════════\n";
echo "🏢 TU ES CONNECTÉ SUR : {$org['name']} (ID: {$org['id']})\n";
echo "👤 Toi : {$current['first_name']} {$current['last_name']} (ID: {$current['id']})\n";
echo "═══════════════════════════════════════════════\n\n";

$project_id = (int)($_GET['project_id'] ?? 0);
$user_id = (int)($_GET['user_id'] ?? 0);

if ($project_id <= 0 || $user_id <= 0) {
    echo "❌ Manque les paramètres.\n";
    echo "Usage : ?project_id=X&user_id=Y\n\n";
    
    echo "═══════════════════════════════════════════════\n";
    echo "📋 PROJETS DE TON ASSOCIATION\n";
    echo "═══════════════════════════════════════════════\n";
    
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, f.name AS folder_name
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.archived_at IS NULL
        ORDER BY p.id DESC
        LIMIT 10
    ");
    $stmt->execute([$org_id]);
    foreach ($stmt as $p) {
        echo "  • Projet <strong>#{$p['id']}</strong> : {$p['name']} ({$p['folder_name']})\n";
    }
    
    echo "\n═══════════════════════════════════════════════\n";
    echo "👥 MEMBRES DE TON ASSOCIATION (avec email)\n";
    echo "═══════════════════════════════════════════════\n";
    
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, role
        FROM users
        WHERE org_id = ? AND is_active = 1 
          AND email != '' AND email IS NOT NULL
        ORDER BY first_name ASC
    ");
    $stmt->execute([$org_id]);
    foreach ($stmt as $u) {
        $is_self = ($u['id'] === $current['id']) ? ' ← TOI' : '';
        echo "  • User <strong>#{$u['id']}</strong> : {$u['first_name']} {$u['last_name']} ({$u['email']}) [{$u['role']}]$is_self\n";
    }
    
    echo "\n👉 Choisis un projet ID + un user ID dans <strong>TON ORG</strong>, et relance avec ces paramètres.\n";
    echo "Exemple : ?project_id=1377&user_id=18\n";
    exit;
}

// =============================================================
// 2. VÉRIFIER QUE LE PROJET ET LE USER APPARTIENNENT À TON ORG
// =============================================================
echo "═══════════════════════════════════════════════\n";
echo "ÉTAPE 1 : Vérifier que projet #$project_id est dans TON org\n";
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
if ((int)$project['org_id'] !== $org_id) {
    echo "❌ Ce projet appartient à une AUTRE org (org_id = {$project['org_id']}). Tu es dans org $org_id.\n";
    exit;
}

echo "  ✅ Projet trouvé dans TON org\n";
echo "  📌 {$project['name']} ({$project['folder_name']})\n";
echo "  👤 Référent ID : " . ($project['referent_id'] ?? 'aucun') . "\n";

echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 2 : Vérifier que user #$user_id est dans TON org\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("SELECT id, email, first_name, last_name, is_active, org_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "❌ User introuvable\n";
    exit;
}
if ((int)$user['org_id'] !== $org_id) {
    echo "❌ Ce user appartient à une AUTRE org (org_id = {$user['org_id']}). Tu es dans org $org_id.\n";
    exit;
}

echo "  ✅ User trouvé dans TON org\n";
echo "  📌 {$user['first_name']} {$user['last_name']}\n";
echo "  📧 Email : " . (!empty($user['email']) ? $user['email'] : '❌ VIDE') . "\n";
echo "  🟢 Actif : " . ($user['is_active'] ? 'OUI' : '❌ NON') . "\n";

if (empty($user['email'])) {
    echo "\n❌ PROBLÈME : Pas d'email pour ce user, l'envoi sera skip.\n";
    exit;
}

// =============================================================
// 3. TESTER L'ENVOI DIRECT
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 3 : Test d'envoi avec le helper\n";
echo "═══════════════════════════════════════════════\n";
echo "  Appel : ak_email_project_team_added(\$pdo, $project_id, $user_id, {$current['id']})\n\n";

// Capturer les error_log
$log_file = tempnam(sys_get_temp_dir(), 'phperr_');
$old_log = ini_get('error_log');
ini_set('error_log', $log_file);

try {
    $result = ak_email_project_team_added($pdo, $project_id, $user_id, $current['id']);
    
    if ($result === true) {
        echo "  ✅ <strong style='color:green; font-size:18px;'>EMAIL ENVOYÉ AVEC SUCCÈS !</strong>\n";
        echo "  → Vérifie la boîte {$user['email']}\n";
    } else {
        echo "  ⚠️  La fonction a renvoyé FALSE (sans exception)\n\n";
        
        $logs = file_get_contents($log_file);
        if (!empty($logs)) {
            echo "  📋 Logs PHP capturés :\n";
            echo "  ────────────────────────────────────\n";
            echo "  " . htmlspecialchars(str_replace("\n", "\n  ", trim($logs))) . "\n";
            echo "  ────────────────────────────────────\n";
        } else {
            echo "  Aucun log capturé.\n";
        }
    }
} catch (Throwable $e) {
    echo "  ❌ EXCEPTION : " . htmlspecialchars($e->getMessage()) . "\n";
    echo "  Fichier : " . $e->getFile() . "\n";
    echo "  Ligne   : " . $e->getLine() . "\n";
}

ini_set('error_log', $old_log);
@unlink($log_file);

// =============================================================
// 4. VÉRIFIER L'ÉTAT DE L'ÉQUIPE ACTUELLE
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "ÉTAPE 4 : Équipe actuelle du projet #$project_id\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, pm.role_in_project, pm.joined_at
    FROM project_members pm
    JOIN users u ON pm.user_id = u.id
    WHERE pm.project_id = ?
    ORDER BY pm.joined_at ASC
");
$stmt->execute([$project_id]);
$team = $stmt->fetchAll();

if (empty($team)) {
    echo "  ⚠️  Aucun membre dans l'équipe (pas même le référent)\n";
    echo "  → C'est normal si tu n'as pas encore enregistré l'équipe via /modifier-projet\n";
} else {
    echo "  ✅ " . count($team) . " membre(s) dans l'équipe :\n";
    foreach ($team as $m) {
        $is_target = ((int)$m['id'] === $user_id) ? ' 👈 LE USER TESTÉ' : '';
        echo "    • {$m['first_name']} {$m['last_name']} ({$m['role_in_project']}) — joined: {$m['joined_at']}$is_target\n";
    }
}

echo "\n═══════════════════════════════════════════════\n";
echo "🏁 FIN\n";
echo "═══════════════════════════════════════════════\n";
echo "\n💡 Si l'email est envoyé directement ici mais PAS quand tu fais via /modifier-projet,\n";
echo "   alors le problème est dans action-equipe.php (peut-être que tu re-coches\n";
echo "   un membre qui était déjà là, donc pas considéré comme 'nouveau').\n";

echo "\n⚠️  Supprime ce fichier après le diagnostic.\n";
echo "</pre>";
