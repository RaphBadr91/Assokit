<?php
/**
 * fichier-projet.php — Service sécurisé des fichiers de projet (proxy authentifié)
 * ------------------------------------------------------------------
 * Remplace l'accès direct à /uploads/projet_<id>/... (devinable, non contrôlé).
 * Sert un fichier UNIQUEMENT après login + vérification d'appartenance à l'org
 * (via project -> folder -> org_id). Accès par ID de ligne (pas par chemin) :
 * aucun risque de path traversal.
 *
 *   /fichier-projet?type=invoice&id=<project_invoices.id>
 *   /fichier-projet?type=file&id=<project_files.id>
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { http_response_code(403); exit('Accès refusé.'); }

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !in_array($type, ['invoice', 'file'], true)) { http_response_code(400); exit('Requête invalide.'); }

// Récupère chemin + org propriétaire (via folders.org_id), scopé par id de ligne.
if ($type === 'invoice') {
    $st = $pdo->prepare(
        "SELECT pi.file_path AS path, NULL AS filename, NULL AS mime_type, fo.org_id AS org
         FROM project_invoices pi
         JOIN projects p ON p.id = pi.project_id
         JOIN folders fo ON fo.id = p.folder_id
         WHERE pi.id = ? LIMIT 1");
} else {
    $st = $pdo->prepare(
        "SELECT pf.filepath AS path, pf.filename, pf.mime_type, fo.org_id AS org
         FROM project_files pf
         JOIN projects p ON p.id = pf.project_id
         JOIN folders fo ON fo.id = p.folder_id
         WHERE pf.id = ? LIMIT 1");
}
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['path'])) { http_response_code(404); exit('Fichier introuvable.'); }

// Contrôle d'accès : l'utilisateur doit pouvoir voir cette org (gère aussi
// fondateur/super-admin en consultation d'une autre org).
if (!user_can_view_org((int)$row['org'])) { http_response_code(403); exit('Accès refusé.'); }

// Résolution + garde anti-traversal : le fichier doit rester sous /uploads.
$rel  = ltrim((string)$row['path'], '/');
$full = realpath(__DIR__ . '/' . $rel);
$base = realpath(__DIR__ . '/uploads');
if ($full === false || $base === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    http_response_code(404); exit('Fichier introuvable.');
}

// Type MIME
$mime = $row['mime_type'] ?: null;
if (!$mime) {
    try { $fi = new finfo(FILEINFO_MIME_TYPE); $mime = $fi->file($full) ?: null; } catch (Throwable $e) {}
}
if (!$mime) $mime = 'application/octet-stream';

// Nom de téléchargement
$name = $row['filename'] ?: basename($full);
$name = preg_replace('/[^\w.\- ]+/u', '_', $name);

// Affichage inline pour PDF/images (visionnage), pièce jointe sinon (anti-XSS).
$inline = (stripos($mime, 'image/') === 0 && stripos($mime, 'image/svg') !== 0) || $mime === 'application/pdf';
$disp = $inline ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60');
readfile($full);
