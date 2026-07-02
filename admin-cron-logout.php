<?php
/**
 * ============================================================
 * ASSOKIT — admin-cron-logout.php
 * Déconnexion du cockpit CRON (isolée du site principal)
 * ============================================================
 * URL : /admin-cron-logout
 *
 * N'affecte que le cookie ak_cockpit, pas la session Assokit.
 * ============================================================
 */

define('CRON_ADMIN_UI', true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/cron-includes.php';

cron_clear_cockpit_cookie();

header('Location: /admin-cron-login?logged_out=1');
exit;
