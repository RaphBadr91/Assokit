<?php
namespace AIOSEO\Plugin\Addon\Redirects {

	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class Redirects {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var Redirects
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
		 * InternalOptions class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Utils\InternalOptions
		 */
		public $internalOptions = null;

		/**
		 * Options class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Utils\Options
		 */
		public $options = null;

		/**
		 * Helpers class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Utils\Helpers
		 */
		public $helpers = null;

		/**
		 * Updates class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\Updates
		 */
		public $updates = null;

		/**
		 * Api class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Api\Api
		 */
		public $api = null;

		/**
		 * Monitor class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\Monitor
		 */
		public $monitor = null;

		/**
		 * ImportExport class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var ImportExport\ImportExport
		 */
		public $importExport = null;

		/**
		 * Server class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\Server\Unknown|Main\Server\Apache|Main\Server\Nginx
		 */
		public $server = null;

		/**
		 * Redirect class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\Redirect
		 */
		public $redirect = null;

		/**
		 * FullSiteRedirects class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\FullSiteRedirects
		 */
		public $fullSiteRedirects = null;

		/**
		 * Cache class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Utils\Cache
		 */
		public $cache = null;

		/**
		 * Redirect404 class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Main\Redirect404
		 */
		public $redirect404 = null;

		/**
		 * Admin class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Admin\Admin
		 */
		public $admin = null;

		/**
		 * Usage class instance.
		 *
		 * @since 1.2.5
		 *
		 * @var Admin\Usage
		 */
		public $usage = null;

		/**
		 * Post redirect class instance.
		 *
		 * @since 1.2.7
		 *
		 * @var Main\PostRedirect
		 */
		public $postRedirect = null;

		/**
		 * Filters class instance.
		 *
		 * @since 1.4.11
		 *
		 * @var Main\Filters
		 */
		public $filters = null;

		/**
		 * Main Redirection Manager Instance.
		 *
		 * Insures that only one instance of the addon exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return Redirects
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

			$pluginData = get_file_data( AIOSEO_REDIRECTION_MANAGER_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_REDIRECTION_MANAGER_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_REDIRECTION_MANAGER_VERSION;
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
				if ( ! file_exists( AIOSEO_REDIRECTION_MANAGER_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-redirects' ) );
				}
				require AIOSEO_REDIRECTION_MANAGER_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-redirects' );

			$this->filters           = new Main\Filters();
			$this->internalOptions   = new Utils\InternalOptions();
			$this->options           = new Utils\Options();
			$this->helpers           = new Utils\Helpers();
			$this->updates           = new Main\Updates();
			$this->api               = new Api\Api();
			$this->monitor           = new Main\Monitor();
			$this->importExport      = new ImportExport\ImportExport();
			$this->server            = Main\Server\Server::getServer();
			$this->redirect          = new Main\Redirect();
			$this->fullSiteRedirects = new Main\FullSiteRedirects();
			$this->cache             = new Utils\Cache();
			$this->redirect404       = new Main\Redirect404();
			$this->admin             = new Admin\Admin();
			$this->usage             = new Admin\Usage();
			$this->postRedirect      = new Main\PostRedirect();

			// Load into main aioseo instance.
			aioseo()->addons->loadAddon( 'redirects', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}
	/**
	 * The function which returns the one Addon instance.
	 *
	 * @since 1.0.0
	 *
	 * @return AIOSEO\Plugin\Addon\Redirects\Redirects
	 */
	function aioseoRedirects() {
		return AIOSEO\Plugin\Addon\Redirects\Redirects::instance();
	}
}