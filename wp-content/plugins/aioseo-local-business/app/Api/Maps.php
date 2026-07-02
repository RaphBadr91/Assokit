<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map class for the API.
 *
 * @since 1.1.3
 */
class Maps {
	/**
	 * Check if an API key has access to a library.
	 *
	 * @since 1.1.3
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function checkApiAccess( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['apiKey'] ) || empty( $params['apiName'] ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$updateOption = false;
		switch ( $params['apiName'] ) {
			case 'places/embed':
				$updateOption = 'mapsEmbedApiEnabled';
				$url          = add_query_arg( [
					'key' => $params['apiKey'],
					'q'   => 'New+York'
				], 'https://www.google.com/maps/embed/v1/place' );
				break;
		}

		if ( empty( $url ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$checkApiAccess = wp_remote_get( $url );

		if ( 200 === wp_remote_retrieve_response_code( $checkApiAccess ) ) {
			if ( $updateOption ) {
				aioseo()->options->localBusiness->maps->{$updateOption} = true;
			}

			return new \WP_REST_Response( [
				'success' => true
			], 200 );
		}

		if ( $updateOption ) {
			aioseo()->options->localBusiness->maps->{$updateOption} = false;
		}

		return new \WP_REST_Response( [
			'success' => false
		], 400 );
	}
}