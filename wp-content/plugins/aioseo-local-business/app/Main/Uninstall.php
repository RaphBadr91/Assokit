<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LocalBusiness\Admin;

/**
 * Handles plugin deinstallation.
 *
 * @since 1.3.10
 */
class Uninstall {
	/**
	 * Removes all our tables and options.
	 *
	 * @since 1.3.10
	 *
	 * @param  bool $force Whether we should ignore the uninstall option or not. We ignore it when we reset all data via the Debug Panel.
	 * @return void
	 */
	public function dropData( $force = false ) {
		// Don't call `aioseo()->options` as it's not loaded during uninstall.
		$aioseoOptions = get_option( 'aioseo_options', '' );
		$aioseoOptions = json_decode( $aioseoOptions, true );

		// Confirm that user has decided to remove all data, otherwise stop.
		if (
			! $force &&
			empty( $aioseoOptions['advanced']['uninstall'] )
		) {
			return;
		}

		// Delete all our custom capabilities.
		$this->uninstallCapabilities();
	}

	/**
	 * Removes all our custom capabilities.
	 *
	 * @since 1.3.10
	 *
	 * @return void
	 */
	private function uninstallCapabilities() {
		$access             = new Admin\Location();
		$customCapabilities = $access->getCapabilities() ?? [];
		$roles              = aioseoLocalBusiness()->helpers->getUserRoles();

		// Loop through roles and remove custom capabilities.
		foreach ( $roles as $roleName => $roleInfo ) {
			$role = get_role( $roleName );

			if ( $role ) {
				foreach ( $customCapabilities as $capability ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}
}