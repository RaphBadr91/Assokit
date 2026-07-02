<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Main class.
	 *
	 * @since 1.0.0
	 */
	final class VideoSitemap {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 1.0.0
		 *
		 * @var \AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap
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
		 * Content class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Content
		 */
		public $content = null;

		/**
		 * Output class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Output
		 */
		public $output = null;

		/**
		 * Root class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Root
		 */
		public $root = null;

		/**
		 * Query class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Query
		 */
		public $query = null;

		/**
		 * File class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\File
		 */
		public $file = null;

		/**
		 * Helpers class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Helpers
		 */
		public $helpers = null;

		/**
		 * RequestParser class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\RequestParser
		 */
		public $requestParser = null;

		/**
		 * Xsl class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Xsl
		 */
		public $xsl = null;

		/**
		 * Video class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Video
		 */
		public $video = null;

		/**
		 * Sitemap class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Sitemap
		 */
		public $sitemap = null;

		/**
		 * Cache class instance.
		 *
		 * @since 1.1.8
		 *
		 * @var VideoSitemap\Cache
		 */
		public $cache = null;

		/**
		 * Main VideoSitemap Instance.
		 *
		 * Insures that only one instance of VideoSitemap exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @since 1.0.0
		 *
		 * @return \AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap
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

			$pluginData = get_file_data( AIOSEO_VIDEO_SITEMAP_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_VIDEO_SITEMAP_VERSION' => $pluginData['version']
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value );
				}
			}

			$this->version = AIOSEO_VIDEO_SITEMAP_VERSION;
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
				if ( ! file_exists( AIOSEO_VIDEO_SITEMAP_DIR ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', 'aioseo-video-sitemap' ) );
				}
				require AIOSEO_VIDEO_SITEMAP_DIR . $path;
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
			aioseo()->helpers->loadTextDomain( 'aioseo-video-sitemap' );

			$this->content       = new VideoSitemap\Content();
			$this->output        = new VideoSitemap\Output();
			$this->root          = new VideoSitemap\Root();
			$this->query         = new VideoSitemap\Query();
			$this->file          = new VideoSitemap\File();
			$this->helpers       = new VideoSitemap\Helpers();
			$this->requestParser = new VideoSitemap\RequestParser();
			$this->xsl           = new VideoSitemap\Xsl();
			$this->video         = new VideoSitemap\Video();
			$this->sitemap       = new VideoSitemap\Sitemap();
			$this->cache         = new VideoSitemap\Cache();

			aioseo()->addons->loadAddon( 'videoSitemap', $this );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one VideoSitemap instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap
	 */
	function aioseoVideoSitemap() {
		return \AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap::instance();
	}
}