<?php
namespace AIOSEO\Plugin\Addon\Redirects\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the request.
 *
 * @since 1.0.0
 */
class Request {
	/**
	 * Retrieve the request URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string The request URL.
	 */
	public static function getRequestUrl() {
		$url = '';

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$url = $_SERVER['REQUEST_URI'];
		}

		// Decode non-english characters.
		$url = rawurldecode( $url );

		// Remove the home path from the url for subfolder installs.
		$url = WpUri::excludeHomePath( $url );

		return apply_filters( 'aioseo_redirects_request_url', stripslashes( $url ) );
	}

	/**
	 * Get the plain 'matched' URL:
	 *
	 * - Encoded
	 * - Lowercase
	 * - No trailing slashes
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url The URL.
	 * @return string      The matched URL.
	 */
	public static function getMatchedUrl( $url ) {
		$url = explode( '?', $url );
		$url = untrailingslashit( $url[0] );

		// Return / or // as-is.
		if ( '/' !== $url ) {
			// Anything else remove the last /.
			$url = preg_replace( '@/$@', '', (string) $url );
		}

		// URL encode.
		$decode = [
			'/',
			':',
			'[',
			']',
			'@',
			'~',
			',',
			'(',
			')',
			';',
		];

		// Always try to decode the URL first. This makes sure there's no double encoding below.
		$url = rawurldecode( $url );

		// URL encode everything - this converts any i10n to the proper encoding.
		$url = rawurlencode( $url );

		// We also converted things we don't want encoding, such as a /. Change these back.
		foreach ( $decode as $char ) {
			$url = str_replace( rawurlencode( $char ), $char, $url );
		}

		// Lowercase everything.
		$url = aioseo()->helpers->toLowercase( $url );

		return $url ? $url : '/';
	}

	/**
	 * Gets the hash of the plain 'matched' URL.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $url The URL.
	 * @return string      The URL hash.
	 */
	public static function getMatchedUrlHash( $url ) {
		return self::getUrlHash( self::getMatchedUrl( $url ) );
	}

	/**
	 * Gets a URL hash.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $url The URL.
	 * @return string      The URL hash.
	 */
	public static function getUrlHash( $url ) {
		return sha1( $url );
	}

	/**
	 * Get the regex hash.
	 *
	 * @since 1.1.4
	 *
	 * @return string The hash.
	 */
	public static function getRegexHash() {
		return self::getUrlHash( 'regex' );
	}

	/**
	 * Get the target URL formatted.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url The URL.
	 * @return string      The URL.
	 */
	public static function getTargetUrl( $url ) {
		$parsed = wp_parse_url( $url );

		// Add trailing slash to the path if it's not a file and is an internal url.
		if ( ! empty( $parsed['path'] ) ) {
			$extension = pathinfo( $parsed['path'], PATHINFO_EXTENSION );
			if ( empty( $extension ) && WpUri::isUrlInternal( $url ) ) {
				$parsed['path'] = user_trailingslashit( $parsed['path'] );
			}
		}

		return self::buildUrl( $parsed );
	}

	/**
	 * Format source URL.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url The original url.
	 * @return string      The formatted URL.
	 */
	public static function formatSourceUrl( $url ) {
		if ( strpos( $url, 'http' ) === 0 || strpos( $url, 'ftp' ) === 0 ) {
			return $url;
		}

		return WpUri::addHomeUrl( $url );
	}

	/**
	 * Format source path.
	 *
	 * @since 1.2.8
	 *
	 * @param  string $path The original path.
	 * @return string       The formatted path.
	 */
	public static function formatSourcePath( $path ) {
		return WpUri::addHomePath( $path );
	}

	/**
	 * Format target URL.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url The original url.
	 * @return string      The formatted URL.
	 */
	public static function formatTargetUrl( $url ) {
		$url = self::getTargetUrl( $url );
		if ( strpos( $url, 'http' ) === 0 || strpos( $url, 'ftp' ) === 0 ) {
			return $url;
		}

		return WpUri::addHomeUrl( $url );
	}

	/**
	 * Format target path.
	 *
	 * @since 1.2.8
	 *
	 * @param  string $path The original path.
	 * @return string       The formatted path.
	 */
	public static function formatTargetPath( $path ) {
		return user_trailingslashit( WpUri::addHomePath( $path ) );
	}

	/**
	 * Get the user agent from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string The user agent.
	 */
	public static function getUserAgent() {
		$agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = $_SERVER['HTTP_USER_AGENT'];
		}

		$userAgent = apply_filters( 'aioseo_redirects_request_agent', $agent );

		return sanitize_text_field( $userAgent );
	}

	/**
	 * Get the referrer from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string The referrer.
	 */
	public static function getReferrer() {
		$referrer = '';
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer = wp_unslash( $_SERVER['HTTP_REFERER'] ); // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$referrer = apply_filters( 'aioseo_redirects_request_referrer', $referrer );

		return sanitize_text_field( $referrer );
	}

	/**
	 * Returns a cookie value by name.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $cookie The cookie name.
	 * @return string         The cookie value.
	 */
	public static function getCookie( $cookie ) {
		$cookie = ! empty( $_COOKIE[ $cookie ] ) ? wp_unslash( $_COOKIE[ $cookie ] ) : ''; // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized

		return sanitize_text_field( $cookie );
	}

	/**
	 * Get the request method from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string The request method.
	 */
	public static function getRequestMethod() {
		$method = '';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = $_SERVER['REQUEST_METHOD'];
		}

		$method = apply_filters( 'aioseo_redirects_request_method', $method );

		return sanitize_text_field( $method );
	}

	/**
	 * Get the headers from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string The headers.
	 */
	public static function getRequestHeaders() {
		$ignore = apply_filters( 'aioseo_redirects_request_headers_ignore', [
			'cookie',
			'host',
		] );
		$headers = [];

		foreach ( $_SERVER as $name => $value ) {
			if ( substr( $name, 0, 5 ) === 'HTTP_' ) {
				$name = strtolower( $name );
				if ( in_array( $name, array_map( 'strtolower', self::getIpHeaders() ), true ) ) {
					if ( ! aioseoRedirects()->options->logs->ipAddress->enabled ) {
						continue;
					}

					$ipLevel = json_decode( aioseoRedirects()->options->logs->ipAddress->level )->value;
					$value   = 'full' === $ipLevel ? $value : self::maskIp( $value );
				}

				$name = substr( $name, 5 );
				$name = str_replace( '_', ' ', $name );
				$name = ucwords( $name );
				$name = str_replace( ' ', '-', $name );

				if ( ! in_array( strtolower( $name ), $ignore, true ) ) {
					$headers[ $name ] = $value;
				}
			}
		}

		$headers = apply_filters( 'aioseo_redirects_request_headers', $headers );

		return array_map( 'sanitize_text_field', $headers );
	}

	/**
	 * Get a header from the request.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $header The header name.
	 * @return string         The header value.
	 */
	public static function getRequestHeader( $header ) {
		$header = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );

		$header = ! empty( $_SERVER[ $header ] ) ? wp_unslash( $_SERVER[ $header ] ) : ''; // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized

		return sanitize_text_field( $header );
	}

	/**
	 * Get the IP headers.
	 *
	 * @since 1.0.0
	 *
	 * @return string The IP headers.
	 */
	public static function getIpHeaders() {
		return [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_X_REAL_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'HTTP_VIA',
			'REMOTE_ADDR',
		];
	}

	/**
	 * Get the IP address from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string The IP address.
	 */
	public static function getIp() {
		$ip = '';

		foreach ( self::getIpHeaders() as $var ) {
			if ( ! empty( $_SERVER[ $var ] ) ) {
				$ip = wp_unslash( $_SERVER[ $var ] ); // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized
				$ip = explode( ',', $ip );
				$ip = array_shift( $ip );
				break;
			}
		}

		// Convert to binary.
		$ip = @inet_pton( trim( $ip ) );
		if ( false !== $ip ) {
			$ip = @inet_ntop( $ip ); // Convert back to string.
		}

		return $ip ? sanitize_text_field( $ip ) : '';
	}

	/**
	 * Masks the IP address passed in.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $ip The IP Address to mask.
	 * @return string     The masked IP address.
	 */
	public static function maskIp( $ip ) {
		$ip = trim( $ip );
		if ( false !== strpos( $ip, ':' ) ) {
			$ip = @inet_pton( trim( $ip ) );

			return @inet_ntop( $ip & pack( 'a16', 'ffff:ffff:ffff:ffff::ff00::0000::0000::0000' ) );
		}

		$parts = [];
		if ( strlen( $ip ) > 0 ) {
			$parts = explode( '.', $ip );
		}

		if ( count( $parts ) > 0 ) {
			$parts[ count( $parts ) - 1 ] = 0;
		}

		return implode( '.', $parts );
	}

	/**
	 * Get the server.
	 *
	 * @since 1.0.0
	 *
	 * @return string The server.
	 */
	public static function getServer() {
		return self::getProtocol() . '://' . self::getServerName();
	}

	/**
	 * Get the protocol.
	 *
	 * @since 1.0.0
	 *
	 * @return string The protocol.
	 */
	public static function getProtocol() {
		return is_ssl() ? 'https' : 'http';
	}

	/**
	 * Get the server name (from $_SERVER['SERVER_NAME]), or use the request name ($_SERVER['HTTP_HOST']) if not present.
	 *
	 * @since 1.0.0
	 *
	 * @return string The server name.
	 */
	public static function getServerName() {
		$host = self::getRequestServerName();

		if ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$host = wp_unslash( $_SERVER['SERVER_NAME'] ); // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$host = apply_filters( 'aioseo_redirects_request_server', $host );

		return sanitize_text_field( $host );
	}

	/**
	 * Get the request server name (from $_SERVER['HTTP_HOST]).
	 *
	 * @since 1.0.0
	 *
	 * @return string The request server name.
	 */
	public static function getRequestServerName() {
		$host = '';

		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = $_SERVER['HTTP_HOST'];
		}

		$host = apply_filters( 'aioseo_redirects_request_server_host', $host );

		return sanitize_text_field( $host );
	}

	/**
	 * Returns an array of protected paths.
	 *
	 * @since 1.1.0
	 *
	 * @return array An array of paths.
	 */
	public static function getProtectedPaths() {
		// Parse the admin, login, rest, wp_content and wp_includes urls for consistency.
		$wpAdmin    = WpUri::getUrlPath( get_admin_url() );
		$wpIncludes = WpUri::getUrlPath( includes_url() );
		$wpContent  = WpUri::getUrlPath( content_url() );
		$wpLogin    = WpUri::getUrlPath( wp_login_url() );
		$wpJson     = wp_parse_url( get_rest_url() );

		$paths = [
			$wpAdmin,
			$wpIncludes,
			$wpContent,
			$wpLogin,
			WpUri::excludeHomePath( $wpJson['path'] ) . ( ! empty( $wpJson['query'] ) ? '?' . $wpJson['query'] : '' )
		];

		// Path cleanup to make sure we are not excluding the whole website.
		foreach ( $paths as $pathKey => $path ) {
			if ( '/' === $path || empty( $path ) ) {
				unset( $paths[ $pathKey ] );
			}
		}

		return apply_filters( 'aioseo_redirects_protected_paths', $paths );
	}

	/**
	 * Returns if a url is protected.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $path The path.
	 * @return bool         Is the path protected.
	 */
	public static function isProtectedPath( $path = '' ) {
		if ( empty( $path ) ) {
			$path = self::getRequestUrl();
		}

		foreach ( self::getProtectedPaths() as $protectedPath ) {
			$pattern = '/^' . preg_quote( (string) $protectedPath, '/' ) . '/';
			if ( preg_match( $pattern, (string) $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns if a URL is external to this site.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $url The url to check.
	 * @return bool        Is the url external.
	 */
	public static function isUrlExternal( $url ) {
		$url            = wp_parse_url( $url );
		$currentSiteUrl = wp_parse_url( home_url() );

		if ( $url['host'] && $url['host'] !== $currentSiteUrl['host'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns if a URL has a trailing slash.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $url The url to check.
	 * @return bool        Has trailing slash.
	 */
	public static function urlHasTrailingSlash( $url ) {
		return preg_match( '/\/$/', (string) $url );
	}

	/**
	 * Builds a URL from a parse_url array.
	 * TODO: Replace all buildUrl in redirects with the main AIOSEO buildUrl helper.
	 *
	 * @since 1.1.4
	 *
	 * @param  array  $params  The params array.
	 * @param  array  $include The keys to include.
	 * @param  array  $exclude The keys to exclude.
	 * @return string          The url.
	 */
	public static function buildUrl( $params, $include = [], $exclude = [] ) {
		if ( ! empty( $include ) ) {
			foreach ( array_keys( $params ) as $includeKey ) {
				if ( ! in_array( $includeKey, $include, true ) ) {
					unset( $params[ $includeKey ] );
				}
			}
		}

		if ( ! empty( $exclude ) ) {
			foreach ( array_keys( $params ) as $excludeKey ) {
				if ( in_array( $excludeKey, $exclude, true ) ) {
					unset( $params[ $excludeKey ] );
				}
			}
		}

		$url = '';
		if ( ! empty( $params['scheme'] ) ) {
			$url .= $params['scheme'] . '://';
		}
		if ( ! empty( $params['user'] ) ) {
			$url .= $params['user'];

			if ( isset( $params['pass'] ) ) {
				$url .= ':' . $params['pass'];
			}

			$url .= '@';
		}

		if ( ! empty( $params['host'] ) ) {
			$url .= $params['host'];
		}

		if ( ! empty( $params['port'] ) ) {
			$url .= ':' . $params['port'];
		}

		if ( ! empty( $params['path'] ) ) {
			$url .= $params['path'];
		}

		if ( ! empty( $params['query'] ) ) {
			$url .= '?' . $params['query'];
		}

		if ( ! empty( $params['fragment'] ) ) {
			$url .= '#' . $params['fragment'];
		}

		return $url;
	}

	/**
	 * Normalize URL.
	 *
	 * @since 1.2.0
	 *
	 * @param  string $url The original url.
	 * @return string      The normalized url.
	 */
	public static function normalizeUrl( $url ) {
		return preg_replace( '/\s/', '', (string) $url );
	}

	/**
	 * Returns if the current request is a redirect test.
	 *
	 * @since 1.2.2
	 *
	 * @return bool Is it a redirect test.
	 */
	public static function isRedirectTest() {
		// Don't log the testRedirect as a 404.
		$testRedirect = aioseoRedirects()->server->test->getTestRedirect();
		if ( self::getRequestUrl() === '/' . $testRedirect || self::getRequestHeader( 'X-AIOSEO-Redirect-Test' ) ) {
			return true;
		}

		return false;
	}
}