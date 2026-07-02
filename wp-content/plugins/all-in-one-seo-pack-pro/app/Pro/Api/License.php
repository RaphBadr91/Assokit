<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * Route class for the API.
 *
 * @since 4.0.0
 */
class License {
	/**
	 * Activate the license key.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function activateLicense( $request ) {
		$body         = $request->get_json_params();
		$network      = is_multisite() && ! empty( $body['network'] ) ? (bool) $body['network'] : false;
		$licenseKey   = ! empty( $body['licenseKey'] ) ? sanitize_text_field( $body['licenseKey'] ) : null;
		$responseCode = 200;
		$response     = [
			'success' => true
		];

		$options         = aioseo()->options;
		$internalOptions = aioseo()->internalOptions;
		$license         = aioseo()->license;
		if ( $network ) {
			$options         = aioseo()->networkOptions;
			$internalOptions = aioseo()->internalNetworkOptions;
			$license         = aioseo()->networkLicense;
		}

		// Save the license key.
		$options->general->licenseKey = $licenseKey;

		// Check if it validates.
		$activated = $license->activate();

		$licenseStats = [
			'isActive'   => $license->isActive(),
			'isExpired'  => $license->isExpired(),
			'isDisabled' => $license->isDisabled(),
			'isInvalid'  => $license->isInvalid(),
			'expires'    => $internalOptions->internal->license->expires
		];

		if ( $activated ) {
			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );
			aioseo()->core->networkCache->delete( 'addons' );

			$response['licenseData'] = $internalOptions->internal->license->all();
			$response['license']     = $licenseStats;

			$addons = aioseo()->addons->getAddons( true );
			foreach ( $addons as $addon ) {
				aioseo()->addons->getAddon( $addon->sku );
			}
		}

		// If it does not activate, update the response to be an error.
		if ( ! $activated ) {
			$options->general->licenseKey = null;

			$responseCode = 400;
			$response     = [
				'error'       => true,
				'licenseData' => $internalOptions->internal->license->all(),
				'license'     => $licenseStats
			];
		}

		aioseo()->notices->init();
		$response['notifications'] = Models\Notification::getNotifications();

		if ( aioseo()->internalOptions->internal->has( 'ai' ) ) {
			try {
				aioseo()->internalOptions->internal->ai->accessToken        = '';
				aioseo()->internalOptions->internal->ai->isTrialAccessToken = false;
				aioseo()->ai->getAccessToken( true );

				$response['aiOptions'] = aioseo()->internalOptions->internal->ai->all();
			} catch ( \Exception $e ) {
				error_log( $e->getMessage() );
			}
		}

		return new \WP_REST_Response( $response, $responseCode );
	}

	/**
	 * Deactivate the license key.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function deactivateLicense( $request ) {
		$body         = $request->get_json_params();
		$network      = is_multisite() && ! empty( $body['network'] ) ? (bool) $body['network'] : false;
		$responseCode = 200;
		$response     = [
			'success' => true
		];

		$options         = aioseo()->options;
		$internalOptions = aioseo()->internalOptions;
		$license         = aioseo()->license;
		if ( $network ) {
			$options         = aioseo()->networkOptions;
			$internalOptions = aioseo()->internalNetworkOptions;
			$license         = aioseo()->networkLicense;
		}

		// Deactivate the license.
		$deactivated = $license->deactivate();

		// Remove the license key.
		$options->general->licenseKey = null;

		$licenseStats = [
			'isActive'   => $license->isActive(),
			'isExpired'  => $license->isExpired(),
			'isDisabled' => $license->isDisabled(),
			'isInvalid'  => $license->isInvalid(),
			'expires'    => $internalOptions->internal->license->expires
		];

		if ( $deactivated ) {
			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );
			aioseo()->core->cache->delete( 'addons' );

			$internalOptions->internal->license->reset(
				[
					'expires',
					'expired',
					'invalid',
					'disabled',
					'activationsError',
					'connectionError',
					'requestError',
					'level',
					'addons'
				]
			);

			$response['license']     = $licenseStats;
			$response['licenseData'] = $internalOptions->internal->license->all();

			try {
				if ( aioseo()->internalOptions->internal->has( 'ai' ) ) {
					aioseo()->internalOptions->internal->ai->accessToken = '';
					aioseo()->ai->getAccessToken( true );

					$response['aiData'] = aioseo()->internalOptions->internal->ai->all();
				}
			} catch ( \Exception $e ) {
				error_log( $e->getMessage() );
			}
		}

		// If it does not deactivate, update the response to be an error.
		if ( ! $deactivated ) {
			$response = [
				'error'       => true,
				'licenseData' => $internalOptions->internal->license->all(),
				'license'     => $licenseStats
			];
		}

		aioseo()->notices->init();
		$response['notifications'] = Models\Notification::getNotifications();

		return new \WP_REST_Response( $response, $responseCode );
	}

	/**
	 * Multisite license activations and deactivation request.
	 *
	 * @since 4.2.5
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function multisite( $request ) {
		$body         = $request->get_json_params();
		$sites        = ! empty( $body['sites'] ) ? $body['sites'] : [];
		$domains      = [];
		$responseCode = 200;
		$response     = [
			'success' => true
		];

		if ( ! empty( $sites['activate'] ) ) {
			foreach ( $sites['activate'] as $siteData ) {
				$site = aioseo()->helpers->getSiteByBlogId( $siteData['blog_id'] );
				if ( $site ) {
					$domain = $site->domain;
					$path   = $site->path;

					if ( $domain !== $siteData['domain'] && $path !== $siteData['path'] ) {
						$aliases = aioseo()->helpers->getSiteAliases( $site );
						foreach ( $aliases as $alias ) {
							if ( $alias['domain'] === $siteData['domain'] ) {
								$domain = $siteData['domain'];
								$path   = $siteData['path'];
								break;
							}
						}
					}

					$domains['activate'][] = [
						'blog_id' => $siteData['blog_id'],
						'domain'  => $domain,
						'path'    => $path
					];
				}
			}
		}

		if ( ! empty( $sites['deactivate'] ) ) {
			foreach ( $sites['deactivate'] as $siteData ) {
				$site = aioseo()->helpers->getSiteByBlogId( $siteData['blog_id'] );
				if ( $site ) {
					$domain = $site->domain;
					$path   = $site->path;

					if ( $domain !== $siteData['domain'] && $path !== $siteData['path'] ) {
						$aliases = aioseo()->helpers->getSiteAliases( $site );
						foreach ( $aliases as $alias ) {
							if ( $alias['domain'] === $siteData['domain'] ) {
								$domain = $siteData['domain'];
								$path   = $siteData['path'];
								break;
							}
						}
					}

					$domains['deactivate'][] = [
						'blog_id' => $siteData['blog_id'],
						'domain'  => $domain,
						'path'    => $path
					];
				}
			}
		}

		// Check if it validates.
		$validated = aioseo()->networkLicense->multisite( $domains );

		$licenseStats = [
			'isActive'   => aioseo()->license->isActive(),
			'isExpired'  => aioseo()->license->isExpired(),
			'isDisabled' => aioseo()->license->isDisabled(),
			'isInvalid'  => aioseo()->license->isInvalid(),
			'expires'    => aioseo()->internalNetworkOptions->internal->license->expires
		];

		if ( $validated ) {
			// Force WordPress to check for updates.
			delete_site_transient( 'update_plugins' );
			aioseo()->core->networkCache->delete( 'addons' );

			$response['licenseData'] = aioseo()->internalNetworkOptions->internal->license->all();
			$response['license']     = $licenseStats;

			$addons = aioseo()->addons->getAddons( true );
			foreach ( $addons as $addon ) {
				aioseo()->addons->getAddon( $addon->sku );
			}
		}

		// If it does not validate, update the response to be an error.
		if ( ! $validated ) {
			$responseCode = 400;
			$response     = [
				'error'       => true,
				'licenseData' => aioseo()->internalNetworkOptions->internal->license->all(),
				'license'     => $licenseStats
			];
		}

		aioseo()->notices->init();
		$response['notifications'] = Models\Notification::getNotifications();

		return new \WP_REST_Response( $response, $responseCode );
	}

	/**
	 * Checks if the given sites are active.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function activated( $request ) {
		$body    = $request->get_json_params();
		$domains = ! empty( $body['domains'] ) ? $body['domains'] : [];
		if ( empty( $domains ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'No valid domains were passed.'
			], 400 );
		}

		$activeSites = aioseo()->networkLicense->areSitesActive( $domains );

		return new \WP_REST_Response( [
			'success'     => true,
			'activeSites' => $activeSites
		], 200 );
	}
}