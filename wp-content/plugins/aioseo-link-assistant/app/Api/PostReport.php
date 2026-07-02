<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all endpoints for the Post Report.
 *
 * @since 1.0.0
 */
class PostReport extends Common {
	/**
	 * Returns the initial data for the post report.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchPostsReport( $request ) {
		$filter            = $request->get_param( 'filter' );
		$body              = $request->get_json_params();
		$limit             = ! empty( $body['limit'] ) ? intval( $body['limit'] ) : 20;
		$offset            = ! empty( $body['offset'] ) ? intval( $body['offset'] ) : 0;
		$additionalFilters = ! empty( $body['additionalFilters'] ) ? $body['additionalFilters'] : [];
		if ( empty( $additionalFilters['postId'] ) || 'all' !== $filter ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post ID was passed.'
			], 200 );
		}

		$type  = $additionalFilters['type'] ?? '';
		$links = aioseoLinkAssistant()->helpers->getPostLinks( $additionalFilters['postId'], $limit, $offset, $type );

		$post           = aioseo()->helpers->getPost( $additionalFilters['postId'] );
		$postTypeObject = get_post_type_object( get_post_type( $additionalFilters['postId'] ) );

		return new \WP_REST_Response( [
			'success' => true,
			'links'   => $links,
			'context' => [
				'postTitle'   => aioseo()->helpers->decodeHtmlEntities( $post->post_title ),
				'publishDate' => $post->post_date,
				'permalink'   => get_permalink( $additionalFilters['postId'] ),
				'editLink'    => get_edit_post_link( $additionalFilters['postId'], '' ),
				'postType'    => aioseo()->helpers->getPostType( $postTypeObject )
			]
		], 200 );
	}
}