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
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

/** Taille lue dans php.ini (« 8M », « 64M »…) en octets. */
function ak_ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}
function ak_mo(int $bytes): string {
    return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' Mo';
}

// Taille maximale d'un fichier : 10 Mo, ou moins si l'hébergement est réglé
// plus bas (upload_max_filesize) : c'est alors cette limite qu'on annonce.
$max_file_bytes = 10 * 1024 * 1024;
$ini_file_max = ak_ini_bytes('upload_max_filesize');
if ($ini_file_max > 0 && $ini_file_max < $max_file_bytes) $max_file_bytes = $ini_file_max;
$too_big_label = 'trop volumineux (' . ak_mo($max_file_bytes) . ' maximum)';

// Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES (donc aussi
// le jeton CSRF). Ce n'est pas une session expirée : on le dit clairement et
// on revient sur l'onglet Fichiers (project_id passé dans l'URL du formulaire).
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $max_post = ak_ini_bytes('post_max_size');
    $msg = ['type' => 'error', 'message' => '⚠️ Envoi trop volumineux'
        . ($max_post > 0 ? ' (' . ak_mo($max_post) . ' maximum par envoi)' : '')
        . ' : aucun fichier n’a été ajouté. Envoyez les fichiers en plusieurs fois (' . ak_mo($max_file_bytes) . ' maximum par fichier).'];
    $back = (int)($_GET['project_id'] ?? 0);
    if ($back > 0) {
        $_SESSION['flash'] = $msg;              // affiché par l'onglet Fichiers
        header('Location: /projet/' . $back . '/fichiers');
    } else {
        $_SESSION['flash_projets'] = $msg;      // affiché par /projets
        header('Location: /projets');
    }
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

// Suiveur (financeur externe) : lecture seule, pas d'onglet Fichiers, et
// aucun dépôt possible, même sur un projet qu'il suit.
if (($user['role'] ?? '') === 'follower') {
    http_response_code(403);
    die('Accès refusé à ce projet.');
}

// Vérification du projet
$stmt = $pdo->prepare("
    SELECT p.id, p.referent_id, p.archived_at, f.archived_at AS folder_archived_at FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $user['org_id']]);
$proj = $stmt->fetch();
if (!$proj) {
    http_response_code(403);
    die('Accès refusé à ce projet.');
}
// Projet archivé : lecture seule, aucun nouveau fichier
if (!empty($proj['archived_at']) || !empty($proj['folder_archived_at'])) {
    header('Location: /projet/' . $project_id . '/fichiers?error=archived');
    exit;
}

// =============================================================
// COLLECTER LES FICHIERS À UPLOADER
// =============================================================
$files_to_process = [];
$errors = [];

// Fichier refusé par PHP avant même d'arriver ici : on dit lequel et pourquoi
// (un fichier plus gros que upload_max_filesize n'est plus ignoré en silence).
$upload_error_label = function (int $code) use ($too_big_label): ?string {
    switch ($code) {
        case UPLOAD_ERR_OK:         return null;
        case UPLOAD_ERR_NO_FILE:    return null; // champ vide : rien à signaler
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return $too_big_label;
        case UPLOAD_ERR_PARTIAL:    return 'envoi interrompu, réessayez';
        default:                    return 'erreur d’envoi côté serveur';
    }
};

// Cas 1 : multiple files[] (nouveau)
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $count = count($_FILES['files']['name']);
    for ($i = 0; $i < $count; $i++) {
        $code = (int)$_FILES['files']['error'][$i];
        if ($code === UPLOAD_ERR_OK) {
            $files_to_process[] = [
                'name'     => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size'     => (int)$_FILES['files']['size'][$i],
                'type'     => $_FILES['files']['type'][$i],
            ];
        } elseif (($why = $upload_error_label($code)) !== null) {
            $errors[] = $_FILES['files']['name'][$i] . ' : ' . $why;
        }
    }
}
// Cas 2 : single file (compatibilité ancien)
elseif (!empty($_FILES['file'])) {
    $code = (int)$_FILES['file']['error'];
    if ($code === UPLOAD_ERR_OK) {
        $files_to_process[] = [
            'name'     => $_FILES['file']['name'],
            'tmp_name' => $_FILES['file']['tmp_name'],
            'size'     => (int)$_FILES['file']['size'],
            'type'     => $_FILES['file']['type'],
        ];
    } elseif (($why = $upload_error_label($code)) !== null) {
        $errors[] = $_FILES['file']['name'] . ' : ' . $why;
    }
}

if (empty($files_to_process)) {
    if (!empty($errors)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '⚠️ Aucun fichier ajouté · ' . implode(' · ', $errors)];
        header('Location: /projet/' . $project_id . '/fichiers');
    } else {
        header('Location: /projet/' . $project_id . '/fichiers?err=upload');
    }
    exit;
}

// =============================================================
// UPLOAD CHAQUE FICHIER
// =============================================================
$uploaded_count = 0;
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
    // Taille max 10 Mo (ou la limite de l'hébergement si elle est plus basse)
    if ($file['size'] > $max_file_bytes) {
        $errors[] = $file['name'] . ' : ' . $too_big_label;
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
    
    // Type réel lu dans le contenu (jamais celui annoncé par le navigateur, falsifiable)
    $real_mime = 'application/octet-stream';
    try { $real_mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: $real_mime; } catch (Throwable $e) {}
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'], true)
        && !in_array($real_mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true)) {
        $errors[] = $file['name'] . ' : le contenu ne correspond pas à l’extension';
        continue;
    }
    $stored_name = $safe_name . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
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
        $project_id, $user['id'], $file['name'], $relative_path, $file['size'], $real_mime
    ]);
    
    $uploaded_count++;
}

// Flash message
if ($uploaded_count > 0) {
    $msg = "✅ $uploaded_count fichier" . ($uploaded_count > 1 ? 's' : '') . " ajouté" . ($uploaded_count > 1 ? 's' : '');
    if (!empty($errors)) {
        // Lesquels et pourquoi, pour que l'utilisateur sache quoi corriger
        $msg .= " · ⚠️ " . count($errors) . " refusé" . (count($errors) > 1 ? 's' : '') . " : " . implode(' · ', $errors);
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '⚠️ Aucun fichier ajouté · ' . implode(' · ', $errors)];
}

header('Location: /projet/' . $project_id . '/fichiers');
exit;
