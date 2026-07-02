<?php
namespace AIOSEO\Plugin\Pro\Standalone\BbPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone as CommonStandalone;

/**
 * Handles the bbPress integration with AIOSEO.
 *
 * @since 4.8.1
 */
class BbPress extends CommonStandalone\BbPress\BbPress {
	/**
	 * Instance of the Component class.
	 *
	 * @since 4.8.1
	 *
	 * @var Component
	 */
	public $component;

	/**
	 * Hooked into `wp` action hook.
	 *
	 * @since 4.8.1
	 *
	 * @return void
	 */
	public function setComponent() {
		$this->component = new Component();
	}
}