<?php
/**
 * super-admin-stop-impersonation.php
 * Arrête l'incarnation en cours et redirige vers /super-admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/impersonation-helpers.php';

require_login();

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    exit('Session expirée — rechargez la page.');
}

if (is_impersonating()) {
    stop_impersonation(false);
}

header('Location: /super-admin?impersonation_stopped=1');
exit;
