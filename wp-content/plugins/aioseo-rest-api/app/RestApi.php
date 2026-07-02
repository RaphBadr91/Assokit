<?php
namespace AIOSEO\Plugin\Addon\RestApi {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class RestApi {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\RestApi\RestApi
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
		 * Main RestApi Instance.
		 *
		 * Insures that only one instance of the RestApi exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return \AIOSEO\Plugin\Addon\RestApi\RestApi
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

			$pluginData = get_file_data( AIOSEO_REST_API_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_REST_API_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_REST_API_VERSION;
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
				if ( ! file_exists( AIOSEO_REST_API_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-rest-api' ) );
				}
				require AIOSEO_REST_API_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-rest-api' );

			new Controllers\Post();
			new Controllers\Term();
		}
	}
}

namespace {
	/**
	 * The function which returns the one RestApi instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\RestApi\RestApi
	 */
	function aioseoRestApi() {
		return \AIOSEO\Plugin\Addon\RestApi\RestApi::instance();
	}
}