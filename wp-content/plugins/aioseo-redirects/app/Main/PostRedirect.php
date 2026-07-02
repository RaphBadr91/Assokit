<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors changes to posts.
 *
 * @since 1.2.7
 */
class PostRedirect {
	/**
	 * Class constructor.
	 *
	 * @since 1.2.7
	 */
	public function __construct() {
		add_action( 'transition_post_status', [ $this, 'postStatusChanged' ], 10, 3 );
	}

	/**
	 * Process a redirect tied to this post.
	 *
	 * @since 1.2.7
	 *
	 * @param  string   $newStatus The new status.
	 * @param  string   $oldStatus The old status.
	 * @param  \WP_Post $post      The post object.
	 * @return void
	 */
	public function postStatusChanged( $newStatus, $oldStatus = '', $post = null ) {
		// Sanity check.
		if ( ! is_object( $post ) || ! property_exists( $post, 'ID' ) ) {
			return;
		}

		$tiedRedirect = aioseoRedirects()->redirect->getRedirectByPostId( $post->ID );
		if ( ! $tiedRedirect->exists() ) {
			return;
		}

		// Refresh the source and remove the post_id reference.
		// This is done so we don't have duplicates for the same post_id.
		$tiedRedirect->refreshRedirectSource();
	}
}