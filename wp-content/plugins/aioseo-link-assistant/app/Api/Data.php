<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all endpoints for generic link assistant data.
 *
 * @since 1.0.0
 */
class Data {
	/**
	 * Returns the menu data after activation.
	 *
	 * @NOTE: This function is run via a special hook inside the main settings API class.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function getMenuData() {
		$data = aioseoLinkAssistant()->helpers->getMenuData();

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $data['linkAssistant']
		], 200 );
	}

	/**
	 * Returns the Overview menu data.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function getOverviewData() {
		$data = aioseoLinkAssistant()->helpers->getOverviewData();

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $data
		], 200 );
	}

	/**
	 * Get post data after activation.
	 *
	 * @NOTE: This function is run via a special hook inside the main settings API class.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function getPostData( $request ) {
		$postId = (int) $request['postId'];
		if ( ! $postId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post ID was passed.'
			], 404 );
		}

		$data = aioseoLinkAssistant()->helpers->getPostData( [], $postId );

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $data
		], 200 );
	}

	/**
	 * Get suggestions scan percent completed.
	 *
	 * @NOTE: This function is run via a special hook inside the main settings API class.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function getSuggestionsScanPercent() {
		return new \WP_REST_Response( [
			'success' => true,
			'percent' => aioseoLinkAssistant()->helpers->getSuggestionsScanPercent()
		], 200 );
	}

	/**
	 * Dismisses the suggestions alert.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function dismissAlert() {
		aioseoLinkAssistant()->internalOptions->internal->dismissedAlerts->suggestions = true;

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Forces Link Assistant to pull in some link results.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function triggerScan() {
		// We pass in false so that we don't schedule a new, second scan.
		aioseoLinkAssistant()->main->links->scanPosts( false );

		$data = aioseoLinkAssistant()->helpers->getMenuData();

		// Clear the overview cache.
		aioseoLinkAssistant()->cache->delete( 'overview_data' );

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $data['linkAssistant']
		], 200 );
	}
}