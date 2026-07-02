<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Main class to run our full site redirects.
 *
 * @since 1.1.0
 */
class FullSiteRedirects {
	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		// HTTP headers for the whole site.
		add_filter( 'wp_headers', [ $this, 'siteHttpHeaders' ], 50 );

		// HTTP headers for redirections.
		add_filter( 'wp_redirect', [ $this, 'redirectHttpHeaders' ], 0 );

		// If we are using server level redirects, return early.
		if ( aioseoRedirects()->server->valid() ) {
			return;
		}

		// Aliases always run.
		add_action( 'init', [ $this, 'aliases' ], 2 );

		// We don't need to run canonical and relocate in WP CLI.
		if ( aioseo()->helpers->isDoingWpCli() ) {
			return;
		}

		add_action( 'init', [ $this, 'relocate' ], 0 );
		add_action( 'init', [ $this, 'canonical' ], 4 );
	}

	/**
	 * Runs if relocation is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function relocate() {
		$newDomain = $this->shouldRelocate();
		if ( ! $newDomain || ! $this->shouldRun() ) {
			return;
		}

		wp_redirect( aioseoRedirects()->helpers->makeUrl( [ $newDomain, Utils\Request::getRequestUrl() ] ), 301, 'AIOSEO' );
		exit;
	}

	/**
	 * Returns the relocation address if the website should be relocated.
	 *
	 * @since 1.1.0
	 *
	 * @return false|string The relocation address.
	 */
	public function shouldRelocate() {
		$newDomain = $this->getRelocateAddress();
		if ( ! aioseoRedirects()->options->fullSite->relocate->enabled || empty( $newDomain ) ) {
			return false;
		}

		return $newDomain;
	}

	/**
	 * Returns the relocation address validating if it's an actual URL.
	 *
	 * @since 1.1.0
	 *
	 * @return void|string The relocation address.
	 */
	public function getRelocateAddress() {
		$newDomain = aioseoRedirects()->options->fullSite->relocate->newDomain;

		if ( empty( $newDomain ) || ! aioseo()->helpers->isUrl( $newDomain ) ) {
			return;
		}

		return $newDomain;
	}

	/**
	 * Runs an alias redirect if it matches the server's current host.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function aliases() {
		$aliases = $this->getAliases();

		if ( empty( $aliases ) ) {
			return;
		}

		$aliases = array_map( 'untrailingslashit', $aliases );

		// Prevent redirect loop if the alias domain is the same as the current site domain.
		$realHost = wp_parse_url( get_home_url(), PHP_URL_HOST );
		if ( $this->getCurrentHost() === $realHost ) {
			return;
		}

		if ( in_array( $this->getCurrentHost(), $aliases, true ) ) {
			wp_redirect( aioseoRedirects()->helpers->makeUrl( [ get_home_url(), Utils\Request::getRequestUrl() ] ), 301, 'AIOSEO' );
			exit;
		}
	}

	/**
	 * Returns if the canonical redirect can happen.
	 *
	 * @since 1.1.0
	 *
	 * @return boolean|string The canonical url if the redirect can happen.
	 */
	public function shouldCanonical() {
		if ( ! aioseoRedirects()->options->fullSite->canonical->enabled ) {
			return false;
		}

		// These return the scheme + host, ignoring the path.
		$canonicalUrl = untrailingslashit( $this->getCanonicalHostUrl() );
		$siteUrl      = untrailingslashit( aioseo()->helpers->getSiteUrl() );

		// If the canonical url is NOT configured in WP it'll create a redirect loop and we need to prevent that.
		if ( $canonicalUrl !== $siteUrl ) {
			return false;
		}

		return $canonicalUrl;
	}

	/**
	 * Apply canonical settings if they are enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function canonical() {
		$canonicalHost = $this->shouldCanonical();

		// If the canonical URL is different from the current URL then we redirect.
		if ( $canonicalHost && $canonicalHost !== $this->getCurrentHostUrl() ) {
			wp_redirect( aioseoRedirects()->helpers->makeUrl( [ get_home_url(), Utils\Request::getRequestUrl() ] ), 301, 'AIOSEO' );
			exit;
		}
	}

	/**
	 * Add configured HTTP Headers to all pages.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $wpHeaders The WP headers.
	 * @return array            Our added headers.
	 */
	public function siteHttpHeaders( $wpHeaders ) {
		if ( ! $this->shouldRun() ) {
			return $wpHeaders;
		}

		$httpHeaders = $this->getHttpHeaders( [ 'site' ] );

		foreach ( $httpHeaders as $header ) {
			$wpHeaders = array_merge( $wpHeaders, $this->makeHttpHeader( $header ) );
		}

		return $wpHeaders;
	}

	/**
	 * Add configured HTTP Headers to redirects.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $url The target URL.
	 * @return string      The target URL.
	 */
	public function redirectHttpHeaders( $url ) {
		$headers = $this->getHttpHeaders( [ 'site', 'redirect' ] );
		if ( empty( $headers ) ) {
			return $url;
		}

		foreach ( $headers as $header ) {
			$header = $this->makeHttpHeader( $header );
			header( sprintf( '%s: %s', key( $header ), current( $header ) ) );
		}

		return $url;
	}

	/**
	 * Return a header string from a header object.
	 *
	 * @since 1.1.0
	 *
	 * @param  string|object $httpHeader The header object.
	 * @return array                     A http header array.
	 */
	public function makeHttpHeader( $httpHeader ) {
		$httpHeader  = is_string( $httpHeader ) ? json_decode( $httpHeader ) : $httpHeader;
		$headerValue = ( is_array( $httpHeader->value ) ? implode( ',', $httpHeader->value ) : $httpHeader->value );

		// Custom header.
		if ( 'custom' === $httpHeader->header ) {
			$httpHeader->header = $httpHeader->customHeader;
		}

		// Custom value.
		if ( ! empty( $httpHeader->customValue ) ) {
			$headerValue = preg_replace( '/\[[a-zA-Z0-9_-]+\]/', $httpHeader->customValue, (string) $headerValue );
		}

		return [ trim( $httpHeader->header ) => trim( $headerValue ) ];
	}

	/**
	 * Get the configured http headers optionally filtered by type.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $types Types to filter (site, redirect).
	 * @return array        HTTP header array.
	 */
	public function getHttpHeaders( $types = [] ) {
		$headers = aioseoRedirects()->options->fullSite->httpHeaders;
		if ( empty( $headers ) ) {
			return [];
		}

		foreach ( $headers as $key => &$httpHeader ) {
			$httpHeader = json_decode( $httpHeader );

			// Let's make sure the headers are usable or it may crash Apache.
			if (
				! $httpHeader ||
				empty( $httpHeader->header ) ||
				empty( $httpHeader->value ) ||
				(
					! empty( $types ) &&
					! empty( $httpHeader->location ) &&
					! in_array( $httpHeader->location, $types, true )
				)
			) {
				unset( $headers[ $key ] );
			}
		}

		return $headers;
	}

	/**
	 * Returns our aliases.
	 *
	 * @since 1.1.0
	 *
	 * @return array An array of aliases.
	 */
	public function getAliases() {
		$aliases = aioseoRedirects()->options->fullSite->aliases;
		foreach ( $aliases as &$alias ) {
			$alias = json_decode( $alias );
			if ( ! empty( $alias->aliasedDomain ) ) {
				// Replace or add double forward slashes so we can safely extract the host.
				$alias->aliasedDomain = preg_replace( '/^http(|s):\/\/|^(?!\/\/)/i', '//', (string) trim( $alias->aliasedDomain ) );
			}
			$alias = wp_parse_url( $alias->aliasedDomain, PHP_URL_HOST );
		}

		return array_filter( $aliases );
	}

	/**
	 * Gets our canonical configured host url.
	 *
	 * @since 1.1.0
	 *
	 * @return string The canonical host url.
	 */
	public function getCanonicalHostUrl() {
		$currentUrl = wp_parse_url( get_home_url() );

		// Start canonical url with the current url scheme.
		$canonicalUrl = $currentUrl['scheme'] . '://';

		if ( aioseoRedirects()->options->fullSite->canonical->httpToHttps ) {
			$canonicalUrl = 'https://';
		}

		$canonicalHost = $currentUrl['host'];

		$preferredDomain = aioseoRedirects()->options->fullSite->canonical->preferredDomain;
		if ( 'add-www' === $preferredDomain && ! preg_match( '/^www\./', (string) $canonicalHost ) ) {
			$canonicalHost = 'www.' . $canonicalHost;
		} elseif ( 'remove-www' === $preferredDomain && preg_match( '/^www\./', (string) $canonicalHost ) ) {
			$canonicalHost = preg_replace( '/^www\./', '', (string) $canonicalHost );
		}

		$canonicalUrl .= $canonicalHost;

		return untrailingslashit( $canonicalUrl );
	}

	/**
	 * Returns the current url being accessed.
	 *
	 * @since 1.1.0
	 *
	 * @return string The current URL.
	 */
	private function getCurrentHostUrl() {
		return untrailingslashit( $this->getCurrentScheme() . '://' . $this->getCurrentHost() );
	}

	/**
	 * Returns the current scheme.
	 *
	 * @since 1.1.0
	 *
	 * @return string The scheme.
	 */
	private function getCurrentScheme() {
		return is_ssl() ? 'https' : 'http';
	}

	/**
	 * Returns the current host.
	 *
	 * @since 1.1.0
	 *
	 * @return string The host address.
	 */
	private function getCurrentHost() {
		return untrailingslashit( Utils\Request::getRequestServerName() );
	}

	/**
	 * Returns if we should run a redirection.
	 *
	 * @since 1.1.0
	 *
	 * @return bool Should run.
	 */
	private function shouldRun() {
		if (
			is_admin() ||
			aioseo()->helpers->isDoingWpCli() ||
			aioseo()->helpers->isAjaxCronRestRequest() ||
			Utils\Request::isProtectedPath() ||
			aioseoRedirects()->redirect->isPageBuilderPreviewRequest()
		) {
			return false;
		}

		return true;
	}
}