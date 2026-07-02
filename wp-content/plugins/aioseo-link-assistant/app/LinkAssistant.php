<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main Link Assistant addon class.
	 *
	 * @since 1.0.0
	 */
	final class LinkAssistant {
		/**
		 * Holds the instance of the addon.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\LinkAssistant\LinkAssistant
		 */
		private static $instance = null;

		/**
		 * Plugin version for enqueueing, etc.
		 *
		 * The value is retrieved from the version constant.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $version = '';

		/**
		 * InternalOptions class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Options\InternalOptions
		 */
		public $internalOptions = null;

		/**
		 * Options class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Options\Options
		 */
		public $options = null;

		/**
		 * Updates class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Main\Updates
		 */
		public $updates = null;

		/**
		 * Helpers class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Utils\Helpers
		 */
		public $helpers = null;

		/**
		 * Cache class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Utils\Cache
		 */
		public $cache = null;

		/**
		 * Main class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Main\Main
		 */
		public $main = null;

		/**
		 * Api class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Api\Api
		 */
		public $api = null;

		/**
		 * Admin class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Admin\Admin
		 */
		public $admin = null;

		/**
		 * Usage class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var Admin\Usage
		 */
		public $usage = null;

		/**
		 * Main instance.
		 *
		 * Insures that only one instance of the addon exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return \AIOSEO\Plugin\Addon\LinkAssistant\LinkAssistant
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
		 *
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

			$pluginData = get_file_data( AIOSEO_LINK_ASSISTANT_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_LINK_ASSISTANT_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_LINK_ASSISTANT_VERSION;
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
				if ( ! file_exists( AIOSEO_LINK_ASSISTANT_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-link-assistant' ) );
				}
				require AIOSEO_LINK_ASSISTANT_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-link-assistant' );

			$this->internalOptions = new Options\InternalOptions();
			$this->options         = new Options\Options();
			$this->updates         = new Main\Updates();
			$this->helpers         = new Utils\Helpers();
			$this->cache           = new Utils\Cache();
			$this->main            = new Main\Main();
			$this->api             = new Api\Api();
			$this->admin           = new Admin\Admin();
			$this->usage           = new Admin\Usage();

			// Register addon in the main plugin.
			aioseo()->addons->loadAddon( 'linkAssistant', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one LinkAssistant class instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\LinkAssistant\LinkAssistant
	 */
	function aioseoLinkAssistant() {
		return AIOSEO\Plugin\Addon\LinkAssistant\LinkAssistant::instance();
	}
}