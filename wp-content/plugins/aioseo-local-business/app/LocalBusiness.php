<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class LocalBusiness {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\LocalBusiness\LocalBusiness
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
		 * Instance of the Admin class.
		 *
		 * @since 1.1.0
		 *
		 * @var Admin\Admin
		 */
		public $admin = null;

		/**
		 * Instance of the Usage class.
		 *
		 * @since 1.2.10
		 *
		 * @var Admin\Usage
		 */
		public $usage = null;

		/**
		 * Instance of the Locations class.
		 *
		 * @since 1.1.0
		 *
		 * @var Locations\Locations
		 */
		public $locations = null;

		/**
		 * Instance of the Shortcodes class.
		 *
		 * @since 1.1.0
		 *
		 * @var Shortcodes\Shortcodes
		 */
		public $shortcodes = null;

		/**
		 * Instance of the Location postType class.
		 *
		 * @since 1.1.0
		 *
		 * @var Admin\Location
		 */
		public $postType = null;

		/**
		 * Instance of the Location taxonomy class.
		 *
		 * @since 1.1.0
		 *
		 * @var Admin\LocationCategory
		 */
		public $taxonomy = null;

		/**
		 * Instance of the Blocks class containing all blocks.
		 *
		 * @since 1.1.0
		 *
		 * @var Blocks\Blocks
		 */
		public $blocks = null;

		/**
		 * Instance of the Widgets class registering all widgets.
		 *
		 * @since 1.1.0
		 *
		 * @var Widgets\Widgets
		 */
		public $widgets = null;

		/**
		 * Instance of the Schema class.
		 *
		 * @since 1.1.0
		 *
		 * @var Schema\Schema
		 */
		public $schema = null;

		/**
		 * Instance of the Tags class.
		 *
		 * @since 1.1.0
		 *
		 * @var Utils\Tags
		 */
		public $tags = null;

		/**
		 * Instance of the Helpers class.
		 *
		 * @since 1.1.0
		 *
		 * @var Utils\Helpers
		 */
		public $helpers = null;

		/**
		 * Instance of the Search class.
		 *
		 * @since 1.1.0
		 *
		 * @var Main\Search
		 */
		public $search = null;

		/**
		 * Instance of the Templates class.
		 *
		 * @since 1.1.0
		 *
		 * @var Utils\Templates
		 */
		public $templates = null;

		/**
		 * Instance of the Access class.
		 *
		 * @since 1.1.0
		 *
		 * @var Utils\Access
		 */
		public $access = null;

		/**
		 * Instance of the Activate class.
		 *
		 * @since 1.1.0
		 *
		 * @var Main\Activate
		 */
		public $activate = null;

		/**
		 * Instance of the Map class.
		 *
		 * @since 1.1.3
		 *
		 * @var Locations\Maps
		 */
		public $maps = null;

		/**
		 * Instance of the API class.
		 *
		 * @since 1.1.3
		 *
		 * @var Api\Api
		 */
		public $api = null;

		/**
		 * Whether we're in a dev environment.
		 *
		 * @since 1.2.4
		 *
		 * @var bool
		 */
		public $isDev = false;

		/**
		 * Cache class instance.
		 *
		 * @since 1.2.12
		 *
		 * @var Utils\Cache
		 */
		public $cache = null;

		/**
		 * Assets class instance.
		 *
		 * @since 1.2.12
		 *
		 * @var Utils\Assets
		 */
		public $assets = null;

		/**
		 * InternalOptions class instance.
		 *
		 * @since 1.2.12
		 *
		 * @var Utils\InternalOptions
		 */
		public $internalOptions = null;

		/**
		 * Updates class instance.
		 *
		 * @since 1.2.12
		 *
		 * @var Main\Updates
		 */
		public $updates = null;

		/**
		 * Import class instance.
		 *
		 * @since 1.3.0
		 *
		 * @var Import\Import
		 */
		public $import = null;

		/**
		 * Knowledge Graph Organization graph class instance.
		 *
		 * @since 1.3.3
		 *
		 * @var Schema\Graphs\KgOrganization
		 */
		public $kgOrganization;

		/**
		 * Uninstall class instance.
		 *
		 * @since 1.3.10
		 *
		 * @var Main\Uninstall
		 */
		public $uninstall = null;

		/**
		 * Main LocalBusiness Instance.
		 *
		 * Insures that only one instance of the addon exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return \AIOSEO\Plugin\Addon\LocalBusiness\LocalBusiness
		 */
		public static function instance() {
			if ( null === self::$instance || ! self::$instance instanceof self ) {
				self::$instance = new self();
				self::$instance->constants();
				self::$instance->includes();
				self::$instance->preload();

				if ( ! self::$instance->helpers->isUninstalling() ) {
					self::$instance->load();
				}

				add_action( 'init', [ self::$instance, 'registerStyles' ] );
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

			$pluginData = get_file_data( AIOSEO_LOCAL_BUSINESS_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_LOCAL_BUSINESS_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_LOCAL_BUSINESS_VERSION;
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
				if ( ! file_exists( AIOSEO_LOCAL_BUSINESS_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-local-business' ) );
				}
				require AIOSEO_LOCAL_BUSINESS_DIR . $path;
			}

			$this->setDev();
		}

		/**
		 * Load the version of the plugin we are currently using.
		 *
		 * @since 1.2.4
		 *
		 * @return void
		 */
		private function setDev() {
			if (
				! class_exists( '\Dotenv\Dotenv' ) ||
				! file_exists( AIOSEO_LOCAL_BUSINESS_DIR . '/build/.env' )
			) {
				return;
			}

			$dotenv = \Dotenv\Dotenv::createUnsafeImmutable( AIOSEO_LOCAL_BUSINESS_DIR, '/build/.env' );
			$dotenv->load();

			$domain = strtolower( getenv( 'VITE_AIOSEO_LOCAL_BUSINESS_DOMAIN' ) );
			if ( ! empty( $domain ) ) {
				$this->isDev = true;
			}
		}

		/**
		 * Register styles.
		 *
		 * @since 1.1.0
		 *
		 * @return void
		 */
		public function registerStyles() {
			aioseoLocalBusiness()->assets->registerCss( 'src/assets/scss/business-info.scss' );
			aioseoLocalBusiness()->assets->registerCss( 'src/assets/scss/opening-hours.scss' );
		}

		/**
		 * Runs before we load the plugin.
		 *
		 * @since 1.3.10
		 *
		 * @return void
		 */
		public function preLoad() {
			$this->helpers   = new Utils\Helpers();
			$this->uninstall = new Main\Uninstall();
		}

		/**
		 * Load our classes.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function load() {
			aioseo()->helpers->loadTextDomain( 'aioseo-local-business' );

			$this->cache           = new Utils\Cache();
			$this->assets          = new Utils\Assets();
			$this->internalOptions = new Utils\InternalOptions();
			$this->updates         = new Main\Updates();
			$this->admin           = new Admin\Admin();
			$this->usage           = new Admin\Usage();
			$this->postType        = new Admin\Location();
			$this->taxonomy        = new Admin\LocationCategory();
			$this->locations       = new Locations\Locations();
			$this->maps            = new Locations\Maps();
			$this->shortcodes      = new Shortcodes\Shortcodes();
			$this->blocks          = new Blocks\Blocks();
			$this->widgets         = new Widgets\Widgets();
			$this->tags            = new Utils\Tags();
			$this->search          = new Main\Search();
			$this->templates       = new Utils\Templates();
			$this->access          = new Utils\Access();
			$this->schema          = new Schema\Schema();
			$this->activate        = new Main\Activate();
			$this->api             = new Api\Api();
			$this->import          = new Import\Import();
			$this->kgOrganization  = new Schema\Graphs\KgOrganization();

			// Load into main aioseo instance.
			aioseo()->addons->loadAddon( 'localBusiness', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one LocalBusiness instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\LocalBusiness\LocalBusiness
	 */
	function aioseoLocalBusiness() {
		return \AIOSEO\Plugin\Addon\LocalBusiness\LocalBusiness::instance();
	}

	if ( ! function_exists( 'aioseo_local_business_info' ) ) {
		/**
		 * Global function for business info output.
		 *
		 * @param  array $args
		 * @return void
		 */
		function aioseo_local_business_info( $args = [] ) {
			$shortcodeArgs = [];
			foreach ( $args as $key => $value ) {
				$shortcodeArgs[ aioseo()->helpers->toSnakeCase( $key ) ] = $value;
			}

			echo aioseoLocalBusiness()->shortcodes->businessInfo( $shortcodeArgs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	if ( ! function_exists( 'aioseo_local_opening_hours' ) ) {
		/**
		 * Global function for opening hours output.
		 *
		 * @param  array $args Opening hours arguments.
		 * @return void
		 */
		function aioseo_local_opening_hours( $args = [] ) {
			$shortcodeArgs = [];
			foreach ( $args as $key => $value ) {
				$shortcodeArgs[ aioseo()->helpers->toSnakeCase( $key ) ] = $value;
			}

			echo aioseoLocalBusiness()->shortcodes->openingHours( $shortcodeArgs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	if ( ! function_exists( 'aioseo_local_locations' ) ) {
		/**
		 * Global function for locations output.
		 *
		 * @param  array $args Locations arguments.
		 * @return void
		 */
		function aioseo_local_locations( $args = [] ) {
			$shortcodeArgs = [];
			foreach ( $args as $key => $value ) {
				$shortcodeArgs[ aioseo()->helpers->toSnakeCase( $key ) ] = $value;
			}

			echo aioseoLocalBusiness()->shortcodes->locations( $shortcodeArgs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	if ( ! function_exists( 'aioseo_local_map' ) ) {
		/**
		 * Global function for locations output.
		 *
		 * @param  array $args Locations arguments.
		 * @return void
		 */
		function aioseo_local_map( $args = [] ) {
			$shortcodeArgs = [];
			foreach ( $args as $key => $value ) {
				$shortcodeArgs[ aioseo()->helpers->toSnakeCase( $key ) ] = $value;
			}

			echo aioseoLocalBusiness()->shortcodes->map( $shortcodeArgs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}