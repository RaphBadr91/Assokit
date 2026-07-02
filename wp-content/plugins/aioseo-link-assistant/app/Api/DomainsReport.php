<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all endpoints for the Domains Report.
 *
 * @since 1.0.0
 */
class DomainsReport {
	/**
	 * Returns paginated domain results.
	 *
	 * @since 1.0.10
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchDomainsReport( $request ) {
		$body       = $request->get_json_params();
		$limit      = ! empty( $body['limit'] ) ? intval( $body['limit'] ) : 20;
		$offset     = ! empty( $body['offset'] ) ? intval( $body['offset'] ) : 0;
		$searchTerm = ! empty( $body['searchTerm'] ) ? sanitize_text_field( $body['searchTerm'] ) : null;

		return new \WP_REST_Response( [
			'success'       => true,
			'domainsReport' => aioseoLinkAssistant()->helpers->getDomainsReportData( $limit, $offset, $searchTerm )
		], 200 );
	}

	/**
	 * Processes a bulk action on domains.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function bulk( $request ) {
		$body        = $request->get_json_params();
		$hostnames   = ! empty( $body['hostnames'] ) ? $body['hostnames'] : [];

		if ( empty( $hostnames ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid domains were passed.'
			], 400 );
		}

		foreach ( $hostnames as $hostname ) {
			$links   = Models\Link::getDomainLinks( $hostname );
			$linkIds = array_map( function( $link ) {
				return $link->id;
			}, $links );

			aioseoLinkAssistant()->helpers->deleteLinksInPost( $linkIds );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}
}