<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models\SearchStatistics as Models;
use AIOSEO\Plugin\Common\Models\Post;

/**
 * Handles the objects scan.
 *
 * @since 4.3.0
 */
class Objects {
	/**
	 * The action name.
	 *
	 * @since 4.3.0
	 *
	 * @var string
	 */
	public $action = 'aioseo_search_statistics_objects_scan';

	/**
	 * Class constructor.
	 *
	 * @since 4.3.0
	 */
	public function __construct() {
		if ( ! aioseo()->license->hasCoreFeature( 'search-statistics' ) ) {
			return;
		}

		add_action( 'admin_init', [ $this, 'init' ] );
		add_action( 'update_option_permalink_structure', [ $this, 'reset' ], 10 );
		add_action( 'updated_option', [ $this, 'syncHomeUrl' ], 10, 3 );

		add_action( $this->action, [ $this, 'scan' ] );
	}

	/**
	 * Initialize the objects.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return;
		}

		$this->scheduleInitialScan();

		add_action( 'save_post', [ $this, 'updatePost' ], 100 );
		add_action( 'delete_post', [ $this, 'updatePost' ], 100 );
		add_action( 'wp_trash_post', [ $this, 'updatePost' ], 100 );

		add_action( 'edit_term', [ $this, 'updateTerm' ], 100 );
		add_action( 'delete_term', [ $this, 'deleteTerm' ], 100 );
		add_action( 'created_term', [ $this, 'updateTerm' ], 100 );
	}

	/**
	 * Schedules the initial scan.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	private function scheduleInitialScan() {
		if ( aioseo()->actionScheduler->isScheduled( $this->action ) ) {
			return;
		}

		aioseo()->actionScheduler->scheduleAsync( $this->action );
	}

	/**
	 * Drops all object rows if the permalink structure changes.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public function reset() {
		aioseo()->core->db->truncate( 'aioseo_search_statistics_objects' )->run();

		as_unschedule_all_actions( $this->action );
		$this->scheduleInitialScan();
	}

	/**
	 * Syncs the homeurl when user updates the page on front.
	 *
	 * @since 4.3.4
	 *
	 * @param  string $option    The option name.
	 * @param  mixed  $oldValue  The old option value.
	 * @param  mixed  $value     The new option value.
	 * @return void
	 */
	public function syncHomeUrl( $option, $oldValue = '', $value = '' ) {
		if ( 'page_on_front' !== $option ) {
			return;
		}

		// Get the object with '/' path, which is the homepage.
		$wpObject = Models\WpObject::getObjectBy( 'path', '/' );

		// If the new value is greater than 0, then it's a page id and we need to update the object.
		if ( 0 < (int) $value ) {
			// Sets this object to the new homepage id, so it can be updated later by post_id.
			Models\WpObject::update( [
				'id'          => $wpObject->id ?? null,
				'object_id'   => $value,
				'object_type' => 'post',
				'object_path' => '/'
			] );

			// Update the post with all the needed data.
			$this->updatePost( $value );

			return;
		}

		// Otherwise, just detach the object from any post because it's not a page anymore.
		Models\WpObject::update( [
			'id'             => $wpObject->id ?? null,
			'object_id'      => null,
			'object_type'    => null,
			'object_subtype' => null,
			'object_path'    => '/',
		] );
	}

	/**
	 * The main scan function that triggers all the sub scans.
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function scan() {
		$postsObjects = $this->scanForPosts();
		$termsObjects = $this->scanForTerms();
		$objects      = array_merge( $postsObjects, $termsObjects );

		if ( empty( $objects ) ) {
			aioseo()->actionScheduler->scheduleSingle( $this->action, DAY_IN_SECONDS, [], true );

			return;
		}

		Models\WpObject::bulkInsert( $objects );

		aioseo()->actionScheduler->scheduleSingle( $this->action, MINUTE_IN_SECONDS, [], true );
	}

	/**
	 * Checks if posts need to be updated/inserted.
	 *
	 * @since 4.3.0
	 *
	 * @return array The list of posts to insert.
	 */
	private function scanForPosts() {
		$missingPosts = aioseo()->core->db->start( 'posts as p' )
			->select( 'DISTINCT p.ID, p.post_type, asso.object_id' )
			->leftJoin( 'aioseo_search_statistics_objects as asso', 'p.ID = asso.object_id' )
			->where( 'p.post_status', 'publish' )
			->whereIn( 'p.post_type', aioseo()->helpers->getPublicPostTypes( true ) )
			->whereRaw( '(
				asso.object_id IS NULL OR
				asso.updated < p.post_modified_gmt
			)' )
			->limit( 50 )
			->run()
			->result();

		if ( empty( $missingPosts ) ) {
			return [];
		}

		$objectsToInsert = [];
		foreach ( $missingPosts as $post ) {
			$path     = aioseo()->searchStatistics->helpers->getPageSlug( get_permalink( $post->ID ) );
			$wpObject = Models\WpObject::getObjectBy( 'path', $path );
			$object   = [
				'id'             => $wpObject->id ?? null,
				'object_id'      => $post->ID,
				'object_type'    => 'post',
				'object_subtype' => $post->post_type,
				'object_path'    => $path,
			];

			if ( ! empty( $object['id'] ) ) {
				Models\WpObject::update( $object );
			} else {
				$objectsToInsert[] = $object;
			}
		}

		return $objectsToInsert;
	}

	/**
	 * Checks if terms need to be updated/inserted.
	 *
	 * @since 4.5.0
	 *
	 * @return array The list of terms to insert.
	 */
	private function scanForTerms() {
		$missingTerms = aioseo()->core->db->start( 'terms as t' )
			->select( 'DISTINCT t.term_id, tt.taxonomy, asso.object_id' )
			->join( 'term_taxonomy as tt', 't.term_id = tt.term_id' )
			->leftJoin( 'aioseo_search_statistics_objects as asso', 't.term_id = asso.object_id' )
			->leftJoin( 'aioseo_terms as at', 't.term_id = at.term_id' )
			->whereIn( 'tt.taxonomy', aioseo()->helpers->getPublicTaxonomies( true ) )
			->whereRaw( '(
				asso.object_id IS NULL OR
				asso.updated < at.updated
			)' )
			->limit( 50 )
			->run()
			->result();

		if ( empty( $missingTerms ) ) {
			return [];
		}

		$objectsToInsert = [];
		foreach ( $missingTerms as $term ) {
			$path     = aioseo()->searchStatistics->helpers->getPageSlug( get_term_link( (int) $term->term_id, $term->taxonomy ) );
			$wpObject = Models\WpObject::getObjectBy( 'path', $path );
			$object   = [
				'id'             => $wpObject->id ?? null,
				'object_id'      => $term->term_id,
				'object_type'    => 'term',
				'object_subtype' => $term->taxonomy,
				'object_path'    => $path
			];

			if ( ! empty( $object['id'] ) ) {
				Models\WpObject::update( $object );
			} else {
				$objectsToInsert[] = $object;
			}
		}

		return $objectsToInsert;
	}

	/**
	 * Updates or deletes the data for the given post.
	 *
	 * @since 4.3.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function updatePost( $postId ) {
		if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}

		if ( 'publish' !== get_post_status( $postId ) ) {
			aioseo()->core->db->delete( 'aioseo_search_statistics_objects' )
				->where( 'object_id', $postId )
				->where( 'object_type', 'post' )
				->run();

			return;
		}

		$post = get_post( $postId );
		if ( ! is_a( $post, 'WP_Post' ) || ! in_array( $post->post_type, aioseo()->searchStatistics->helpers->getIncludedPostTypes(), true ) ) {
			return;
		}

		$path     = aioseo()->searchStatistics->helpers->getPageSlug( get_permalink( $postId ) );
		$wpObject = Models\WpObject::getObjectBy( 'post_id', $postId );
		$object   = [
			'id'             => $wpObject->id ?? null,
			'object_id'      => $postId,
			'object_type'    => 'post',
			'object_subtype' => $post->post_type,
			'object_path'    => $path,
		];

		if ( ! empty( $object['id'] ) ) {
			Models\WpObject::update( $object );
		} else {
			Models\WpObject::bulkInsert( [ $object ] );
		}

		// Clear cache the for the posts.
		aioseo()->core->cache->clearPrefix( 'aioseo_search_statistics_post_' );
	}

	/**
	 * Updates the data for the given term.
	 *
	 * @since 4.5.0
	 *
	 * @param  int  $termId The term ID.
	 * @return void
	 */
	public function updateTerm( $termId ) {
		$term = get_term( $termId );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return;
		}

		$path     = aioseo()->searchStatistics->helpers->getPageSlug( get_term_link( (int) $termId, $term->taxonomy ) );
		$wpObject = Models\WpObject::getObjectBy( 'term_id', $termId );
		$object   = [
			'id'             => $wpObject->id ?? null,
			'object_id'      => $termId,
			'object_type'    => 'term',
			'object_subtype' => $term->taxonomy,
			'object_path'    => $path
		];

		if ( ! empty( $object['id'] ) ) {
			Models\WpObject::update( $object );
		} else {
			Models\WpObject::bulkInsert( [ $object ] );
		}

		// Clear cache the for the terms.
		aioseo()->core->cache->clearPrefix( 'aioseo_search_statistics_term_' );
	}

	/**
	 * Deletes the data for the given term.
	 *
	 * @since 4.5.0
	 *
	 * @param  int  $termId The term ID.
	 * @return void
	 */
	public function deleteTerm( $termId ) {
		aioseo()->core->db->delete( 'aioseo_search_statistics_objects' )
			->where( 'object_id', $termId )
			->where( 'object_type', 'term' )
			->run();

		// Clear cache the for the terms.
		aioseo()->core->cache->clearPrefix( 'aioseo_search_statistics_term_' );
	}
}