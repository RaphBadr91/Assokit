<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

class Simple301Redirects extends Importer {
	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'Simple 301 Redirects',
			'version'  => '1.07',
			'basename' => 'simple-301-redirects/wp-simple-301-redirects.php',
			'slug'     => 'simple-301-redirects'
		]
	];

	/**
	 * Import.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function doImport() {
		$rules         = get_option( '301_redirects' );
		$usesWildcards = get_option( '301_redirects_wildcard' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( $rules as $origin => $target ) {
			if ( empty( $target ) ) {
				$target = '/';
			}

			// If wildcard redirects had been used, and this is one, flip it.
			$regex = false;
			if ( $usesWildcards && false !== strpos( $origin, '*' ) ) {
				$regex  = true;
				$origin = str_replace( '*', '(.*)', $origin );
				$target = str_replace( '*', '$1', $target );
			}

			$redirect = Models\Redirect::getRedirectBySourceUrl( $origin );
			$redirect->set( [
				'source_url'   => $origin,
				'target_url'   => $target,
				'type'         => 301,
				'query_param'  => json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
				'group'        => 'manual',
				'regex'        => $regex,
				'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
				'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
				'enabled'      => true
			] );
			$redirect->save();
		}
	}
}