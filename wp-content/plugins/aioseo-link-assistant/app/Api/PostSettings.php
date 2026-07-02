<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles requests from the metabox.
 *
 * @since 1.0.0
 */
class PostSettings extends Common {
	/**
	 * Returns the updated link & suggestion results.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response with the link data.
	 */
	public static function update( $request ) {
		$postId      = isset( $request['postId'] ) ? $request['postId'] : 0;
		$postContent = isset( $request['postContent'] ) ? $request['postContent'] : '';
		if ( ! $postId || ! $postContent ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No post ID or content was passed.'
			], 404 );
		}

		// Scan for new links; suggestions are updated in the background.
		aioseoLinkAssistant()->main->links->data->indexLinks( $postId, $postContent );

		return new \WP_REST_Response( [
			'success' => true,
			'links'   => aioseoLinkAssistant()->helpers->getPostLinks( $postId, null )
		], 200 );
	}
}