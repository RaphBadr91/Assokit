<?php
namespace AIOSEO\Plugin\Addon\ImageSeo {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class ImageSeo {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var ImageSeo
		 */
		private static $instance;

		/**
		 * Plugin version for enqueueing, etc.
		 * The value is retrieved from the version constant.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $version = '';

		/**
		 * Helpers class instance
		 *
		 * @since 1.1.2
		 *
		 * @var Utils\Helpers
		 */
		public $helpers;

		/**
		 * Tags class instance.
		 *
		 * @since 1.0.5
		 *
		 * @var Utils\Tags
		 */
		public $tags;

		/**
		 * Image class instance.
		 *
		 * @since 1.0.5
		 *
		 * @var Image\Image
		 */
		public $image;

		/**
		 * Admin class instance.
		 *
		 * @since 1.0.5
		 *
		 * @var Admin\Admin
		 */
		public $admin;

		/**
		 * Main ImageSEO addon Instance.
		 *
		 * Insures that only one instance of the addon exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return ImageSeo
		 */
		public static function instance() {
			if ( null === self::$instance || ! self::$instance instanceof self ) {
				self::$instance = new self();
				self::$instance->constants();
				self::$instance->includes();
				self::$instance->load();
			}

			return self::$instance;
		}

		/**
		 * Setup plugin constants.
		 * All the path/URL related constants are defined in main plugin file.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		private function constants() {
			$defaultHeaders = [
				'name'    => 'Plugin Name',
				'version' => 'Version',
			];

			$pluginData = get_file_data( AIOSEO_IMAGE_SEO_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_IMAGE_SEO_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_IMAGE_SEO_VERSION;
		}

		/**
		 * Including the new files with PHP 5.3 style.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		private function includes() {
			$dependencies = [
				'/vendor/autoload.php'
			];

			foreach ( $dependencies as $path ) {
				if ( ! file_exists( AIOSEO_IMAGE_SEO_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-image-seo' ) );
				}
				require AIOSEO_IMAGE_SEO_DIR . $path;
			}
		}

		/**
		 * Load our classes.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function load() {
			aioseo()->helpers->loadTextDomain( 'aioseo-image-seo' );

			$this->helpers = new Utils\Helpers();
			$this->tags    = new Utils\Tags();
			$this->image   = new Image\Image();
			$this->admin   = new Admin\Admin();

			aioseo()->addons->loadAddon( 'imageSeo', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one ImageSeo instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\ImageSeo\ImageSeo
	 */
	function aioseoImageSeo() {
		return \AIOSEO\Plugin\Addon\ImageSeo\ImageSeo::instance();
	}
}