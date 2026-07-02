<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Locations block class.
 *
 * @since 1.1.1
 */
class LocationCategories {
	/**
	 * Class constructor.
	 *
	 * @since 1.1.1
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Registers the block.
	 *
	 * @since 4.7.6
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock(
			'locationcategories', [
				'render_callback' => [ $this, 'render' ]
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @since 1.1.1
	 *
	 * @param  array  $blockAttributes The block attributes.
	 * @return string                  The output from the output buffering.
	 */
	public function render( $blockAttributes ) {
		ob_start();

		aioseoLocalBusiness()->locations->outputLocationCategories( $blockAttributes );

		return ob_get_clean();
	}
}