<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import class for the API.
 *
 * @since 1.3.0
 */
class Import {
	/**
	 * Import data from a plugin.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function importPlugins( $request ) {
		$plugins = $request->get_json_params();

		if ( empty( $plugins ) || ! is_array( $plugins ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		foreach ( $plugins as $plugin ) {
			if ( empty( $plugin['plugin'] ) ) {
				continue;
			}

			aioseoLocalBusiness()->import->startImport( $plugin['plugin'] );
		}

		return new \WP_REST_Response( [
			'success'              => true,
			'localBusinessOptions' => aioseo()->options->localBusiness->all(),
		], 200 );
	}
}