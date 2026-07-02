<?php
namespace AIOSEO\Plugin\Addon\Redirects\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for the WP urls.
 *
 * @since 1.2.3
 */
class WpUri {
	/**
	 * Returns a post url path without the home path.
	 *
	 * @since 1.2.3
	 *
	 * @param  string $postId The post id.
	 * @return string         The path without WP's home path.
	 */
	public static function getPostPath( $postId ) {
		return self::getUrlPath( get_permalink( $postId ) );
	}

	/**
	 * Returns an url path without the home path.
	 *
	 * @since 1.2.3
	 *
	 * @param  string $url The url.
	 * @return string      The path without WP's home path.
	 */
	public static function getUrlPath( $url ) {
		return self::excludeHomePath( wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * Exclude the home path from a full path.
	 *
	 * @since   1.2.3
	 * @version 1.4.4 Restored this function from version 1.3.8
	 *
	 * @param  string $path The original path.
	 * @return string       The path without WP's home path.
	 */
	public static function excludeHomePath( $path ) {
		return preg_replace( '@^' . self::getHomePath() . '@', '/', (string) $path );
	}

	/**
	 * Exclude the home url from a full url.
	 *
	 * @since 1.2.3
	 *
	 * @param  string $url The original url.
	 * @return string      The url without WP's home url.
	 */
	public static function excludeHomeUrl( $url ) {
		$path = str_replace( self::getHomeUrl(), '', $url );

		return $url !== $path ? aioseo()->helpers->leadingSlashIt( $path ) : $url;
	}

	/**
	 * The home path.
	 *
	 * @since 1.2.6
	 *
	 * @return string The WP's home path.
	 */
	public static function getHomePath() {
		$homePath = aioseo()->helpers->getHomePath();
		// Account for WPML lang in the home URL.
		if ( 'directory' === aioseo()->helpers->getWpmlUrlFormat() ) {
			$regex    = function_exists( 'wpml_get_current_language' ) ? wpml_get_current_language() : '[a-z-]{2,5}';
			$homePath = preg_replace( '@/' . $regex . '/?$@', '', (string) $homePath );
		}

		// Account for TranslatePress lang in the home URL.
		$translatePressLangs = aioseo()->helpers->getTranslatePressUrlSlugs();
		if ( ! empty( $translatePressLangs ) ) {
			$locale   = get_locale();
			$regex    = empty( $locale ) || ! isset( $translatePressLangs[ $locale ] ) ? '[a-z-]{2,5}' : $translatePressLangs[ $locale ];
			$homePath = preg_replace( '@/' . $regex . '/?$@', '', (string) $homePath );
		}

		return untrailingslashit( $homePath ) . '/';
	}

	/**
	 * The home url.
	 *
	 * @since 1.2.6
	 *
	 * @return string The WP's home url.
	 */
	public static function getHomeUrl() {
		$homeUrl = get_home_url();

		// Account for WPML lang in the home URL.
		if ( 'directory' === aioseo()->helpers->getWpmlUrlFormat() ) {
			$regex   = function_exists( 'wpml_get_current_language' ) ? wpml_get_current_language() : '[a-z-]{2,5}';
			$homeUrl = preg_replace( '@/' . $regex . '/?$@', '', (string) $homeUrl );
		}

		// Account for TranslatePress lang in the home URL.
		$translatePressLangs = aioseo()->helpers->getTranslatePressUrlSlugs();
		if ( ! empty( $translatePressLangs ) ) {
			$locale   = get_locale();
			$regex    = empty( $locale ) || ! isset( $translatePressLangs[ $locale ] ) ? '[a-z-]{2,5}' : $translatePressLangs[ $locale ];
			$homeUrl = preg_replace( '@/' . $regex . '/?$@', '', (string) $homeUrl );
		}

		return user_trailingslashit( $homeUrl );
	}

	/**
	 * Add the home url to a path.
	 *
	 * @since 1.2.6
	 *
	 * @param  string $path A path.
	 * @return string       The path with WP's home url.
	 */
	public static function addHomeUrl( $path ) {
		return untrailingslashit( self::getHomeUrl() ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Add the home path to a path.
	 *
	 * @since 1.2.8
	 *
	 * @param  string $path A path.
	 * @return string       The path with WP's home url.
	 */
	public static function addHomePath( $path ) {
		return untrailingslashit( self::getHomePath() ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Returns an array of urls redirected by WordPress.
	 * @see \wp_redirect_admin_locations()
	 *
	 * @since 1.3.0
	 *
	 * @return array An array of WP redirected urls.
	 */
	public static function getRedirectAdminPaths() {
		$redirectAdminPaths = [
			home_url( 'wp-admin', 'relative' ),
			home_url( 'dashboard', 'relative' ),
			home_url( 'admin', 'relative' ),
			site_url( 'dashboard', 'relative' ),
			site_url( 'admin', 'relative' ),
			home_url( 'wp-login.php', 'relative' ),
			home_url( 'login', 'relative' ),
			site_url( 'login', 'relative' )
		];

		return array_map( [ __CLASS__, 'excludeHomePath' ], $redirectAdminPaths );
	}

	/**
	 * Checks if an url is internal to the site.
	 *
	 * @since 4.7.9
	 *
	 * @param  string $url The url to check.
	 * @return bool        True if the url is internal.
	 */
	public static function isUrlInternal( $url ) {
		$url = wp_parse_url( $url );

		// If there's no host, it's considered internal.
		if ( empty( $url['host'] ) ) {
			return true;
		}

		// Check against the home url.
		$url     = aioseoRedirects()->helpers->makeUrl( [ $url['host'], $url['path'] ?? '' ] );
		$homeUrl = wp_parse_url( self::getHomeUrl() );
		$homeUrl = aioseoRedirects()->helpers->makeUrl( [ $homeUrl['host'], $homeUrl['path'] ?? '' ] );

		return 0 === strpos( $url, $homeUrl );
	}
}