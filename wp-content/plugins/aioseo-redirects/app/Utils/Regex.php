<?php
namespace AIOSEO\Plugin\Addon\Redirects\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regex helpers.
 *
 * @since 1.2.1
 */
class Regex {
	/**
	 * Returns a regex with ignore slash support.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $regex A regex string.
	 * @return string        The regex with ignore slash support.
	 */
	public static function ignoreSlash( $regex ) {
		// Slash at the end of the regex.
		$regex = preg_replace( '@/$@', '(|/)', (string) $regex, 1 );
		// Slash at the end of the regex with an explicit $.
		$regex = preg_replace( '@/\$$@', '(|/)$', (string) $regex, 1 );
		// Slash in the middle of the regex right before the start of a query arg.
		$regex = preg_replace( '@/\?|(?<!\\\)\?@', '(|/)?', (string) $regex, 1 );

		return $regex;
	}
}