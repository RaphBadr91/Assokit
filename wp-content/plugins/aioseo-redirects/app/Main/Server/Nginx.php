<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main\Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Main class to work with server redirects.
 *
 * @since 1.0.0
 */
class Nginx extends Server {
	/**
	 * %1$s is the origin
	 * %2$s is the target
	 * %3$s is the redirect type
	 * %4$s is the optional x-redirect-by filter.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $urlFormat = 'location = %1$s { %4$s return %3$s %2$s; }';

	/**
	 * %1$s is the origin
	 * %2$s is the target
	 * %3$s is the redirect type
	 * %4$s is the optional x-redirect-by filter.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $regexFormat = 'location ~ %1$s { %4$s return %3$s %2$s; }';

	/**
	 * %1$s is the origin
	 * %2$s is the optional x-redirect-by filter.
	 * %3$s is the redirect type
	 *
	 * @since 1.2.1
	 *
	 * @var string
	 */
	protected $urlNonTargetFormat = 'location = %1$s { %2$s return %3$s; }';

	/**
	 * %1$s is the origin
	 * %2$s is the optional x-redirect-by filter.
	 * %3$s is the redirect type
	 *
	 * @since 1.2.1
	 *
	 * @var string
	 */
	protected $regexNonTargetFormat = 'location ~ %1$s { %2$s return %3$s; }';

	/**
	 * The relocation format.
	 *
	 * %1$s is the target
	 * %2$s are protected paths
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $relocationFormat = 'if ($request_uri !~* (%2$s)) { return 301 %1$s$request_uri; }';

	/**
	 * The format for aliases.
	 *
	 * %1$s is the origin host
	 * %2$s is the target host
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $aliasFormat = 'if ($host ~* ^%1$s) {  return 301 %2$s$request_uri; }';

	/**
	 * Formats a redirect for use in the export.
	 *
	 * @since 1.0.0
	 *
	 * @param  object $redirect The redirect to format.
	 * @return string           The formatted redirect.
	 */
	public function format( $redirect ) {
		if ( ! $this->shouldRedirect( $redirect ) ) {
			return '';
		}

		// Exclude cases we can't redirect through the server. Those should still be handled by PHP.
		if ( ! $redirect->regex ) {
			$parsedSource = wp_parse_url( $redirect->source_url );
			if ( ! empty( $parsedSource['query'] ) ) {
				// We can't redirect a query string yet.
				return '';
			}
		}

		if ( $redirect->regex && $redirect->ignore_slash ) {
			$redirect->source_url = Utils\Regex::ignoreSlash( $redirect->source_url );
		}

		if ( ! $redirect->regex ) {
			if ( $redirect->ignore_slash || $redirect->ignore_case ) {
				// We'll need to turn this into a regex.
				$redirect->regex      = true;
				$redirect->source_url = '^' . preg_quote( (string) trim( $redirect->source_url ) );
				if ( $redirect->ignore_slash ) {
					$redirect->source_url = untrailingslashit( $redirect->source_url ) . '(|/)';
				}

				$redirect->source_url .= '$';
			}
		}

		// 4xx redirects don't have a target.
		$redirectType = intval( $redirect->type );
		if ( $redirectType >= 400 && $redirectType < 500 ) {
			return $this->formatNonTarget( $redirect );
		}

		return sprintf(
			$this->getFormat( $redirect->regex, $redirect->ignore_case ),
			$redirect->source_url,
			$redirect->target_url,
			$redirect->type,
			$this->addRedirectHeader()
		);
	}

	/**
	 * Returns the needed format for the redirect.
	 *
	 * @since 1.2.1
	 *
	 * @param  boolean $regex           Whether to use regex.
	 * @param  boolean $caseInsensitive Whether to match the case.
	 * @return string                   The format to use.
	 */
	protected function getFormat( $regex = false, $caseInsensitive = false ) {
		$format = $regex ? $this->regexFormat : $this->urlFormat;

		return $caseInsensitive ? $this->caseInsensitiveFormat( $format ) : $format;
	}

	/**
	 * Build the redirect output for non-target status codes (4xx).
	 *
	 * @since 1.2.1
	 *
	 * @param object  $redirect The redirect data.
	 * @return string           The formatted string.
	 */
	public function formatNonTarget( $redirect ) {
		return sprintf(
			$this->getNonTargetFormat( $redirect->regex, $redirect->ignore_case ),
			$redirect->source_url,
			$this->addRedirectHeader(),
			$redirect->type
		);
	}

	/**
	 * Get the format the redirect needs to output.
	 *
	 * @since 1.2.1
	 *
	 * @param  boolean $regex      Whether to use regex.
	 * @param  boolean $ignoreCase Whether to ignore case.
	 * @return string              The format of the redirect.
	 */
	public function getNonTargetFormat( $regex = false, $ignoreCase = false ) {
		$format = $regex ? $this->regexNonTargetFormat : $this->urlNonTargetFormat;

		return $ignoreCase ? $this->caseInsensitiveFormat( $format ) : $format;
	}

	/**
	 * Formats a relocation.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $relocationAddress The redirect to format.
	 * @return string                    The formatted redirect.
	 */
	public function formatRelocation( $relocationAddress, $protectedPaths = '' ) {
		return sprintf(
			$this->relocationFormat,
			$relocationAddress,
			$protectedPaths
		);
	}

	/**
	 * Formats an alias.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $alias The alias to format.
	 * @return string        The formatted alias.
	 */
	public function formatAlias( $alias ) {
		return sprintf(
			$this->aliasFormat,
			preg_quote( (string) $alias ),
			get_home_url()
		);
	}

	/**
	 * Formats a canonical redirect.
	 *
	 * @since 1.1.0
	 *
	 * @return void|string The formatted redirect.
	 */
	public function formatCanonical( $url, $https = false, $preferredDomain = '' ) {
		// Do not redirect canonical unless we are forcing https or a preferredDomain.
		if ( ! $https && ! $preferredDomain ) {
			return;
		}

		$conditions = 'set $aioseoCanonical 0;' . PHP_EOL;
		if ( $https ) {
			$conditions .= ' if ($scheme = http) { set $aioseoCanonical 1; } ' . PHP_EOL;
		}
		switch ( $preferredDomain ) {
			case 'add-www':
				$conditions .= 'if ($host !~* ^www) { set $aioseoCanonical 1; }' . PHP_EOL;
				break;
			case 'remove-www':
				$conditions .= 'if ($host ~* ^www) { set $aioseoCanonical 1; }' . PHP_EOL;
		}

		$canonical = 'if ($aioseoCanonical) { return 301 %1$s$request_uri; }' . PHP_EOL;

		return $conditions . sprintf( $canonical, $url );
	}

	/**
	 * Returns the format for case insensitive match.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $format The string format.
	 * @return string         The case insensitive format.
	 */
	private function caseInsensitiveFormat( $format ) {
		return preg_replace( '/^location \~/', 'location ~*', (string) $format );
	}

	/**
	 * Adds an X-Redirect-By header if allowed by the filter.
	 *
	 * @since 1.0.0
	 *
	 * @return string The redirect header.
	 */
	private function addRedirectHeader() {
		if ( apply_filters( 'aioseo_redirects_nginx_add_redirect_header', true ) ) {
			return 'add_header X-Redirect-By "AIOSEO";';
		}

		return '';
	}

	/**
	 * Get the test redirect.
	 *
	 * @since 1.1.4
	 *
	 * @return string The test redirect.
	 */
	protected function getAioseoRedirectTest() {
		return 'location /' . $this->test->getTestRedirect() . ' { add_header X-Redirect-By "AIOSEO Server Test"; return 301 /' . $this->test->getTestInterceptedRedirect() . '; }' . PHP_EOL;
	}
}