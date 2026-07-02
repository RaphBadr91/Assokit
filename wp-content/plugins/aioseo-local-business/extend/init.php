<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Arrays.DisallowLongArraySyntax.Found
// If the class exists already don't redeclare.
if ( ! class_exists( 'AIOSEOExtend' ) ) {
	/**
	 * This class checks for compatibility for this plugin to load.
	 *
	 * @since 1.0.0
	 */
	class AIOSEOExtend {
		/**
		 * The addon name.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		private $name = '';

		/**
		 * The function name.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		private $function = '';

		/**
		 * The filename.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		private $file = '';

		/**
		 * The minimum version.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		private $minimumVersion = '';

		/**
		 * The required plan levels.
		 *
		 * @since 1.0.0
		 *
		 * @var array
		 */
		private $levels = array();

		/**
		 * Whether to disable other notices.
		 *
		 * @since 1.0.0
		 *
		 * @var bool
		 */
		public $disableNotices = false;

		/**
		 * Holds the active AIOSEO Pro version.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public static $aioseoVersion = '';

		/**
		 * Holds the addon notices.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public static $addonNotices = array();

		/**
		 * The construct function.
		 *
		 * @since 1.0.0
		 *
		 * @param string $name           The name of the addon.
		 * @param string $function       The name of the function to call once we pass compatibility checks.
		 * @param string $file           The addon file to deactivate if checks fail.
		 * @param string $minimumVersion The minimum version of our plugin we can activate against.
		 * @param array  $levels         The levels that this addon support.
		 */
		public function __construct( $name, $function, $file, $minimumVersion, $levels = array() ) {
			$this->name           = $name;
			$this->function       = $function;
			$this->file           = $file;
			$this->minimumVersion = $minimumVersion;
			$this->levels         = $levels;

			add_action( 'plugins_loaded', array( $this, 'init' ) );
		}

		/**
		 * Check addon requirements.
		 * We do it on `plugins_loaded` hook. If earlier the core constants are still not defined.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function init() {
			if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
				$this->failPhp();

				return;
			}

			// Since the version numbers may vary, we only want to compare the first 3 numbers.
			self::$aioseoVersion = defined( 'AIOSEO_VERSION' ) ? current( explode( '-', AIOSEO_VERSION ) ) : '';

			if ( ! has_action( 'admin_notices', array( __CLASS__, 'adminNotices' ) ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'adminNotices' ) );
			}

			if (
				empty( self::$aioseoVersion ) ||
				version_compare( self::$aioseoVersion, $this->minimumVersion, '<' )
			) {
				$this->requiresPro();
				add_filter( 'auto_update_plugin', array( $this, 'disableAutoUpdate' ), 10, 2 );
				add_filter( 'plugin_auto_update_setting_html', array( $this, 'modifyAutoupdaterSettingHtml' ), 11, 2 );

				return;
			}

			add_action( 'aioseo_loaded', $this->function, 10 );
		}

		/**
		 * Prints out all notices.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public static function adminNotices() {
			// Double check we're actually in the admin before outputting anything.
			if ( ! is_admin() ) {
				return;
			}

			self::showAddonsNotice( 'FailPhpVersion', sprintf(
				// Translators: 1 - Opening link tag, 2 - Closing link tag.
				esc_html__( 'Your site is running an outdated version of PHP that is no longer supported and is not compatible with the following addons (%1$sRead more%2$s for additional information):', 'aioseo-addon' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'<a href="https://aioseo.com/docs/supported-php-version/" target="_blank" rel="noopener noreferrer">',
				'</a>'
			) );

			self::showAddonsNotice( 'FailUpdate', sprintf(
				// Translators: 1 - "All in One SEO 4.0.0".
				esc_html__( 'The following addons cannot be used, because they require an update to work with %1$s:', 'aioseo-addon' ),
				'<strong>All in One SEO Pro ' . wp_kses_post( self::$aioseoVersion ) . '</strong>' // We need to put the name here in since the plugin is most likely not active.
			) );

			self::showAddonsNotice( 'FailActiveLicense', sprintf(
				// Translators: 1 - "All in One SEO", 2 - Opening HTML link tag, 3 - Closing HTML link tag.
				esc_html__( 'The following addons cannot be used, because they require an active license for %1$s. Your license is missing or has expired. To verify your subscription, please %2$svisit our website%3$s.', 'aioseo-addon' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				esc_html( defined( 'AIOSEO_PLUGIN_NAME' ) ? AIOSEO_PLUGIN_NAME : 'All in One SEO' ),
				'<a target="_blank" href="' . self::getUtmUrl( 'account/', 'FailActiveLicense', 'fail-valid-license' ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput, Generic.Files.LineLength.MaxExceeded
				'</a>'
			) );

			self::showMinimumVersionAddonsNotice( 'FailProVersion', sprintf(
				// Translators: 1 - "All in One SEO 4.0.0".
				esc_html__( 'The following addons cannot be used, because they require %1$s or later to work:', 'aioseo-addon' ),
				'<strong>All in One SEO Pro [minimumVersion]</strong>'
			) );

			self::showAddonsNotice( 'FailPlanExpired', sprintf(
				// Translators: 1 - Opening HTML link tag, 2 - Closing HTML link tag.
				esc_html__( 'The following addons cannot be used, because your plan has expired. To renew your subscription, please %1$svisit our website%2$s.', 'aioseo-addon' ),
				'<a target="_blank" href="' . self::getUtmUrl( 'account/subscriptions/', 'FailPlanExpired', 'fail-plan-expired' ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput, Generic.Files.LineLength.MaxExceeded
				'</a>'
			) );

			$level = '';
			if ( self::$aioseoVersion ) {
				$level = aioseo()->internalOptions->internal->has( 'license' ) && aioseo()->internalOptions->internal->license->level
					? aioseo()->internalOptions->internal->license->level
					: $level;
				if ( ! $level ) {
					if ( aioseo()->core->cache->get( 'failed_update' ) ) {
						return;
					}
					$level = esc_html__( 'Unlicensed', 'aioseo-addon' );
				}
			}

			self::showAddonsNotice( 'FailPlanLevel', sprintf(
				// Translators: 1 - The current plan name, 2 - Opening HTML link tag, 3 - Closing HTML link tag.
				esc_html__( 'The following addons cannot be used, because your plan level %1$s does not include access to these addons. To upgrade your subscription, please %2$svisit our website%3$s.', 'aioseo-addon' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'<strong>(' . ( ! empty( $level ) ? wp_kses_post( ucfirst( $level ) ) : '' ) . ')</strong>',
				'<a target="_blank" href="' . self::getUtmUrl( 'pro-upgrade/', 'FailPlanLevel', 'fail-plan-level' ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput, Generic.Files.LineLength.MaxExceeded
				'</a>'
			) );
		}

		/**
		 * Get the current notice.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $noticeKey The notice key.
		 * @return string            The current notice.
		 */
		public static function getCurrentNotice( $noticeKey ) {
			$noticeKey = 'aioseoAddon' . $noticeKey;
			if ( empty( self::$addonNotices[ $noticeKey ] ) ) {
				return '';
			}

			return current( self::$addonNotices[ $noticeKey ] );
		}

		/**
		 * Get the URL for the current notice.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $uri       The URL to append to the marketing site.
		 * @param  string $noticeKey The notice key.
		 * @param  string $content   The UTM content parameter.
		 * @return string            The current notice.
		 */
		public static function getUtmUrl( $uri, $noticeKey, $content ) {
			$url = 'https://aioseo.com/' . $uri;

			// Generate the new arguments.
			$args = [
				'utm_source'   => 'WordPress',
				'utm_campaign' => 'addon',
				'utm_medium'   => self::getCurrentNotice( $noticeKey ),
				'utm_content'  => $content
			];

			// Return the new URL.
			$url = add_query_arg( $args, $url );

			return esc_url( $url );
		}

		/**
		 * Adds a generic notice for an addon.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $noticeKey The notice key.
		 * @param  string $addonName The addon name.
		 * @return void
		 */
		public function addAddonNotice( $noticeKey, $addonName ) {
			if ( $this->disableNotices ) {
				return;
			}

			$noticeKey = 'aioseoAddon' . $noticeKey;
			if ( empty( self::$addonNotices[ $noticeKey ] ) ) {
				self::$addonNotices[ $noticeKey ] = array();
			}

			self::$addonNotices[ $noticeKey ][] = [
				'addonName'      => $addonName,
				'minimumVersion' => $this->minimumVersion
			];
		}

		/**
		 * HTML for the addon notice.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $noticeKey The notice key.
		 * @param  string $message   The message to output.
		 * @return void
		 */
		public static function showAddonsNotice( $noticeKey, $message ) {
			$noticeKey = 'aioseoAddon' . $noticeKey;
			if ( empty( self::$addonNotices[ $noticeKey ] ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo wp_kses_post( $message );
			echo '</p>';

			$addonsList = wp_list_pluck( self::$addonNotices[ $noticeKey ], 'addonName' );
			$addonsList = implode( '</strong></li><li><strong>', $addonsList );
			echo '<ul><li><strong>' . wp_kses_post( $addonsList ) . '</strong></li></ul>';

			echo '</div>';

			if ( isset( $_GET['activate'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
				unset( $_GET['activate'] );
			}
		}

		/**
		 * HTML for the addon notice.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $noticeKey The notice key.
		 * @param  string $message   The message to output.
		 * @return void
		 */
		public static function showMinimumVersionAddonsNotice( $noticeKey, $message ) {
			$noticeKey = 'aioseoAddon' . $noticeKey;
			if ( empty( self::$addonNotices[ $noticeKey ] ) ) {
				return;
			}

			$versions = array_unique( wp_list_pluck( self::$addonNotices[ $noticeKey ], 'minimumVersion' ) );
			sort( $versions );

			foreach ( $versions as $version ) {
				$versionMessage = str_replace( '[minimumVersion]', $version, $message );
				$addonsList     = wp_filter_object_list( self::$addonNotices[ $noticeKey ], [ 'minimumVersion' => $version ], 'and', 'addonName' );

				echo '<div class="notice notice-error"><p>';
				echo wp_kses_post( $versionMessage );
				echo '</p>';

				$addonsList = implode( '</strong></li><li><strong>', $addonsList );
				echo '<ul><li><strong>' . wp_kses_post( $addonsList ) . '</strong></li></ul>';

				echo '</div>';
			}

			if ( isset( $_GET['activate'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
				unset( $_GET['activate'] );
			}
		}

		/**
		 * Throws a notice if PHP version is too low.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function failPhp() {
			$this->addAddonNotice( 'FailPhpVersion', $this->name );
		}

		/**
		 * A secondary function to call if Pro is not active.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function requiresPro() {
			$this->addAddonNotice( 'FailProVersion', $this->name );
		}

		/**
		 * A secondary function to call if Pro is not active.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function requiresUpdate() {
			$this->addAddonNotice( 'FailUpdate', $this->name );
		}

		/**
		 * A secondary function to call if an active license is not found.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function requiresActiveLicense() {
			$this->addAddonNotice( 'FailActiveLicense', $this->name );
		}

		/**
		 * A secondary function to call if an expired license is found.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function requiresUnexpiredLicense() {
			$this->addAddonNotice( 'FailPlanExpired', $this->name );
		}

		/**
		 * A secondary function to call if an active license is not found.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function requiresPlanLevel() {
			$this->addAddonNotice( 'FailPlanLevel', $this->name );
		}

		/**
		 * Disable auto-update.
		 *
		 * @since 1.0.0
		 *
		 * @param  bool   $update Flag to update the plugin or not.
		 * @param  object $item   Update data about a specific plugin.
		 * @return bool           Whether or not to auto update.
		 */
		public function disableAutoUpdate( $update, $item ) {
			// If this is multisite and is not on the main site, return early.
			if ( is_multisite() && ! is_main_site() ) {
				return $update;
			}

			if (
				! empty( $item->plugin ) &&
				plugin_basename( $this->file ) === $item->plugin
			) {
				return false;
			}

			return $update;
		}

		/**
		 * Display AIOSEO Pro CTA on Plugins -> autoupdater setting column
		 *
		 * @since 1.0.0
		 *
		 * @param  string $html
		 * @param  string $pluginFile
		 * @return string             The HTML.
		 */
		public function modifyAutoupdaterSettingHtml( $html, $pluginFile ) {
			if ( plugin_basename( $this->file ) === $pluginFile &&
				// If main plugin (free) happens to be enabled and already takes care of this, then bail
				! apply_filters( "aioseo_is_autoupdate_setting_html_filtered_$pluginFile", false )
			) {
				$html = sprintf(
					'<a href="%s" target="_blank">%s</a>',
					"https://aioseo.com/docs/how-to-upgrade-from-all-in-one-seo-lite-to-pro/?utm_source=liteplugin&utm_medium=plugins-autoupdate&utm_campaign=upgrade-to-autoupdate&utm_content={$this->name}", // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					// Translators: 1 - "AIOSEO Pro"
					sprintf( esc_html__( 'Enable the %1$s plugin to manage auto-updates', 'aioseo-addon' ), 'AIOSEO Pro' )
				);
			}

			return $html;
		}
	}
}

if ( ! function_exists( 'aioseoAddonIsDisabled' ) ) {
	/**
	 * Disable the current addon if triggered externally.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $slug The addon slug.
	 * @return bool         True if the addon should be disabled.
	 */
	function aioseoAddonIsDisabled( $slug ) {
		if ( ! defined( 'AIOSEO_DEV_VERSION' ) && ! isset( $_REQUEST['aioseo-dev'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
			return false;
		}

		if ( ! isset( $_REQUEST['aioseo-disable-addon'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
			return false;
		}

		$request = wp_unslash( $_REQUEST['aioseo-disable-addon'] ); // phpcs:ignore HM.Security
		$request = explode( ',', $request );
		foreach ( $request as $r ) {
			if ( 0 !== strpos( $r, 'aioseo' ) ) {
				$r = 'aioseo-' . $r;
			}

			if ( $slug === $r ) {
				return true;
			}
		}

		return false;
	}
}