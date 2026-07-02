<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class NewsSitemap {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap
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
		 * Sitemap class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Sitemap
		 */
		public $sitemap = null;

		/**
		 * Content class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Content
		 */
		public $content = null;

		/**
		 * Query class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Query
		 */
		public $query = null;

		/**
		 * Output class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Output
		 */
		public $output = null;

		/**
		 * RequestParser class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\RequestParser
		 */
		public $requestParser = null;

		/**
		 * Xsl class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Xsl
		 */
		public $xsl = null;

		/**
		 * Helpers class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Helpers
		 */
		public $helpers = null;

		/**
		 * Cache class instance.
		 *
		 * @since 1.0.11
		 *
		 * @var NewsSitemap\Cache
		 */
		public $cache = null;

		/**
		 * Main NewsSitemap Instance.
		 *
		 * Insures that only one instance of NewsSitemap exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return NewsSitemap
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

			$pluginData = get_file_data( AIOSEO_NEWS_SITEMAP_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_NEWS_SITEMAP_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_NEWS_SITEMAP_VERSION;
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
				if ( ! file_exists( AIOSEO_NEWS_SITEMAP_DIR ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-news-sitemap' ) );
				}
				require AIOSEO_NEWS_SITEMAP_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-news-sitemap' );

			$this->sitemap       = new NewsSitemap\Sitemap();
			$this->content       = new NewsSitemap\Content();
			$this->query         = new NewsSitemap\Query();
			$this->output        = new NewsSitemap\Output();
			$this->requestParser = new NewsSitemap\RequestParser();
			$this->xsl           = new NewsSitemap\Xsl();
			$this->helpers       = new NewsSitemap\Helpers();
			$this->cache         = new NewsSitemap\Cache();

			aioseo()->addons->loadAddon( 'newsSitemap', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one NewsSitemap instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap
	 */
	function aioseoNewsSitemap() {
		return \AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap::instance();
	}
}