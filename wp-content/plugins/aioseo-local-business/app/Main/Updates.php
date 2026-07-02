<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * Updater class.
 *
 * @since 1.0.1.2
 */
class Updates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.1.2
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
	 * @since 1.0.1.2
	 *
	 * @return void
	 */
	public function runUpdates() {
		$lastActiveVersion = aioseoLocalBusiness()->internalOptions->internal->lastActiveVersion;

		// Don't run updates if the last active version is the same as the current version.
		if ( aioseoLocalBusiness()->version === $lastActiveVersion ) {
			return;
		}

		// Try to acquire the lock.
		if ( ! aioseo()->core->db->acquireLock( 'aioseo_local_business_run_updates_lock', 0 ) ) {
			// If we couldn't acquire the lock, exit early without doing anything.
			// This means another process is already running updates.
			return;
		}

		if ( version_compare( $lastActiveVersion, '1.1.0.2', '<' ) ) {
			$this->fixBusinessType();
		}

		if ( version_compare( $lastActiveVersion, '1.3.5', '<' ) ) {
			$this->fixKoreaCountriesCode();
		}
	}

	/**
	 * Updates the latest version after all migrations and updates have run.
	 *
	 * @since 1.0.1.2
	 *
	 * @return void
	 */
	public function updateLatestVersion() {
		if ( aioseoLocalBusiness()->internalOptions->internal->lastActiveVersion === aioseoLocalBusiness()->version ) {
			return;
		}

		aioseoLocalBusiness()->internalOptions->internal->lastActiveVersion = aioseoLocalBusiness()->version;

		// Bust the DB cache so we can make sure that everything is fresh.
		aioseo()->core->db->bustCache();
	}

	/**
	 * Updates the Business Type if it was previously incorrectly stored as JSON.
	 *
	 * @since 1.0.1.2
	 *
	 * @return void
	 */
	private function fixBusinessType() {
		if ( ! aioseo()->options->has( 'localBusiness' ) ) {
			return;
		}

		$businessType = aioseo()->options->localBusiness->locations->business->businessType;
		if ( is_array( $businessType ) ) {
			aioseo()->options->localBusiness->locations->business->businessType = $businessType['value'];
		}
	}

	/**
	 * Updates country code for Korea on locations
	 *
	 * @since 1.3.5
	 *
	 * @return void
	 */
	private function fixKoreaCountriesCode() {
		// Fix the country code for Korea.
		aioseo()->options->localBusiness->locations->business->address->country = Models\Post::invertKoreaCode( aioseo()->options->localBusiness->locations->business->address->country );

		// Fix multiple locations.
		$locations = aioseoLocalBusiness()->locations->getLocations( [ 'posts_per_page' => -1 ] );
		foreach ( $locations as $location ) {
			$post     = Models\Post::getPost( $location->ID );
			$localSeo = $post->local_seo;

			// Skip if the country is not set.
			if ( ! isset( $localSeo->locations->business->address->country ) ) {
				continue;
			}

			$localSeo->locations->business->address->country = Models\Post::invertKoreaCode( $localSeo->locations->business->address->country );
			$post->local_seo                                 = $localSeo;

			$post->save();
		}
	}
}