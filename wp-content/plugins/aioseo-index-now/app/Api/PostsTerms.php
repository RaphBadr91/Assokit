<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all post/term update related endpoints.
 *
 * @since 1.0.0
 */
class PostsTerms {
	/**
	 * Update post settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request.
	 * @param  \WP_REST_Response $response The REST Request.
	 * @return \WP_REST_Response           The response.
	 */
	public static function updatePosts( $request, $response ) {
		$body    = $request->get_json_params();
		$postId  = ! empty( $body['id'] ) ? intval( $body['id'] ) : null;
		$context = ! empty( $body['context'] ) ? sanitize_text_field( $body['context'] ) : 'post';

		// We just need to schedule a post or term ping.
		if ( 'post' === $context ) {
			$post = aioseo()->helpers->getPost( $postId );
			aioseoIndexNow()->ping->schedulePost( $postId, $post );

			return $response;
		}

		aioseoIndexNow()->ping->scheduleTerm( $postId );

		return $response;
	}
}