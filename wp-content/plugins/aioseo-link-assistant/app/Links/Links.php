<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * Registers and executes the Links scan.
 *
 * @since 1.0.0
 */
class Links {
	/**
	 * The action name of the links scan.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $scanActionName = 'aioseo_link_assistant_links_scan';

	/**
	 * Data class instance.
	 *
	 * @since 1.0.11
	 *
	 * @var Data
	 */
	public $data = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->data = new Data();

		if ( ! aioseo()->license->isActive() ) {
			return;
		}

		add_action( $this->scanActionName, [ $this, 'scanPosts' ] );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'init', [ $this, 'scheduleInitialScan' ], 3002 );
		add_action( 'save_post', [ $this, 'scanPost' ], 20 );
	}

	/**
	 * Schedules the initial links scan.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduleInitialScan() {
		aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 10 );
	}

	/**
	 * Scans posts for links and stores them in the DB.
	 *
	 * @since 1.0.0
	 *
	 * @param  bool $shouldScheduleScan Whether a new scan should be scheduled.
	 * @return void
	 */
	public function scanPosts( $shouldScheduleScan = true ) {
		static $iterations = 0;
		$iterations++;

		aioseoLinkAssistant()->helpers->timeElapsed();

		$postsPerScan        = apply_filters( 'aioseo_link_assistant_links_posts_per_scan', 10 );
		$postTypes           = aioseoLinkAssistant()->helpers->getScannablePostTypes(); // Scan all post types so that results instantly show up when you include a new one.
		$postStatuses        = aioseo()->helpers->getPublicPostStatuses( true );
		$minimumLinkScanDate = aioseoLinkAssistant()->internalOptions->internal->minimumLinkScanDate;
		if ( empty( $minimumLinkScanDate ) ) {
			$minimumLinkScanDate = gmdate( 'Y-m-d H:i:s' );
		}

		$postsToScan = aioseo()->core->db->start( 'posts as p' )
			->select( 'p.ID, p.post_content, p.post_type, p.post_status' )
			->leftJoin( 'aioseo_posts as ap', 'p.ID = ap.post_id' )
			->whereIn( 'p.post_type', $postTypes )
			->whereIn( 'p.post_status', $postStatuses )
			->whereRaw( "(
				ap.post_id IS NULL OR
				ap.link_scan_date IS NULL OR
				ap.link_scan_date < p.post_modified_gmt OR
				ap.link_scan_date < '$minimumLinkScanDate'
			)" )
			->limit( $postsPerScan )
			->run()
			->result();

		if ( empty( $postsToScan ) ) {
			aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 15 * MINUTE_IN_SECONDS, [], true );

			return;
		}

		foreach ( $postsToScan as $postToScan ) {
			$this->scanPost( $postToScan );
		}

		$timeElapsed = aioseoLinkAssistant()->helpers->timeElapsed();
		if ( 20 > $timeElapsed && 200 > $iterations ) {
			$this->scanPosts( $shouldScheduleScan );

			return;
		}

		if ( $shouldScheduleScan ) {
			aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 60, [], true );
		}
	}

	/**
	 * Scans the given post for links.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object|int $post The post object or ID (if called on "save_post").
	 * @return void
	 */
	public function scanPost( $post ) {
		if ( ! is_object( $post ) ) {
			$post = aioseo()->helpers->getPost( $post );
		}

		if ( ! aioseoLinkAssistant()->helpers->isScannablePost( $post ) ) {
			return;
		}

		$this->data->indexLinks( $post->ID, $post->post_content );

		$aioseoPost = CommonModels\Post::getPost( $post->ID );
		$aioseoPost->set( [
			'post_id'        => $post->ID,
			'link_scan_date' => gmdate( 'Y-m-d H:i:s' )
		] );
		$aioseoPost->save();
	}
}