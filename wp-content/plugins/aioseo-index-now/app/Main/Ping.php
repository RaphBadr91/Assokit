<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * Handles our IndexNow search engine ping feature.
 *
 * @since 1.0.0
 */
class Ping {
	/**
	 * The base url for IndexNow.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $baseUrl = 'https://api.indexnow.org/indexnow';

	/**
	 * The post ping scheduled action.
	 *
	 * @since 1.0.2
	 *
	 * @var string
	 */
	private $postPingAction = 'aioseo_index_now_ping_post';

	/**
	 * The term ping scheduled action.
	 *
	 * @since 1.0.2
	 *
	 * @var string
	 */
	private $termPingAction = 'aioseo_index_now_ping_term';

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Indexing is disabled if the site is not public.
		if ( ! get_option( 'blog_public' ) ) {
			return;
		}

		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Registers our hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		// Action Scheduler hooks.
		add_action( $this->postPingAction, [ $this, 'pingPost' ] );
		add_action( $this->termPingAction, [ $this, 'pingTerm' ] );

		if ( ! is_admin() ) {
			return;
		}

		if ( wp_doing_cron() ) {
			return;
		}

		// This action runs on ajax, so we need to move it up.
		add_action( 'pre_delete_term', [ $this, 'scheduleTerm' ], 1000 );
		add_action( 'create_term', [ $this, 'scheduleTerm' ], 1000 );

		if ( wp_doing_ajax() ) {
			return;
		}

		// Ping IndexNow on each post update.
		add_action( 'save_post', [ $this, 'schedulePost' ], 1000, 2 );
		add_action( 'delete_post', [ $this, 'schedulePost' ], 1000, 2 );

		// Ping IndexNow on each term update.
		add_action( 'edited_term', [ $this, 'scheduleTerm' ], 1000 );
	}

	/**
	 * Schedules an IndexNow ping for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param  integer  $postId The ID of the post.
	 * @param  \WP_Post $post   The post object.
	 * @return void
	 */
	public function schedulePost( $postId, $post = null ) {
		if ( ! aioseo()->helpers->isValidPost( $post, [ 'publish', 'trash' ] ) ) {
			return;
		}

		// If Limit Modified Date is enabled, let's return early.
		$aioseoPost = Models\Post::getPost( $postId );
		if ( $aioseoPost->limit_modified_date ) {
			return;
		}

		// Since this is a post, let's check if it's noindexed.
		aioseo()->meta->robots->post( $post );

		$meta = aioseo()->meta->robots->metaHelper( true );
		if ( ! empty( $meta['noindex'] ) ) {
			return;
		}

		$permalink = get_permalink( $postId );
		if ( 'trash' === $post->post_status ) {
			// We need to clone the post here so we can get a real permalink for the post even if it is not published already.
			$clonedPost              = clone $post;
			$clonedPost->post_status = 'publish';
			$clonedPost->post_name   = sanitize_title(
				$clonedPost->post_name ? $clonedPost->post_name : $clonedPost->post_title,
				$clonedPost->ID
			);

			$permalink = str_replace( '__trashed', '', get_permalink( $clonedPost ) );
		}

		if ( aioseo()->actionScheduler->isScheduled( $this->postPingAction, [ 'permalink' => $permalink ] ) ) {
			return;
		}

		// Schedule the new ping.
		aioseo()->actionScheduler->scheduleAsync( $this->postPingAction, [ 'permalink' => $permalink ] );
	}

	/**
	 * Schedules an IndexNow ping for a term.
	 *
	 * @since 1.0.0
	 *
	 * @param  integer $termId The ID of the post.
	 * @return void
	 */
	public function scheduleTerm( $termId ) {
		// Since this is a post, let's check if it's noindexed.
		$term = get_term( $termId );
		if ( ! is_a( $term, 'WP_Term' ) ) {
			return;
		}

		aioseo()->meta->robots->term( $term );

		$meta = aioseo()->meta->robots->metaHelper( true );
		if ( ! empty( $meta['noindex'] ) ) {
			return;
		}

		if ( aioseo()->actionScheduler->isScheduled( $this->termPingAction, [ 'termLink' => get_term_link( $term->term_id ) ] ) ) {
			return;
		}

		// Schedule the new ping.
		aioseo()->actionScheduler->scheduleAsync( $this->termPingAction, [ 'termLink' => get_term_link( $term->term_id ) ] );
	}

	/**
	 * Pings search engines via IndexNow when a post is updated.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $permalink The permalink of the post we want to update.
	 * @return void
	 */
	public function pingPost( $permalink ) {
		// If the keys is missing, let's go ahead and return early.
		$apiKey = aioseoIndexNow()->options->indexNow->apiKey;
		if ( empty( $apiKey ) ) {
			return;
		}

		// Return early if we have recently sent this request.
		$postRecentlySent = aioseoIndexNow()->cache->get( 'post_recently_sent_' . sha1( $permalink ) );
		if ( null !== $postRecentlySent ) {
			return;
		}

		aioseoIndexNow()->cache->update( 'post_recently_sent_' . sha1( $permalink ), time(), 11 * MINUTE_IN_SECONDS );

		wp_remote_post(
			$this->getUrl(),
			$this->getPostData( [ $permalink ] )
		);
	}

	/**
	 * Pings search engines via IndexNow when a term is updated.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $termLink The term URL we want to update.
	 * @return void
	 */
	public function pingTerm( $termLink ) {
		// If the keys is missing, let's go ahead and return early.
		$apiKey = aioseoIndexNow()->options->indexNow->apiKey;
		if ( empty( $apiKey ) ) {
			return;
		}

		// Return early if we have recently sent this request.
		$termRecentlySent = aioseoIndexNow()->cache->get( 'term_recently_sent_' . sha1( $termLink ) );
		if ( null !== $termRecentlySent ) {
			return;
		}

		aioseoIndexNow()->cache->update( 'term_recently_sent_' . sha1( $termLink ), time(), 11 * MINUTE_IN_SECONDS );

		wp_remote_post(
			$this->getUrl(),
			$this->getPostData( [ $termLink ] )
		);
	}

	/**
	 * Returns the data to send to IndexNow.
	 *
	 * @since 1.0.7
	 *
	 * @param  string $urls The URLs to send to IndexNow.
	 * @return array        The data to send to IndexNow.
	 */
	private function getPostData( $urls ) {
		$apiKey = aioseoIndexNow()->options->indexNow->apiKey;

		return [
			'headers' => [
				'Content-Type'  => 'application/json',
				'X-Source-Info' => 'https://aioseo.com/' . AIOSEO_VERSION . '/false'
			],
			'body'    => wp_json_encode(
				[
					'host'    => aioseo()->helpers->getSiteDomain(),
					'key'     => $apiKey,
					'urlList' => $urls
				]
			)
		];
	}

	/**
	 * Returns the URL for the remote endpoint we're pinging.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function getUrl() {
		if ( defined( 'AIOSEO_INDEX_NOW_API_URL' ) ) {
			return AIOSEO_INDEX_NOW_API_URL;
		}

		return $this->baseUrl;
	}
}