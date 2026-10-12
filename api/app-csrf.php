<?php
/**
 * api/app-csrf.php — Jeton CSRF pour les formulaires natifs de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
 */
// Session dont le compte a été désactivé ou supprimé : aucun jeton, session fermée, 401 'auth'
require_once __DIR__ . '/_app-read-boot.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode(['ok' => true, 'csrf' => $_SESSION['csrf_token']]);
