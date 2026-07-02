<?php
namespace AIOSEO\Plugin\Pro\Standalone\BuddyPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone as CommonStandalone;

/**
 * Handles the BuddyPress integration with AIOSEO.
 *
 * @since 4.8.1
 */
class BuddyPress extends CommonStandalone\BuddyPress\BuddyPress {
	/**
	 * Instance of the Component class.
	 *
	 * @since 4.8.1
	 *
	 * @var Component
	 */
	public $component;

	/**
	 * Hooked into `bp_parse_query` action hook.
	 *
	 * @since 4.8.1
	 *
	 * @return void
	 */
	public function setComponent() {
		$this->component = new Component();
	}
}