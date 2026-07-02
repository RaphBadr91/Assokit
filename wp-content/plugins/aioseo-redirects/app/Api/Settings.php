<?php
namespace AIOSEO\Plugin\Addon\Redirects\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * Route class for the API.
 *
 * @since 1.0.0
 */
class Settings {
	/**
	 * Save options from the front end.
	 *
	 * @NOTE: This function is run via a special hook inside the main settings api class.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Request           The same request.
	 */
	public static function saveChanges( $request ) {
		$body    = $request->get_json_params();
		$options = ! empty( $body['redirectOptions'] ) ? $body['redirectOptions'] : [];

		if ( empty( $options ) ) {
			return $request;
		}

		aioseoRedirects()->options->sanitizeAndSave( $options );

		return $request;
	}

	/**
	 * Import other plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function importPlugins( $request ) {
		$body     = $request->get_json_params();
		$plugins  = ! empty( $body['plugins'] ) ? $body['plugins'] : [];

		foreach ( $plugins as $plugin ) {
			aioseoRedirects()->importExport->startImport( $plugin['plugin'] );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Export settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request.
	 * @param  \WP_REST_Response $response The parent response.
	 * @return \WP_REST_Response           The response.
	 */
	public static function exportSettings( $request, $response ) {
		if ( ! aioseo()->access->hasCapability( 'aioseo_redirects_settings' ) ) {
			return $response;
		}

		$body     = $request->get_json_params();
		$settings = ! empty( $body['settings'] ) ? $body['settings'] : [];

		if ( in_array( 'redirects', $settings, true ) ) {
			$response->data['settings']['settings']['redirects'] = aioseoRedirects()->options->all();
		}

		return $response;
	}

	/**
	 * Imports settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request.
	 * @param  \WP_REST_Response $response The REST Request.
	 * @return \WP_REST_Response           The response.
	 */
	public static function importSettings( $request, $response ) {
		if ( ! aioseo()->access->hasCapability( 'aioseo_redirects_settings' ) ) {
			return $response;
		}

		$file = $request->get_file_params()['file'];
		if (
			empty( $file['tmp_name'] ) ||
			empty( $file['type'] ) ||
			'application/json' !== $file['type']
		) {
			return $response;
		}

		$contents = aioseo()->core->fs->getContents( $file['tmp_name'] );

		// Since this could be any file, we need to pretend like every variable here is missing.
		$contents = json_decode( $contents, true );
		if ( empty( $contents ) ) {
			return $response;
		}

		if ( isset( $contents['settings']['redirects'] ) ) {
			aioseoRedirects()->options->sanitizeAndSave( $contents['settings']['redirects'] );
		}

		return $response;
	}

	/**
	 * Reset settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request.
	 * @param  \WP_REST_Response $response The REST Request.
	 * @return \WP_REST_Response           The response.
	 */
	public static function resetSettings( $request, $response ) {
		if ( ! aioseo()->access->hasCapability( 'aioseo_redirects_settings' ) ) {
			return $response;
		}

		$body     = $request->get_json_params();
		$settings = ! empty( $body['settings'] ) ? $body['settings'] : [];

		foreach ( $settings as $setting ) {
			switch ( $setting ) {
				case 'redirects':
					aioseoRedirects()->options->reset();
					break;
			}
		}

		return $response;
	}
}