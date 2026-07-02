<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

class SafeRedirectManager extends Importer {
	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'Safe Redirect Manager',
			'version'  => '1.10.0',
			'basename' => 'safe-redirect-manager/safe-redirect-manager.php',
			'slug'     => 'safe-redirect-manager'
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
		if ( ! function_exists( 'srm_get_redirects' ) ) {
			return;
		}

		$rules = \srm_get_redirects();
		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( $rules as $rule ) {
			$rule = $this->convertWildcards( $rule );

			if ( ! $this->validateStatusCode( $rule['status_code'] ) ) {
				continue;
			}

			if ( empty( $rule['redirect_from'] ) ) {
				$rule['redirect_from'] = '/';
			}

			// Codes higher than 400 don't have a target URL.
			if ( 400 <= $rule['status_code'] ) {
				$rule['redirect_to'] = '';
			}

			$redirect = Models\Redirect::getRedirectBySourceUrl( $rule['redirect_from'] );
			$redirect->set( [
				'source_url'   => $rule['redirect_from'],
				'target_url'   => $rule['redirect_to'],
				'type'         => $rule['status_code'],
				'query_param'  => json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
				'group'        => 'manual',
				'regex'        => 1 === (int) $rule['enable_regex'],
				'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
				'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
				'enabled'      => true
			] );
			$redirect->save();
		}
	}

	/**
	 * Converts unsupported wildcard format to supported regex format.
	 *
	 * @param  array $rule A Safe Redirect Manager redirect.
	 * @return array       A converted redirect.
	 */
	protected function convertWildcards( $rule ) {
		if ( '*' === substr( $rule['redirect_from'], -1, 1 ) ) {
			$rule['redirect_from'] = preg_replace( '/(\*)$/', '.*', (string) $rule['redirect_from'] );
			$rule['enable_regex']  = 1;
		}

		return $rule;
	}
}