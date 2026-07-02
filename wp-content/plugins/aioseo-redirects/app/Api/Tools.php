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
class Tools {
	/**
	 * Save options from the front end.
	 *
	 * @NOTE: This function is run via a special hook inside the main settings api class.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request.
	 * @param  \WP_REST_Response $response The REST Response.
	 * @return \WP_REST_Response           The REST Response.
	 */
	public static function clearLog( $request, $response ) {
		$body = $request->get_json_params();
		$log  = ! empty( $body['log'] ) ? $body['log'] : null;

		$logSize = 0;
		switch ( $log ) {
			case 'redirectLogs':
				aioseo()->core->db->truncate( 'aioseo_redirects_logs' )->run();
				$logSize = aioseo()->core->db->getTableSize( 'aioseo_redirects_logs' );
				break;
			case 'logs404':
				aioseo()->core->db->truncate( 'aioseo_redirects_404_logs' )->run();
				$logSize = aioseo()->core->db->getTableSize( 'aioseo_redirects_404_logs' );
				break;
		}

		if ( ! empty( $logSize ) ) {
			$response->data['logSize'] = aioseo()->helpers->convertFileSize( $logSize );
		}

		return $response;
	}
}