<?php

namespace AIOSEO\Plugin\Pro\Ai;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Ai as CommonAi;

/**
 * Handles AIOSEO AI.
 *
 * @since 4.8.4
 */
class Ai extends CommonAi\Ai {
	/**
	 * Class constructor.
	 *
	 * @since 4.8.4
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'init', [ $this, 'maybeResetAccessToken' ] );
	}

	/**
	 * Maybe reset the access token if the site got activated/deactivated through the network.
	 *
	 * @since 4.8.4
	 *
	 * @return void
	 */
	public function maybeResetAccessToken() {
		if (
			is_multisite() &&
			! is_network_admin()
		) {
			if (
				aioseo()->internalOptions->internal->ai->accessToken &&
				aioseo()->internalOptions->internal->ai->isTrialAccessToken &&
				aioseo()->license->isNetworkLicensed()
			) {
				// Force the site to get a new access token if the site got activated through the network.
				aioseo()->internalOptions->internal->ai->accessToken        = '';
				aioseo()->internalOptions->internal->ai->isTrialAccessToken = false;
				$this->getAccessToken( true );

				return;
			}

			if (
				aioseo()->internalOptions->internal->ai->accessToken &&
				! aioseo()->internalOptions->internal->ai->isTrialAccessToken &&
				! aioseo()->internalOptions->internal->ai->isManuallyConnected &&
				! aioseo()->license->isNetworkLicensed() &&
				! aioseo()->license->isActive()
			) {
				// If the site got deactivated through the network, we need to reset the access token.
				aioseo()->internalOptions->internal->ai->accessToken = '';
				$this->getAccessToken( true );
			}
		}
	}

	/**
	 * Gets an access token from the server.
	 *
	 * @since 4.8.4
	 *
	 * @param  bool $refresh Whether to refresh the access token.
	 * @return void
	 */
	public function getAccessToken( $refresh = false ) {
		// Check if user has access token. If not, get one from the server.
		if (
			(
				aioseo()->internalOptions->internal->ai->accessToken ||
				aioseo()->core->cache->get( 'ai-access-token-error' )
			) &&
			! $refresh
		) {
			return;
		}

		// If no license, run the lite flow.
		if ( ! aioseo()->license->getLicenseKey() ) {
			parent::getAccessToken( $refresh );

			return;
		}

		$response = aioseo()->helpers->wpRemotePost( $this->getApiUrl() . 'ai/auth/', [
			'body'    => [
				'domain' => aioseo()->helpers->getSiteDomain()
			],
			'headers' => $this->getRequestHeaders()
		] );

		if ( is_wp_error( $response ) ) {
			aioseo()->core->cache->update( 'ai-access-token-error', true, 1 * HOUR_IN_SECONDS );

			// Schedule another, one-time event in approx. 1 hour from now.
			aioseo()->actionScheduler->scheduleSingle( $this->creditFetchAction, 1 * ( HOUR_IN_SECONDS + wp_rand( 0, 30 * MINUTE_IN_SECONDS ) ), [] );

			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		if ( empty( $data->accessToken ) ) {
			aioseo()->core->cache->update( 'ai-access-token-error', true, 1 * HOUR_IN_SECONDS );

			// Schedule another, one-time event in approx. 1 hour from now.
			aioseo()->actionScheduler->scheduleSingle( $this->creditFetchAction, 1 * ( HOUR_IN_SECONDS + wp_rand( 0, 30 * MINUTE_IN_SECONDS ) ), [] );

			return;
		}

		aioseo()->internalOptions->internal->ai->accessToken        = sanitize_text_field( $data->accessToken );
		aioseo()->internalOptions->internal->ai->isTrialAccessToken = $data->isFree ?? false;

		// Reset the manually connected flag when getting a new token automatically.
		aioseo()->internalOptions->internal->ai->isManuallyConnected = false;

		// Fetch the credit totals.
		$this->updateCredits( true );
	}

	/**
	 * Returns the default request headers.
	 *
	 * @since 4.8.4
	 *
	 * @return array The default request headers.
	 */
	protected function getRequestHeaders() {
		$headers = parent::getRequestHeaders();

		$licenseKey = aioseo()->license->getLicenseKey();
		if ( ! $licenseKey || ! aioseo()->license->isActive() ) {
			return $headers;
		}

		$headers['X-AIOSEO-License'] = $licenseKey;

		return $headers;
	}
}