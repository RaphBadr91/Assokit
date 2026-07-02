<?php
namespace AIOSEO\Plugin\Pro\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles outputting the GTM tag (if enabled).
 *
 * @since   4.0.0
 * @version 4.5.1
 */
class GoogleAnalytics {
	/**
	 * Class Constructor.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueGtmAssets' ] );
	}

	/**
	 * Checks if we can show GTM on the site.
	 *
	 * @since 4.0.0
	 *
	 * @return bool Whether or not we can show GTM.
	 */
	public function canShowGtm() {
		if ( aioseo()->helpers->isAmpPage() ) {
			return false;
		}

		$containerId = aioseo()->options->deprecated->webmasterTools->googleAnalytics->gtmContainerId;

		if (
			in_array( 'googleAnalytics', aioseo()->internalOptions->internal->deprecatedOptions, true ) &&
			! $containerId
		) {
			return false;
		}

		$disable = apply_filters( 'aioseo_disable_google_tag_manager', false );

		if (
			$disable ||
			is_admin() ||
			empty( $containerId ) ||
			! preg_match( '/GTM-.{6}/', (string) $containerId )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueues the GTM assets when needed.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function enqueueGtmAssets() {
		if ( ! $this->canShowGtm() ) {
			return;
		}

		aioseo()->core->assets->load( 'src/app/gtm/main.js', [], [
			'containerId' => aioseo()->options->deprecated->webmasterTools->googleAnalytics->gtmContainerId
		], 'aioseoGtm' );
	}
}