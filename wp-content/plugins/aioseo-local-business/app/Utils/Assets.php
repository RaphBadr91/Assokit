<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Traits;

/**
 * Load file assets.
 *
 * @since 1.2.4
 */
class Assets {
	use Traits\Assets;

	/**
	 * Get the script handle to use for asset enqueuing.
	 *
	 * @since 1.2.4
	 *
	 * @var string
	 */
	private $scriptHandle = 'aioseo-local-business';

	/**
	 * Class constructor.
	 *
	 * @since 1.2.4
	 */
	public function __construct() {
		$this->core         = aioseo()->core;
		$this->version      = aioseoLocalBusiness()->version;
		$this->manifestFile = AIOSEO_LOCAL_BUSINESS_DIR . '/dist/manifest.php';
		$this->isDev        = aioseoLocalBusiness()->isDev;

		if ( $this->isDev ) {
			$this->domain = getenv( 'VITE_AIOSEO_LOCAL_BUSINESS_DOMAIN' );
			$this->port   = getenv( 'VITE_AIOSEO_LOCAL_BUSINESS_DEV_PORT' );
		}

		$this->noModuleTag = [
			'src/js-api-loader.js',
			'src/map.js'
		];

		add_filter( 'script_loader_tag', [ $this, 'scriptLoaderTag' ], 10, 3 );
	}

	/**
	 * Get the public URL base.
	 *
	 * @since 1.2.4
	 *
	 * @return string The URL base.
	 */
	private function getPublicUrlBase() {
		return $this->shouldLoadDev() ? '/dist' : $this->basePath();
	}

	/**
	 * Get the base path URL.
	 *
	 * @since 1.2.4
	 *
	 * @return string The base path URL.
	 */
	private function basePath() {
		return $this->normalizeAssetsHost( plugins_url( 'dist/', AIOSEO_LOCAL_BUSINESS_FILE ) );
	}
}