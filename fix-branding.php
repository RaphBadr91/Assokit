<?php
/**
 * fix-branding.php — Scanner / Remplaceur de "Claude" → "AssoKit IA"
 * 
 * MODE 1 (par défaut) : SCAN — affiche toutes les occurrences sans modifier
 * MODE 2 (?action=replace) : REMPLACE pour de vrai (avec backup automatique)
 * 
 * Usage :
 *   1. Upload ce fichier dans public_html/
 *   2. Va sur https://assokit.fr/fix-branding.php
 *   3. Lis le rapport de scan
 *   4. Si OK, clique "REMPLACER POUR DE VRAI"
 *   5. SUPPRIME ce fichier après usage (sécurité)
 */

// ============================================================
// CONFIG : règles de remplacement (ordre IMPORTANT)
// ============================================================
$REPLACEMENTS = [
    // Casse spécifique d'abord (pour pas écraser après)
    'CLAUDE'         => 'ASSOKIT IA',
    'Claude.ai'      => 'AssoKit IA',
    'Claude AI'      => 'AssoKit IA',
    'Claude API'     => 'AssoKit IA',
    'claude-sonnet-4-6' => 'assokit-ia',
    'claude-sonnet'  => 'assokit-ia',
    'claude-opus'    => 'assokit-ia',
    'claude-haiku'   => 'assokit-ia',
    'Anthropic'      => 'AssoKit',
    'anthropic'      => 'assokit',
    'ANTHROPIC'      => 'ASSOKIT',
    'Sonnet'         => 'IA',
    'Claude'         => 'AssoKit IA',  // EN DERNIER (sinon écrase Claude.ai etc)
    'claude'         => 'assokit-ia',
];

// Extensions à scanner
$EXTENSIONS = ['php', 'html', 'htm', 'js', 'css', 'json', 'md', 'txt'];

// Dossiers à exclure
$EXCLUDE_DIRS = ['node_modules', '.git', 'vendor', 'cache', 'tmp', 'backups'];

// Fichiers à exclure (à ne JAMAIS toucher)
$EXCLUDE_FILES = [
    'fix-branding.php',  // Ce script lui-même !
    'config.php',        // Config sensible (variables environnement)
];

// ============================================================
// SÉCURITÉ : require login
// ============================================================
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (function_exists('require_login')) {
        require_login();
        $user = current_user();
        
        // Vérification multi-critères : fondateur OU super_admin OU email spécifique
        $is_authorized = false;
        if (!empty($user['is_founder'])) $is_authorized = true;
        if (!empty($user['is_super_admin'])) $is_authorized = true;
        if (in_array($user['role'] ?? '', ['super_admin', 'founder', 'admin'], true)) $is_authorized = true;
        if (($user['email'] ?? '') === 'psiwaneraph@gmail.com') $is_authorized = true;  // Email Raphaël
        
        // Pour debug : ajouter ?debug=1 dans l'URL pour voir les infos du user
        if (!empty($_GET['debug'])) {
            echo '<pre style="background:#0f172a;color:#10b981;padding:20px;font-family:monospace;">';
            echo "=== DEBUG USER ===\n";
            echo "is_authorized: " . ($is_authorized ? 'OUI ✅' : 'NON ❌') . "\n\n";
            echo "user data:\n";
            print_r($user);
            echo '</pre>';
            exit;
        }
        
        if (!$is_authorized) {
            die('⛔ Accès refusé. <a href="?debug=1">Cliquez ici pour voir vos infos de compte</a> et envoyez-les à Claude pour fix.');
        }
    }
}

// ============================================================
// MODES
// ============================================================
$action = $_GET['action'] ?? 'scan';
$do_replace = ($action === 'replace' && ($_POST['confirm'] ?? '') === 'YES_REPLACE_NOW');

// ============================================================
// SCAN
// ============================================================
function scan_file($path, $replacements) {
    $content = @file_get_contents($path);
    if ($content === false) return null;
    
    $found = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line_num => $line) {
        foreach ($replacements as $needle => $replace) {
            if (strpos($line, $needle) !== false) {
                $count = substr_count($line, $needle);
                $found[] = [
                    'line' => $line_num + 1,
                    'needle' => $needle,
                    'replace' => $replace,
                    'count' => $count,
                    'preview' => trim(substr($line, 0, 200)),
                ];
            }
        }
    }
    
    return $found;
}

function scan_directory($dir, $extensions, $exclude_dirs, $exclude_files, $replacements) {
    $results = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        
        $path = $file->getPathname();
        $name = $file->getFilename();
        
        // Skip excluded dirs
        $skip = false;
        foreach ($exclude_dirs as $excl) {
            if (strpos($path, "/$excl/") !== false || strpos($path, "\\$excl\\") !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;
        
        // Skip excluded files
        if (in_array($name, $exclude_files)) continue;
        
        // Check extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions)) continue;
        
        // Scan
        $found = scan_file($path, $replacements);
        if (!empty($found)) {
            $results[$path] = $found;
        }
    }
    
    return $results;
}

// ============================================================
// REPLACE
// ============================================================
function replace_in_file($path, $replacements) {
    $content = @file_get_contents($path);
    if ($content === false) return ['error' => 'Lecture impossible'];
    
    $original = $content;
    $count_total = 0;
    
    foreach ($replacements as $needle => $replace) {
        $count = substr_count($content, $needle);
        if ($count > 0) {
            $content = str_replace($needle, $replace, $content);
            $count_total += $count;
        }
    }
    
    if ($count_total === 0) {
        return ['count' => 0];
    }
    
    // Backup avant écriture
    $backup_path = $path . '.bak-' . date('YmdHis');
    if (!@file_put_contents($backup_path, $original)) {
        return ['error' => 'Backup impossible : ' . $path];
    }
    
    // Écriture
    if (!@file_put_contents($path, $content)) {
        return ['error' => 'Écriture impossible : ' . $path];
    }
    
    return ['count' => $count_total, 'backup' => $backup_path];
}

// ============================================================
// EXÉCUTION
// ============================================================
$BASE_DIR = __DIR__;

if ($do_replace) {
    // MODE REPLACE
    $scan_results = scan_directory($BASE_DIR, $EXTENSIONS, $EXCLUDE_DIRS, $EXCLUDE_FILES, $REPLACEMENTS);
    
    $replace_results = [];
    $total_replaced = 0;
    foreach (array_keys($scan_results) as $path) {
        $r = replace_in_file($path, $REPLACEMENTS);
        $replace_results[$path] = $r;
        if (isset($r['count'])) $total_replaced += $r['count'];
    }
} else {
    // MODE SCAN
    $scan_results = scan_directory($BASE_DIR, $EXTENSIONS, $EXCLUDE_DIRS, $EXCLUDE_FILES, $REPLACEMENTS);
}

// Stats
$total_files = count($scan_results);
$total_occurrences = 0;
foreach ($scan_results as $occurrences) {
    foreach ($occurrences as $occ) {
        $total_occurrences += $occ['count'];
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Fix Branding — AssoKit IA</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, sans-serif; background: #0f172a; color: #f1f5f9; padding: 30px; line-height: 1.6; max-width: 1280px; margin: 0 auto; }
h1 { color: #fbbf24; }
h2 { color: #60a5fa; border-bottom: 1px solid #334155; padding-bottom: 8px; margin-top: 32px; }
.box { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin: 16px 0; }
.box.success { border-color: #10b981; background: rgba(16,185,129,0.1); }
.box.warn { border-color: #f59e0b; background: rgba(245,158,11,0.1); }
.box.danger { border-color: #ef4444; background: rgba(239,68,68,0.1); }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
.stat { background: #1a2238; padding: 14px; border-radius: 10px; border: 1px solid #334155; }
.stat-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; }
.stat-value { font-size: 22px; font-weight: 700; color: #f8fafc; margin-top: 4px; }
.file-row { background: #1a2238; padding: 14px; border-radius: 8px; margin: 8px 0; border-left: 3px solid #fbbf24; }
.file-path { font-family: monospace; color: #fbbf24; font-size: 13px; word-break: break-all; }
.file-occ { margin-top: 8px; font-size: 12px; color: #94a3b8; }
.line-num { color: #60a5fa; font-family: monospace; }
.needle { background: rgba(239,68,68,0.2); color: #fca5a5; padding: 1px 4px; border-radius: 3px; font-family: monospace; }
.replace { background: rgba(16,185,129,0.2); color: #6ee7b7; padding: 1px 4px; border-radius: 3px; font-family: monospace; }
.preview { background: #0f172a; padding: 8px; border-radius: 4px; font-family: monospace; font-size: 11px; color: #cbd5e0; margin-top: 4px; overflow-x: auto; white-space: pre; }
.btn { display: inline-block; padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; border: 0; font-family: inherit; }
.btn-danger { background: #ef4444; color: white; }
.btn-success { background: #10b981; color: white; }
.btn-secondary { background: #334155; color: white; }
.replace-rules { font-size: 12px; }
.replace-rules td { padding: 4px 12px; border: 1px solid #334155; }
table { border-collapse: collapse; width: 100%; }
.center { text-align: center; }
</style>
</head>
<body>

<h1>🛠️ Fix Branding — Suppression "Claude" → "AssoKit IA"</h1>

<div class="box <?= $do_replace ? 'success' : 'warn' ?>">
    <?php if ($do_replace): ?>
        ✅ <strong>MODE REMPLACEMENT EXÉCUTÉ</strong> — Voir résultats ci-dessous.
    <?php else: ?>
        🔍 <strong>MODE SCAN (lecture seule)</strong> — Aucune modification effectuée.
    <?php endif; ?>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-label">Fichiers concernés</div>
        <div class="stat-value"><?= $total_files ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Occurrences trouvées</div>
        <div class="stat-value"><?= $total_occurrences ?></div>
    </div>
    <?php if ($do_replace): ?>
    <div class="stat">
        <div class="stat-label">Total remplacé</div>
        <div class="stat-value" style="color: #10b981;"><?= $total_replaced ?></div>
    </div>
    <?php endif; ?>
</div>

<h2>📋 Règles de remplacement</h2>
<table class="replace-rules">
<tr><th>Avant</th><th>→</th><th>Après</th></tr>
<?php foreach ($REPLACEMENTS as $n => $r): ?>
<tr>
    <td><span class="needle"><?= htmlspecialchars($n) ?></span></td>
    <td>→</td>
    <td><span class="replace"><?= htmlspecialchars($r) ?></span></td>
</tr>
<?php endforeach; ?>
</table>

<?php if (!$do_replace && $total_files > 0): ?>
<h2>🚀 Lancer le remplacement</h2>
<div class="box danger">
    <p><strong>⚠️ ATTENTION</strong> : cette action va modifier <?= $total_files ?> fichiers.</p>
    <p>Un backup est créé pour chaque fichier (extension <code>.bak-YYYYMMDDHHIISS</code>) au cas où il faudrait revenir en arrière.</p>
    <form method="POST" action="?action=replace" onsubmit="return confirm('Confirmer le remplacement de <?= $total_occurrences ?> occurrences dans <?= $total_files ?> fichiers ?\n\nDes backups seront créés.');">
        <input type="hidden" name="confirm" value="YES_REPLACE_NOW">
        <button type="submit" class="btn btn-danger">🚀 REMPLACER POUR DE VRAI</button>
    </form>
</div>
<?php elseif ($do_replace): ?>
<h2>✅ Remplacement effectué</h2>
<div class="box success">
    <p>✅ <strong><?= $total_replaced ?> occurrences</strong> remplacées dans <strong><?= $total_files ?> fichiers</strong>.</p>
    <p>📁 Backups créés (extension <code>.bak-*</code>) — tu peux les supprimer si tout est OK.</p>
    <p class="center" style="margin-top: 20px;">
        <a href="?action=scan" class="btn btn-secondary">🔍 Re-scanner pour vérifier</a>
    </p>
</div>
<?php endif; ?>

<h2>📂 Détails par fichier</h2>

<?php if (empty($scan_results)): ?>
    <div class="box success">
        <p style="font-size: 18px; text-align: center;">🎉 Aucune occurrence trouvée — branding propre !</p>
    </div>
<?php else: ?>
    <?php foreach ($scan_results as $path => $occurrences): ?>
        <div class="file-row">
            <div class="file-path">📄 <?= htmlspecialchars(str_replace($BASE_DIR . '/', '', $path)) ?></div>
            <?php if ($do_replace && isset($replace_results[$path])): ?>
                <div class="file-occ" style="color: #10b981; font-weight: 700;">
                    ✅ <?= $replace_results[$path]['count'] ?? 0 ?> remplacements effectués
                    <?php if (!empty($replace_results[$path]['error'])): ?>
                        <span style="color: #ef4444;">— ❌ <?= htmlspecialchars($replace_results[$path]['error']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php foreach ($occurrences as $occ): ?>
                <div class="file-occ">
                    <span class="line-num">L<?= $occ['line'] ?></span> · 
                    <span class="needle"><?= htmlspecialchars($occ['needle']) ?></span> 
                    → 
                    <span class="replace"><?= htmlspecialchars($occ['replace']) ?></span>
                    <?= $occ['count'] > 1 ? "× {$occ['count']}" : '' ?>
                    <div class="preview"><?= htmlspecialchars($occ['preview']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>🛡️ Sécurité</h2>
<div class="box danger">
    <p><strong>⚠️ Une fois le branding fixé, SUPPRIME ce fichier <code>fix-branding.php</code></strong> du serveur.</p>
    <p>Ce script peut modifier des fichiers source — il ne doit pas rester accessible publiquement.</p>
    <p>Pour supprimer les backups : <code>find public_html -name "*.bak-*" -delete</code></p>
</div>

</body>
</html>
