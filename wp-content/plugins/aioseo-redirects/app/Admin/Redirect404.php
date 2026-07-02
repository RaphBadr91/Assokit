<?php
namespace AIOSEO\Plugin\Addon\Redirects\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;
use AIOSEO\Plugin\Addon\Redirects\Utils\WpUri;

/**
 * The Trash Post class.
 *
 * @since 1.2.2
 */
class Redirect404 {
	/**
	 * Class constructor.
	 *
	 * @since 1.2.2
	 */
	public function __construct() {
		if ( ! aioseo()->license->hasAddonFeature( 'aioseo-redirects', '404-parent-redirect' ) ) {
			return;
		}

		add_action( 'wp_trash_post', [ $this, 'postTrashed' ] );
		add_action( 'untrashed_post', [ $this, 'postUntrashed' ] );
		add_action( 'pre_delete_term', [ $this, 'termDeleted' ], 50, 2 );
	}

	/**
	 * Runs when a term is deleted.
	 *
	 * @since 1.2.2
	 *
	 * @param  int    $termId   The term ID.
	 * @param  string $taxonomy The taxonomy name.
	 * @return void
	 */
	public function termDeleted( $termId, $taxonomy = '' ) {
		// Not a public taxonomy? Then there's no need for a redirect.
		if ( ! in_array( $taxonomy, aioseo()->helpers->getPublicTaxonomies( true ), true ) ) {
			return;
		}

		// Filter to allow users to disable the 404 trashed redirects.
		if ( apply_filters( 'aioseo_redirects_disable_trashed_redirects', false ) ) {
			return;
		}

		$this->addTermRedirect( $termId, $taxonomy );
	}

	/**
	 * Runs when a post is trashed and the trash monitor is off.
	 *
	 * @since 1.2.2
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function postTrashed( $postId ) {
		// Not a public post type or not published? Then there's no need for a redirect.
		if (
			! in_array( get_post_type( $postId ), aioseo()->helpers->getPublicPostTypes( true ), true ) ||
			'publish' !== get_post_status( $postId )
		) {
			return;
		}

		// Filter to allow users to disable the 404 trashed messages.
		if ( apply_filters( 'aioseo_redirects_disable_trashed_redirects', false ) ) {
			return;
		}

		$this->addPostParentRedirect( $postId );
	}

	/**
	 * Runs when a post is untrashed.
	 *
	 * @since 1.2.2
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function postUntrashed( $postId ) {
		$redirect404 = Models\Redirect404::getRedirectByPostId( $postId );

		if ( $redirect404->exists() ) {
			$redirect404->delete();
		}
	}

	/**
	 * Adds a 404 automatic redirect to parent.
	 *
	 * @since 1.2.2
	 *
	 * @param  int  $postId The post id.
	 * @return void
	 */
	private function addPostParentRedirect( $postId ) {
		$urlPath  = WpUri::getUrlPath( get_permalink( $postId ) );
		$postType = get_post_type( $postId );

		$redirect404             = Models\Redirect404::getRedirectByUrl( $urlPath );
		$redirect404->post_id    = $postId;
		$redirect404->post_type  = $postType;
		$redirect404->source_url = $urlPath;

		// Direct post type parents.
		$ancestors = get_ancestors( $postId, $postType, 'post_type' );
		if ( ! empty( $ancestors ) ) {
			$redirect404->parent_posts = $ancestors;
		}

		// Term parents.
		$parentTerms = [];
		$objectTaxonomies = get_object_taxonomies( $postType, 'names' );
		if ( ! empty( $objectTaxonomies ) ) {
			foreach ( $objectTaxonomies as $objectTaxonomy ) {
				// Not a public taxonomy? Then it can't be redirected.
				if ( ! in_array( $objectTaxonomy, aioseo()->helpers->getPublicTaxonomies( true ), true ) ) {
					continue;
				}

				$parentTerms[ $objectTaxonomy ] = wp_get_object_terms( $postId, $objectTaxonomy, [ 'fields' => 'ids' ] );
			}
		}

		$parentTerms = array_filter( $parentTerms );
		if ( ! empty( $parentTerms ) ) {
			$redirect404->parent_terms = $parentTerms;
		}

		// Save only if we can actually redirect it.
		if (
			! empty( $redirect404->parent_posts ) ||
			! empty( $redirect404->parent_terms ) ||
			aioseo()->helpers->getPostTypeFeature( $postType, 'has_archive' )
		) {
			$redirect404->save();
		}
	}

	/**
	 * Adds a 404 automatic redirect to the term parent.
	 *
	 * @since 1.2.2
	 *
	 * @param  int    $termId   The term ID.
	 * @param  string $taxonomy The taxonomy name.
	 * @return void
	 */
	private function addTermRedirect( $termId, $taxonomy ) {
		$urlPath = WpUri::getUrlPath( get_term_link( $termId, $taxonomy ) );

		$redirect404             = Models\Redirect404::getRedirectByUrl( $urlPath );
		$redirect404->source_url = $urlPath;
		$redirect404->taxonomy   = $taxonomy;

		// Term parents.
		$ancestors = get_ancestors( $termId, $taxonomy, 'taxonomy' );
		if ( ! empty( $ancestors ) ) {
			$redirect404->parent_terms = [
				$taxonomy => $ancestors
			];
		}

		// Save only if we can actually redirect it.
		if (
			! empty( $redirect404->parent_terms ) ||
			'product_cat' === $taxonomy
		) {
			$redirect404->save();
		}
	}
}