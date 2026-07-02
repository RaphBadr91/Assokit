<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Access class.
 *
 * @since 1.1.0
 */
class Access {
	/**
	 * The Access class instance from the main plugin.
	 *
	 * @since 1.1.0
	 *
	 * @var \AIOSEO\Plugin\Pro\Utils\Access
	 */
	public $access;

	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->access = aioseo()->access;
	}

	/**
	 * Add needed capabilities to run Local Business.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function addCapabilities() {
		foreach ( $this->access->getRoles() as $wpRole => $role ) {
			$roleObject = get_role( $wpRole );
			if ( ! is_object( $roleObject ) ) {
				continue;
			}

			if ( ! $this->access->hasCapability( 'aioseo_page_local_seo_settings', $role ) ) {
				foreach ( aioseoLocalBusiness()->postType->getCapabilities() as $postCapability ) {
					$roleObject->remove_cap( $postCapability );
				}
				foreach ( aioseoLocalBusiness()->taxonomy->getCapabilities() as $taxCapability ) {
					$roleObject->remove_cap( $taxCapability );
				}
			}

			if ( $this->access->isAdmin( $role ) || $this->access->hasCapability( 'aioseo_page_local_seo_settings', $role ) ) {
				foreach ( aioseoLocalBusiness()->postType->getCapabilities() as $postCapability ) {
					$roleObject->add_cap( $postCapability );
				}
				foreach ( aioseoLocalBusiness()->taxonomy->getCapabilities() as $taxCapability ) {
					$roleObject->add_cap( $taxCapability );
				}
			}
		}
	}
}