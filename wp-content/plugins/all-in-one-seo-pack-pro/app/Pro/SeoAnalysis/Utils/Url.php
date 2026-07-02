<?php
namespace AIOSEO\Plugin\Pro\SeoAnalysis\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles parsing and sanitizing URLs.
 *
 * @since 4.8.6
 */
class Url {
	/**
	 * The parsed URL.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	public $parsedUrl = [];

	/**
	 * The URL.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 *
	 * @param string $value The URL to sanitize/parse.
	 */
	public function __construct( $value = null ) {
		$this->setUrl( $value );
	}

	/**
	 * Return a json encoded list of all attributes.
	 *
	 * @since 4.8.6
	 *
	 * @return string A JSON string.
	 */
	public function toJson() {
		return wp_json_encode( [
			'scheme'     => $this->getScheme(),
			'hostname'   => $this->getHostname(),
			'path'       => $this->getPath(),
			'fragment'   => $this->getFragment(),
			'tld'        => $this->getTld(),
			'domain'     => $this->getDomain(),
			'subdomains' => $this->getSubdomains()
		] );
	}

	/**
	 * Returns the URL.
	 *
	 * @since 4.8.6
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		$url = $this->getScheme() . '://' . $this->getHostname() . $this->getPath();
		if ( ! empty( $this->getQuery() ) ) {
			$url .= '?' . $this->getQuery();
		}

		if ( ! empty( $this->getFragment() ) ) {
			$url .= '#' . $this->getFragment();
		}

		return trim( $url );
	}

	/**
	 * Sets the URL.
	 *
	 * @since 4.8.6
	 *
	 * @param string $url The URL.
	 */
	public function setUrl( $url ) {
		// Sanitize URL with a protocol first if it is missing.
		if ( false === strpos( $url, '//' ) && '/' !== substr( $url, 0, 1 ) ) {
			$url = 'http://' . $url;
		}

		if ( '//' === substr( $url, 0, 2 ) ) {
			$url = 'http:' . $url;
		}

		$this->value = $url;
	}

	/**
	 * Returns the value of the specified key for the parsed URL.
	 *
	 * @see http://php.net/manual/en/function.parse-url.php
	 *
	 * @since 4.8.6
	 *
	 * @param  string $key The key to retrieve.
	 * @return string
	 */
	private function getParsedUrlValue( $key ) {
		if ( empty( $this->parsedUrl ) ) {
			$this->parsedUrl = wp_parse_url( $this->value );
		}

		return isset( $this->parsedUrl[ $key ] ) ? $this->parsedUrl[ $key ] : '';
	}

	/**
	 * Returns the domain from the URL.
	 *
	 * @since 4.8.6
	 *
	 * @return string The domain from the URL.
	 */
	public function getDomain() {
		$domain = $this->getHostname();

		return $domain ? preg_replace( '/^www\./', '', $domain ) : '';
	}

	/**
	 * Returns the hostname from the URL.
	 *
	 * @since 4.8.6
	 *
	 * @return string The hostname from the URL.
	 */
	public function getHostname() {
		$domain = $this->getParsedUrlValue( 'host' );

		if ( ! $domain ) {
			return '';
		}

		// Just return IP addresses.
		if ( filter_var( $domain, FILTER_VALIDATE_IP ) ) {
			return $domain;
		}

		$domain = $this->toAscii( $domain );

		return $domain ? $domain : '';
	}

	/**
	 * Returns a single string of all subdomains associated with this domain.
	 * Example 1: www
	 * Example 2: ww2.www
	 *
	 * @since 4.8.6
	 *
	 * @return array The subdomains associated with this domain.
	 */
	public function getSubdomains() {
		// If we can't find a TLD, we won't be able to parse a subdomain.
		if ( empty( $this->getTld() ) ) {
			return [];
		}

		// Return any subdomains as an array.
		return array_filter( explode( '.', rtrim( strstr( $this->getDomain(), $this->getTld(), true ), '.' ) ) );
	}

	/**
	 * Returns the TLD associated with this domain.
	 *
	 * @since 4.8.6
	 *
	 * @return string The TLD.
	 */
	public function getTld() {
		if ( preg_match( '/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]+)$/i', $this->getDomain(), $matches ) ) {
			return $matches['domain'];
		}

		return $this->getDomain();
	}

	/**
	 * Returns the scheme of the URL.
	 *
	 * @since 4.8.6
	 *
	 * @return string The scheme of the URL.
	 */
	public function getScheme() {
		return $this->getParsedUrlValue( 'scheme' );
	}

	/**
	 * Returns the path of the URL.
	 *
	 * @since 4.8.6
	 *
	 * @return string The path of the URL.
	 */
	public function getPath() {
		return $this->getParsedUrlValue( 'path' );
	}

	/**
	 * Returns the query of the URL.
	 * The query is the part of the URL after the ?.
	 *
	 * @since 4.8.6
	 *
	 * @return string The path of the URL.
	 */
	public function getQuery() {
		return $this->getParsedUrlValue( 'query' );
	}

	/**
	 * Returns the fragment of the URL.
	 * The fragment is the part of the URL after the #.
	 *
	 * @since 4.8.6
	 *
	 * @return string The path of the URL.
	 */
	public function getFragment() {
		return $this->getParsedUrlValue( 'fragment' );
	}

	/**
	 * Returns the passed domain, converted to ASCII, using the IDNA 2008,
	 * UTS #46 standard.
	 *
	 * @since 4.8.6
	 *
	 * @param  string      $domain The domain. Expected to be the hostname only.
	 * @return bool|string         The converted domain on success. False on failure.
	 */
	public function toAscii( $domain ) {
		return idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
	}

	/**
	 * Converts the given relative URL to an absolute one.
	 * Uses the base URL of the current URL util instance as the base.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $relativeUrl The relative URL to convert.
	 * @return Url                 A new URL util instance with the absolute URL.
	 */
	public function relativeToAbsolute( $relativeUrl ) {
		return new Url( $this->getScheme() . '://' . rtrim( $this->getHostname(), '/' ) . '/' . $this->getPath() . ltrim( $relativeUrl, '/' ) );
	}
}