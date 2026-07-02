<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The License class to validate/activate/deactivate license keys.
 *
 * @since 4.2.5
 */
class NetworkLicense extends License {
	/**
	 * Class constructor.
	 *
	 * @since 4.2.5
	 */
	public function __construct() {
		$this->options         = aioseo()->networkOptions;
		$this->internalOptions = aioseo()->internalNetworkOptions;

		add_action( 'init', [ $this, 'maybeValidate' ] );

		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		if (
			is_network_admin() &&
			! is_plugin_active_for_network( plugin_basename( AIOSEO_FILE ) )
		) {
			return;
		}

		// phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized, HM.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'aioseo-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			add_action( 'network_admin_notices', [ $this, 'notices' ] );
		}
		// phpcs:enable

		add_action( 'after_plugin_row_' . AIOSEO_PLUGIN_BASENAME, [ $this, 'pluginRowNotice' ] );
		add_action( 'in_plugin_update_message-' . AIOSEO_PLUGIN_BASENAME, [ $this, 'updateRowNotice' ] );
	}

	/**
	 * Validate the license keys for a multisite setup.
	 *
	 * @since 4.2.5
	 *
	 * @param  array    $domains Domains for activation and deactivation.
	 * @return boolean           Whether or not it was activated.
	 */
	public function multisite( $domains ) {
		aioseo()->helpers->switchToBlog( aioseo()->helpers->getNetworkId() );

		$this->internalOptions->internal->license->reset(
			[
				'expires',
				'expired',
				'invalid',
				'disabled',
				'activationsError',
				'connectionError',
				'requestError',
				'level',
				'addons',
				'counts',
				'upgradeUrl'
			]
		);

		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		$site    = aioseo()->helpers->getSite();
		$domains = ! empty( $domains )
			? $domains
			: [
				[
					'domain' => $site->domain,
					'path'   => $site->path
				]
			];

		$response = $this->sendLicenseRequest( 'multisite', $licenseKey, $domains );

		if ( empty( $response ) ) {
			// Something bad happened, error unknown.
			$this->internalOptions->internal->license->connectionError = true;

			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		if ( ! empty( $response->error ) ) {
			if ( 'missing-key-or-domain' === $response->error ) {
				$this->internalOptions->internal->license->requestError = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'disabled' === $response->error ) {
				$this->internalOptions->internal->license->disabled = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'activations' === $response->error ) {
				$this->internalOptions->internal->license->activationsError = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'expired' === $response->error ) {
				$this->internalOptions->internal->license->expires = strtotime( $response->expires );
				$this->internalOptions->internal->license->expired = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}
		}

		// Something bad happened, error unknown.
		if ( empty( $response->success ) || empty( $response->level ) ) {
			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		$this->internalOptions->internal->license->level    = $response->level;
		$this->internalOptions->internal->license->addons   = wp_json_encode( $response->addons );
		$this->internalOptions->internal->license->expires  = strtotime( $response->expires );
		$this->internalOptions->internal->license->features = wp_json_encode( $response->features );

		aioseo()->helpers->restoreCurrentBlog();

		$this->clearSitesActiveCache( $domains );

		return true;
	}


	/**
	 * Validate the license key.
	 *
	 * @since 4.2.5
	 *
	 * @param  array   $newDomains New domains to activate.
	 * @return boolean             Whether or not it was activated.
	 */
	public function activate( $newDomains = [] ) {
		aioseo()->helpers->switchToBlog( aioseo()->helpers->getNetworkId() );

		$this->internalOptions->internal->license->reset(
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

		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		$site    = aioseo()->helpers->getSite();
		$domains = ! empty( $newDomains )
			? $newDomains
			: [
				[
					'domain' => $site->domain,
					'path'   => $site->path
				]
			];

		$response = $this->sendLicenseRequest( 'activate', $licenseKey, $domains );

		if ( empty( $response ) ) {
			// Something bad happened, error unknown.
			$this->internalOptions->internal->license->connectionError = true;

			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		if ( ! empty( $response->error ) ) {
			if ( 'missing-key-or-domain' === $response->error ) {
				$this->internalOptions->internal->license->requestError = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'disabled' === $response->error ) {
				$this->internalOptions->internal->license->disabled = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'domain-disabled' === $response->error ) {
				$this->internalOptions->internal->license->domainDisabled = true;

				return false;
			}

			if ( 'activations' === $response->error ) {
				$this->internalOptions->internal->license->activationsError = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'expired' === $response->error ) {
				$this->internalOptions->internal->license->expires = strtotime( $response->expires );
				$this->internalOptions->internal->license->expired = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}
		}

		// Something bad happened, error unknown.
		if ( empty( $response->success ) || empty( $response->level ) ) {
			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		$this->internalOptions->internal->license->level    = $response->level;
		$this->internalOptions->internal->license->addons   = wp_json_encode( $response->addons );
		$this->internalOptions->internal->license->expires  = strtotime( $response->expires );
		$this->internalOptions->internal->license->features = wp_json_encode( $response->features );

		// Store activation counts if provided.
		if ( ! empty( $response->counts ) ) {
			$this->internalOptions->internal->license->counts = wp_json_encode( $response->counts );
		}

		// Store upgrade URL if provided.
		if ( ! empty( $response->upgradeUrl ) ) {
			$this->internalOptions->internal->license->upgradeUrl = $response->upgradeUrl;
		}

		if ( aioseo()->internalOptions->internal->has( 'ai' ) ) {
			// Update the AI credits.
			aioseo()->internalOptions->internal->ai->accessToken        = '';
			aioseo()->internalOptions->internal->ai->isTrialAccessToken = false;
			aioseo()->ai->getAccessToken( true );
		}

		aioseo()->helpers->restoreCurrentBlog();

		$this->clearSitesActiveCache( $newDomains );

		return true;
	}

	/**
	 * Deactivate the license key.
	 *
	 * @since 4.2.5
	 *
	 * @param  array   $domains New domains to activate.
	 * @return boolean          Whether it was deactivated.
	 */
	public function deactivate( $domains = [] ) {
		aioseo()->helpers->switchToBlog( aioseo()->helpers->getNetworkId() );

		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		$site    = aioseo()->helpers->getSite();
		$domainsToDeactivate = ! empty( $domains )
			? $domains
			: [
				[
					'domain' => $site->domain,
					'path'   => $site->path
				]
			];

		$response = $this->sendLicenseRequest( 'deactivate', $licenseKey, $domainsToDeactivate );

		if ( empty( $response ) ) {
			// Something bad happened, error unknown.
			$this->internalOptions->internal->license->connectionError = true;

			aioseo()->helpers->restoreCurrentBlog();

			return false;
		}

		if ( ! empty( $response->error ) ) {
			if ( 'missing-key-or-domain' === $response->error || 'not-activated' === $response->error ) {
				$this->internalOptions->internal->license->requestError = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'missing-license' === $response->error ) {
				$this->internalOptions->internal->license->invalid = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'disabled' === $response->error ) {
				$this->internalOptions->internal->license->disabled = true;

				aioseo()->helpers->restoreCurrentBlog();

				return false;
			}

			if ( 'domain-disabled' === $response->error ) {
				$this->internalOptions->internal->license->domainDisabled = true;

				return false;
			}
		}

		$this->internalOptions->internal->license->reset(
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

		$this->internalOptions->internal->license->level    = $response->level;
		$this->internalOptions->internal->license->addons   = wp_json_encode( $response->addons );
		$this->internalOptions->internal->license->expires  = strtotime( $response->expires );
		$this->internalOptions->internal->license->features = wp_json_encode( $response->features );

		if ( aioseo()->internalOptions->internal->has( 'ai' ) ) {
			// Update the AI credits.
			aioseo()->internalOptions->internal->ai->accessToken = '';
			aioseo()->ai->getAccessToken( true );
		}

		aioseo()->helpers->restoreCurrentBlog();

		$this->clearSitesActiveCache( $domains );

		return true;
	}

	/**
	 * Checks to see if the current license is expired.
	 *
	 * @since 4.2.5
	 *
	 * @return bool True if expired, false if not.
	 */
	public function isExpired() {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return false;
		}

		$expired = $this->internalOptions->internal->license->expired || $this->internalOptions->internal->license->expires < time();
		if ( $expired ) {
			$didActivationAttempt = $this->maybeReactivateExpiredLicense();

			// If we tried to activate the license again, start over. Otherwise, return true.
			return $didActivationAttempt ? $this->isExpired() : true;
		}

		$expires = $this->internalOptions->internal->license->expires;

		return 0 !== $expires && $expires < time();
	}

	/**
	 * Checks to see if the current license is disabled.
	 *
	 * @since 4.2.5
	 *
	 * @return bool True if disabled, false if not.
	 */
	public function isDisabled() {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return false;
		}

		return $this->internalOptions->internal->license->disabled;
	}

	/**
	 * Checks to see if the current license is invalid.
	 *
	 * @since 4.2.5
	 *
	 * @return bool True if invalid, false if not.
	 */
	public function isInvalid() {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return false;
		}

		return $this->internalOptions->internal->license->invalid;
	}

	/**
	 * Checks to see if the current license is disabled.
	 *
	 * @since 4.2.5
	 *
	 * @param  \WP_Site $site The site to check if the the license is active on.
	 * @return bool           True if disabled, false if not.
	 */
	public function isActive( $site = null ) {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return false;
		}

		if ( ! $this->isSiteActive( $site ) ) {
			return false;
		}

		return ! $this->isExpired() && ! $this->isDisabled() && ! $this->isInvalid();
	}

	/**
	 * Get the license level for the activated license.
	 *
	 * @since 4.2.5
	 *
	 * @param  \WP_Site $site The site to check if the the license is active on.
	 * @return string         The license level.
	 */
	public function getLicenseLevel( $site = null ) {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return 'Unknown';
		}

		if ( ! $this->isSiteActive( $site ) ) {
			return 'Unknown';
		}

		return $this->internalOptions->internal->license->level;
	}

	/**
	 * Checks if the current site is licensed at the network level.
	 *
	 * @since 4.2.5
	 *
	 * @return bool True if licensed at the network level.
	 */
	public function isNetworkLicensed() {
		// If we are already locally activated, then no it's not network licensed.
		if ( aioseo()->license->isActive() ) {
			return false;
		}

		if ( $this->isActive() ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a given site (or the current one) is active.
	 *
	 * @since   4.2.5
	 * @version 4.4.0
	 *
	 * @param  \WP_Site $site The site to check.
	 * @return bool           True if active, false if not.
	 */
	public function isSiteActive( $site = null ) {
		$licenseKey = $this->options->general->licenseKey;
		if ( empty( $licenseKey ) ) {
			return false;
		}

		if ( empty( $site ) ) {
			$site = \WP_Site::get_instance( get_current_blog_id() );
		}

		$urlHash  = sha1( $site->domain . $site->path );
		$cacheKey = "site_active_{$urlHash}";
		$isActive = aioseo()->core->cache->get( $cacheKey );
		if ( null !== $isActive ) {
			return $isActive;
		}

		$response = $this->sendLicenseRequest( 'activated', $licenseKey, [ $site ] );
		if ( ! empty( $response->error ) || empty( $response->all_activations_and_paths ) ) {
			aioseo()->core->cache->update( $cacheKey, false, DAY_IN_SECONDS );

			return false;
		}

		$active = false;
		foreach ( $response->all_activations_and_paths as $activeSite ) {
			if ( $site->domain === $activeSite->domain && $site->path === $activeSite->path ) {
				$active = true;
				break;
			}

			if ( ! empty( $site->aliases ) ) {
				foreach ( $site->aliases as $alias ) {
					if ( $activeSite->domain === $alias['domain'] ) {
						$active = true;
						break;
					}
				}
			}
		}

		aioseo()->core->cache->update( $cacheKey, $active, DAY_IN_SECONDS );

		return $active;
	}

	/**
	 * Checks if the given sites are activated.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $domains The domains to check.
	 * @return array          The domains with the active status.
	 */
	public function areSitesActive( $domains ) {
		// Force domains to be objects.
		$domains = json_decode( wp_json_encode( $domains ) );

		$fullDomains = array_map( function( $domain ) {
			return $domain->domain . $domain->path;
		}, $domains );
		$domainsHash = sha1( implode( ',', $fullDomains ) );
		$cacheKey    = "sites_active_{$domainsHash}";
		$areActive   = aioseo()->core->cache->get( $cacheKey );
		if ( null !== $areActive ) {
			return $areActive;
		}

		$licenseKey = $this->options->general->licenseKey;
		$response   = $this->sendLicenseRequest( 'activated', $licenseKey, $domains );
		if ( ! empty( $response->error ) || empty( $response->all_activations_and_paths ) ) {
			aioseo()->core->cache->update( $cacheKey, [], HOUR_IN_SECONDS );

			return [];
		}

		$activeSites = [];
		foreach ( $domains as $domain ) {
			foreach ( $response->all_activations_and_paths as $activeSite ) {
				if ( $domain->domain === $activeSite->domain && $domain->path === $activeSite->path ) {
					$activeSites[] = $activeSite;
					break;
				}

				if ( ! empty( $domain->aliases ) ) {
					foreach ( $domain->aliases as $alias ) {
						if ( $activeSite->domain === $alias['domain'] ) {
							$activeSites[] = $activeSite;
							break;
						}
					}
				}
			}
		}

		aioseo()->core->cache->update( $cacheKey, $activeSites, HOUR_IN_SECONDS );

		return $activeSites;
	}

	/**
	 * Adds a notice to the update row for unlicensed users.
	 *
	 * @since 4.2.5
	 *
	 * @return void
	 */
	public function updateRowNotice() {
		if ( $this->isActive() ) {
			return;
		}

		$this->outputUpdateRowNotice();
	}

	/**
	 * Add row to Plugins page with licensing information, if license key is invalid or not found.
	 *
	 * @since 4.2.5
	 *
	 * @return void
	 */
	public function pluginRowNotice() {
		// Don't output the notice on subsites since to prevent duplicate notices.
		if ( $this->isActive() || ! is_main_site() ) {
			return;
		}

		$this->outputPluginRowNotice();
	}

	/**
	 * Clears the active site(s) cache.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	private function clearSitesActiveCache( $domains = [] ) {
		$cacheTableName = aioseo()->core->db->prefix . 'aioseo_cache';
		aioseo()->core->db->execute( "DELETE FROM $cacheTableName WHERE `key` LIKE 'sites_active_%'" );

		$allDomains = [];
		if ( isset( $domains['activate'] ) ) {
			$allDomains = array_merge( $allDomains, $domains['activate'] );
		}

		if ( isset( $domains['deactivate'] ) ) {
			$allDomains = array_merge( $allDomains, $domains['deactivate'] );
		}

		foreach ( $allDomains as $domain ) {
			aioseo()->helpers->switchToBlog( $domain['blog_id'] );

			$cacheTableName = aioseo()->core->db->prefix . 'aioseo_cache';
			aioseo()->core->db->execute( "DELETE FROM $cacheTableName WHERE `key` LIKE 'site_active_%'" );

			aioseo()->helpers->restoreCurrentBlog();
		}
	}
}