<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Widgets;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Widgets.
 *
 * @since 1.1.0
 */
class Widgets {
	/**
	 * Widgets constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'widgets_init', [ $this, 'registerWidgets' ] );
	}

	/**
	 * Register AIOSEO plugin widgets.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function registerWidgets() {
		register_widget( 'AIOSEO\Plugin\Addon\LocalBusiness\Widgets\BusinessInfo' );
		register_widget( 'AIOSEO\Plugin\Addon\LocalBusiness\Widgets\Locations' );
		register_widget( 'AIOSEO\Plugin\Addon\LocalBusiness\Widgets\OpeningHours' );
		register_widget( 'AIOSEO\Plugin\Addon\LocalBusiness\Widgets\Map' );
	}
}