<?php
/**
 * ============================================================
 * ASSOKIT — Restaurer un dossier ou projet archivé
 * ============================================================
 * URL : /archives-restaurer (POST)
 * Admin uniquement. Dans les 30 jours max.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/archive-helper.php';

require_login();

$user = current_user();
$user_id = (int)$user['id'];

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    header('Location: /archives');
    exit;
}

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['folder', 'project'], true)) {
    $_SESSION['flash_archives'] = ['type' => 'error', 'message' => 'Requête invalide.'];
    header('Location: /archives');
    exit;
}

if ($type === 'folder') {
    $result = restore_folder($pdo, $id, $user_id);
    if ($result['ok']) {
        $_SESSION['flash_archives'] = [
            'type' => 'success',
            'message' => 'Dossier restauré avec succès. Il est de nouveau visible dans /projets.',
        ];
    } else {
        $msg = match($result['error'] ?? '') {
            'folder_not_found' => 'Dossier introuvable.',
            'not_archived' => 'Ce dossier n\'est pas archivé.',
            'retention_expired' => 'Délai de restauration dépassé (>' . ARCHIVE_RETENTION_DAYS . ' jours).',
            default => 'Erreur technique : ' . ($result['error'] ?? 'inconnue'),
        };
        $_SESSION['flash_archives'] = ['type' => 'error', 'message' => $msg];
    }
} else {
    $result = restore_project($pdo, $id, $user_id);
    if ($result['ok']) {
        $_SESSION['flash_archives'] = [
            'type' => 'success',
            'message' => 'Projet restauré avec succès.',
        ];
    } else {
        $msg = match($result['error'] ?? '') {
            'project_not_found' => 'Projet introuvable.',
            'not_archived' => 'Ce projet n\'est pas archivé.',
            'folder_still_archived' => 'Le dossier parent est encore archivé. Restaurez d\'abord le dossier.',
            'retention_expired' => 'Délai de restauration dépassé.',
            default => 'Erreur technique.',
        };
        $_SESSION['flash_archives'] = ['type' => 'error', 'message' => $msg];
    }
}

header('Location: /archives');
exit;
