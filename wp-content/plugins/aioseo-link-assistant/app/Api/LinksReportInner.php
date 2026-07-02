<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all endpoints for the inner Links Report.
 *
 * @since 1.0.0
 */
class LinksReportInner extends Common {
	/**
	 * Returns the initial data for the post report.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchLinksReportInner( $request ) {
		$body              = $request->get_json_params();
		$additionalFilters = ! empty( $body['additionalFilters'] ) ? $body['additionalFilters'] : [];
		if ( empty( $additionalFilters['postId'] ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post ID was passed.'
			], 404 );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'links'   => aioseoLinkAssistant()->helpers->getPostLinks( $additionalFilters['postId'], 5, 0 )
		], 200 );
	}
}