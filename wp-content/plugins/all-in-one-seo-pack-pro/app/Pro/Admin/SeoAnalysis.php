<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Admin\SeoAnalysis as CommonSeoAnalysis;
use AIOSEO\Plugin\Pro\Models\Issue;
use AIOSEO\Plugin\Pro\Models\Term;

/**
 * Handles all admin code for the SEO Analysis menu.
 *
 * @since 4.8.6
 */
class SeoAnalysis extends CommonSeoAnalysis {
	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'save_post', [ $this, 'enqueuePostToScan' ], 10, 3 );
		add_action( 'saved_term', [ $this, 'enqueueTermToScan' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'deletedPostCallback' ] );
		add_action( 'delete_term', [ $this, 'deleteTermCallback' ] );
	}

	/**
	 * Callback for the deleted_post action.
	 *
	 * @since 4.8.6
	 *
	 * @param  int  $postId The ID of the post that was deleted.
	 * @return void
	 */
	public function deletedPostCallback( $postId ) {
		Issue::deleteAll( $postId, 'post' );
	}

	/**
	 * Callback for the delete_term action.
	 *
	 * @since 4.8.6
	 *
	 * @param  int  $termId The ID of the term that was deleted.
	 * @return void
	 */
	public function deleteTermCallback( $termId ) {
		Issue::deleteAll( $termId, 'term' );
	}

	/**
	 * Enqueues a post page to be scanned by the SEO Analyzer.
	 *
	 * @since 4.8.6
	 *
	 * @param  int      $postId The post id.
	 * @param  \WP_Post $post   The post object.
	 * @param  bool     $update Whether this is an existing post being updated.
	 * @return void
	 */
	public function enqueuePostToScan( $postId, $post, $update ) { //phpcs:ignore
		aioseo()->seoAnalysis->enqueuePostToScan( $postId );
	}

	/**
	 * Enqueues a term to be scanned by the SEO Analyzer.
	 *
	 * @since 4.8.6
	 *
	 * @param  int    $termId   The term id.
	 * @param  int    $ttId     The term taxonomy id.
	 * @param  string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function enqueueTermToScan( $termId, $ttId, $taxonomy ) {
		$taxonomiesToExclude = apply_filters( 'aioseo_seo_analyzer_scan_taxonomies_to_exclude', [ 'product_attributes' ] );
		if (
			in_array( $taxonomy, $taxonomiesToExclude, true ) ||
			! in_array( $taxonomy, aioseo()->helpers->getPublicTaxonomies( true ), true )
		) {
			return;
		}

		$aioseoTerm = Term::getTerm( $termId );
		$aioseoTerm->seo_analyzer_scan_date = null;
		$aioseoTerm->save();

		// Delete issues
		Issue::deleteAll( $termId, 'term' );
	}
}