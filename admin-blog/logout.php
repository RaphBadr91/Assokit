<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

admin_log('logout', 'Déconnexion fondateur', 'info');
auth_logout();
header('Location: /admin-blog/login.php');
exit;
