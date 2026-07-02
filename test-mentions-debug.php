<?php
/**
 * ============================================================
 * DIAGNOSTIC @MENTIONS
 * ============================================================
 * Va sur https://assokit.fr/test-mentions-debug.php?project_id=1386
 * 
 * Vérifie si tout est bien configuré côté serveur
 * et si le JS est bien chargé côté client.
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/projet-email-helpers.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

echo "<pre style='font-family:monospace; padding:20px; background:#f5f5f5;'>";
echo "<h2>🔬 DIAGNOSTIC @MENTIONS</h2>\n\n";

$project_id = (int)($_GET['project_id'] ?? 0);

if ($project_id <= 0) {
    echo "❌ Manque le paramètre project_id\n";
    echo "Exemple : ?project_id=1386\n";
    echo "</pre>";
    exit;
}

echo "═══════════════════════════════════════════════\n";
echo "1️⃣  PROJET\n";
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
    echo "❌ Projet introuvable\n</pre>";
    exit;
}

echo "  ✅ Projet : {$project['name']}\n";
echo "  📁 Dossier : {$project['folder_name']}\n";
echo "  👤 Référent ID : " . ($project['referent_id'] ?? 'aucun') . "\n";

// =============================================================
// 2. LISTE DES MEMBRES MENTIONNABLES (le dropdown @)
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "2️⃣  MEMBRES QUI APPARAISSENT DANS LE DROPDOWN @\n";
echo "═══════════════════════════════════════════════\n";

$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.email,
           CASE 
               WHEN u.id = :ref THEN 'Référent du projet'
               WHEN u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid1) THEN 'Membre équipe'
               WHEN u.role = 'admin' THEN 'Admin de l\'org'
               ELSE 'Autre'
           END AS source
    FROM users u
    WHERE u.is_active = 1
      AND u.org_id = :org_id
      AND (u.deleted_at IS NULL OR u.deleted_at = '')
      AND (
          u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid2)
          OR u.id = :ref2
          OR u.role = 'admin'
      )
    ORDER BY u.first_name ASC, u.last_name ASC
");
$stmt->execute([
    ':org_id' => $org_id,
    ':pid1' => $project_id,
    ':pid2' => $project_id,
    ':ref' => (int)($project['referent_id'] ?? 0),
    ':ref2' => (int)($project['referent_id'] ?? 0),
]);
$mentionables = $stmt->fetchAll();

if (empty($mentionables)) {
    echo "  ❌ AUCUN membre mentionnable trouvé !\n";
    echo "  → Le dropdown sera VIDE\n";
} else {
    echo "  ✅ " . count($mentionables) . " membres mentionnables :\n";
    foreach ($mentionables as $m) {
        $tag = mb_strtolower($m['first_name']);
        echo "    • @$tag → {$m['first_name']} {$m['last_name']} ({$m['source']})\n";
        echo "      Email: " . (empty($m['email']) ? '❌ VIDE' : $m['email']) . "\n";
    }
}

// =============================================================
// 3. TEST DE DÉTECTION DES MENTIONS
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "3️⃣  TEST DE DÉTECTION D'UNE MENTION\n";
echo "═══════════════════════════════════════════════\n";

if (!empty($mentionables)) {
    $first_member = $mentionables[0];
    $tag = mb_strtolower($first_member['first_name']);
    
    $test_message = "Salut @$tag, peux-tu valider ?";
    echo "  Message test : « $test_message »\n\n";
    
    $detected = ak_extract_mentions($pdo, $project_id, $test_message);
    
    if (empty($detected)) {
        echo "  ❌ AUCUNE mention détectée par ak_extract_mentions()\n";
        echo "     → Bug dans le helper. Le tag '@$tag' n'a pas été reconnu.\n";
    } else {
        echo "  ✅ " . count($detected) . " mention(s) détectée(s) :\n";
        foreach ($detected as $uid) {
            $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            echo "    • User #$uid : {$u['first_name']} {$u['last_name']} ({$u['email']})\n";
        }
    }
}

// =============================================================
// 4. VÉRIFIER ENDPOINT api-messages.php (temps réel)
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "4️⃣  ENDPOINTS TEMPS RÉEL\n";
echo "═══════════════════════════════════════════════\n";

$files_check = [
    'api-messages.php' => 'API polling messages',
    'action-message.php' => 'Endpoint envoi message',
    'projet-email-helpers.php' => 'Helper emails',
];
foreach ($files_check as $f => $desc) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        echo "  ✅ $f existe (" . filesize($path) . " octets) — $desc\n";
    } else {
        echo "  ❌ $f MANQUANT — $desc\n";
    }
}

// =============================================================
// 5. SIMULER UNE @ MENTION ET ENVOYER UN EMAIL
// =============================================================
echo "\n═══════════════════════════════════════════════\n";
echo "5️⃣  TEST D'ENVOI D'EMAIL DE MENTION\n";
echo "═══════════════════════════════════════════════\n";

if (!empty($mentionables)) {
    // Trouver un user avec un email valide qui n'est pas toi
    $target = null;
    foreach ($mentionables as $m) {
        if ((int)$m['id'] !== (int)$current['id'] && !empty($m['email'])) {
            $target = $m;
            break;
        }
    }
    
    if (!$target) {
        echo "  ⚠️  Aucun user mentionnable (avec email, autre que toi) pour tester.\n";
    } else {
        $tag = mb_strtolower($target['first_name']);
        $test_msg = "@$tag — TEST diagnostic mention " . date('H:i:s');
        
        echo "  Cible : {$target['first_name']} {$target['last_name']} ({$target['email']})\n";
        echo "  Tag testé : @$tag\n";
        echo "  Message : « $test_msg »\n\n";
        
        // Insérer un message test
        try {
            $stmt = $pdo->prepare("
                INSERT INTO project_messages (project_id, author_id, content, message_type)
                VALUES (?, ?, ?, 'text')
            ");
            $stmt->execute([$project_id, $current['id'], $test_msg]);
            $msg_id = (int)$pdo->lastInsertId();
            echo "  ✅ Message de test créé (ID #$msg_id)\n";
            
            // Détection de la mention
            $detected = ak_extract_mentions($pdo, $project_id, $test_msg);
            if (in_array((int)$target['id'], $detected, true)) {
                echo "  ✅ Mention bien détectée\n";
                
                // Envoi de l'email
                $sent = ak_email_message_mention($pdo, $msg_id, (int)$target['id']);
                if ($sent === true) {
                    echo "  ✅ <strong style='color:green;'>EMAIL ENVOYÉ avec succès !</strong>\n";
                    echo "  → Vérifie la boîte {$target['email']}\n";
                } else {
                    echo "  ❌ Email NON envoyé (helper a renvoyé false)\n";
                }
            } else {
                echo "  ❌ La mention n'a PAS été détectée par le regex\n";
                echo "     IDs détectés : [" . implode(',', $detected) . "]\n";
                echo "     ID attendu : {$target['id']}\n";
            }
            
            // Nettoyer le message de test
            $pdo->prepare("DELETE FROM project_messages WHERE id = ?")->execute([$msg_id]);
            echo "  🧹 Message de test supprimé de la base\n";
            
        } catch (Throwable $e) {
            echo "  ❌ ERREUR : " . htmlspecialchars($e->getMessage()) . "\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════\n";
echo "🏁 FIN\n";
echo "═══════════════════════════════════════════════\n";
echo "\n⚠️  Supprime ce fichier après le diagnostic.\n";
echo "</pre>";
