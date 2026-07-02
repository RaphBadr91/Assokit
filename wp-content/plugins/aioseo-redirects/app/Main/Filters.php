<?php

namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters class for third party plugins.
 *
 * @since 1.4.11
 */
class Filters {
	/**
	 * Class constructor.
	 *
	 * @since 1.4.11
	 */
	public function __construct() {
		// Add filter for LearnDash notifications subscription page.
		add_filter( 'aioseo_redirects_use_alternate_404_hook', [ $this, 'learnDashNotificationsSubscription' ], 10, 2 );
	}

	/**
	 * Checks if the current page is the LearnDash notifications subscription page.
	 *
	 * @since 1.4.11
	 *
	 * @param  bool   $useAlternate Whether to use the alternate 404 hook.
	 * @param  string $requestUrl   The current request URL.
	 * @return bool                 Whether to use the alternate 404 hook.
	 */
	public function learnDashNotificationsSubscription( $useAlternate, $requestUrl ) {
		// Check if the LearnDash Notifications Subscription Manager class exists.
		if (
			class_exists( 'LD_Notifications_Subscription_Manager' ) &&
			! empty( \LD_Notifications_Subscription_Manager::$slug ) &&
			preg_match( '#/' . \LD_Notifications_Subscription_Manager::$slug . '/?$#', $requestUrl )
		) {
			$useAlternate = true;
		}

		return $useAlternate;
	}
}