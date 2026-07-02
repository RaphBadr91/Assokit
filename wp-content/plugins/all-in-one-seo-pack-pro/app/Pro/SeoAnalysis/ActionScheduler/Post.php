<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis\ActionScheduler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models\Post as AioseoPost;
use AIOSEO\Plugin\Pro\Models\Issue;

/**
 * Handles the action scheduler for Posts.
 *
 * @since 4.8.6
 */
class Post {
	/**
	 * The action.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	protected $action = 'aioseo_seo_analysis_posts_scan';

	/**
	 * The number of items to analyze per run.
	 *
	 * @since 4.8.6
	 *
	 * @var integer
	 */
	protected $perRun = 5;

	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 *
	 */
	public function __construct() {
		if ( ! aioseo()->options->general->licenseKey ) {
			return;
		}

		add_action( $this->action, [ $this, 'analyze' ] );
		add_action( 'admin_init', [ $this, 'schedule' ] );
	}

	/**
	 * Schedule actions.
	 *
	 * @since 4.8.6
	 *
	 * @return void
	 */
	public function schedule() {
		$nextScan = apply_filters( 'aioseo_seo_analyzer_posts_next_scan', aioseo()->internalOptions->internal->siteAnalysis->postsNextScan );
		if ( ! empty( $nextScan ) && $nextScan > time() ) {
			aioseo()->actionScheduler->unschedule( $this->action );

			return;
		}

		aioseo()->actionScheduler->scheduleRecurrent( $this->action, 0, MINUTE_IN_SECONDS );
	}

	/**
	 * Get the number of items to analyze per page.
	 *
	 * @since 4.8.6
	 *
	 * @return int
	 */
	protected function getPerRun() {
		return (int) apply_filters( 'aioseo_seo_analyzer_scan_items_per_run', $this->perRun );
	}

	/**
	 * Handles the analysis for X posts and store the results.
	 *
	 * @since 4.8.6
	 *
	 * @return void
	 */
	public function analyze() {
		$postsToAnalyze = $this->getEnqueuedPosts();
		if ( empty( $postsToAnalyze ) ) {
			aioseo()->internalOptions->internal->siteAnalysis->postsNextScan = strtotime( '+15 minutes' );

			return;
		}

		foreach ( $postsToAnalyze as $postId ) {
			Issue::deleteAll( $postId, 'post' );

			$scan                     = aioseo()->seoAnalysis->analyzePost( $postId );
			list( $basic, $advanced ) = array_values( $scan['results'] );

			$data     = array_merge( $basic, $advanced );
			$postType = get_post_type( $postId );
			foreach ( $data as $issue ) {
				$model = new Issue( [
					'object_id'      => $postId,
					'object_type'    => 'post',
					'object_subtype' => $postType,
					'code'           => $issue->code,
					'metadata'       => $issue->metadata
				] );

				$model->save();
			}

			// Update the scan date.
			$post = AioseoPost::getPost( $postId );
			$post->seo_analyzer_scan_date = gmdate( 'Y-m-d H:i:s' );
			$post->save();
		}
	}

	/**
	 * Get the posts that need to be analyzed.
	 *
	 * @since 4.8.6
	 *
	 * @return array Posts object
	 */
	private function getEnqueuedPosts() {
		$settings           = aioseo()->dynamicOptions->seoAnalysis->all();
		$publicPostTypes    = aioseo()->helpers->getScannablePostTypes();
		$publicPostStatuses = aioseo()->helpers->getPublicPostStatuses( true );

		$postTypes = $publicPostTypes;
		if ( 1 !== (int) $settings['postTypes']['all'] && ! empty( $settings['postTypes']['included'] ) ) {
			$postTypes = array_intersect( $postTypes, $settings['postTypes']['included'] );
		}

		$orderByCasesPostTypes = [];
		foreach ( $postTypes as $value ) {
			$count                   = count( $orderByCasesPostTypes ) + 1;
			$orderByCasesPostTypes[] = "WHEN p.post_type = '$value' THEN $count";
		}

		$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );
		if ( 1 !== (int) $settings['postStatuses']['all'] && ! empty( $settings['postStatuses']['included'] ) ) {
			$postStatuses = array_intersect( $postStatuses, $settings['postStatuses']['included'] );
		}

		$orderByCasesPostStatuses = [];
		foreach ( $postStatuses as $value ) {
			$count                      = count( $orderByCasesPostStatuses ) + 1;
			$orderByCasesPostStatuses[] = "WHEN p.post_status = '$value' THEN $count";
		}

		$select  = [ 'p.ID' ];
		$orderBy = [];

		if ( ! empty( $orderByCasesPostTypes ) ) {
			$select[]  = '(CASE ' . implode( ' ', $orderByCasesPostTypes ) . ' END) as post_type_order';
			$orderBy[] = 'post_type_order';
		}

		if ( ! empty( $orderByCasesPostStatuses ) ) {
			$select[]  = '(CASE ' . implode( ' ', $orderByCasesPostStatuses ) . ' END) as post_status_order';
			$orderBy[] = 'post_status_order';
		}

		$query = aioseo()->core->db->start( 'posts as p' )
			->select( implode( ', ', $select ) )
			->leftJoin( 'aioseo_posts as ap', 'ap.post_id = p.ID' )
			->whereIn( 'p.post_type', $publicPostTypes )
			->whereIn( 'p.post_status', $publicPostStatuses )
			->whereRaw( 'ap.seo_analyzer_scan_date IS NULL' );

		if ( ! empty( $orderBy ) ) {
			$query->orderBy( implode( ', ', $orderBy ) . ' ASC' );
		}

		$posts = $query
			->limit( $this->getPerRun() )
			->run()
			->result();

		// We just need the IDs.
		foreach ( $posts as &$post ) {
			$post = $post->ID;
		}

		return $posts;
	}
}