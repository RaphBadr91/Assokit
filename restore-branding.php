<?php
/**
 * restore-branding.php — RESTAURATION des fichiers depuis les backups .bak-*
 * 
 * Le script précédent (fix-branding.php) a fait des remplacements 
 * trop agressifs incluant les plugins / API.
 * 
 * Ce script :
 *  1. Trouve tous les fichiers .bak-* créés par fix-branding.php
 *  2. Restaure le fichier original depuis le backup
 *  3. Supprime le fichier .bak-* après restauration
 * 
 * Usage : 
 *   - Mode SCAN (défaut) : juste lister les backups trouvés
 *   - Mode RESTORE (?action=restore) : restaure pour de vrai
 */

// ============================================================
// SÉCURITÉ : require login
// ============================================================
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (function_exists('require_login')) {
        require_login();
        $user = current_user();
        
        $is_authorized = false;
        if (!empty($user['is_founder'])) $is_authorized = true;
        if (!empty($user['is_super_admin'])) $is_authorized = true;
        if (in_array($user['role'] ?? '', ['super_admin', 'founder', 'admin'], true)) $is_authorized = true;
        if (($user['email'] ?? '') === 'psiwaneraph@gmail.com') $is_authorized = true;
        
        if (!$is_authorized) {
            die('⛔ Accès refusé.');
        }
    }
}

// ============================================================
// SCAN : trouver tous les .bak-*
// ============================================================
$BASE_DIR = __DIR__;
$backups = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($BASE_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    
    $path = $file->getPathname();
    $name = $file->getFilename();
    
    // Détection des backups : *.bak-YYYYMMDDHHIISS
    if (preg_match('/\.bak-\d{14}$/', $name)) {
        // Le fichier original c'est le path sans le suffixe .bak-*
        $original = preg_replace('/\.bak-\d{14}$/', '', $path);
        $backups[] = [
            'backup' => $path,
            'original' => $original,
            'size' => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
}

// Tri par date (plus récent en premier)
usort($backups, fn($a, $b) => $b['mtime'] - $a['mtime']);

// ============================================================
// MODE RESTORE
// ============================================================
$action = $_GET['action'] ?? 'scan';
$do_restore = ($action === 'restore' && ($_POST['confirm'] ?? '') === 'YES_RESTORE_NOW');

$restore_results = [];
if ($do_restore) {
    foreach ($backups as $b) {
        $bak = $b['backup'];
        $orig = $b['original'];
        
        // Lire le contenu du backup
        $content = @file_get_contents($bak);
        if ($content === false) {
            $restore_results[$bak] = ['error' => 'Lecture backup impossible'];
            continue;
        }
        
        // Écrire dans le fichier original (écrase)
        if (!@file_put_contents($orig, $content)) {
            $restore_results[$bak] = ['error' => 'Écriture original impossible'];
            continue;
        }
        
        // Supprimer le backup
        if (!@unlink($bak)) {
            $restore_results[$bak] = ['warn' => 'Restauré mais backup non supprimé'];
            continue;
        }
        
        $restore_results[$bak] = ['ok' => true];
    }
}

$total = count($backups);
$nb_ok = 0;
$nb_err = 0;
foreach ($restore_results as $r) {
    if (!empty($r['ok'])) $nb_ok++;
    if (!empty($r['error'])) $nb_err++;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Restaurer fichiers — AssoKit</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, sans-serif; background: #0f172a; color: #f1f5f9; padding: 30px; line-height: 1.6; max-width: 1280px; margin: 0 auto; }
h1 { color: #fbbf24; }
h2 { color: #60a5fa; border-bottom: 1px solid #334155; padding-bottom: 8px; margin-top: 32px; }
.box { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin: 16px 0; }
.box.success { border-color: #10b981; background: rgba(16,185,129,0.1); }
.box.warn { border-color: #f59e0b; background: rgba(245,158,11,0.1); }
.box.danger { border-color: #ef4444; background: rgba(239,68,68,0.1); }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.stat { background: #1a2238; padding: 14px; border-radius: 10px; border: 1px solid #334155; }
.stat-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; }
.stat-value { font-size: 22px; font-weight: 700; color: #f8fafc; margin-top: 4px; }
.btn { display: inline-block; padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; border: 0; font-family: inherit; }
.btn-success { background: #10b981; color: white; }
.btn-danger { background: #ef4444; color: white; }
.file-row { background: #1a2238; padding: 12px 16px; border-radius: 8px; margin: 6px 0; border-left: 3px solid #fbbf24; font-family: monospace; font-size: 12px; }
.file-row.ok { border-left-color: #10b981; }
.file-row.err { border-left-color: #ef4444; }
.file-path { color: #cbd5e0; word-break: break-all; }
.size { color: #64748b; font-size: 11px; }
</style>
</head>
<body>

<h1>🛟 RESTAURATION des fichiers depuis backups</h1>

<?php if ($do_restore): ?>
<div class="box success">
    ✅ <strong>RESTAURATION TERMINÉE</strong> — <?= $nb_ok ?> fichiers restaurés, <?= $nb_err ?> erreurs.
</div>
<?php else: ?>
<div class="box warn">
    🔍 <strong>MODE SCAN</strong> — Aucune action effectuée. Voici les backups trouvés.
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="stat-label">Backups trouvés</div>
        <div class="stat-value"><?= $total ?></div>
    </div>
    <?php if ($do_restore): ?>
    <div class="stat">
        <div class="stat-label">Restaurés ✅</div>
        <div class="stat-value" style="color: #10b981;"><?= $nb_ok ?></div>
    </div>
    <?php if ($nb_err > 0): ?>
    <div class="stat">
        <div class="stat-label">Erreurs ❌</div>
        <div class="stat-value" style="color: #ef4444;"><?= $nb_err ?></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($total > 0 && !$do_restore): ?>
<h2>🚀 Lancer la restauration</h2>
<div class="box danger">
    <p><strong>⚠️ ATTENTION :</strong> cette action va :</p>
    <ul>
        <li>📥 Restaurer les <?= $total ?> fichiers depuis leurs backups (écrase les versions actuelles modifiées)</li>
        <li>🗑️ Supprimer les fichiers <code>.bak-*</code> après restauration</li>
    </ul>
    <p>Le résultat : ton serveur revient à l'état AVANT le script fix-branding.php.</p>
    <form method="POST" action="?action=restore" onsubmit="return confirm('Confirmer la restauration de <?= $total ?> fichiers ?\n\nLes versions modifiées seront ÉCRASÉES par les backups.');">
        <input type="hidden" name="confirm" value="YES_RESTORE_NOW">
        <button type="submit" class="btn btn-danger">🛟 RESTAURER POUR DE VRAI</button>
    </form>
</div>
<?php endif; ?>

<?php if ($total === 0 && !$do_restore): ?>
<div class="box success">
    <p style="font-size: 18px; text-align: center;">🤔 Aucun fichier <code>.bak-*</code> trouvé.</p>
    <p>Soit la restauration a déjà été effectuée, soit aucun backup n'existe.</p>
</div>
<?php endif; ?>

<h2>📂 Liste des backups</h2>
<?php if (empty($backups)): ?>
    <p>Aucun backup à afficher.</p>
<?php else: ?>
    <?php foreach ($backups as $b):
        $rel_orig = str_replace($BASE_DIR . '/', '', $b['original']);
        $rel_bak = str_replace($BASE_DIR . '/', '', $b['backup']);
        $r = $restore_results[$b['backup']] ?? null;
        $cls = '';
        $status = '';
        if ($do_restore) {
            if (!empty($r['ok'])) { $cls = 'ok'; $status = '✅ Restauré'; }
            elseif (!empty($r['error'])) { $cls = 'err'; $status = '❌ ' . $r['error']; }
            else { $status = '⏳ ' . ($r['warn'] ?? 'En attente'); }
        }
    ?>
    <div class="file-row <?= $cls ?>">
        <div class="file-path">📄 <strong><?= htmlspecialchars($rel_orig) ?></strong></div>
        <div class="size">↩️ depuis : <?= htmlspecialchars($rel_bak) ?> · <?= number_format($b['size'] / 1024, 1) ?> Ko</div>
        <?php if ($status): ?>
        <div style="margin-top:6px; color: <?= $cls === 'ok' ? '#10b981' : ($cls === 'err' ? '#ef4444' : '#94a3b8') ?>;"><?= $status ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>🛡️ Sécurité</h2>
<div class="box danger">
    <p><strong>⚠️ Une fois la restauration terminée, SUPPRIME ces 2 fichiers du serveur :</strong></p>
    <ul>
        <li><code>fix-branding.php</code></li>
        <li><code>restore-branding.php</code></li>
    </ul>
</div>

</body>
</html>
