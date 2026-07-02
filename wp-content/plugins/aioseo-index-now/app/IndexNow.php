<?php
namespace AIOSEO\Plugin\Addon\IndexNow {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class IndexNow {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\IndexNow\IndexNow
		 */
		private static $instance = null;

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
		 * Cache class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Utils\Cache
		 */
		public $cache = null;

		/**
		 * Helpers class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Utils\Helpers
		 */
		public $helpers = null;

		/**
		 * InternalOptions class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Utils\InternalOptions
		 */
		public $internalOptions = null;

		/**
		 * Updates class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Main\Updates
		 */
		public $updates = null;

		/**
		 * Options class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Options\Options
		 */
		public $options = null;

		/**
		 * Verify class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Main\Verify
		 */
		public $verify = null;

		/**
		 * Ping class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Main\Ping
		 */
		public $ping = null;

		/**
		 * Api class instance.
		 *
		 * @since 1.0.6
		 *
		 * @var Api\Api
		 */
		public $api = null;

		/**
		 * Main Addon Instance.
		 *
		 * Insures that only one instance of the addon exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return \AIOSEO\Plugin\Addon\IndexNow\IndexNow
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

			$pluginData = get_file_data( AIOSEO_INDEX_NOW_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_INDEX_NOW_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_INDEX_NOW_VERSION;
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
				if ( ! file_exists( AIOSEO_INDEX_NOW_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-index-now' ) );
				}
				require AIOSEO_INDEX_NOW_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-index-now' );

			$this->cache           = new Utils\Cache();
			$this->helpers         = new Utils\Helpers();
			$this->internalOptions = new Utils\InternalOptions();
			$this->updates         = new Main\Updates();
			$this->options         = new Options\Options();
			$this->verify          = new Main\Verify();
			$this->ping            = new Main\Ping();
			$this->api             = new Api\Api();

			aioseo()->addons->loadAddon( 'indexNow', $this );
		}
	}
}

namespace {
	/**
	 * The function which returns the one Addon instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\IndexNow\IndexNow
	 */
	function aioseoIndexNow() {
		return AIOSEO\Plugin\Addon\IndexNow\IndexNow::instance();
	}
}