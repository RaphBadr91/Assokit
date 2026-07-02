<?php
namespace AIOSEO\Plugin\Pro\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains common update methods.
 *
 * @since 4.4.7
 */
trait Updates {
	/**
	 * Source of notifications content.
	 *
	 * @since   4.0.0
	 * @version 4.4.7 Moved to trait.
	 *
	 * @var string
	 */
	public $baseUrl = 'https://licensing.aioseo.com/v1/';

	/**
	 * Validates the download URL before WP downloads the package.
	 *
	 * @since 4.4.7
	 *
	 * @param  array $options The options.
	 * @return void
	 */
	public function validateDownloadUrl( $options ) {
		// Check for the slug inside the temp_backup data.
		// If no temp_backup data is present, bail because then we're installing a new plugin instead of doing an update.
		$slug = $options['hook_extra']['temp_backup']['slug'] ?? '';
		if ( ! $slug ) {
			return $options;
		}

		// First, check if we're dealing with one of our plugins.
		$slug = strtolower( $slug );
		if ( ! preg_match( '/^aioseo|^all-in-one-seo-pack-pro/', (string) $slug ) ) {
			return $options;
		}

		// Now, send a HEAD request to see if the download URL hasn't expired.
		$response = wp_remote_head( $options['package'] );
		if ( is_wp_error( $response ) ) {
			// If a WP error occurred, we'll just bail out.
			return $options;
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			return $options;
		}

		// Check if a refresh has been attempted in the last hour to prevent overloading the remote server.
		$cacheKey    = 'refresh_download_url_' . $slug;
		$downloadUrl = aioseo()->core->cache->get( $cacheKey );
		if ( $downloadUrl ) {
			$options['package'] = $downloadUrl;

			return $options;
		}

		// If the response code isn't 200, we'll refresh the download URL.
		switch ( $slug ) {
			case 'all-in-one-seo-pack-pro':
				$args = [
					'license'     => aioseo()->license->getLicenseKey(),
					'domain'      => aioseo()->helpers->getSiteDomain( true ),
					'sku'         => $slug,
					'version'     => aioseo()->version,
					'php_version' => PHP_VERSION,
					'wp_version'  => get_bloginfo( 'version' )
				];

				$updateInfo  = aioseo()->helpers->sendRequest( $this->getUrl() . 'update/', $args );
				$downloadUrl = $updateInfo->package ?? $options['package'];

				break;
			default:
				// If we don't find a specific slug, we'll assume it's an addon plugin.
				$downloadUrl = aioseo()->addons->getDownloadUrl( $slug );
				$downloadUrl = $downloadUrl ? $downloadUrl : $options['package'];
		}

		aioseo()->core->cache->update( $cacheKey, $downloadUrl, 45 * MINUTE_IN_SECONDS );

		$options['package'] = $downloadUrl;

		return $options;
	}

	/**
	 * Get the URL to check licenses.
	 *
	 * @since   4.0.0
	 * @version 4.4.7 Moved to trait.
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		if ( defined( 'AIOSEO_LICENSING_URL' ) ) {
			return AIOSEO_LICENSING_URL;
		}

		return $this->baseUrl;
	}
}