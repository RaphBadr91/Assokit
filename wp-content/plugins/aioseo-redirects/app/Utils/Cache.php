<?php
namespace AIOSEO\Plugin\Addon\Redirects\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;

/**
 * Main class for redirects cache.
 *
 * @since 1.1.4
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The redirect addon cache prefix.
	 *
	 * @since 1.1.4
	 *
	 * @var string
	 */
	protected $prefix = 'aioseo_redirects_';

	/**
	 * The redirect URL cache prefix.
	 *
	 * @since 1.1.4
	 *
	 * @var string
	 */
	private $redirectUrlPrefix = 'url_';

	/**
	 * Gets redirects from cache for a URL path.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $path The path.
	 * @return array        An array of redirect results.
	 */
	public function getRedirects( $path ) {
		$redirects = $this->get( $this->getUrlCacheName( $path ), [ 'stdClass' ] );
		if ( ! empty( $redirects ) ) {
			foreach ( $redirects as &$redirect ) {
				$redirect = new Models\Redirect( $redirect );
			}
		}

		return $redirects;
	}

	/**
	 * Adds redirects to a URL path's cache.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $path      The path.
	 * @param  string $redirects Redirects to cache.
	 * @return void
	 */
	public function setRedirects( $path, $redirects ) {
		foreach ( $redirects as &$redirect ) {
			if ( is_a( $redirect, 'AIOSEO\Plugin\Addon\Redirects\Models\Redirect' ) ) {
				if ( isset( $redirect->regexMatches ) ) {
					$redirect = array_merge( $redirect->jsonSerialize(), [ 'regexMatches' => $redirect->regexMatches ] );
				} else {
					$redirect = $redirect->jsonSerialize();
				}
			}
		}

		$this->update( $this->getUrlCacheName( $path ), $redirects, WEEK_IN_SECONDS );
	}

	/**
	 * Deletes all redirects from cache.
	 *
	 * @since 1.1.4
	 *
	 * @return void
	 */
	public function clearRedirects() {
		$this->clearPrefix( $this->redirectUrlPrefix );
	}

	/**
	 * Adds redirects to a URL path's cache.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $path The path.
	 * @return string       The cache name.
	 */
	public function getUrlCacheName( $path ) {
		return $this->redirectUrlPrefix . Request::getUrlHash( $path );
	}
}