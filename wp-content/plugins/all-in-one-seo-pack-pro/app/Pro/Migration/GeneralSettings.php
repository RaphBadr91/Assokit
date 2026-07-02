<?php
namespace AIOSEO\Plugin\Pro\Migration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

/**
 * Migrates the Video Sitemap settings from V3.
 *
 * @since 4.0.0
 */
class GeneralSettings {
	/**
	 * Class constructor.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		$this->migrateGoogleAnalytics();

		$settings = [
			'aiosp_gtm_container_id' => [ 'type' => 'string', 'newOption' => [ 'deprecated', 'webmasterTools', 'googleAnalytics', 'gtmContainerId' ] ],
		];

		aioseo()->migration->helpers->mapOldToNew( $settings, aioseo()->migration->oldOptions );
	}

	/**
	 * Enables deprecated Google Analytics if there is an existing GTM id.
	 *
	 * @since 4.0.6
	 *
	 * @return void
	 */
	private function migrateGoogleAnalytics() {
		$oldOptions = aioseo()->migration->oldOptions;
		if ( empty( $oldOptions['aiosp_gtm_container_id'] ) ) {
			return;
		}

		$deprecatedOptions = aioseo()->internalOptions->internal->deprecatedOptions;
		if ( ! in_array( 'googleAnalytics', $deprecatedOptions, true ) ) {
			array_push( $deprecatedOptions, 'googleAnalytics' );
			aioseo()->internalOptions->internal->deprecatedOptions = $deprecatedOptions;
		}
	}
}