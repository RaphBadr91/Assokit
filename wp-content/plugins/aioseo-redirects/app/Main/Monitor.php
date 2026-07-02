<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Monitors changes to posts.
 *
 * @since 1.0.0
 */
class Monitor {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// We can't monitor changes without permalinks enabled.
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}

		// Monitor trash.
		if ( aioseoRedirects()->options->monitor->trash ) {
			add_action( 'wp_trash_post', [ $this, 'postTrashed' ] );
			if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === EMPTY_TRASH_DAYS ) {
				add_action( 'delete_post', [ $this, 'postTrashed' ] );
			}
		}
	}

	/**
	 * Adds an automatic redirect.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $postType The post type.
	 * @param  string $before   The url before.
	 * @param  string $after    The url after.
	 * @return bool             Automatic redirect added.
	 */
	public function automaticRedirect( $postType, $before, $after ) {
		if ( ! $this->canMonitorPostType( $postType ) ) {
			return false;
		}

		$this->addRedirect( $before, $after );

		return true;
	}

	/**
	 * Checks if this is a post we can monitor.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $postType  The post type.
	 * @return bool              True if we can monitor this post type.
	 */
	public function canMonitorPostType( $postType ) {
		if (
			! in_array( $postType, aioseoRedirects()->options->monitor->postTypes->included, true ) &&
			! aioseoRedirects()->options->monitor->postTypes->all
		) {
			return false;
		}

		// Don't do anything is the post type is not public.
		if ( ! is_post_type_viewable( $postType ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks for a modified slug on a monitored post.
	 *
	 * @since 1.2.1
	 *
	 * @param  string $before The url path before.
	 * @param  string $after  The url path after.
	 * @return void
	 */
	private function addRedirect( $before, $after ) {
		// Disable all redirects that match the new URL.
		$newUrlRedirects = aioseoRedirects()->redirect->getRedirectsBySource( $after );
		if ( ! empty( $newUrlRedirects ) ) {
			foreach ( $newUrlRedirects as $newUrlRedirect ) {
				$newUrlRedirect->enabled = false;
				$newUrlRedirect->save();
			}
		}

		// Maybe get an existing redirect to update it.
		// Should we ignore custom rule redirects?
		$redirect = aioseoRedirects()->redirect->getRedirectsBySource( $before, 'all' );
		$redirect = empty( $redirect ) ? new Models\Redirect() : current( $redirect );
		$redirect->set( [
			'source_url'   => $before,
			'target_url'   => $after,
			'type'         => 301,
			'query_param'  => json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
			'group'        => 'modified',
			'regex'        => false,
			'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
			'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
			'enabled'      => true
		] );

		$redirect->save();
	}

	/**
	 * Create a redirect if we are monitoring the trash.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function postTrashed( $postId ) {
		// Don't do anything if we can't monitor this post type.
		if ( ! $this->canMonitorPostType( get_post_type( $postId ) ) ) {
			return;
		}

		$url = Utils\WpUri::getPostPath( $postId );
		if ( '/' === $url ) {
			return;
		}

		$redirect = Models\Redirect::getRedirectBySourceUrl( $url );
		$redirect->set( [
			'source_url'   => $url,
			'target_url'   => '/',
			'type'         => 301,
			'query_param'  => json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
			'group'        => 'modified',
			'regex'        => false,
			'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
			'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
			'enabled'      => false
		] );
		$redirect->save();
	}
}