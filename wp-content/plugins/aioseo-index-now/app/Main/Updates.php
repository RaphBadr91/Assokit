<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updater class.
 *
 * @since 1.0.6
 */
class Updates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.6
	 */
	public function __construct() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'aioseo_run_updates', [ $this, 'runUpdates' ], 1000 );
		add_action( 'aioseo_run_updates', [ $this, 'updateLatestVersion' ], 3000 );
	}

	/**
	 * Runs our migrations.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function runUpdates() {
		$lastActiveVersion = aioseoIndexNow()->internalOptions->internal->lastActiveVersion;

		// Don't run updates if the last active version is the same as the current version.
		if ( aioseoIndexNow()->version === $lastActiveVersion ) {
			return;
		}

		// Try to acquire the lock.
		if ( ! aioseo()->core->db->acquireLock( 'aioseo_index_now_run_updates_lock', 0 ) ) {
			// If we couldn't acquire the lock, exit early without doing anything.
			// This means another process is already running updates.
			return;
		}

		if ( version_compare( $lastActiveVersion, '1.0.0', '<' ) ) {
			// Do something here.
		}
	}

	/**
	 * Updates the latest version after all migrations and updates have run.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public function updateLatestVersion() {
		if ( aioseoIndexNow()->internalOptions->internal->lastActiveVersion === aioseoIndexNow()->version ) {
			return;
		}

		aioseoIndexNow()->internalOptions->internal->lastActiveVersion = aioseoIndexNow()->version;

		// Bust the DB cache so we can make sure that everything is fresh.
		aioseo()->core->db->bustCache();
	}
}