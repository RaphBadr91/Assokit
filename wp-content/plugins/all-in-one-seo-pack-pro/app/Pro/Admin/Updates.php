<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The updates class to check for updates from our server.
 *
 * @since 4.0.0
 */
class Updates {
	use \AIOSEO\Plugin\Pro\Traits\Updates;

	/**
	 * Plugin slug.
	 *
	 * @since 4.0.0
	 *
	 * @var bool|string
	 */
	public $pluginSlug = false;

	/**
	 * Plugin path.
	 *
	 * @since 4.0.0
	 *
	 * @var bool|string
	 */
	public $pluginPath = false;

	/**
	 * Version number of the plugin.
	 *
	 * @since 4.0.0
	 *
	 * @var bool|int
	 */
	public $version = false;

	/**
	 * License key for the plugin.
	 *
	 * @since 4.0.0
	 *
	 * @var bool|string
	 */
	public $key = false;

	/**
	 * Store the update data returned from the API.
	 *
	 * @since 4.0.0
	 *
	 * @var object
	 */
	public $update;

	/**
	 * Store the plugin info details for the update.
	 *
	 * @since 4.0.0
	 *
	 * @var bool|object
	 */
	public $info = false;

	/**
	 * Source of notifications content.
	 *
	 * @since 4.0.0
	 *
	 * @var string
	 */
	public $baseUrl = 'https://licensing.aioseo.com/v1/';

	/**
	 * Primary class constructor.
	 *
	 * @since 4.0.0
	 *
	 * @param array $config Array of updater config args.
	 */
	public function __construct( array $config ) {
		// Set class properties.
		$acceptedArgs = [
			'pluginSlug',
			'pluginPath',
			'version',
			'key',
		];

		foreach ( $acceptedArgs as $arg ) {
			$this->$arg = $config[ $arg ];
		}

		// Initialize the class on "init". This cannot run on "plugins_loaded"
		// because that's when this class is constructed in the first place.
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Initialize the class.
	 *
	 * @since 4.7.0
	 */
	public function init() {
		// If the current user cannot update plugins, stop processing here.
		// We want to make sure a user is logged in because we need WP CLI and cron jobs to get past this check.
		if ( is_user_logged_in() && ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Load the updater hooks and filters.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'updatePluginsFilter' ], 1000 );
		add_filter( 'plugins_api', [ $this, 'pluginsApi' ], 10, 3 );

		if ( ! wp_doing_cron() ) {
			add_filter( 'upgrader_package_options', [ $this, 'validateDownloadUrl' ] );
		}
	}

	/**
	 * Add our Pro plugin update details when WordPress runs its update checker.
	 * Right before WordPress saves the plugin update object, we infuse it with our own data.
	 *
	 * @since 4.0.0
	 *
	 * @param  mixed  $value The WordPress update object.
	 * @return object        Amended WordPress update object on success, default if object is empty.
	 */
	public function updatePluginsFilter( $value ) {
		// If no update object exists, bail to prevent errors.
		if ( empty( $value ) || ! is_object( $value ) ) {
			return $value;
		}

		// If we haven't checked for update details, do so now.
		// wp_update_plugins() sets the transient twice so we store the update to prevent a second redundant request.
		// If the request fails, we will return a default object based on the current version of the plugin.
		if ( empty( $this->update ) ) {
			$this->update = $this->checkForUpdates();

			if ( empty( $this->update ) || ! empty( $this->update->error ) ) {
				$this->update = new \stdClass();

				return $value;
			}

			$this->update->description = $this->update->description ? preg_replace( '/\s+/', ' ', (string) $this->update->description ) : null;
			$this->update->changelog   = $this->update->changelog ? preg_replace( '/\s+/', ' ', (string) $this->update->changelog ) : null;
		}

		$this->update->icons      = ! empty( $this->update->icons ) ? (array) $this->update->icons : [];
		$this->update->aioseo     = true;
		$this->update->plugin     = $this->pluginPath;
		$this->update->oldVersion = $this->version;

		// Infuse the update object with our data if the version from the remote API is newer.
		if ( isset( $this->update->new_version ) && version_compare( $this->version, $this->update->new_version, '<' ) ) {
			// The $plugin_update object contains new_version, package, slug, and last_update keys.
			$value->response[ $this->pluginPath ] = $this->update;
		} else {
			$this->update->new_version             = $this->version;
			$this->update->plugin                  = $this->pluginPath;
			$value->no_update[ $this->pluginPath ] = $this->update;
		}

		// Return the update object.
		return $value;
	}

	/**
	 * Check for updates request.
	 *
	 * @since 4.0.0
	 *
	 * @return object an object with the update information.
	 */
	public function checkForUpdates() {
		$cacheKeyHash = sha1( $this->pluginSlug . $this->version . $this->key );
		$cacheKey     = "aioseo_update_check_{$cacheKeyHash}";
		$cachedUpdate = aioseo()->core->cache->get( $cacheKey );
		if ( null !== $cachedUpdate ) {
			if ( is_string( $cachedUpdate ) ) {
				$cachedUpdate = json_decode( $cachedUpdate );
			}

			return $cachedUpdate;
		}

		$args = [
			'license'     => $this->key,
			'domain'      => aioseo()->helpers->getSiteDomain( true ),
			'sku'         => $this->pluginSlug,
			'version'     => $this->version,
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' )
		];

		$update = aioseo()->helpers->sendRequest( $this->getUrl() . 'update/', $args );

		// Validate the response has required properties
		$isValid = ! empty( $update ) &&
			is_object( $update ) &&
			! property_exists( $update, 'error' ) &&
			property_exists( $update, 'new_version' );

		// Cache for 1 hour if valid, 1 minute if invalid
		$cacheDuration = $isValid ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;
		aioseo()->core->cache->update( $cacheKey, wp_json_encode( $update ), $cacheDuration );

		return $update;
	}

	/**
	 * Filter the plugins_api function to get our own custom plugin information
	 * from our private repo.
	 *
	 * @since 4.0.0
	 *
	 * @param  object $api    The original plugins_api object.
	 * @param  string $action The action sent by plugins_api.
	 * @param  object $args   Additional args to send to plugins_api.
	 * @return object         New stdClass with plugin information on success, default response on failure.
	 */
	public function pluginsApi( $api, $action = '', $args = null ) {
		$plugin = ( 'plugin_information' === $action ) && isset( $args->slug ) && ( $this->pluginSlug === $args->slug );

		// If our plugin matches the request, set our own plugin data, else return the default response.
		if ( $plugin ) {
			return $this->setPluginsApi( $api );
		}

		return $api;
	}

	/**
	 * Ping a remote API to retrieve plugin information for WordPress to display.
	 *
	 * @since 4.0.0
	 *
	 * @param  object $defaultApi The default API object.
	 * @return object             Return custom plugin information to plugins_api.
	 */
	public function setPluginsApi( $defaultApi ) {
		// Perform the remote request to retrieve our plugin information. If it fails, return the default object.
		if ( ! $this->info ) {
			$response = aioseo()->helpers->sendRequest( $this->getUrl() . 'info/', [
				'license'     => $this->key,
				'domain'      => aioseo()->helpers->getSiteDomain( true ),
				'sku'         => $this->pluginSlug,
				'version'     => $this->version,
				'php_version' => PHP_VERSION,
				'wp_version'  => get_bloginfo( 'version' )
			] );

			if ( empty( $response ) || property_exists( $response, 'error' ) ) {
				$this->info = false;

				return $defaultApi;
			}

			$this->info = $response;
		}

		// Create a new stdClass object and populate it with our plugin information.
		$api                          = new \stdClass();
		$api->name                    = $this->info->name ?? '';
		$api->slug                    = $this->info->slug ?? '';
		$api->version                 = $this->info->version ?? '';
		$api->author                  = $this->info->author ?? '';
		$api->author_profile          = $this->info->author_profile ?? '';
		$api->requires                = $this->info->requires ?? '';
		$api->tested                  = $this->info->tested ?? '';
		$api->last_updated            = $this->info->last_updated ?? '';
		$api->homepage                = $this->info->homepage ?? '';
		$api->sections['description'] = $this->info->description ?? '';
		$api->sections['changelog']   = $this->info->changelog ?? '';
		$api->download_link           = $this->info->download_link ?? '';
		$api->active_installs         = $this->info->active_installs ?? '';
		$api->banners                 = isset( $this->info->banners ) ? (array) $this->info->banners : '';

		// Return the new API object with our custom data.
		return $api;
	}

	/**
	 * Get the URL to check licenses.
	 *
	 * @since 4.0.0
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