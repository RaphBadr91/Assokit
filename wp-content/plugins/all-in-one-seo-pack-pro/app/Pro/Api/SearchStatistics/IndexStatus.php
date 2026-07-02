<?php
namespace AIOSEO\Plugin\Pro\Api\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Index Status related REST API endpoint callbacks.
 *
 * @since 4.8.2
 */
class IndexStatus {
	/**
	 * Retrieves objects.
	 *
	 * @since 4.8.2
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchObjects( $request ) {
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Not connected to the Search Statistics service.' // Not shown to the user.
			], 400 );
		}

		$params           = $request->get_params();
		$formattedObjects = aioseo()->searchStatistics->indexStatus->getFormattedObjects( $params );

		return new \WP_REST_Response( [
			'success'   => true,
			'paginated' => $formattedObjects['paginated'],
		], 200 );
	}

	/**
	 * Retrieves the overview.
	 *
	 * @since 4.8.2
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchOverview( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Not connected to the Search Statistics service.' // Not shown to the user.
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => aioseo()->searchStatistics->indexStatus->getOverview(),
		], 200 );
	}
}