<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all endpoints for the Links Report.
 *
 * @since 1.0.0
 */
class LinksReport extends Common {
	/**
	 * Returns filtered links report results.
	 *
	 * @since 1.0.10
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchLinksReport( $request ) {
		$filter            = $request->get_param( 'filter' );
		$body              = $request->get_json_params();
		$limit             = ! empty( $body['limit'] ) ? intval( $body['limit'] ) : 20;
		$offset            = ! empty( $body['offset'] ) ? intval( $body['offset'] ) : 0;
		$searchTerm        = ! empty( $body['searchTerm'] ) ? sanitize_text_field( $body['searchTerm'] ) : null;
		$additionalFilters = ! empty( $body['additionalFilters'] ) ? $body['additionalFilters'] : [];

		return new \WP_REST_Response( [
			'success'     => true,
			'linksReport' => aioseoLinkAssistant()->helpers->getLinksReportData( $limit, $offset, $searchTerm, $filter, $additionalFilters )
		], 200 );
	}

	/**
	 * Deletes all links for the given post ID.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function deletePostLinks( $request ) {
		$postId = $request['postId'];

		if ( ! $postId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post ID was passed.'
			], 404 );
		}

		$links   = Models\Link::getLinks( $postId );
		$linkIds = array_map( function( $link ) {
			return $link->id;
		}, $links );

		aioseoLinkAssistant()->helpers->deleteLinksInPost( $linkIds );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}
}