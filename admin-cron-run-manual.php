<?php
/**
 * ============================================================
 * ASSOKIT — admin-cron-run-manual.php (v5)
 * ============================================================
 */

define('CRON_ADMIN_UI', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/cron-includes.php';

if (!cron_is_super_admin()) {
    header('Location: /admin-cron-login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin-cron');
    exit;
}

// CSRF
$csrfProvided = $_POST['csrf_token'] ?? '';
$csrfExpected = $_SESSION['csrf_token'] ?? '';
if (empty($csrfExpected) || !hash_equals($csrfExpected, $csrfProvided)) {
    http_response_code(419);
    exit('Session expirée — rechargez la page.');
}

$job = strtolower(trim($_POST['job'] ?? ''));
$validJobs = ['impayes', 'essai', 'renouvellements', 'all'];
if (!in_array($job, $validJobs, true)) {
    header('Location: /admin-cron');
    exit;
}

$token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
if (empty($token)) {
    exit('CRON_TOKEN non configuré dans config.php — impossible de lancer.');
}

// On marque le user cockpit pour tracer dans triggered_by_user_id
$cockpitUser = cron_current_cockpit_user();
if ($cockpitUser) {
    $_SESSION['user']['id'] = (int) $cockpitUser['id']; // Astuce pour que cron.php le récupère
}

$_GET['token']  = $token;
$_GET['job']    = $job;
$_GET['manual'] = '1';

require __DIR__ . '/cron.php';
