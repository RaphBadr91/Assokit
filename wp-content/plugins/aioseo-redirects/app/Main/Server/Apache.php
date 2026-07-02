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
class Apache extends Server {
	/**
	 * %1$s is the old URL
	 * %2$s is the new URL
	 * %3$s is the redirect type
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $urlFormat = 'RedirectMatch %3$s ^%1$s$ %2$s';

	/**
	 * %1$s is the old URL
	 * %2$s is the redirect type
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $urlNonTargetFormat = 'RedirectMatch %2$s ^%1$s$';

	/**
	 * %1$s is the old URL
	 * %2$s is the new URL
	 * %3$s is the redirect type
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $regexFormat = 'RedirectMatch %3$s %1$s %2$s';

	/**
	 * %1$s is the old URL
	 * %2$s is the redirect type
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $regexNonTargetFormat = 'RedirectMatch %2$s %1$s';

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
	protected $relocationFormat = 'RewriteCond %%{REQUEST_URI}%%{QUERY_STRING} !^(%2$s) \nRewriteRule .* %1$s%%{REQUEST_URI} [R=301,L]';

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
	protected $aliasFormat = 'RewriteCond %%{HTTP_HOST} %1$s \nRewriteRule .* %2$s%%{REQUEST_URI} [R=301,L]';

	/**
	 * The start of the server code.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $preContent = '';

	/**
	 * The end of the server code.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $postContent = '';

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->preContent  = '# BEGIN AIOSEO REDIRECTS' . PHP_EOL . '<IfModule mod_rewrite.c>' . PHP_EOL . 'RewriteEngine On' . PHP_EOL;
		$this->postContent = '</IfModule>' . PHP_EOL . '# END AIOSEO REDIRECTS' . PHP_EOL;
		parent::__construct();
	}

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

			if ( $redirect->ignore_slash ) {
				$redirect->source_url = untrailingslashit( $redirect->source_url ) . '(|/)';
			}
		}

		if ( $redirect->regex && $redirect->ignore_slash ) {
			$redirect->source_url = Utils\Regex::ignoreSlash( $redirect->source_url );
		}

		// 4xx redirects don't have a target.
		$redirectType = intval( $redirect->type );
		if ( $redirectType >= 400 && $redirectType < 500 ) {
			return $this->formatNonTarget( $redirect );
		}

		// If the target url is a relative url let's fix the target with a forward slash.
		if ( ! aioseo()->helpers->isUrl( $redirect->target_url ) ) {
			$redirect->target_url = '/' . ltrim( $redirect->target_url, '/' );
		}

		return sprintf(
			$this->getFormat( $redirect->regex, $redirect->ignore_case ),
			$redirect->source_url,
			$redirect->target_url,
			$redirect->type
		);
	}

	/**
	 * Returns the needed format for the redirect.
	 *
	 * @since 1.2.1
	 *
	 * @param  boolean $regex      Whether to use regex.
	 * @param  boolean $ignoreCase Whether to match the case.
	 * @return string              The format to use.
	 */
	protected function getFormat( $regex = false, $ignoreCase = false ) {
		$format = $regex ? $this->regexFormat : $this->urlFormat;

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
			str_replace( '\n', PHP_EOL, $this->relocationFormat ),
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
			str_replace( '\n', PHP_EOL, $this->aliasFormat ),
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

		$conditions = '';
		if ( $https ) {
			$conditions .= 'RewriteCond %%{HTTPS} off [OR]' . PHP_EOL;
		}
		switch ( $preferredDomain ) {
			case 'add-www':
				$conditions .= 'RewriteCond %%{HTTP_HOST} !^www [NC]' . PHP_EOL;
				break;
			case 'remove-www':
				$conditions .= 'RewriteCond %%{HTTP_HOST} ^www [NC]' . PHP_EOL;
		}

		$canonical = 'RewriteRule .* %1$s%%{REQUEST_URI} [R=301,L]' . PHP_EOL;

		return $conditions . sprintf( $canonical, $url );
	}

	/**
	 * Build the redirect output for non-target status codes (4xx).
	 *
	 * @since 1.0.0
	 *
	 * @param object  $redirect The redirect data.
	 * @return string           The formatted string.
	 */
	public function formatNonTarget( $redirect ) {
		return sprintf(
			$this->getNonTargetFormat( $redirect->regex, $redirect->ignore_case ),
			$redirect->source_url,
			$redirect->type
		);
	}

	/**
	 * Get the format the redirect needs to output.
	 *
	 * @since 1.0.0
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
	 * Returns the format for case insensitive match.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $format The string format.
	 * @return string         The case insensitive format.
	 */
	private function caseInsensitiveFormat( $format ) {
		return preg_replace( '/(\^%1\$s\$|%1\$s)/', '(?i)$1', (string) $format, 1 );
	}

	/**
	 * Get the test redirect.
	 *
	 * @since 1.1.4
	 *
	 * @return string The test redirect.
	 */
	protected function getAioseoRedirectTest() {
		return 'Redirect 301 "/' . $this->test->getTestRedirect() . '" "/' . $this->test->getTestInterceptedRedirect() . '"' . PHP_EOL;
	}
}