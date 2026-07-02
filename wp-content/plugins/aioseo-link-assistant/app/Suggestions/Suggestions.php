<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Suggestions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Registers and executes the Link Suggestions scan.
 *
 * @since 1.0.0
 */
class Suggestions {
	/**
	 * The base URL for the Link Suggestions server.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $baseUrl = 'https://link-suggestions.aioseo.com/v2/';

	/**
	 * The action name of the action that starts a suggestion scan.
	 *
	 * @since 1.0.3
	 *
	 * @var string
	 */
	private $registerScanActionName = 'aioseo_link_assistant_register_suggestions_scan';

	/**
	 * The action name of the main suggestions scan.
	 *
	 * @since 1.0.3
	 *
	 * @var string
	 */
	private $scanActionName = 'aioseo_link_assistant_suggestions_scan';

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

		if ( ! aioseo()->license->isActive() || aioseoLinkAssistant()->cache->get( 'teapot' ) ) {
			return;
		}

		add_action( $this->registerScanActionName, [ $this, 'registerScan' ] );
		add_action( $this->scanActionName, [ $this, 'scanPosts' ] );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'init', [ $this, 'scheduleRegisterScan' ] );
		add_action( 'init', [ $this, 'scheduleMainScan' ] );
	}

	/**
	 * Schedules a new suggestions scan if there is no ongoing one.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	public function scheduleRegisterScan() {
		$scanId = aioseoLinkAssistant()->internalOptions->internal->scanId;
		if (
			! empty( $scanId ) ||
			aioseo()->actionScheduler->isScheduled( $this->registerScanActionName ) ||
			aioseoLinkAssistant()->cache->get( 'no_scan' )
		) {
			return;
		}

		aioseo()->actionScheduler->scheduleAsync( $this->registerScanActionName );
	}

	/**
	 * Schedules a suggestions scan if there is no ongoing one.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	public function scheduleMainScan() {
		$scanId = aioseoLinkAssistant()->internalOptions->internal->scanId;
		if (
			empty( $scanId ) ||
			aioseo()->actionScheduler->isScheduled( $this->scanActionName )
		) {
			return;
		}

		aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 60 );
	}

	/**
	 * Kicks off the initial scan for the link suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerScan() {
		$transientName = 'registering_suggestions_scan';
		$scanId        = aioseoLinkAssistant()->internalOptions->internal->scanId;
		if ( ! empty( $scanId ) || aioseoLinkAssistant()->cache->get( $transientName ) ) {
			return;
		}

		$arePostsToScan = $this->data->arePostsToScan();
		if ( ! $arePostsToScan ) {
			aioseoLinkAssistant()->cache->update( 'no_scan', true, 15 * MINUTE_IN_SECONDS );

			return;
		}

		$posts = $this->data->getAllPosts();
		if ( empty( $posts ) ) {
			return;
		}

		$requestBody = array_merge(
			$this->data->getBaseData(),
			[
				'posts'                     => $posts,
				'cornerstoneContentPostIds' => $this->data->getCornerstoneContentPostIds(),
				'totals'                    => [
					'posts' => aioseoLinkAssistant()->helpers->getTotalScannablePosts()
				]
			]
		);

		// Set a transient while the scan is being registered to prevent the data from being uploaded multiple times.
		aioseoLinkAssistant()->cache->update( $transientName, true, 5 * MINUTE_IN_SECONDS );

		$response     = $this->doPostRequest( 'suggestions/scan/start/', $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );

		// Delete the transient again.
		aioseoLinkAssistant()->cache->delete( $transientName );

		if ( 401 === $responseCode ) {
			aioseo()->actionScheduler->scheduleSingle( $this->registerScanActionName, DAY_IN_SECONDS + wp_rand( 60, 600 ), [], true );

			return;
		}

		if ( 418 === $responseCode ) {
			aioseoLinkAssistant()->cache->update( 'teapot', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if ( 200 !== $responseCode || empty( $responseBody->success ) ) {
			return false;
		}

		aioseoLinkAssistant()->internalOptions->internal->scanId = $responseBody->scanId;
	}

	/**
	 * Scans posts for link suggestions.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	public function scanPosts() {
		$scanId = aioseoLinkAssistant()->internalOptions->internal->scanId;
		if ( empty( $scanId ) ) {
			return;
		}

		$postsToScan = $this->data->getPostsToScan( true );
		if ( empty( $postsToScan ) ) {
			$this->endScan();

			return;
		}

		Models\Suggestion::deleteNonDismissedSuggestions( $postsToScan );

		$requestBody  = [ 'postsToScan' => $postsToScan ];
		$response     = $this->doPostRequest( "suggestions/scan/{$scanId}/", $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $responseCode ) {
			aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, DAY_IN_SECONDS + wp_rand( 60, 600 ), [], true );

			return;
		}

		if ( 418 === $responseCode ) {
			aioseoLinkAssistant()->cache->update( 'teapot', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if ( 200 !== $responseCode || empty( $responseBody->success ) ) {
			// If the JSON file with the scan data cannot be found on the server, wipe the scan ID so the scan restarts.
			if ( ! empty( $responseBody->error ) && 'missing-scan-data' === strtolower( $responseBody->error ) ) {
				aioseoLinkAssistant()->internalOptions->internal->scanId = '';
			}

			aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 60, [], true );

			return false;
		}

		if ( empty( $responseBody->scannedPostsWithSuggestions ) ) {
			aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 60, [], true );

			return;
		}

		$scannedPostIds = array_keys( (array) $responseBody->scannedPostsWithSuggestions );
		$this->markPostsAsScanned( $scannedPostIds );

		$this->data->parseSuggestions( $responseBody->scannedPostsWithSuggestions );

		aioseo()->actionScheduler->scheduleSingle( $this->scanActionName, 60, [], true );
	}

	/**
	 * Ends the scan and deletes the scan ID.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	private function endScan() {
		$scanId = aioseoLinkAssistant()->internalOptions->internal->scanId;
		if ( empty( $scanId ) ) {
			return;
		}

		$response = wp_remote_request( $this->getUrl() . "suggestions/scan/$scanId/", [
			'method'     => 'DELETE',
			'timeout'    => 60,
			'headers'    => array_merge( [
				'Content-Type' => 'application/json'
			], aioseo()->helpers->getApiHeaders() ),
			'user-agent' => aioseo()->helpers->getApiUserAgent()
		] );

		$responseCode = (int) wp_remote_retrieve_response_code( $response );
		if ( 418 === $responseCode ) {
			aioseoLinkAssistant()->cache->update( 'teapot', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		// Reset the scan ID.
		aioseoLinkAssistant()->internalOptions->internal->scanId = null;
	}

	/**
	 * Refreshes the link suggestions for the given post.
	 *
	 * @since 1.0.3
	 *
	 * @param  \WP_Post $postToScan The post that needs to be scanned.
	 * @return void
	 */
	public function refresh( $postToScan ) {
		if ( aioseoLinkAssistant()->cache->get( 'refresh_delay' ) ) {
			return;
		}

		$posts = $this->data->getAllPosts();
		if ( empty( $posts ) ) {
			return;
		}

		Models\Suggestion::deleteNonDismissedSuggestions( $postToScan );

		$postToScan->phrases = $this->data->getPhrases( $postToScan );
		unset( $postToScan->post_content );

		$requestBody = array_merge(
			$this->data->getBaseData(),
			[
				'cornerstoneContentPostIds' => $this->data->getCornerstoneContentPostIds(),
				'postToScan'                => $postToScan,
				'posts'                     => $posts
			]
		);

		$response     = $this->doPostRequest( 'suggestions/refresh/', $requestBody );
		$responseCode = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $responseCode ) {
			aioseoLinkAssistant()->cache->update( 'refresh_delay', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		if ( 418 === $responseCode ) {
			aioseoLinkAssistant()->cache->update( 'teapot', true, HOUR_IN_SECONDS + wp_rand( 60, 600 ) );

			return;
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $response ) );
		if (
			200 !== $responseCode ||
			empty( $responseBody->success ) ||
			empty( $responseBody->scannedPostsWithSuggestions )
		) {
			return false;
		}

		$this->markPostsAsScanned( $postToScan->ID );

		$this->data->parseSuggestions( $responseBody->scannedPostsWithSuggestions );
	}

	/**
	 * Marks the given posts as scanned.
	 *
	 * @since 1.0.3
	 *
	 * @param  array|int $scannedPostIds The posts that were scanned.
	 * @return void
	 */
	private function markPostsAsScanned( $scannedPostIds ) {
		if ( ! is_array( $scannedPostIds ) ) {
			$scannedPostIds = [ $scannedPostIds ];
		}

		$tableName          = aioseo()->core->db->prefix . 'aioseo_posts';
		$postIdPlaceholders = aioseo()->helpers->implodePlaceholders( $scannedPostIds, '%d' );

		aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				"UPDATE $tableName
				SET `link_suggestions_scan_date`=%s
				WHERE `post_id` IN ( $postIdPlaceholders )",
				array_merge(
					[ gmdate( 'Y-m-d H:i:s' ) ],
					$scannedPostIds
				)
			)
		);
	}

	/**
	 * Sends a POST request to the server.
	 *
	 * @since 1.0.3
	 *
	 * @param  string            $path        The path.
	 * @param  array             $requestBody The request body.
	 * @return \WP_REST_Response              The response.
	 */
	private function doPostRequest( $path, $requestBody = [] ) {
		$requestData = [
			'timeout' => 60,
			'headers' => [
				'Content-Type' => 'application/json'
			]
		];

		if ( ! empty( $requestBody ) ) {
			$requestData['body'] = wp_json_encode( $requestBody );
		}

		$baseUrl  = $this->getUrl();
		$response = aioseo()->helpers->wpRemotePost( $baseUrl . $path, $requestData );

		return $response;
	}

	/**
	 * Returns the URL for the Link Suggestions server.
	 *
	 * @since 1.0.0
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		if ( defined( 'AIOSEO_LINK_SUGGESTIONS_URL' ) ) {
			return AIOSEO_LINK_SUGGESTIONS_URL;
		}

		return $this->baseUrl;
	}
}