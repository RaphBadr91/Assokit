<?php
/**
 * Uninstall AIOSEO Local Business.
 *
 * @since 1.3.10
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load plugin file.
require_once 'aioseo-local-business.php';

// Disable Action Scheduler Queue Runner.
if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
	ActionScheduler_QueueRunner::instance()->unhook_dispatch_async_request();
}

require_once __DIR__ . '/app/LocalBusiness.php';

// Drop our Local Business data.
aioseoLocalBusiness()->uninstall->dropData();