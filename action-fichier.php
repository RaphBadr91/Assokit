<?php
/**
 * ============================================================
 * ASSOKIT — Action : upload de fichiers (v2 multiple)
 * ============================================================
 * Accepte maintenant PLUSIEURS fichiers en une fois via name="files[]".
 * Continue d'accepter "file" pour rétro-compatibilité.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Session expirée.');
}

$project_id = (int)($_POST['project_id'] ?? 0);
if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

$user = current_user();

// Vérification du projet
$stmt = $pdo->prepare("
    SELECT p.id, p.referent_id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $user['org_id']]);
$proj = $stmt->fetch();
if (!$proj) {
    http_response_code(403);
    die('Accès refusé à ce projet.');
}

// =============================================================
// COLLECTER LES FICHIERS À UPLOADER
// =============================================================
$files_to_process = [];

// Cas 1 : multiple files[] (nouveau)
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $count = count($_FILES['files']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $files_to_process[] = [
                'name'     => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size'     => (int)$_FILES['files']['size'][$i],
                'type'     => $_FILES['files']['type'][$i],
            ];
        }
    }
}
// Cas 2 : single file (compatibilité ancien)
elseif (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $files_to_process[] = [
        'name'     => $_FILES['file']['name'],
        'tmp_name' => $_FILES['file']['tmp_name'],
        'size'     => (int)$_FILES['file']['size'],
        'type'     => $_FILES['file']['type'],
    ];
}

if (empty($files_to_process)) {
    header('Location: /projet/' . $project_id . '/fichiers?err=upload');
    exit;
}

// =============================================================
// UPLOAD CHAQUE FICHIER
// =============================================================
$uploaded_count = 0;
$errors = [];
$allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'ppt', 'pptx', 'odt', 'ods'];

// Créer le dossier
$uploads_dir = __DIR__ . '/uploads/projet_' . $project_id;
if (!is_dir($uploads_dir)) {
    if (!mkdir($uploads_dir, 0755, true)) {
        header('Location: /projet/' . $project_id . '/fichiers?err=mkdir');
        exit;
    }
}

foreach ($files_to_process as $file) {
    // Taille max 10 Mo
    if ($file['size'] > 10 * 1024 * 1024) {
        $errors[] = $file['name'] . ' : taille > 10 Mo';
        continue;
    }
    
    // Extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        $errors[] = $file['name'] . ' : extension non autorisée';
        continue;
    }
    
    // Nom de fichier sécurisé
    $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $safe_name = substr($safe_name, 0, 60);
    $stored_name = $safe_name . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $stored_path = $uploads_dir . '/' . $stored_name;
    
    if (!move_uploaded_file($file['tmp_name'], $stored_path)) {
        $errors[] = $file['name'] . ' : échec du déplacement';
        continue;
    }
    
    // Enregistrer en base
    $relative_path = 'uploads/projet_' . $project_id . '/' . $stored_name;
    $pdo->prepare("
        INSERT INTO project_files (project_id, uploaded_by, filename, filepath, filesize_bytes, mime_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $project_id, $user['id'], $file['name'], $relative_path, $file['size'], $file['type']
    ]);
    
    $uploaded_count++;
}

// Flash message
if ($uploaded_count > 0) {
    $msg = "✅ $uploaded_count fichier" . ($uploaded_count > 1 ? 's' : '') . " ajouté" . ($uploaded_count > 1 ? 's' : '');
    if (!empty($errors)) {
        $msg .= " · ⚠️ " . count($errors) . " échec" . (count($errors) > 1 ? 's' : '');
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '⚠️ Aucun fichier uploadé. ' . implode(', ', $errors)];
}

header('Location: /projet/' . $project_id . '/fichiers');
exit;
